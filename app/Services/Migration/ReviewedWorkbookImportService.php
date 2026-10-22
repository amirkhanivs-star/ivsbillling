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
    public const EXPECTED_SHA256 = 'd2276cf0c75a0c17b143d1815fda568c9b5cc7e0154f546b8075d1a98e225401';
    public const REVISED_SHA256 = 'f48f1ee57eb826776c2f1eb7cab0150640605d4b30f1c761044f7d0d12d0468e';
    public const SHEET_REGISTRATION = '01_REGISTRATION_PRESENT (1)';
    public const SHEET_COLLECTION = '03_COLLECTION_PRESENT_ONLY';
    public const SHEET_OUTSTANDING = '05_OUTSTANDING_PRESENT_ONLY';

    /**
     * Preview the workbook reconciliation and metrics without modifying the database.
     */
    public function preview(
        string $filePath,
        ?string $expectedSha256 = self::EXPECTED_SHA256,
        ?string $qaConn = null
    ): array {
        @ini_set('memory_limit', '1024M');
        $qaConn = $qaConn ?: (config('database.default') === 'sqlite' ? 'sqlite' : 'qa');
        $this->validateFileAndHash($filePath, $expectedSha256);
        $this->validateQaConnection($qaConn);

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);

        // 1. Sheet 1: Registration
        $reader->setLoadSheetsOnly([self::SHEET_REGISTRATION]);
        $sp = $reader->load($filePath);
        $sheetReg = $sp->getSheetByName(self::SHEET_REGISTRATION);
        $regData = $sheetReg->toArray(null, false, false, false);
        $sp->disconnectWorksheets();
        unset($sp, $sheetReg);
        gc_collect_cycles();

        array_shift($regData); // Header

        $regGrs = [];
        $provCounts = [];
        $currCounts = [];
        $blankCurrencyCount = 0;
        $deptCounts = ['school' => 0, 'tuition' => 0];
        $approvedCount = 0;
        $heldCount = 0;

        $mutuallyExclusive = [
            'active_billable' => 0,
            'active_zero_fee' => 0,
            'leaving_date_discrepancy' => 0,
            'review_hold_review' => 0,
            'review_hold_yes' => 0,
        ];

        foreach ($regData as $idx => $row) {
            $r = $idx + 2;
            $gr = trim((string) ($row[1] ?? ''));
            if ($gr === '') {
                continue;
            }

            $curr = trim((string) ($row[21] ?? ''));
            $prov = trim((string) ($row[23] ?? ''));
            $class = trim((string) ($row[11] ?? ''));
            $fee = self::parseMoneyDecimal($row[13] ?? 0);
            $leavingDate = trim((string) ($row[17] ?? ''));
            $hasLeaving = ($leavingDate !== '');

            $regGrs[$gr] = [
                'row' => $r,
                'currency' => $curr,
                'provisional' => $prov,
                'class' => $class,
            ];

            $provCounts[$prov] = ($provCounts[$prov] ?? 0) + 1;
            $currCounts[$curr] = ($currCounts[$curr] ?? 0) + 1;
            if ($curr === '') {
                $blankCurrencyCount++;
            }

            if ($prov === 'NO' && $curr !== '') {
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

        // Reconcile with existing QA students
        $qa = DB::connection($qaConn);
        $existingGrs = $qa->table('students')->pluck('gr_number')->all();
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

        // 2. Sheet 2: Collection
        $reader->setLoadSheetsOnly([self::SHEET_COLLECTION]);
        $sp = $reader->load($filePath);
        $sheetCol = $sp->getSheetByName(self::SHEET_COLLECTION);
        $colData = $sheetCol->toArray(null, false, false, false);
        $sp->disconnectWorksheets();
        unset($sp, $sheetCol);
        gc_collect_cycles();

        array_shift($colData); // header
        $colRowsCount = count($colData);

        $colUnresolved = 0;
        $colCurrencyTotals = [];
        foreach ($colData as $row) {
            $curr = trim((string) ($row[10] ?? ''));
            $flags = trim((string) ($row[19] ?? ''));
            $amt = (float) ($row[12] ?? 0);

            $colCurrencyTotals[$curr] = ($colCurrencyTotals[$curr] ?? 0) + $amt;
            if ($curr === '' || $curr === 'UNRESOLVED' || str_contains($flags, 'UNRESOLVED')) {
                $colUnresolved++;
            }
        }
        unset($colData);
        gc_collect_cycles();

        // 3. Sheet 3: Outstanding
        $reader->setLoadSheetsOnly([self::SHEET_OUTSTANDING]);
        $sp = $reader->load($filePath);
        $sheetDue = $sp->getSheetByName(self::SHEET_OUTSTANDING);
        $dueData = $sheetDue->toArray(null, false, false, false);
        $sp->disconnectWorksheets();
        unset($sp, $sheetDue);
        gc_collect_cycles();

        array_shift($dueData); // header
        $dueRowsCount = count($dueData);

        $dueUnresolved = 0;
        $dueCurrencyTotals = [];
        foreach ($dueData as $row) {
            $curr = trim((string) ($row[9] ?? ''));
            $flags = trim((string) ($row[17] ?? ''));
            $amt = (float) ($row[11] ?? 0);

            $dueCurrencyTotals[$curr] = ($dueCurrencyTotals[$curr] ?? 0) + $amt;
            if ($curr === '' || $curr === 'UNRESOLVED' || str_contains($flags, 'UNRESOLVED')) {
                $dueUnresolved++;
            }
        }
        unset($dueData);
        gc_collect_cycles();

        return [
            'file_path' => $filePath,
            'sha256' => hash_file('sha256', $filePath),
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
            ],
            'collection' => [
                'total_rows' => $colRowsCount,
                'unresolved_currency_rows' => $colUnresolved,
                'totals_by_currency' => $colCurrencyTotals,
            ],
            'outstanding' => [
                'total_rows' => $dueRowsCount,
                'unresolved_currency_rows' => $dueUnresolved,
                'totals_by_currency' => $dueCurrencyTotals,
            ],
        ];
    }

    /**
     * Import the reviewed workbook into the QA database.
     */
    public function import(
        string $filePath,
        ?string $expectedSha256 = self::EXPECTED_SHA256,
        ?string $qaConn = null,
        ?string $batchId = null
    ): array {
        @ini_set('memory_limit', '1024M');
        gc_collect_cycles();
        $qaConn = $qaConn ?: (config('database.default') === 'sqlite' ? 'sqlite' : 'qa');
        $this->validateFileAndHash($filePath, $expectedSha256);
        $this->validateQaConnection($qaConn);

        $batchId = $batchId ?: 'BATCH-20261022-PORTAL-V2';
        $now = now();
        $qa = DB::connection($qaConn);

        return $qa->transaction(function () use ($filePath, $qa, $batchId, $now) {
            @ini_set('memory_limit', '1024M');

            // Capture initial financial ledger baseline to guarantee zero ledger mutation
            $ledgerBefore = [
                'invoices' => $qa->table('invoices')->count(),
                'invoice_items' => $qa->table('invoice_items')->count(),
                'payments' => $qa->table('payments')->count(),
                'payment_allocations' => $qa->table('payment_allocations')->count(),
                'billing_runs' => $qa->table('billing_runs')->count(),
            ];

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

            // 3. Process Sheet 1: Registration
            $reader->setLoadSheetsOnly([self::SHEET_REGISTRATION]);
            $sp = $reader->load($filePath);
            $sheetReg = $sp->getSheetByName(self::SHEET_REGISTRATION);
            $regData = $sheetReg->toArray(null, false, false, false);
            $sp->disconnectWorksheets();
            unset($sp);

            $regHeaders = array_shift($regData); // Remove header row

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
                $rawId = trim((string) ($row[0] ?? ''));
                $gr = trim((string) ($row[1] ?? ''));
                if ($gr === '') {
                    continue;
                }

                $seenWorkbookGrs[$gr] = true;

                $famCode = trim((string) ($row[2] ?? ''));
                $isProvisionalFamily = false;
                if ($famCode === '') {
                    $famCode = 'PROV-FAM-' . $gr;
                    $isProvisionalFamily = true;
                }

                $name = trim((string) ($row[3] ?? ''));
                $father = trim((string) ($row[4] ?? ''));
                $gender = trim((string) ($row[5] ?? ''));
                $religion = trim((string) ($row[7] ?? ''));
                $contactFather = trim((string) ($row[8] ?? ''));
                $contactMother = trim((string) ($row[9] ?? ''));
                $admissionDateRaw = $row[10] ?? null;
                $parsedAdmissionDate = self::parseExcelDate($admissionDateRaw);
                $className = trim((string) ($row[11] ?? 'General'));
                $classAdmitted = trim((string) ($row[12] ?? ''));
                $rawFee = trim((string) ($row[13] ?? '0'));
                $statusRaw = trim((string) ($row[14] ?? 'present'));
                $guardian = trim((string) ($row[15] ?? ''));
                $currencyRaw = strtoupper(trim((string) ($row[21] ?? '')));
                $currencyEvidence = trim((string) ($row[22] ?? ''));
                $currencyProvisional = strtoupper(trim((string) ($row[23] ?? 'NO')));
                $paidPdfCurrencies = trim((string) ($row[24] ?? ''));
                $outstandingPdfCurrencies = trim((string) ($row[25] ?? ''));
                $conflictFlags = trim((string) ($row[26] ?? ''));

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
                    'source_workbook' => 'UPdated school record for portal.xlsx',
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
                        'legacy_myskool_id' => $rawId ?: ('XLSX:V2:' . $gr),
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
                if ($isApprovedCurrency && $enrollmentFeeMinor > 0) {
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

            // 4. Reconcile 18 Absent Records (Preserve, Flag for Historical Review)
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
            $reader->setLoadSheetsOnly([self::SHEET_COLLECTION]);
            $sp = $reader->load($filePath);
            $sheetCol = $sp->getSheetByName(self::SHEET_COLLECTION);
            $colData = $sheetCol->toArray(null, false, false, false);
            $sp->disconnectWorksheets();
            unset($sp, $sheetCol);
            gc_collect_cycles();

            array_shift($colData); // remove header

            $colRowsToInsert = [];
            $exceptionsToInsert = [];

            foreach ($colData as $idx => $r) {
                $sourceRow = $idx + 2;
                $slip = trim((string) ($r[0] ?? ''));
                $gr = trim((string) ($r[1] ?? ''));
                $stuName = trim((string) ($r[2] ?? ''));
                $regName = trim((string) ($r[3] ?? ''));
                $famCode = trim((string) ($r[4] ?? ''));
                $presentAt22 = trim((string) ($r[5] ?? ''));
                $particular = trim((string) ($r[6] ?? ''));
                $payDateRaw = $r[7] ?? null;
                $payDateParsed = self::parseExcelDate($payDateRaw);
                $payMethod = trim((string) ($r[9] ?? ''));
                $curr = strtoupper(trim((string) ($r[10] ?? '')));
                $currOriginal = trim((string) ($r[11] ?? ''));
                $amtErr = null;
                $amtDecimal = self::parseMoneyDecimal($r[12] ?? 0, $amtErr);
                $amtMinor = self::parseMoneyMinor($r[12] ?? 0, $amtErr);
                $regCurr = strtoupper(trim((string) ($r[13] ?? '')));
                $currCompare = trim((string) ($r[14] ?? ''));
                $pdfPage = trim((string) ($r[15] ?? ''));
                $pdfY = trim((string) ($r[16] ?? ''));
                $grnOriginal = trim((string) ($r[17] ?? ''));
                $nameOriginal = trim((string) ($r[18] ?? ''));
                $reviewFlags = trim((string) ($r[19] ?? ''));

                $isUnresolved = ($curr === '' || $curr === 'UNRESOLVED' || str_contains($reviewFlags, 'UNRESOLVED'));
                $hasConflict = ($curr !== '' && $regCurr !== '' && $curr !== $regCurr);

                if ($amtErr !== null) {
                    $exceptionsToInsert[] = [
                        'batch_id' => $batchId,
                        'sheet_name' => self::SHEET_COLLECTION,
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
                        'sheet_name' => self::SHEET_COLLECTION,
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
                        'sheet_name' => self::SHEET_COLLECTION,
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
                    'present_at_22oct' => $presentAt22 ?: null,
                    'fee_particular' => $particular ?: null,
                    'payment_date_raw' => (string) $payDateRaw,
                    'payment_date_parsed' => $payDateParsed,
                    'payment_method' => $payMethod ?: null,
                    'currency' => $curr ?: null,
                    'currency_original' => $currOriginal ?: null,
                    'amount' => $amtDecimal,
                    'amount_minor' => $amtMinor,
                    'registration_currency' => $regCurr ?: null,
                    'currency_compare' => $currCompare ?: null,
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
            $reader->setLoadSheetsOnly([self::SHEET_OUTSTANDING]);
            $sp = $reader->load($filePath);
            $sheetDue = $sp->getSheetByName(self::SHEET_OUTSTANDING);
            $dueData = $sheetDue->toArray(null, false, false, false);
            $sp->disconnectWorksheets();
            unset($sp);

            array_shift($dueData); // remove header

            $dueRowsToInsert = [];

            foreach ($dueData as $idx => $r) {
                $sourceRow = $idx + 2;
                $gr = trim((string) ($r[0] ?? ''));
                $stuName = trim((string) ($r[1] ?? ''));
                $regName = trim((string) ($r[2] ?? ''));
                $famFinal = trim((string) ($r[3] ?? ''));
                $famPdf = trim((string) ($r[4] ?? ''));
                $famReg = trim((string) ($r[5] ?? ''));
                $presentAt22 = trim((string) ($r[6] ?? ''));
                $classDues = trim((string) ($r[7] ?? ''));
                $particular = trim((string) ($r[8] ?? ''));
                $curr = strtoupper(trim((string) ($r[9] ?? '')));
                $currOriginal = trim((string) ($r[10] ?? ''));
                $dueAmtErr = null;
                $amtDecimal = self::parseMoneyDecimal($r[11] ?? 0, $dueAmtErr);
                $amtMinor = self::parseMoneyMinor($r[11] ?? 0, $dueAmtErr);
                $regCurr = strtoupper(trim((string) ($r[12] ?? '')));
                $pdfPage = trim((string) ($r[13] ?? ''));
                $pdfY = trim((string) ($r[14] ?? ''));
                $grnOriginal = trim((string) ($r[15] ?? ''));
                $nameOriginal = trim((string) ($r[16] ?? ''));
                $reviewFlags = trim((string) ($r[17] ?? ''));

                $isUnresolved = ($curr === '' || $curr === 'UNRESOLVED' || str_contains($reviewFlags, 'UNRESOLVED'));
                $hasConflict = ($curr !== '' && $regCurr !== '' && $curr !== $regCurr);

                if ($dueAmtErr !== null) {
                    $exceptionsToInsert[] = [
                        'batch_id' => $batchId,
                        'sheet_name' => self::SHEET_OUTSTANDING,
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
                        'sheet_name' => self::SHEET_OUTSTANDING,
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
                        'sheet_name' => self::SHEET_OUTSTANDING,
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
                    'present_at_22oct' => $presentAt22 ?: null,
                    'class_in_dues_report' => $classDues ?: null,
                    'fee_particular' => $particular ?: null,
                    'currency' => $curr ?: null,
                    'currency_original' => $currOriginal ?: null,
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
                    'version_key' => config('imports.active_version', 'v2_reviewed_20261022'),
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

    private function validateFileAndHash(string $filePath, ?string $expectedSha256): void
    {
        $normalized = str_replace('\\', '/', $filePath);
        $disallowed = config('imports.disallowed_legacy_sources', []);
        foreach ($disallowed as $legacy) {
            if (str_ends_with($normalized, $legacy) || basename($filePath) === basename($legacy)) {
                $activeKey = config('imports.active_version', 'v2_reviewed_20261022');
                throw new RuntimeException("DISALLOWED SOURCE: Legacy CSV '{$filePath}' is blocked. The active import version is '{$activeKey}' (storage/app/imports/UPdated school record for portal.xlsx).");
            }
        }

        if (!is_file($filePath)) {
            throw new RuntimeException("Workbook file not found at path: {$filePath}");
        }

        if ($expectedSha256 !== null) {
            $actual = strtolower(hash_file('sha256', $filePath));
            $expected = strtolower($expectedSha256);
            $revised = strtolower(self::REVISED_SHA256);
            if ($actual !== $expected && $actual !== $revised) {
                throw new RuntimeException("SHA-256 mismatch for '{$filePath}'. Expected {$expected} (or revised {$revised}), found {$actual}.");
            }
        }
    }

    private function validateQaConnection(string $qaConn): void
    {
        if (config("database.connections.{$qaConn}.driver") === 'sqlite') {
            return;
        }

        DatabaseSafetyGuard::assertQaDatabase($qaConn);
        DatabaseSafetyGuard::assertNotQuarantineDatabase($qaConn);
        DatabaseSafetyGuard::assertNotStagingDatabase($qaConn);
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
