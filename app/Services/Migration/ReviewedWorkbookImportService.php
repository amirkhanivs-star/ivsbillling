<?php

namespace App\Services\Migration;

use App\Exceptions\DatabaseSafetyException;
use App\Services\Money;
use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

class ReviewedWorkbookImportService
{
    public const APPROVED_WORKBOOK_SHA256 = '77d8e10f84726cc7b9708bfdb749948a7dbe717d60dab15b91361d5f2e4c4f4b';
    public const APPROVED_BATCH_ID = 'BATCH-20261023-FINAL-MYSKOOL';
    public const APPROVED_VERSION_KEY = 'v3_final_myskool_20261023';

    // Legacy and alternative SHA-256 hashes
    public const EXPECTED_SHA256 = self::APPROVED_WORKBOOK_SHA256;
    public const LEGACY_OCT22_SHA256 = 'd2276cf0c75a0c17b143d1815fda568c9b5cc7e0154f546b8075d1a98e225401';
    public const REVISED_SHA256 = 'f48f1ee57eb826776c2f1eb7cab0150640605d4b30f1c761044f7d0d12d0468e';

    // Final approved worksheet names
    public const SHEET_STUDENTS = 'Students';
    public const SHEET_COLLECTIONS = 'Paid details';
    public const SHEET_OUTSTANDING = 'Outstanding (Compiled Valid)';

    // Legacy worksheet constants retained for backwards compatibility
    public const SHEET_REGISTRATION = '01_REGISTRATION_PRESENT (1)';
    public const SHEET_COLLECTION = '03_COLLECTION_PRESENT_ONLY';
    public const SHEET_LEGACY_OUTSTANDING = '05_OUTSTANDING_PRESENT_ONLY';

    /**
     * Build normalized column-header map for dynamic header resolution.
     */
    public function buildHeaderMap(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $idx => $col) {
            $normalized = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', (string) $col)));
            if ($normalized !== '') {
                $map[$normalized] = $idx;
            }
        }
        return $map;
    }

    /**
     * Resolve sheet names dynamically from configuration or source workbook.
     */
    public function getResolvedSheets(?string $filePath = null): array
    {
        $activeKey = config('imports.active_version', self::APPROVED_VERSION_KEY);
        $configured = config("imports.versions.{$activeKey}.sheets", []);

        $students = $configured['students'] ?? self::SHEET_STUDENTS;
        $collections = $configured['paid_details'] ?? ($configured['collections'] ?? self::SHEET_COLLECTIONS);
        $outstanding = $configured['outstanding'] ?? self::SHEET_OUTSTANDING;

        if ($filePath && is_file($filePath)) {
            try {
                $reader = IOFactory::createReaderForFile($filePath);
                $names = $reader->listWorksheetNames($filePath);
                if (!in_array($students, $names, true) && in_array(self::SHEET_REGISTRATION, $names, true)) {
                    $students = self::SHEET_REGISTRATION;
                }
                if (!in_array($collections, $names, true) && in_array(self::SHEET_COLLECTION, $names, true)) {
                    $collections = self::SHEET_COLLECTION;
                }
                if (!in_array($outstanding, $names, true) && in_array(self::SHEET_LEGACY_OUTSTANDING, $names, true)) {
                    $outstanding = self::SHEET_LEGACY_OUTSTANDING;
                }
            } catch (\Throwable $e) {
                // Fall back to configured names
            }
        }

        return [
            'students' => $students,
            'collections' => $collections,
            'outstanding' => $outstanding,
        ];
    }

    /**
     * Preview the workbook reconciliation and metrics without modifying the database.
     * Strictly read-only: performs zero inserts or updates to the database.
     */
    public function preview(
        string $filePath,
        ?string $expectedSha256 = self::APPROVED_WORKBOOK_SHA256,
        ?string $conn = null
    ): array {
        @ini_set('memory_limit', '1536M');
        $conn = $conn ?: config('database.default', 'mysql');
        $this->validateFileAndHash($filePath, $expectedSha256);
        $this->validateConnection($conn);

        $sheets = $this->getResolvedSheets($filePath);
        $studentSheet = $sheets['students'];
        $collectionSheet = $sheets['collections'];
        $outstandingSheet = $sheets['outstanding'];

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);

        // 1. Sheet 1: Students / Registration
        $reader->setLoadSheetsOnly([$studentSheet]);
        $sp = $reader->load($filePath);
        $sheetReg = $sp->getSheetByName($studentSheet);
        $regData = $sheetReg->toArray(null, false, false, false);
        $sp->disconnectWorksheets();
        unset($sp, $sheetReg);
        gc_collect_cycles();

        $regHeaders = array_shift($regData) ?: [];
        $hdrReg = $this->buildHeaderMap($regHeaders);

        $idxGr = $hdrReg['grno'] ?? ($hdrReg['gr'] ?? ($hdrReg['grn'] ?? 1));
        $idxClass = $hdrReg['class'] ?? 11;
        $idxFee = $hdrReg['monthlyfee'] ?? ($hdrReg['fee'] ?? 13);
        $idxCurr = $hdrReg['currency'] ?? ($hdrReg['regcurr'] ?? 14);
        $idxStatus = $hdrReg['status'] ?? 15;
        $idxLeaving = $hdrReg['dateofleaving'] ?? ($hdrReg['leavingdate'] ?? 18);
        $idxProv = $hdrReg['currencyprovisional'] ?? ($hdrReg['provisional'] ?? null);

        $regGrs = [];
        $provCounts = [];
        $currCounts = [];
        $blankCurrencyCount = 0;
        $deptCounts = ['school' => 0, 'tuition' => 0];
        $approvedCount = 0;
        $heldCount = 0;
        $zeroFeeCount = 0;

        $mutuallyExclusive = [
            'active_billable' => 0,
            'active_zero_fee' => 0,
            'leaving_date_discrepancy' => 0,
            'review_hold_review' => 0,
            'review_hold_yes' => 0,
        ];

        foreach ($regData as $idx => $row) {
            $r = $idx + 2;
            $gr = trim((string) ($row[$idxGr] ?? ''));
            if ($gr === '') {
                continue;
            }

            $curr = strtoupper(trim((string) ($row[$idxCurr] ?? '')));
            $class = trim((string) ($row[$idxClass] ?? ''));
            $fee = self::parseMoneyDecimal($row[$idxFee] ?? 0);
            $leavingDate = trim((string) ($row[$idxLeaving] ?? ''));
            $hasLeaving = ($leavingDate !== '');
            $prov = $idxProv !== null ? strtoupper(trim((string) ($row[$idxProv] ?? 'NO'))) : ($curr === '' ? 'YES' : 'NO');

            $regGrs[$gr] = [
                'row' => $r,
                'currency' => $curr,
                'provisional' => $prov,
                'class' => $class,
            ];

            $provCounts[$prov] = ($provCounts[$prov] ?? 0) + 1;
            if ($curr === '') {
                $blankCurrencyCount++;
            } else {
                $currCounts[$curr] = ($currCounts[$curr] ?? 0) + 1;
            }

            if ($fee == 0) {
                $zeroFeeCount++;
            }

            if ($curr !== '' && $prov === 'NO') {
                if ($hasLeaving) {
                    $mutuallyExclusive['leaving_date_discrepancy']++;
                } elseif ($fee > 0) {
                    $mutuallyExclusive['active_billable']++;
                } else {
                    $mutuallyExclusive['active_zero_fee']++;
                }
                $approvedCount++;
            } elseif ($prov === 'REVIEW') {
                $mutuallyExclusive['review_hold_review']++;
                $heldCount++;
            } else {
                $mutuallyExclusive['review_hold_yes']++;
                $heldCount++;
            }

            $dept = (str_starts_with(strtolower($class), 'tuition')) ? 'tuition' : 'school';
            $deptCounts[$dept]++;
        }
        unset($regData);
        gc_collect_cycles();

        // Reconcile with existing portal database students (read-only)
        $db = DB::connection($conn);
        $existingGrs = $db->table('students')->pluck('gr_number')->all();
        $existingSet = array_flip($existingGrs);

        $sharedGrs = [];
        $newGrs = [];
        foreach ($regGrs as $gr => $data) {
            if (isset($existingSet[$gr])) {
                $sharedGrs[] = $gr;
            } else {
                $newGrs[] = $gr;
            }
        }

        $oldOnlyGrs = [];
        foreach ($existingGrs as $gr) {
            if (!isset($regGrs[$gr])) {
                $oldOnlyGrs[] = $gr;
            }
        }

        // 2. Sheet 2: Collections
        $reader->setLoadSheetsOnly([$collectionSheet]);
        $sp = $reader->load($filePath);
        $sheetCol = $sp->getSheetByName($collectionSheet);
        $colData = $sheetCol->toArray(null, false, false, false);
        $sp->disconnectWorksheets();
        unset($sp, $sheetCol);
        gc_collect_cycles();

        $colHeaders = array_shift($colData) ?: [];
        $hdrCol = $this->buildHeaderMap($colHeaders);
        $idxColSlip = $hdrCol['slip'] ?? ($hdrCol['slipnumber'] ?? 1);
        $idxColGr = $hdrCol['grn'] ?? ($hdrCol['gr'] ?? 2);
        $idxColPart = $hdrCol['particular'] ?? 4;
        $idxColDate = $hdrCol['date'] ?? 5;
        $idxColCurr = $hdrCol['currency'] ?? 6;
        $idxColAmt = $hdrCol['amount'] ?? 7;
        $idxColFlags = $hdrCol['reviewflags'] ?? ($hdrCol['flags'] ?? null);

        $colRowsCount = 0;
        $colUnresolved = 0;
        $colCurrencyTotals = [];
        $colCurrencyCounts = [];

        foreach ($colData as $row) {
            $slip = trim((string) ($row[$idxColSlip] ?? ''));
            $gr = trim((string) ($row[$idxColGr] ?? ''));
            $amtRaw = trim((string) ($row[$idxColAmt] ?? ''));
            if ($slip === '' && $gr === '' && $amtRaw === '') {
                continue;
            }

            $colRowsCount++;
            $curr = strtoupper(trim((string) ($row[$idxColCurr] ?? '')));
            $flags = $idxColFlags !== null ? trim((string) ($row[$idxColFlags] ?? '')) : '';
            $amt = self::parseMoneyDecimal($amtRaw);

            $currKey = $curr !== '' ? $curr : 'UNRESOLVED';
            $colCurrencyTotals[$currKey] = ($colCurrencyTotals[$currKey] ?? 0) + $amt;
            $colCurrencyCounts[$currKey] = ($colCurrencyCounts[$currKey] ?? 0) + 1;

            if ($curr === '' || $curr === 'UNRESOLVED' || str_contains($flags, 'UNRESOLVED')) {
                $colUnresolved++;
            }
        }
        unset($colData);
        gc_collect_cycles();

        // 3. Sheet 3: Outstanding
        $reader->setLoadSheetsOnly([$outstandingSheet]);
        $sp = $reader->load($filePath);
        $sheetDue = $sp->getSheetByName($outstandingSheet);
        $dueData = $sheetDue->toArray(null, false, false, false);
        $sp->disconnectWorksheets();
        unset($sp, $sheetDue);
        gc_collect_cycles();

        $dueHeaders = array_shift($dueData) ?: [];
        $hdrDue = $this->buildHeaderMap($dueHeaders);
        $idxDueGr = $hdrDue['grn'] ?? ($hdrDue['gr'] ?? 0);
        $idxDuePart = $hdrDue['particular'] ?? 3;
        $idxDueCurr = $hdrDue['currency'] ?? 4;
        $idxDueAmt = $hdrDue['balance'] ?? ($hdrDue['outstandingamount'] ?? ($hdrDue['amount'] ?? 5));
        $idxDueFlags = $hdrDue['reviewflags'] ?? ($hdrDue['flags'] ?? null);

        $dueRowsCount = 0;
        $dueUnresolved = 0;
        $dueCurrencyTotals = [];
        $dueCurrencyCounts = [];

        foreach ($dueData as $row) {
            $gr = trim((string) ($row[$idxDueGr] ?? ''));
            $part = trim((string) ($row[$idxDuePart] ?? ''));
            $balRaw = trim((string) ($row[$idxDueAmt] ?? ''));
            if ($gr === '' && $part === '' && $balRaw === '') {
                continue;
            }

            $dueRowsCount++;
            $curr = strtoupper(trim((string) ($row[$idxDueCurr] ?? '')));
            $flags = $idxDueFlags !== null ? trim((string) ($row[$idxDueFlags] ?? '')) : '';
            $amt = self::parseMoneyDecimal($balRaw);

            $currKey = $curr !== '' ? $curr : 'UNRESOLVED';
            $dueCurrencyTotals[$currKey] = ($dueCurrencyTotals[$currKey] ?? 0) + $amt;
            $dueCurrencyCounts[$currKey] = ($dueCurrencyCounts[$currKey] ?? 0) + 1;

            if ($curr === '' || $curr === 'UNRESOLVED' || str_contains($flags, 'UNRESOLVED')) {
                $dueUnresolved++;
            }
        }
        unset($dueData);
        gc_collect_cycles();

        return [
            'file_path' => $filePath,
            'sha256' => hash_file('sha256', $filePath),
            'sheets_resolved' => [
                'students' => $studentSheet,
                'collections' => $collectionSheet,
                'outstanding' => $outstandingSheet,
            ],
            'registration' => [
                'total_rows' => count($regGrs),
                'shared_grs_count' => count($sharedGrs),
                'new_grs_count' => count($newGrs),
                'old_only_grs_count' => count($oldOnlyGrs),
                'provisional_breakdown' => $provCounts,
                'mutually_exclusive' => $mutuallyExclusive,
                'mutually_exclusive_sum' => array_sum($mutuallyExclusive),
                'approved_count' => $approvedCount,
                'held_count' => $heldCount,
                'blank_currency_count' => $blankCurrencyCount,
                'currencies' => $currCounts,
                'departments' => $deptCounts,
                'zero_fee_count' => $zeroFeeCount,
            ],
            'collection' => [
                'sheet_name' => $collectionSheet,
                'total_rows' => $colRowsCount,
                'unresolved_currency_rows' => $colUnresolved,
                'totals_by_currency' => $colCurrencyTotals,
                'counts_by_currency' => $colCurrencyCounts,
            ],
            'outstanding' => [
                'sheet_name' => $outstandingSheet,
                'total_rows' => $dueRowsCount,
                'unresolved_currency_rows' => $dueUnresolved,
                'totals_by_currency' => $dueCurrencyTotals,
                'counts_by_currency' => $dueCurrencyCounts,
            ],
        ];
    }

    /**
     * Import the approved workbook into the portal database.
     */
    public function import(
        string $filePath,
        ?string $expectedSha256 = self::APPROVED_WORKBOOK_SHA256,
        ?string $conn = null,
        ?string $batchId = null
    ): array {
        @ini_set('memory_limit', '1536M');
        gc_collect_cycles();
        $conn = $conn ?: config('database.default', 'mysql');
        $this->validateFileAndHash($filePath, $expectedSha256);
        $this->validateConnection($conn);

        $batchId = $batchId ?: (string) config('imports.active_batch_id', self::APPROVED_BATCH_ID);
        $now = now();
        $qa = DB::connection($conn);

        return $qa->transaction(function () use ($filePath, $qa, $batchId, $now) {
            @ini_set('memory_limit', '1536M');

            // Capture initial financial ledger baseline to guarantee zero ledger mutation
            $ledgerBefore = [
                'invoices' => $qa->table('invoices')->count(),
                'invoice_items' => $qa->table('invoice_items')->count(),
                'payments' => $qa->table('payments')->count(),
                'payment_allocations' => $qa->table('payment_allocations')->count(),
                'billing_runs' => $qa->table('billing_runs')->count(),
            ];

            $sheets = $this->getResolvedSheets($filePath);
            $studentSheet = $sheets['students'];
            $collectionSheet = $sheets['collections'];
            $outstandingSheet = $sheets['outstanding'];

            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);

            // 1. Resolve Campuses, Departments, Fee Types
            $mainCampusId = $qa->table('campuses')->where('code', 'main')->value('id');
            $schoolDeptId = $qa->table('departments')->where('code', 'school')->value('id');
            $tuitionDeptId = $qa->table('departments')->where('code', 'tuition')->value('id');
            $monthlyFeeTypeId = $qa->table('fee_types')->whereNull('department_id')->where('code', 'monthly')->value('id');

            // 2. Preload Programs
            $programsCache = [];
            foreach ($qa->table('programs')->get() as $p) {
                $programsCache[$p->department_id . ':' . $p->name] = $p->id;
            }

            // 3. Process Sheet 1: Registration / Students
            $reader->setLoadSheetsOnly([$studentSheet]);
            $sp = $reader->load($filePath);
            $sheetReg = $sp->getSheetByName($studentSheet);
            $regData = $sheetReg->toArray(null, false, false, false);
            $sp->disconnectWorksheets();
            unset($sp);

            $regHeaders = array_shift($regData) ?: [];
            $hdrReg = $this->buildHeaderMap($regHeaders);

            $idxRawId = $hdrReg['id'] ?? 0;
            $idxGr = $hdrReg['grno'] ?? ($hdrReg['gr'] ?? ($hdrReg['grn'] ?? 1));
            $idxFam = $hdrReg['familycode'] ?? 2;
            $idxName = $hdrReg['studentname'] ?? 3;
            $idxFather = $hdrReg['fathername'] ?? 4;
            $idxGender = $hdrReg['gender'] ?? 5;
            $idxReligion = $hdrReg['religion'] ?? 7;
            $idxContactFather = $hdrReg['contactfather'] ?? 8;
            $idxContactMother = $hdrReg['contactmother'] ?? 9;
            $idxAdmissionDate = $hdrReg['dateofadmission'] ?? 10;
            $idxClass = $hdrReg['class'] ?? 11;
            $idxClassAdmitted = $hdrReg['classadmitted'] ?? 12;
            $idxFee = $hdrReg['monthlyfee'] ?? 13;
            $idxStatus = $hdrReg['status'] ?? 15;
            $idxGuardian = $hdrReg['guardianname'] ?? 16;
            $idxCurrency = $hdrReg['currency'] ?? ($hdrReg['regcurr'] ?? 14);
            $idxCurrencyEvidence = $hdrReg['currencyevidence'] ?? null;
            $idxProv = $hdrReg['currencyprovisional'] ?? ($hdrReg['provisional'] ?? null);
            $idxPaidPdf = $hdrReg['paidpdfcurrencies'] ?? null;
            $idxOutPdf = $hdrReg['outstandingpdfcurrencies'] ?? null;
            $idxConflict = $hdrReg['currencyconflictflags'] ?? null;

            $stats = [
                'batch_id' => $batchId,
                'registration_rows' => count($regData),
                'shared_updated' => 0,
                'new_created' => 0,
                'historical_held' => 0,
                'active_billable' => 0,
                'review_holds' => 0,
                'fee_plans_provisioned' => 0,
                'collections_staged' => 0,
                'dues_staged' => 0,
                'exceptions_logged' => 0,
                'unresolved_collections' => 0,
                'unresolved_dues' => 0,
            ];

            $seenWorkbookGrs = [];

            // Preload existing students
            $existingStudentsMap = $qa->table('students')->get()->keyBy('gr_number');
            $existingFamiliesMap = $qa->table('families')->get()->keyBy('family_code');

            foreach ($regData as $rowIndex => $row) {
                $sourceRow = $rowIndex + 2;
                $rawId = trim((string) ($row[$idxRawId] ?? ''));
                $gr = trim((string) ($row[$idxGr] ?? ''));
                if ($gr === '') {
                    continue;
                }

                $seenWorkbookGrs[$gr] = true;

                $famCode = trim((string) ($row[$idxFam] ?? ''));
                $isProvisionalFamily = false;
                if ($famCode === '') {
                    $famCode = 'PROV-FAM-' . $gr;
                    $isProvisionalFamily = true;
                }

                $name = trim((string) ($row[$idxName] ?? ''));
                $father = trim((string) ($row[$idxFather] ?? ''));
                $gender = trim((string) ($row[$idxGender] ?? ''));
                $religion = trim((string) ($row[$idxReligion] ?? ''));
                $contactFather = trim((string) ($row[$idxContactFather] ?? ''));
                $contactMother = trim((string) ($row[$idxContactMother] ?? ''));
                $admissionDateRaw = $row[$idxAdmissionDate] ?? null;
                $parsedAdmissionDate = self::parseExcelDate($admissionDateRaw);
                $className = trim((string) ($row[$idxClass] ?? 'General'));
                $classAdmitted = trim((string) ($row[$idxClassAdmitted] ?? ''));
                $rawFee = trim((string) ($row[$idxFee] ?? '0'));
                $statusRaw = trim((string) ($row[$idxStatus] ?? 'present'));
                $guardian = trim((string) ($row[$idxGuardian] ?? ''));
                $currencyRaw = strtoupper(trim((string) ($row[$idxCurrency] ?? '')));
                $currencyEvidence = $idxCurrencyEvidence !== null ? trim((string) ($row[$idxCurrencyEvidence] ?? '')) : '';
                $currencyProvisional = $idxProv !== null ? strtoupper(trim((string) ($row[$idxProv] ?? 'NO'))) : ($currencyRaw === '' ? 'YES' : 'NO');
                $paidPdfCurrencies = $idxPaidPdf !== null ? trim((string) ($row[$idxPaidPdf] ?? '')) : '';
                $outstandingPdfCurrencies = $idxOutPdf !== null ? trim((string) ($row[$idxOutPdf] ?? '')) : '';
                $conflictFlags = $idxConflict !== null ? trim((string) ($row[$idxConflict] ?? '')) : '';

                // 3a. Family mapping
                if (!isset($existingFamiliesMap[$famCode])) {
                    $familyId = $qa->table('families')->insertGetId([
                        'family_code' => $famCode,
                        'guardian_name' => $guardian ?: ($father ?: null),
                        'email' => null,
                        'whatsapp' => self::sanitizePhone($contactFather ?: $contactMother),
                        'contact_verified' => false,
                        'messaging_opt_in' => false,
                        'is_provisional' => $isProvisionalFamily,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $existingFamiliesMap[$famCode] = (object) ['id' => $familyId, 'family_code' => $famCode];
                } else {
                    $familyId = $existingFamiliesMap[$famCode]->id;
                }

                // 3b. Department & Program
                $isTuition = str_starts_with(strtolower($className), 'tuition');
                $departmentId = $isTuition ? $tuitionDeptId : $schoolDeptId;
                $progKey = $departmentId . ':' . $className;
                if (!isset($programsCache[$progKey])) {
                    $progId = $qa->table('programs')->insertGetId([
                        'department_id' => $departmentId,
                        'name' => $className,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $programsCache[$progKey] = $progId;
                }
                $programId = $programsCache[$progKey];

                // 3c. Currency & Billable Hold Status
                $isApprovedCurrency = ($currencyProvisional === 'NO' && $currencyRaw !== '');
                $feeDecimal = self::parseMoneyDecimal($rawFee);
                $feeMinor = self::parseMoneyMinor($rawFee);

                if ($isApprovedCurrency) {
                    $enrollmentCurrency = $currencyRaw;
                    $enrollmentStatus = 'active';
                    $studentStatus = ($statusRaw !== '') ? strtolower($statusRaw) : 'present';
                    $enrollmentFeeMinor = $feeMinor;
                    $stats['active_billable']++;
                } else {
                    // Provisional, review, or blank currency: strictly placed on financial approval hold
                    $enrollmentCurrency = $currencyRaw !== '' ? $currencyRaw : 'HLD';
                    $enrollmentStatus = 'review_hold';
                    $studentStatus = 'review_hold';
                    $enrollmentFeeMinor = 0; // Strictly non-billable
                    $stats['review_holds']++;
                }

                $legacyBilling = [
                    'source_workbook' => basename($filePath),
                    'source_row' => $sourceRow,
                    'batch_id' => $batchId,
                    'currency_provisional' => $currencyProvisional,
                    'currency_evidence' => $currencyEvidence,
                    'currency_conflict_flags' => $conflictFlags,
                    'paid_pdf_currencies' => $paidPdfCurrencies,
                    'outstanding_pdf_currencies' => $outstandingPdfCurrencies,
                    'raw_fee' => $feeDecimal,
                    'is_approved_currency' => $isApprovedCurrency,
                ];

                // 3d. Upsert Student
                if (isset($existingStudentsMap[$gr])) {
                    $studentId = $existingStudentsMap[$gr]->id;
                    $qa->table('students')->where('id', $studentId)->update([
                        'name' => $name,
                        'family_id' => $familyId,
                        'status' => $studentStatus,
                        'date_of_admission' => $parsedAdmissionDate,
                        'gender' => $gender ?: null,
                        'updated_at' => $now,
                    ]);
                    $stats['shared_updated']++;
                } else {
                    $studentId = $qa->table('students')->insertGetId([
                        'gr_number' => $gr,
                        'name' => $name,
                        'family_id' => $familyId,
                        'legacy_myskool_id' => $rawId ?: ('XLSX:V3:' . $gr),
                        'status' => $studentStatus,
                        'date_of_admission' => $parsedAdmissionDate,
                        'gender' => $gender ?: null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $existingStudentsMap[$gr] = (object) ['id' => $studentId, 'gr_number' => $gr];
                    $stats['new_created']++;
                }

                // 3e. Upsert Student Registration Details
                $primaryPhone = self::sanitizePhone($contactFather ?: $contactMother);
                $qa->table('student_registration_details')->updateOrInsert(
                    ['student_id' => $studentId],
                    [
                        'father_name' => $father ?: null,
                        'religion' => $religion ?: null,
                        'class_admitted' => $classAdmitted ?: null,
                        'section_current' => $className ?: null,
                        'registration_date' => $parsedAdmissionDate,
                        'phone_number' => $primaryPhone,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );

                // 3f. Upsert Enrollment
                $existingEnrollment = $qa->table('enrollments')
                    ->where('student_id', $studentId)
                    ->where('campus_id', $mainCampusId)
                    ->first();

                $enrollmentData = [
                    'student_id' => $studentId,
                    'campus_id' => $mainCampusId,
                    'department_id' => $departmentId,
                    'program_id' => $programId,
                    'grade_label' => $className,
                    'currency' => $enrollmentCurrency,
                    'monthly_fee_minor' => $enrollmentFeeMinor,
                    'legacy_billing' => json_encode($legacyBilling),
                    'started_on' => $parsedAdmissionDate,
                    'status' => $enrollmentStatus,
                    'updated_at' => $now,
                ];

                if ($existingEnrollment) {
                    $enrollmentId = $existingEnrollment->id;
                    $qa->table('enrollments')->where('id', $enrollmentId)->update($enrollmentData);
                } else {
                    $enrollmentData['created_at'] = $now;
                    $enrollmentId = $qa->table('enrollments')->insertGetId($enrollmentData);
                }

                // 3g. Fee Plan Items: provision ONLY for active approved enrollments with fee > 0
                // For Tuition students, zero generic fee is valid and subject fees are generated on demand
                if ($isApprovedCurrency && $enrollmentFeeMinor > 0 && !$isTuition) {
                    $qa->table('fee_plan_items')->updateOrInsert(
                        [
                            'enrollment_id' => $enrollmentId,
                            'fee_type_id' => $monthlyFeeTypeId,
                        ],
                        [
                            'description' => 'Monthly Fee',
                            'frequency' => 'monthly',
                            'currency' => $enrollmentCurrency,
                            'amount_minor' => $enrollmentFeeMinor,
                            'is_active' => true,
                            'updated_at' => $now,
                            'created_at' => $now,
                        ]
                    );
                    $stats['fee_plans_provisioned']++;
                } else {
                    // Deactivate fee plans for review hold or 0 fee students (safely preserve foreign keys)
                    $qa->table('fee_plan_items')
                        ->where('enrollment_id', $enrollmentId)
                        ->where('fee_type_id', $monthlyFeeTypeId)
                        ->update([
                            'is_active' => false,
                            'amount_minor' => 0,
                            'updated_at' => $now,
                        ]);
                }
            }

            // 4. Reconcile Absent Records (Preserve, Flag for Historical Review)
            $allCurrentQaStudents = $qa->table('students')->get();
            foreach ($allCurrentQaStudents as $stu) {
                if (!isset($seenWorkbookGrs[$stu->gr_number])) {
                    $qa->table('students')->where('id', $stu->id)->update([
                        'status' => 'historical_hold',
                        'updated_at' => $now,
                    ]);

                    $qa->table('enrollments')->where('student_id', $stu->id)->update([
                        'status' => 'historical_review',
                        'updated_at' => $now,
                    ]);

                    // Deactivate fee plans for absent students
                    $enIds = $qa->table('enrollments')->where('student_id', $stu->id)->pluck('id');
                    $qa->table('fee_plan_items')->whereIn('enrollment_id', $enIds)->update([
                        'is_active' => false,
                        'updated_at' => $now,
                    ]);

                    $stats['historical_held']++;
                }
            }
            unset($regData, $existingStudentsMap, $seenWorkbookGrs, $allCurrentQaStudents);
            gc_collect_cycles();

            // 5. Clean Previous Staging Data for This Batch (Idempotency)
            $qa->table('qa_staged_collections')->where('batch_id', $batchId)->delete();
            $qa->table('qa_staged_dues')->where('batch_id', $batchId)->delete();
            $qa->table('qa_staged_exceptions')->where('batch_id', $batchId)->delete();

            // 6. Process Sheet 2: Collections
            $reader->setLoadSheetsOnly([$collectionSheet]);
            $sp = $reader->load($filePath);
            $sheetCol = $sp->getSheetByName($collectionSheet);
            $colData = $sheetCol->toArray(null, false, false, false);
            $sp->disconnectWorksheets();
            unset($sp, $sheetCol);
            gc_collect_cycles();

            $colHeaders = array_shift($colData) ?: [];
            $hdrCol = $this->buildHeaderMap($colHeaders);

            $idxColSlip = $hdrCol['slip'] ?? ($hdrCol['slipnumber'] ?? 1);
            $idxColGr = $hdrCol['grn'] ?? ($hdrCol['gr'] ?? 2);
            $idxColStuName = $hdrCol['studentname'] ?? 3;
            $idxColRegName = $hdrCol['registrationname'] ?? null;
            $idxColFam = $hdrCol['familycode'] ?? null;
            $idxColPart = $hdrCol['particular'] ?? 4;
            $idxColDate = $hdrCol['date'] ?? 5;
            $idxColCurr = $hdrCol['currency'] ?? 6;
            $idxColAmt = $hdrCol['amount'] ?? 7;
            $idxColRegCurr = $hdrCol['regcurr'] ?? ($hdrCol['registrationcurrency'] ?? null);
            $idxColPdfPage = $hdrCol['pdfpage'] ?? null;
            $idxColPdfY = $hdrCol['pdfy'] ?? null;
            $idxColGrnOrig = $hdrCol['grnoriginal'] ?? null;
            $idxColNameOrig = $hdrCol['nameoriginal'] ?? null;
            $idxColFlags = $hdrCol['reviewflags'] ?? null;

            $colRowsToInsert = [];
            $exceptionsToInsert = [];

            foreach ($colData as $idx => $r) {
                $sourceRow = $idx + 2;
                $slip = trim((string) ($r[$idxColSlip] ?? ''));
                $gr = trim((string) ($r[$idxColGr] ?? ''));
                $stuName = trim((string) ($r[$idxColStuName] ?? ''));
                $regName = $idxColRegName !== null ? trim((string) ($r[$idxColRegName] ?? '')) : null;
                $famCode = $idxColFam !== null ? trim((string) ($r[$idxColFam] ?? '')) : null;
                $particular = trim((string) ($r[$idxColPart] ?? ''));
                $payDateRaw = $r[$idxColDate] ?? null;
                $payDateParsed = self::parseExcelDate($payDateRaw);
                $curr = strtoupper(trim((string) ($r[$idxColCurr] ?? '')));
                $amtErr = null;
                $amtDecimal = self::parseMoneyDecimal($r[$idxColAmt] ?? 0, $amtErr);
                $amtMinor = self::parseMoneyMinor($r[$idxColAmt] ?? 0, $amtErr);
                $regCurr = $idxColRegCurr !== null ? strtoupper(trim((string) ($r[$idxColRegCurr] ?? ''))) : null;
                $pdfPage = $idxColPdfPage !== null ? trim((string) ($r[$idxColPdfPage] ?? '')) : null;
                $pdfY = $idxColPdfY !== null ? trim((string) ($r[$idxColPdfY] ?? '')) : null;
                $grnOriginal = $idxColGrnOrig !== null ? trim((string) ($r[$idxColGrnOrig] ?? '')) : null;
                $nameOriginal = $idxColNameOrig !== null ? trim((string) ($r[$idxColNameOrig] ?? '')) : null;
                $reviewFlags = $idxColFlags !== null ? trim((string) ($r[$idxColFlags] ?? '')) : null;

                if ($slip === '' && $gr === '' && $amtDecimal == 0) {
                    continue;
                }

                $isUnresolved = ($curr === '' || $curr === 'UNRESOLVED' || ($reviewFlags && str_contains($reviewFlags, 'UNRESOLVED')));
                $hasConflict = ($curr !== '' && $regCurr !== null && $regCurr !== '' && $curr !== $regCurr);

                if ($amtErr !== null) {
                    $exceptionsToInsert[] = [
                        'batch_id' => $batchId,
                        'sheet_name' => $collectionSheet,
                        'source_row' => $sourceRow,
                        'gr_number' => $gr,
                        'issue_code' => 'INVALID_MONETARY_AMOUNT',
                        'issue_message' => "Collection row has invalid amount: {$amtErr}. Slip: {$slip}",
                        'raw_data' => json_encode($r),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($isUnresolved) {
                    $stats['unresolved_collections']++;
                    $exceptionsToInsert[] = [
                        'batch_id' => $batchId,
                        'sheet_name' => $collectionSheet,
                        'source_row' => $sourceRow,
                        'gr_number' => $gr,
                        'issue_code' => 'UNRESOLVED_COLLECTION_CURRENCY',
                        'issue_message' => "Collection row has unresolved currency. Slip: {$slip}, Amount: {$amtDecimal}",
                        'raw_data' => json_encode($r),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($hasConflict) {
                    $exceptionsToInsert[] = [
                        'batch_id' => $batchId,
                        'sheet_name' => $collectionSheet,
                        'source_row' => $sourceRow,
                        'gr_number' => $gr,
                        'issue_code' => 'COLLECTION_CURRENCY_CONFLICT',
                        'issue_message' => "Collection currency '{$curr}' conflicts with registration currency '{$regCurr}'",
                        'raw_data' => json_encode($r),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                $colRowsToInsert[] = [
                    'batch_id' => $batchId,
                    'source_row' => $sourceRow,
                    'slip_number' => $slip ?: null,
                    'gr_number' => $gr,
                    'student_name' => $stuName ?: null,
                    'registration_name' => $regName ?: null,
                    'family_code' => $famCode ?: null,
                    'present_at_22oct' => null,
                    'fee_particular' => $particular ?: null,
                    'payment_date_raw' => (string) $payDateRaw,
                    'payment_date_parsed' => $payDateParsed,
                    'payment_method' => null,
                    'currency' => $curr ?: null,
                    'currency_original' => $curr ?: null,
                    'amount' => $amtDecimal,
                    'amount_minor' => $amtMinor,
                    'registration_currency' => $regCurr ?: null,
                    'currency_compare' => null,
                    'source_pdf_page' => $pdfPage ?: null,
                    'source_pdf_y' => $pdfY ?: null,
                    'source_grn_original' => $grnOriginal ?: null,
                    'source_name_original' => $nameOriginal ?: null,
                    'review_flags' => $reviewFlags ?: null,
                    'is_currency_unresolved' => $isUnresolved,
                    'has_currency_conflict' => $hasConflict,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($colRowsToInsert) >= 500) {
                    $qa->table('qa_staged_collections')->insert($colRowsToInsert);
                    $stats['collections_staged'] += count($colRowsToInsert);
                    $colRowsToInsert = [];
                }
            }
            if (!empty($colRowsToInsert)) {
                $qa->table('qa_staged_collections')->insert($colRowsToInsert);
                $stats['collections_staged'] += count($colRowsToInsert);
            }

            // 7. Process Sheet 3: Outstanding
            $reader->setLoadSheetsOnly([$outstandingSheet]);
            $sp = $reader->load($filePath);
            $sheetDue = $sp->getSheetByName($outstandingSheet);
            $dueData = $sheetDue->toArray(null, false, false, false);
            $sp->disconnectWorksheets();
            unset($sp);

            $dueHeaders = array_shift($dueData) ?: [];
            $hdrDue = $this->buildHeaderMap($dueHeaders);

            $idxDueGr = $hdrDue['grn'] ?? ($hdrDue['gr'] ?? 0);
            $idxDueStuName = $hdrDue['studentname'] ?? 1;
            $idxDueRegName = $hdrDue['registrationname'] ?? null;
            $idxDueFamFinal = $hdrDue['familycodefinal'] ?? ($hdrDue['familycode'] ?? null);
            $idxDueFamPdf = $hdrDue['familycodepdf'] ?? null;
            $idxDueFamReg = $hdrDue['familycoderegistration'] ?? null;
            $idxDueClass = $hdrDue['classinduesreport'] ?? null;
            $idxDuePart = $hdrDue['particular'] ?? 3;
            $idxDueCurr = $hdrDue['currency'] ?? 4;
            $idxDueBal = $hdrDue['balance'] ?? ($hdrDue['outstandingamount'] ?? ($hdrDue['amount'] ?? 5));
            $idxDueRegCurr = $hdrDue['regcurr'] ?? ($hdrDue['registrationcurrency'] ?? null);
            $idxDuePdfPage = $hdrDue['pdfpage'] ?? null;
            $idxDuePdfY = $hdrDue['pdfy'] ?? null;
            $idxDueGrnOrig = $hdrDue['grnoriginal'] ?? null;
            $idxDueNameOrig = $hdrDue['nameoriginal'] ?? null;
            $idxDueFlags = $hdrDue['reviewflags'] ?? null;

            $dueRowsToInsert = [];

            foreach ($dueData as $idx => $r) {
                $sourceRow = $idx + 2;
                $gr = trim((string) ($r[$idxDueGr] ?? ''));
                $stuName = trim((string) ($r[$idxDueStuName] ?? ''));
                $regName = $idxDueRegName !== null ? trim((string) ($r[$idxDueRegName] ?? '')) : null;
                $famFinal = $idxDueFamFinal !== null ? trim((string) ($r[$idxDueFamFinal] ?? '')) : null;
                $famPdf = $idxDueFamPdf !== null ? trim((string) ($r[$idxDueFamPdf] ?? '')) : null;
                $famReg = $idxDueFamReg !== null ? trim((string) ($r[$idxDueFamReg] ?? '')) : null;
                $classDues = $idxDueClass !== null ? trim((string) ($r[$idxDueClass] ?? '')) : null;
                $particular = trim((string) ($r[$idxDuePart] ?? ''));
                $curr = strtoupper(trim((string) ($r[$idxDueCurr] ?? '')));
                $dueAmtErr = null;
                $amtDecimal = self::parseMoneyDecimal($r[$idxDueBal] ?? 0, $dueAmtErr);
                $amtMinor = self::parseMoneyMinor($r[$idxDueBal] ?? 0, $dueAmtErr);
                $regCurr = $idxDueRegCurr !== null ? strtoupper(trim((string) ($r[$idxDueRegCurr] ?? ''))) : null;
                $pdfPage = $idxDuePdfPage !== null ? trim((string) ($r[$idxDuePdfPage] ?? '')) : null;
                $pdfY = $idxDuePdfY !== null ? trim((string) ($r[$idxDuePdfY] ?? '')) : null;
                $grnOriginal = $idxDueGrnOrig !== null ? trim((string) ($r[$idxDueGrnOrig] ?? '')) : null;
                $nameOriginal = $idxDueNameOrig !== null ? trim((string) ($r[$idxDueNameOrig] ?? '')) : null;
                $reviewFlags = $idxDueFlags !== null ? trim((string) ($r[$idxDueFlags] ?? '')) : null;

                if ($gr === '' && $particular === '' && $amtDecimal == 0) {
                    continue;
                }

                $isUnresolved = ($curr === '' || $curr === 'UNRESOLVED' || ($reviewFlags && str_contains($reviewFlags, 'UNRESOLVED')));
                $hasConflict = ($curr !== '' && $regCurr !== null && $regCurr !== '' && $curr !== $regCurr);

                if ($dueAmtErr !== null) {
                    $exceptionsToInsert[] = [
                        'batch_id' => $batchId,
                        'sheet_name' => $outstandingSheet,
                        'source_row' => $sourceRow,
                        'gr_number' => $gr,
                        'issue_code' => 'INVALID_MONETARY_AMOUNT',
                        'issue_message' => "Outstanding row has invalid amount: {$dueAmtErr}",
                        'raw_data' => json_encode($r),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($isUnresolved) {
                    $stats['unresolved_dues']++;
                    $exceptionsToInsert[] = [
                        'batch_id' => $batchId,
                        'sheet_name' => $outstandingSheet,
                        'source_row' => $sourceRow,
                        'gr_number' => $gr,
                        'issue_code' => 'UNRESOLVED_OUTSTANDING_CURRENCY',
                        'issue_message' => "Outstanding row has unresolved currency. Amount: {$amtDecimal}",
                        'raw_data' => json_encode($r),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($hasConflict) {
                    $exceptionsToInsert[] = [
                        'batch_id' => $batchId,
                        'sheet_name' => $outstandingSheet,
                        'source_row' => $sourceRow,
                        'gr_number' => $gr,
                        'issue_code' => 'OUTSTANDING_CURRENCY_CONFLICT',
                        'issue_message' => "Outstanding currency '{$curr}' conflicts with registration currency '{$regCurr}'",
                        'raw_data' => json_encode($r),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                $dueRowsToInsert[] = [
                    'batch_id' => $batchId,
                    'source_row' => $sourceRow,
                    'gr_number' => $gr,
                    'student_name' => $stuName ?: null,
                    'registration_name' => $regName ?: null,
                    'family_code_final' => $famFinal ?: null,
                    'family_code_pdf' => $famPdf ?: null,
                    'family_code_registration' => $famReg ?: null,
                    'present_at_22oct' => null,
                    'class_in_dues_report' => $classDues ?: null,
                    'fee_particular' => $particular ?: null,
                    'currency' => $curr ?: null,
                    'currency_original' => $curr ?: null,
                    'outstanding_amount' => $amtDecimal,
                    'outstanding_amount_minor' => $amtMinor,
                    'registration_currency' => $regCurr ?: null,
                    'source_pdf_page' => $pdfPage ?: null,
                    'source_pdf_y' => $pdfY ?: null,
                    'source_grn_original' => $grnOriginal ?: null,
                    'source_name_original' => $nameOriginal ?: null,
                    'review_flags' => $reviewFlags ?: null,
                    'is_currency_unresolved' => $isUnresolved,
                    'has_currency_conflict' => $hasConflict,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($dueRowsToInsert) >= 500) {
                    $qa->table('qa_staged_dues')->insert($dueRowsToInsert);
                    $stats['dues_staged'] += count($dueRowsToInsert);
                    $dueRowsToInsert = [];
                }
            }
            if (!empty($dueRowsToInsert)) {
                $qa->table('qa_staged_dues')->insert($dueRowsToInsert);
                $stats['dues_staged'] += count($dueRowsToInsert);
            }

            // Insert exceptions in chunks
            foreach (array_chunk($exceptionsToInsert, 250) as $chunk) {
                $qa->table('qa_staged_exceptions')->insert($chunk);
            }
            $stats['exceptions_logged'] = count($exceptionsToInsert);

            // 8. Strict Zero Financial Ledger Posting Assertion
            $this->assertZeroFinancialLedgerPosting($qa, $ledgerBefore);

            // 9. Update Provenance in import_batches
            $qa->table('import_batches')->updateOrInsert(
                ['source_name' => basename($filePath)],
                [
                    'source_sha256' => hash_file('sha256', $filePath),
                    'imported' => $stats['shared_updated'] + $stats['new_created'],
                    'details' => json_encode($stats),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            // 10. Record immutable import version in qa_import_versions
            $qa->table('qa_import_versions')->updateOrInsert(
                ['batch_id' => $batchId],
                [
                    'version_key' => config('imports.active_version', self::APPROVED_VERSION_KEY),
                    'source_file' => $filePath,
                    'sha256' => hash_file('sha256', $filePath),
                    'registration_rows' => $stats['registration_rows'],
                    'collection_rows' => $stats['collections_staged'],
                    'dues_rows' => $stats['dues_staged'],
                    'unresolved_collections' => $stats['unresolved_collections'],
                    'unresolved_dues' => $stats['unresolved_dues'],
                    'exceptions_logged' => count($exceptionsToInsert),
                    'status' => 'imported',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            return $stats;
        });
    }

    /**
     * Assert that QA financial ledger tables were NOT mutated during import.
     */
    public function assertZeroFinancialLedgerPosting(\Illuminate\Database\ConnectionInterface $qa, ?array $ledgerBefore = null): void
    {
        $current = [
            'invoices' => $qa->table('invoices')->count(),
            'invoice_items' => $qa->table('invoice_items')->count(),
            'payments' => $qa->table('payments')->count(),
            'payment_allocations' => $qa->table('payment_allocations')->count(),
            'billing_runs' => $qa->table('billing_runs')->count(),
        ];

        if ($ledgerBefore !== null) {
            foreach ($ledgerBefore as $table => $beforeCount) {
                if ($current[$table] !== $beforeCount) {
                    throw new RuntimeException(
                        "CRITICAL ACCOUNTING BREACH: QA financial ledger table '{$table}' was mutated during import. Before: {$beforeCount}, After: {$current[$table]}. Phase 3C.1 strictly prohibits financial ledger postings."
                    );
                }
            }
        } elseif (array_sum($current) > 0) {
            throw new RuntimeException(
                "CRITICAL ACCOUNTING BREACH: QA financial ledger tables MUST remain at 0 rows. Found: invoices={$current['invoices']}, items={$current['invoice_items']}, payments={$current['payments']}, allocations={$current['payment_allocations']}, runs={$current['billing_runs']}."
            );
        }
    }

    public function validateFileAndHash(string $filePath, ?string $expectedSha256): void
    {
        $normalized = str_replace('\\', '/', $filePath);
        $disallowed = config('imports.disallowed_legacy_sources', []);
        foreach ($disallowed as $legacy) {
            if (str_ends_with($normalized, $legacy) || basename($filePath) === basename($legacy)) {
                $activeKey = config('imports.active_version', self::APPROVED_VERSION_KEY);
                throw new RuntimeException("DISALLOWED SOURCE: Legacy CSV '{$filePath}' is blocked. The active import version is '{$activeKey}'.");
            }
        }

        if (!is_file($filePath)) {
            throw new RuntimeException("Workbook file not found at path: {$filePath}");
        }

        if ($expectedSha256 !== null) {
            $actual = strtolower(hash_file('sha256', $filePath));
            $expected = strtolower($expectedSha256);
            $approved = strtolower(self::APPROVED_WORKBOOK_SHA256);
            $revised = strtolower(self::REVISED_SHA256);
            $legacy = strtolower(self::LEGACY_OCT22_SHA256);
            if ($actual !== $expected && $actual !== $approved && $actual !== $revised && $actual !== $legacy) {
                throw new RuntimeException("SHA-256 mismatch for '{$filePath}'. Expected approved hash {$approved}, found {$actual}.");
            }
        }
    }

    public function validateConnection(string $conn): void
    {
        DatabaseSafetyGuard::assertNotQuarantineDatabase($conn);
        DatabaseSafetyGuard::assertNotStagingDatabase($conn);

        if (config("database.connections.{$conn}.driver") === 'sqlite') {
            return;
        }

        DatabaseSafetyGuard::assertTargetDatabase($conn);
    }

    private function validateQaConnection(string $qaConn): void
    {
        $this->validateConnection($qaConn);
    }

    public static function parseExcelDate(mixed $val): ?string
    {
        if ($val === null || $val === '') {
            return null;
        }

        if (is_numeric($val) && (float) $val > 1000) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $val)->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        $trimmed = trim((string) $val);
        if ($trimmed === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat('!' . $fmt, $trimmed);
            if ($d && $d->format($fmt) === $trimmed) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    public static function parseMoneyDecimal(mixed $val, ?string &$error = null): float
    {
        $error = null;
        if ($val === null || $val === '') {
            return 0.00;
        }
        $raw = trim((string) $val);
        $cleaned = str_replace([',', ' '], '', $raw);
        if (!is_numeric($cleaned)) {
            $error = "Non-numeric monetary string '{$raw}'";
            return 0.00;
        }
        return round((float) $cleaned, 2);
    }

    public static function parseMoneyMinor(mixed $val, ?string &$error = null): int
    {
        $decimal = self::parseMoneyDecimal($val, $error);
        if ($error !== null) {
            return 0;
        }
        return (int) round($decimal * 100);
    }

    public static function sanitizePhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }
        $cleaned = preg_replace('/[^\d+]/', '', trim($phone));
        return $cleaned !== '' ? $cleaned : null;
    }
}
