<?php

namespace App\Services\Migration;

use App\Exceptions\DatabaseSafetyException;
use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HistoricalOpeningBalanceService
{
    /**
     * Preview the historical opening balances without modifying the financial ledger.
     */
    public function preview(?string $conn = null, ?string $batchId = null): array
    {
        $conn = $conn ?: config('database.default', 'mysql');
        $this->validateQaConnection($conn);
        $db = DB::connection($conn);

        $batchId = $batchId ?: (string) config('imports.active_batch_id', 'BATCH-20261023-FINAL-MYSKOOL');

        // Resolve dynamic department IDs (never hard-code IDs)
        $deptCodes = $db->table('departments')->pluck('id', 'code')->all();
        $schoolDeptId = (int) ($deptCodes['school'] ?? 1);
        $tuitionDeptId = (int) ($deptCodes['tuition'] ?? 3);
        $quranDeptId = (int) ($deptCodes['quran'] ?? 2);

        // 1. Fetch staged dues for the specified batch
        $dues = $db->table('qa_staged_dues')
            ->where('batch_id', $batchId)
            ->orderBy('source_row')
            ->get();

        if ($dues->isEmpty()) {
            return [
                'status' => 'empty',
                'batch_id' => $batchId,
                'summary' => [
                    'total_staged_dues' => 0,
                    'eligible_rows' => 0,
                    'excluded_rows_count' => 0,
                    'invoices_to_create' => 0,
                ],
                'totals_by_currency' => [],
                'totals_by_department' => [],
                'exclusions' => [
                    'unresolved_currency_count' => 0,
                    'review_hold_student_count' => 0,
                    'historical_hold_student_count' => 0,
                    'missing_student_count' => 0,
                    'invalid_amount_count' => 0,
                    'currency_mismatch_count' => 0,
                    'unknown_period_count' => 0,
                    'ambiguous_department_count' => 0,
                    'unmatched_enrollment_count' => 0,
                    'tuition_policy_hold_count' => 0,
                    'urdu_subject_policy_hold_count' => 0,
                    'samples' => [],
                ],
                'sample_invoices' => [],
                'safety_status' => [
                    'ledger_posting_blocked' => true,
                    'posting_authorization_required' => true,
                    'message' => "SAFEGUARD ACTIVE: Opening balance ledger posting is strictly stopped. No staged dues found for batch '{$batchId}'.",
                ],
            ];
        }

        // 2. Preload active QA students and enrollments
        $students = $db->table('students')->get()->keyBy('gr_number');
        $enrollments = $db->table('enrollments')->get()->groupBy('student_id');
        [$studentCollectionCurrencies, $familyEnrollmentCurrencies] = self::preloadCurrencyVerificationMaps($db, $batchId, $students, $enrollments);

        $eligibleRows = [];
        $excludedRows = [
            'invalid_amount' => [],
            'unresolved_currency' => [],
            'missing_student' => [],
            'review_hold_student' => [],
            'historical_hold_student' => [],
            'currency_mismatch' => [],
            'unknown_period' => [],
            'ambiguous_department' => [],
            'unmatched_enrollment' => [],
            'tuition_policy_hold' => [],
            'urdu_subject_policy_hold' => [],
        ];

        $totalsByCurrency = [];
        $totalsByDepartment = [];
        $invoicesGrouped = [];

        foreach ($dues as $due) {
            $amtMinor = (int) $due->outstanding_amount_minor;
            $curr = strtoupper(trim((string) $due->currency));
            $p = trim((string) $due->fee_particular);
            $cls = trim((string) $due->class_in_dues_report);

            // Check 1: Invalid Amount
            if ($amtMinor <= 0) {
                $excludedRows['invalid_amount'][] = [
                    'source_row' => $due->source_row,
                    'gr_number' => $due->gr_number,
                    'reason' => 'Zero or negative amount',
                    'amount_minor' => $amtMinor,
                ];
                continue;
            }

            // Check 2: Unresolved Currency
            if ($due->is_currency_unresolved || $curr === '' || $curr === 'UNRESOLVED') {
                $excludedRows['unresolved_currency'][] = [
                    'source_row' => $due->source_row,
                    'gr_number' => $due->gr_number,
                    'reason' => 'Unresolved currency label',
                    'amount_minor' => $amtMinor,
                    'raw_currency' => $due->currency_original,
                ];
                continue;
            }

            // Check 3: Student Existence
            $student = $students->get($due->gr_number);
            if (!$student) {
                $excludedRows['missing_student'][] = [
                    'source_row' => $due->source_row,
                    'gr_number' => $due->gr_number,
                    'reason' => 'GR number not found in QA students table',
                    'amount_minor' => $amtMinor,
                ];
                continue;
            }

            // Check 4: Review Hold (Unapproved Currency or Identity)
            if ($student->status === 'review_hold') {
                $excludedRows['review_hold_student'][] = [
                    'source_row' => $due->source_row,
                    'gr_number' => $due->gr_number,
                    'reason' => 'Student is on financial review hold (requires explicit currency/identity approval)',
                    'amount_minor' => $amtMinor,
                    'currency' => $curr,
                ];
                continue;
            }

            // Check 5: Historical Hold (Old records absent from reviewed workbook)
            if ($student->status === 'historical_hold') {
                $excludedRows['historical_hold_student'][] = [
                    'source_row' => $due->source_row,
                    'gr_number' => $due->gr_number,
                    'reason' => 'Student is on historical hold',
                    'amount_minor' => $amtMinor,
                    'currency' => $curr,
                ];
                continue;
            }

            // Check 6: Parse Period (Do not invent September 2026; hold unknown periods)
            $period = self::parseParticularPeriod($p);
            if (!$period) {
                $excludedRows['unknown_period'][] = [
                    'source_row' => $due->source_row,
                    'gr_number' => $due->gr_number,
                    'particular' => $p,
                    'class' => $cls,
                    'reason' => 'Fee particular does not contain a verified calendar month and 4-digit year',
                    'amount_minor' => $amtMinor,
                    'currency' => $curr,
                ];
                continue;
            }

            // Check 7 & 8: Department Determination & Enrollment Match
            $studentEnrollments = $enrollments->get($student->id, collect());
            $resolved = self::resolveTargetEnrollment($student, $studentEnrollments, $cls, $p, $schoolDeptId, $tuitionDeptId);

            if ($resolved['status'] === 'no_enrollment' || $resolved['status'] === 'conflict') {
                $excludedRows['unmatched_enrollment'][] = [
                    'source_row' => $due->source_row,
                    'gr_number' => $due->gr_number,
                    'class' => $cls,
                    'particular' => $p,
                    'reason' => $resolved['reason'],
                    'amount_minor' => $amtMinor,
                ];
                continue;
            }

            if ($resolved['status'] === 'ambiguous') {
                $excludedRows['ambiguous_department'][] = [
                    'source_row' => $due->source_row,
                    'gr_number' => $due->gr_number,
                    'class' => $cls,
                    'particular' => $p,
                    'reason' => $resolved['reason'],
                    'amount_minor' => $amtMinor,
                ];
                continue;
            }

            $matchedEnrollment = $resolved['enrollment'];

            // Check 9: Currency Conflict between Enrollment and Dues
            if (!self::isVerifiedCurrency($curr, $matchedEnrollment, $student, $studentEnrollments, $studentCollectionCurrencies, $familyEnrollmentCurrencies)) {
                $excludedRows['currency_mismatch'][] = [
                    'source_row' => $due->source_row,
                    'gr_number' => $due->gr_number,
                    'due_currency' => $curr,
                    'enrollment_currency' => $matchedEnrollment->currency,
                    'reason' => "Dues currency '{$curr}' conflicts with student enrollment currency '{$matchedEnrollment->currency}'",
                    'amount_minor' => $amtMinor,
                ];
                continue;
            }

            // Grouping: family_id | department_id | campus_id | currency | period_key
            $deptId = (int) $matchedEnrollment->department_id;
            $campusId = (int) $matchedEnrollment->campus_id;
            $familyId = (int) $student->family_id;

            if ($deptId <= 0 || $campusId <= 0 || $familyId <= 0) {
                $excludedRows['ambiguous_department'][] = [
                    'source_row' => $due->source_row,
                    'gr_number' => $due->gr_number,
                    'reason' => 'Missing valid family, campus or department association on enrollment',
                    'amount_minor' => $amtMinor,
                ];
                continue;
            }

            // Check 10: Policy Hold for Tuition Department or School Urdu Subject Fee (retained in staging pending owner decision)
            $policyHold = self::isHeldPendingPolicyDecision($batchId, $deptId, $tuitionDeptId, $p);
            if ($policyHold !== null) {
                $excludedRows[$policyHold['category']][] = [
                    'source_row' => $due->source_row,
                    'gr_number' => $due->gr_number,
                    'class' => $cls,
                    'particular' => $p,
                    'currency' => $curr,
                    'reason' => $policyHold['reason'],
                    'amount_minor' => $amtMinor,
                ];
                continue;
            }

            $groupKey = "{$familyId}|{$deptId}|{$campusId}|{$curr}|{$period['period_key']}";

            if (!isset($invoicesGrouped[$groupKey])) {
                $invoicesGrouped[$groupKey] = [
                    'family_id' => $familyId,
                    'department_id' => $deptId,
                    'campus_id' => $campusId,
                    'currency' => $curr,
                    'period_key' => $period['period_key'],
                    'period_start' => $period['period_start'],
                    'period_end' => $period['period_end'],
                    'due_date' => $period['due_date'],
                    'total_minor' => 0,
                    'items_count' => 0,
                    'items' => [],
                ];
            }

            $invoicesGrouped[$groupKey]['total_minor'] += $amtMinor;
            $invoicesGrouped[$groupKey]['items_count']++;
            $invoicesGrouped[$groupKey]['items'][] = [
                'source_staged_due_id' => $due->id,
                'source_row' => $due->source_row,
                'student_id' => $student->id,
                'enrollment_id' => $matchedEnrollment->id,
                'gr_number' => $due->gr_number,
                'fee_particular' => $p,
                'currency' => $curr,
                'amount_minor' => $amtMinor,
            ];

            // Aggregations
            $totalsByCurrency[$curr] = ($totalsByCurrency[$curr] ?? 0) + $amtMinor;
            $deptName = $deptId === $tuitionDeptId ? 'Tuition' : ($deptId === $quranDeptId ? 'Quran' : 'School');
            if (!isset($totalsByDepartment[$deptName])) {
                $totalsByDepartment[$deptName] = [];
            }
            $totalsByDepartment[$deptName][$curr] = ($totalsByDepartment[$deptName][$curr] ?? 0) + $amtMinor;

            $eligibleRows[] = $due->id;
        }

        // Format Currency Totals with Decimals
        $formattedCurrencyTotals = [];
        foreach ($totalsByCurrency as $c => $minor) {
            $formattedCurrencyTotals[$c] = [
                'currency' => $c,
                'lines' => 0,
                'invoices_count' => 0,
                'total_minor' => $minor,
                'total_decimal' => number_format($minor / 100, 2, '.', ''),
            ];
        }
        foreach ($invoicesGrouped as $inv) {
            $formattedCurrencyTotals[$inv['currency']]['lines'] += $inv['items_count'];
            $formattedCurrencyTotals[$inv['currency']]['invoices_count']++;
        }

        // Format Department Totals
        $formattedDeptTotals = [];
        foreach ($totalsByDepartment as $dept => $currs) {
            foreach ($currs as $c => $minor) {
                $formattedDeptTotals[$dept][$c] = [
                    'currency' => $c,
                    'total_minor' => $minor,
                    'total_decimal' => number_format($minor / 100, 2, '.', ''),
                ];
            }
        }

        $totalExcludedCount = array_sum(array_map('count', $excludedRows));

        $alreadyPostedCount = 0;
        if ($db->getSchemaBuilder()->hasTable('qa_opening_balance_lineage')) {
            $alreadyPostedCount = $db->table('qa_opening_balance_lineage')
                ->where('batch_id', $batchId)
                ->count();
        }

        return [
            'status' => 'success',
            'batch_id' => $batchId,
            'summary' => [
                'total_staged_dues' => count($dues),
                'eligible_rows' => count($eligibleRows),
                'already_posted_lines' => $alreadyPostedCount,
                'remaining_eligible_lines' => max(0, count($eligibleRows) - $alreadyPostedCount),
                'excluded_rows_count' => $totalExcludedCount,
                'invoices_to_create' => count($invoicesGrouped),
            ],
            'totals_by_currency' => $formattedCurrencyTotals,
            'totals_by_department' => $formattedDeptTotals,
            'exclusions' => [
                'unresolved_currency_count' => count($excludedRows['unresolved_currency']),
                'review_hold_student_count' => count($excludedRows['review_hold_student']),
                'historical_hold_student_count' => count($excludedRows['historical_hold_student']),
                'missing_student_count' => count($excludedRows['missing_student']),
                'invalid_amount_count' => count($excludedRows['invalid_amount']),
                'currency_mismatch_count' => count($excludedRows['currency_mismatch']),
                'unknown_period_count' => count($excludedRows['unknown_period']),
                'ambiguous_department_count' => count($excludedRows['ambiguous_department']),
                'unmatched_enrollment_count' => count($excludedRows['unmatched_enrollment']),
                'tuition_policy_hold_count' => count($excludedRows['tuition_policy_hold']),
                'urdu_subject_policy_hold_count' => count($excludedRows['urdu_subject_policy_hold']),
                'samples' => array_map(fn($arr) => array_slice($arr, 0, 5), $excludedRows),
            ],
            'sample_invoices' => array_slice(array_values($invoicesGrouped), 0, 5),
            'safety_status' => [
                'ledger_posting_blocked' => true,
                'posting_authorization_required' => true,
                'message' => 'SAFEGUARD ACTIVE: Opening balance ledger posting is strictly stopped pending administrative verification and explicit authorization.',
            ],
        ];
    }

    /**
     * Resolve target enrollment and department for a staged due row.
     * Uses student verified enrollment(s), department, fee particular, and source class evidence.
     *
     * @param object $student
     * @param \Illuminate\Support\Collection $studentEnrollments
     * @param string $class
     * @param string $particular
     * @param int $schoolDeptId
     * @param int $tuitionDeptId
     * @return array ['status' => 'matched'|'ambiguous'|'conflict'|'no_enrollment', 'enrollment' => ?object, 'reason' => string]
     */
    public static function resolveTargetEnrollment(
        object $student,
        $studentEnrollments,
        string $class,
        string $particular,
        int $schoolDeptId = 1,
        int $tuitionDeptId = 3
    ): array {
        $clsLower = strtolower(trim($class));
        $partLower = strtolower(trim($particular));

        if ($studentEnrollments->isEmpty()) {
            return [
                'status' => 'no_enrollment',
                'enrollment' => null,
                'reason' => 'Student has no active enrollment in any department',
            ];
        }

        // Single verified enrollment: use it unless source evidence explicitly contradicts it
        if ($studentEnrollments->count() === 1) {
            $singleEnr = $studentEnrollments->first();
            $enrDeptId = (int) $singleEnr->department_id;

            // Enrolled in School, but source class or particular explicitly specifies Tuition
            if ($enrDeptId === $schoolDeptId && (str_contains($clsLower, 'tuition') || str_contains($clsLower, '1-1') || str_contains($partLower, 'tuition fee') || str_contains($partLower, 'tution fee'))) {
                return [
                    'status' => 'conflict',
                    'enrollment' => null,
                    'reason' => 'Source evidence indicates Tuition department but student is enrolled in School',
                ];
            }

            // Enrolled in Tuition, but source class explicitly names a non-tuition school class
            if ($enrDeptId === $tuitionDeptId && $clsLower !== '' && !str_contains($clsLower, 'tuition') && !str_contains($clsLower, '1-1')) {
                return [
                    'status' => 'conflict',
                    'enrollment' => null,
                    'reason' => "Source class '{$class}' indicates School department but student is enrolled in Tuition",
                ];
            }

            return [
                'status' => 'matched',
                'enrollment' => $singleEnr,
                'reason' => 'Consistent single enrollment',
            ];
        }

        // Multiple enrollments: disambiguate using explicit evidence
        $isTuitionEvidence = str_contains($clsLower, 'tuition') || str_contains($clsLower, '1-1') || str_contains($partLower, 'tuition fee') || str_contains($partLower, 'tution fee');
        $isSchoolEvidence = ($clsLower !== '' && !str_contains($clsLower, 'tuition') && !str_contains($clsLower, '1-1'));

        if ($isTuitionEvidence && !$isSchoolEvidence) {
            $tuitionEnrs = $studentEnrollments->filter(fn($e) => (int) $e->department_id === $tuitionDeptId);
            if ($tuitionEnrs->count() === 1) {
                return [
                    'status' => 'matched',
                    'enrollment' => $tuitionEnrs->first(),
                    'reason' => 'Disambiguated to Tuition based on source evidence',
                ];
            }
        }

        if ($isSchoolEvidence && !$isTuitionEvidence) {
            $schoolEnrs = $studentEnrollments->filter(fn($e) => (int) $e->department_id === $schoolDeptId);
            if ($schoolEnrs->count() === 1) {
                return [
                    'status' => 'matched',
                    'enrollment' => $schoolEnrs->first(),
                    'reason' => 'Disambiguated to School based on source evidence',
                ];
            }
        }

        return [
            'status' => 'ambiguous',
            'enrollment' => null,
            'reason' => 'Student has multiple active enrollments and fee evidence is ambiguous',
        ];
    }

    /**
     * Determine target department ID from source class and particular evidence.
     * Dynamic department IDs (defaults: School = 1, Tuition = 2).
     * Returns null if ambiguous.
     */
    public static function determineDepartmentId(string $class, string $particular, int $schoolId = 1, int $tuitionId = 2): ?int
    {
        $clsLower = strtolower(trim($class));
        $partLower = strtolower(trim($particular));

        // 1. Explicit Tuition Class
        if (str_contains($clsLower, 'tuition') || str_contains($clsLower, '1-1')) {
            return $tuitionId;
        }

        // 2. Explicit Tuition Particular
        if (str_contains($partLower, 'tuition fee') || str_contains($partLower, 'tution fee')) {
            return $tuitionId;
        }

        // 3. School Classes (e.g. 1 - A, Grade 1, FS-1, 8th IGCSE, 9 IGCSE, Federal)
        if ($clsLower !== '' && !str_contains($clsLower, 'tuition')) {
            return $schoolId;
        }

        // 4. Monthly Fee without tuition indicator
        if (str_contains($partLower, 'monthly fee')) {
            return $schoolId;
        }

        return null;
    }

    /**
     * Parse fee particular string into standard billing period dates.
     * Requires explicit month and 4-digit calendar year (2020..2030).
     * Returns null if unverified or missing (never invents a default date).
     */
    public static function parseParticularPeriod(string $particular): ?array
    {
        $months = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
            'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        ];

        if (preg_match('/(?:Monthly\s*Fee|Tution\s*Fee|Tuition\s*Fee)?\s*[-–]?\s*([A-Za-z]{3})[-–\s]+(202[0-9])/i', $particular, $m)) {
            $monthKey = strtolower(substr($m[1], 0, 3));
            if (!isset($months[$monthKey])) {
                return null;
            }

            $monthNum = $months[$monthKey];
            $year = (int) $m[2];
            $start = sprintf('%04d-%02d-01', $year, $monthNum);
            $end = date('Y-m-t', strtotime($start));
            $due = sprintf('%04d-%02d-25', $year, $monthNum);

            return [
                'period_key' => "monthly:{$start}..{$end}",
                'period_start' => $start,
                'period_end' => $end,
                'due_date' => $due,
            ];
        }

        // Never invent a default period — hold unknown periods for review
        return null;
    }

    /**
     * Guarded transactional posting execution.
     * Enforces source-line idempotency, physical database safety, and strict authorization locks.
     */
    public function postOpeningBalances(?string $conn = null, ?string $batchId = null, bool $allowTestExecution = false): array
    {
        $conn = $conn ?: config('database.default', 'mysql');
        $this->validateQaConnection($conn);

        // Security Lock: Opening balance posting requires explicit administrative authorization
        // An automated test flag is only respected in sqlite / testing environments
        $isTestEnv = app()->environment('testing') || (config("database.connections.{$conn}.driver") === 'sqlite');
        $isAuthorized = (bool) config('imports.allow_opening_balance_posting', false);

        if (!$isAuthorized && !($allowTestExecution && $isTestEnv)) {
            throw new RuntimeException(
                'CRITICAL SAFETY STOP: Opening balance ledger posting is STRICTLY BLOCKED. ' .
                'Financial-posting authorization lock is active. Explicit administrative approval (ALLOW_OPENING_BALANCE_POSTING=true) is required.'
            );
        }

        // Safeguard 1: Reject ambiguous or missing batch identity
        if ($batchId !== null && trim($batchId) === '') {
            throw new RuntimeException('CRITICAL SAFETY STOP: Source batch ID cannot be empty. Ambiguous source identity is strictly rejected.');
        }

        $activeBatchId = (string) config('imports.active_batch_id', 'BATCH-20261023-FINAL-MYSKOOL');
        $batchId = $batchId ?: $activeBatchId;

        // Safeguard 2: Enforce posting ONLY the explicitly selected approved source batch
        $isSqlite = (config("database.connections.{$conn}.driver") === 'sqlite' || $conn === 'sqlite');
        if (!$isSqlite && $batchId !== $activeBatchId) {
            throw new RuntimeException(
                "CRITICAL SAFETY STOP: Batch '{$batchId}' is not the approved active batch ('{$activeBatchId}'). " .
                "Cross-batch posting is strictly rejected to prevent financial ledger corruption."
            );
        }

        // Reject attempts to post the unapproved original October 22 batch when revised is active
        if ($batchId === 'BATCH-20261022-PORTAL-V2' && $activeBatchId !== 'BATCH-20261022-PORTAL-V2') {
            throw new RuntimeException(
                "CRITICAL SAFETY STOP: Batch '{$batchId}' is an unapproved historical workbook version. " .
                "Only the approved active batch '{$activeBatchId}' may be posted."
            );
        }

        $db = DB::connection($conn);

        // Safeguard 3: Prevent mixing original and revised workbook balances in the financial ledger
        if ($db->getSchemaBuilder()->hasTable('qa_opening_balance_lineage')) {
            $existingBatches = $db->table('qa_opening_balance_lineage')
                ->distinct()
                ->pluck('batch_id')
                ->filter()
                ->all();

            $foreignBatches = array_values(array_filter($existingBatches, fn($b) => $b !== $batchId));
            if (!empty($foreignBatches)) {
                throw new RuntimeException(
                    "CRITICAL SAFETY STOP: Financial ledger already contains opening balance lineage from different batch: " .
                    implode(', ', $foreignBatches) . ". Mixing original and revised workbook balances is strictly forbidden."
                );
            }
        }

        $preview = $this->preview($conn, $batchId);

        if ($preview['status'] === 'empty' || empty($preview['summary']['eligible_rows'])) {
            return [
                'status' => 'empty',
                'message' => 'No eligible opening balance records to post.',
                'posted_invoices' => 0,
                'posted_items' => 0,
            ];
        }

        return $db->transaction(function () use ($db, $conn, $batchId, $preview) {
            $now = now();
            $postedInvoicesCount = 0;
            $postedItemsCount = 0;
            $totalsPostedByCurrency = [];

            // Group invoices from preview
            $grouped = $this->buildInvoiceGroups($db, $batchId, $conn);

            // Fetch dynamic fee types map
            $feeTypesByCode = $db->table('fee_types')->pluck('id', 'code')->all();
            if (empty($feeTypesByCode)) {
                throw new RuntimeException('Cannot post opening balances: fee_types table is empty.');
            }

            // Fetch department codes
            $deptCodes = $db->table('departments')->pluck('code', 'id')->all();

            // Resolve system actor safely without creating backdoor accounts
            $userId = auth()->id()
                ?: $db->table('users')->where('is_active', true)->whereIn('role', ['admin', 'super_admin'])->value('id')
                ?: $db->table('users')->where('is_active', true)->value('id')
                ?: $db->table('users')->value('id');

            if (!$userId) {
                throw new RuntimeException('Cannot post opening balances: No active system user found to record audit ownership.');
            }

            foreach ($grouped as $groupKey => $group) {
                $stagedDueIds = collect($group['items'])->pluck('source_staged_due_id')->all();

                // Fetch any existing lineages for this group's source lines
                $existingLineages = $db->table('qa_opening_balance_lineage')
                    ->whereIn('qa_staged_due_id', $stagedDueIds)
                    ->get()
                    ->keyBy('qa_staged_due_id');

                // Determine unposted items
                $unpostedItems = collect($group['items'])->filter(function ($item) use ($existingLineages) {
                    return !$existingLineages->has($item['source_staged_due_id']);
                })->values();

                if ($unpostedItems->isEmpty()) {
                    // All items in this group were already posted in a prior run
                    continue;
                }

                // Check if an invoice already exists for this group
                $invoiceId = null;
                if ($existingLineages->isNotEmpty()) {
                    $invoiceId = (int) $existingLineages->first()->invoice_id;
                } else {
                    // Check if an opening-balance invoice exists for this family, dept, campus, curr, period
                    $existingInvoiceId = $db->table('invoices as i')
                        ->join('billing_runs as br', 'br.id', '=', 'i.billing_run_id')
                        ->where('i.family_id', $group['family_id'])
                        ->where('i.department_id', $group['department_id'])
                        ->where('i.campus_id', $group['campus_id'])
                        ->where('i.currency', $group['currency'])
                        ->where('br.frequency', 'opening_balance')
                        ->where('br.period_key', $group['period_key'])
                        ->value('i.id');
                    $invoiceId = $existingInvoiceId ? (int) $existingInvoiceId : null;
                }

                if (!$invoiceId) {
                    // Create Billing Run for opening balance if not exists
                    $billingRun = $db->table('billing_runs')
                        ->where('campus_id', $group['campus_id'])
                        ->where('department_id', $group['department_id'])
                        ->where('frequency', 'opening_balance')
                        ->where('period_key', $group['period_key'])
                        ->first();

                    $billingRunId = $billingRun ? (int) $billingRun->id : (int) $db->table('billing_runs')->insertGetId([
                        'campus_id' => $group['campus_id'],
                        'department_id' => $group['department_id'],
                        'frequency' => 'opening_balance',
                        'period_key' => $group['period_key'],
                        'period_start' => $group['period_start'],
                        'period_end' => $group['period_end'],
                        'due_date' => $group['due_date'],
                        'fee_type_ids' => json_encode(array_values($feeTypesByCode)),
                        'status' => 'generated',
                        'created_by' => $userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    // Generate unique opening balance invoice number
                    $deptCode = $deptCodes[$group['department_id']] ?? 'SCH';
                    $prefix = strtoupper(substr($deptCode, 0, 3)) . '-OB';
                    $year = date('Y', strtotime($group['period_start']));
                    $invNumber = sprintf('%s-%s-%07d', $prefix, $year, $billingRunId);

                    $seq = 1;
                    while ($db->table('invoices')->where('invoice_number', $invNumber)->exists()) {
                        $invNumber = sprintf('%s-%s-%07d-%d', $prefix, $year, $billingRunId, $seq++);
                    }

                    $invoiceId = (int) $db->table('invoices')->insertGetId([
                        'billing_run_id' => $billingRunId,
                        'family_id' => $group['family_id'],
                        'campus_id' => $group['campus_id'],
                        'department_id' => $group['department_id'],
                        'currency' => $group['currency'],
                        'invoice_number' => $invNumber,
                        'issued_on' => $group['period_start'],
                        'due_date' => $group['due_date'],
                        'total_minor' => 0,
                        'paid_minor' => 0,
                        'previous_dues_snapshot_minor' => 0,
                        'status' => 'generated',
                        'approved_to_send' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $postedInvoicesCount++;
                }

                // Insert only unposted items
                foreach ($unpostedItems as $item) {
                    $itemFeeTypeId = $this->resolveFeeTypeId($item['fee_particular'], $feeTypesByCode);

                    // Safeguard 4: Underlying financial obligation cross-batch duplicate protection
                    // Check if this student enrollment already has this exact fee obligation represented in the ledger from another batch
                    $conflictingQuery = $db->table('invoice_items as ii')
                        ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
                        ->join('billing_runs as br', 'br.id', '=', 'inv.billing_run_id')
                        ->leftJoin('qa_opening_balance_lineage as obl', 'obl.invoice_item_id', '=', 'ii.id')
                        ->where('br.frequency', 'opening_balance')
                        ->where(function ($q) use ($batchId) {
                            $q->whereNull('obl.id')
                              ->orWhere('obl.batch_id', '!=', $batchId);
                        })
                        ->where('ii.enrollment_id', $item['enrollment_id'])
                        ->where('ii.period_key', $group['period_key'])
                        ->where('ii.fee_type_id', $itemFeeTypeId);

                    if (preg_match('/\((.*?)\)/', $item['fee_particular'], $subMatch)) {
                        $subject = trim($subMatch[1]);
                        $conflictingQuery->where('ii.description', 'LIKE', "%{$subject}%");
                    } else {
                        $conflictingQuery->where('ii.description', trim($item['fee_particular']));
                    }

                    $conflictingObligation = $conflictingQuery
                        ->select('ii.id', 'ii.description', 'obl.batch_id', 'obl.qa_staged_due_id')
                        ->first();

                    if ($conflictingObligation) {
                        $conflictBatch = $conflictingObligation->batch_id ?? 'another batch';
                        $conflictDue = $conflictingObligation->qa_staged_due_id ?? 'another staged row';
                        throw new RuntimeException(
                            "CRITICAL SAFETY STOP: Cross-batch duplicate financial obligation detected for student GR {$item['gr_number']} " .
                            "('{$item['fee_particular']}') for period {$group['period_key']}. " .
                            "This underlying financial obligation is already represented in the ledger by invoice item #{$conflictingObligation->id} " .
                            "(Source Batch: {$conflictBatch}, Staged Due ID: {$conflictDue}). " .
                            "Cross-batch duplicate posting rejected to protect ledger integrity."
                        );
                    }

                    $itemId = (int) $db->table('invoice_items')->insertGetId([
                        'invoice_id' => $invoiceId,
                        'student_id' => $item['student_id'],
                        'enrollment_id' => $item['enrollment_id'],
                        'fee_plan_item_id' => null,
                        'fee_type_id' => $itemFeeTypeId,
                        'description' => $item['fee_particular'],
                        'period_key' => $group['period_key'],
                        'period_start' => $group['period_start'],
                        'period_end' => $group['period_end'],
                        'currency' => $group['currency'],
                        'amount_minor' => $item['amount_minor'],
                        'discount_minor' => 0,
                        'exemption_minor' => 0,
                        'net_minor' => $item['amount_minor'],
                        'paid_minor' => 0,
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $db->table('qa_opening_balance_lineage')->insert([
                        'batch_id' => $batchId,
                        'qa_staged_due_id' => $item['source_staged_due_id'],
                        'source_row' => $item['source_row'],
                        'gr_number' => $item['gr_number'],
                        'invoice_id' => $invoiceId,
                        'invoice_item_id' => $itemId,
                        'family_id' => $group['family_id'],
                        'student_id' => $item['student_id'],
                        'enrollment_id' => $item['enrollment_id'],
                        'currency' => $group['currency'],
                        'amount_minor' => $item['amount_minor'],
                        'period_key' => $group['period_key'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $postedItemsCount++;
                    $totalsPostedByCurrency[$group['currency']] = ($totalsPostedByCurrency[$group['currency']] ?? 0) + $item['amount_minor'];
                }

                // Refresh parent invoice totals accurately from actual attached invoice items
                $invTotals = $db->table('invoice_items')
                    ->where('invoice_id', $invoiceId)
                    ->selectRaw('COALESCE(SUM(net_minor), 0) AS total, COALESCE(SUM(paid_minor), 0) AS paid')
                    ->first();

                $invStatus = ($invTotals->paid >= $invTotals->total && $invTotals->total > 0)
                    ? 'paid'
                    : ($invTotals->paid > 0 ? 'partial' : 'generated');

                $db->table('invoices')->where('id', $invoiceId)->update([
                    'total_minor' => (int) $invTotals->total,
                    'paid_minor' => (int) $invTotals->paid,
                    'status' => $invStatus,
                    'updated_at' => $now,
                ]);
            }

            return [
                'status' => 'success',
                'batch_id' => $batchId,
                'posted_invoices' => $postedInvoicesCount,
                'posted_items' => $postedItemsCount,
                'totals_by_currency' => $totalsPostedByCurrency,
            ];
        });
    }

    /**
     * Helper to group eligible dues into invoices.
     */
    private function buildInvoiceGroups($db, string $batchId, string $conn): array
    {
        $dues = $db->table('qa_staged_dues')
            ->where('batch_id', $batchId)
            ->orderBy('source_row')
            ->get();

        $deptCodes = $db->table('departments')->pluck('id', 'code')->all();
        $schoolDeptId = (int) ($deptCodes['school'] ?? 1);
        $tuitionDeptId = (int) ($deptCodes['tuition'] ?? 3);

        $students = $db->table('students')->get()->keyBy('gr_number');
        $enrollments = $db->table('enrollments')->get()->groupBy('student_id');
        [$studentCollectionCurrencies, $familyEnrollmentCurrencies] = self::preloadCurrencyVerificationMaps($db, $batchId, $students, $enrollments);

        $invoicesGrouped = [];

        foreach ($dues as $due) {
            $amtMinor = (int) $due->outstanding_amount_minor;
            $curr = strtoupper(trim((string) $due->currency));
            $p = trim((string) $due->fee_particular);
            $cls = trim((string) $due->class_in_dues_report);

            if ($amtMinor <= 0 || $due->is_currency_unresolved || $curr === '' || $curr === 'UNRESOLVED') {
                continue;
            }

            $student = $students->get($due->gr_number);
            if (!$student || $student->status === 'review_hold' || $student->status === 'historical_hold') {
                continue;
            }

            $period = self::parseParticularPeriod($p);
            if (!$period) {
                continue;
            }

            $studentEnrollments = $enrollments->get($student->id, collect());
            $resolved = self::resolveTargetEnrollment($student, $studentEnrollments, $cls, $p, $schoolDeptId, $tuitionDeptId);

            if ($resolved['status'] !== 'matched' || !$resolved['enrollment']) {
                continue;
            }

            $matchedEnrollment = $resolved['enrollment'];

            if (!self::isVerifiedCurrency($curr, $matchedEnrollment, $student, $studentEnrollments, $studentCollectionCurrencies, $familyEnrollmentCurrencies)) {
                continue;
            }

            $deptId = (int) $matchedEnrollment->department_id;
            $campusId = (int) $matchedEnrollment->campus_id;
            $familyId = (int) $student->family_id;

            if ($deptId <= 0 || $campusId <= 0 || $familyId <= 0) {
                continue;
            }

            if (self::isHeldPendingPolicyDecision($batchId, $deptId, $tuitionDeptId, $p) !== null) {
                continue;
            }

            $groupKey = "{$familyId}|{$deptId}|{$campusId}|{$curr}|{$period['period_key']}";

            if (!isset($invoicesGrouped[$groupKey])) {
                $invoicesGrouped[$groupKey] = [
                    'family_id' => $familyId,
                    'department_id' => $deptId,
                    'campus_id' => $campusId,
                    'currency' => $curr,
                    'period_key' => $period['period_key'],
                    'period_start' => $period['period_start'],
                    'period_end' => $period['period_end'],
                    'due_date' => $period['due_date'],
                    'total_minor' => 0,
                    'items_count' => 0,
                    'items' => [],
                ];
            }

            $invoicesGrouped[$groupKey]['total_minor'] += $amtMinor;
            $invoicesGrouped[$groupKey]['items_count']++;
            $invoicesGrouped[$groupKey]['items'][] = [
                'source_staged_due_id' => $due->id,
                'source_row' => $due->source_row,
                'student_id' => $student->id,
                'enrollment_id' => $matchedEnrollment->id,
                'gr_number' => $due->gr_number,
                'fee_particular' => $p,
                'currency' => $curr,
                'amount_minor' => $amtMinor,
            ];
        }

        return $invoicesGrouped;
    }

    /**
     * Deterministically map a fee particular string to a canonical fee_types.code value.
     */
    public static function resolveFeeTypeCode(string $particular): string
    {
        $p = strtolower(trim($particular));
        if (str_contains($p, 'admission') || str_contains($p, 'admisson')) {
            return 'admission';
        }
        if (str_contains($p, 'registration')) {
            return 'registration';
        }
        if (str_contains($p, 'books')) {
            return 'books';
        }
        if (str_contains($p, 'slc')) {
            return 'slc_charges';
        }
        if (str_contains($p, 're-exam') || str_contains($p, 're_exam')) {
            return 're_exam';
        }
        if (str_contains($p, 'exam') || str_contains($p, 'federal')) {
            return 'exam';
        }

        return 'monthly';
    }

    /**
     * Normalize a fee type code for historical obligation identity matching.
     */
    public static function normalizeFeeTypeCode(string $feeTypeCode): string
    {
        $code = strtolower(trim($feeTypeCode));
        if ($code === '' || $code === 'tuition') {
            return 'monthly';
        }

        return $code;
    }

    /**
     * Extract a lowercase subject tag (e.g. 'urdu', 'english', 'mathematics') from subject-specific
     * fee particulars while returning '' for standard monthly/tuition fees and one-time fees.
     */
    public static function extractSubjectTag(string $particular): string
    {
        $p = trim($particular);
        if ($p === '' || preg_match('/^(?:monthly\s*(?:school\s*)?fee|tuition\s*fee|tution\s*fee)\b/i', $p)) {
            return '';
        }
        if (self::resolveFeeTypeCode($p) !== 'monthly') {
            return '';
        }
        if (preg_match('/\(([^)]+)\)/', $p, $m)) {
            return strtolower(trim($m[1]));
        }

        return '';
    }

    /**
     * Normalize an explicit subject name or particular into a canonical subject_tag.
     */
    public static function normalizeSubjectTag(?string $subjectTag): string
    {
        $tag = trim((string) $subjectTag);
        if ($tag === '') {
            return '';
        }
        if (preg_match('/^(?:monthly\s*(?:school\s*)?fee|tuition\s*fee|tution\s*fee)\b/i', $tag)) {
            return '';
        }
        if (preg_match('/\(([^)]+)\)/', $tag, $m)) {
            return strtolower(trim($m[1]));
        }

        return strtolower($tag);
    }

    /**
     * Map fee particular text to appropriate fee type without arbitrary default fallback.
     */
    public function resolveFeeTypeId(string $particular, array $feeTypesByCode): int
    {
        $code = self::resolveFeeTypeCode($particular);

        if (!isset($feeTypesByCode[$code])) {
            throw new RuntimeException("Fee type code '{$code}' not found in fee_types catalog for particular: '{$particular}'");
        }

        return (int) $feeTypesByCode[$code];
    }

    /**
     * Get detailed report of all held/excluded dues lines with reasons and required staff corrections.
     * Does NOT automatically approve any held line.
     */
    public function getHeldDuesReport(?string $conn = null, ?string $batchId = null): array
    {
        $conn = $conn ?: config('database.default', 'mysql');
        $this->validateQaConnection($conn);
        $db = DB::connection($conn);

        $batchId = $batchId ?: (string) config('imports.active_batch_id', 'BATCH-20261023-FINAL-MYSKOOL');

        $dues = $db->table('qa_staged_dues')
            ->where('batch_id', $batchId)
            ->orderBy('source_row')
            ->get();

        $students = $db->table('students')->get()->keyBy('gr_number');
        $enrollments = $db->table('enrollments')->get()->groupBy('student_id');
        [$studentCollectionCurrencies, $familyEnrollmentCurrencies] = self::preloadCurrencyVerificationMaps($db, $batchId, $students, $enrollments);

        $deptCodes = $db->table('departments')->pluck('id', 'code')->all();
        $schoolDeptId = (int) ($deptCodes['school'] ?? 1);
        $tuitionDeptId = (int) ($deptCodes['tuition'] ?? 3);

        $heldRecords = [];

        foreach ($dues as $due) {
            $amtMinor = (int) $due->outstanding_amount_minor;
            $curr = strtoupper(trim((string) $due->currency));
            $p = trim((string) $due->fee_particular);
            $cls = trim((string) $due->class_in_dues_report);
            $student = $students->get($due->gr_number);

            $heldItem = [
                'source_row' => (int) $due->source_row,
                'staged_due_id' => (int) $due->id,
                'gr_number' => (string) $due->gr_number,
                'student_name' => $due->student_name ?: ($due->registration_name ?: ($student?->name ?: '—')),
                'family_code' => $due->family_code_final ?: ($due->family_code_pdf ?: ($due->family_code_registration ?: '—')),
                'class_label' => $cls ?: '—',
                'fee_particular' => $p,
                'currency' => $curr ?: ($due->currency_original ?: 'UNRESOLVED'),
                'amount_minor' => $amtMinor,
                'amount_decimal' => number_format($amtMinor / 100, 2, '.', ''),
                'source_pdf_page' => $due->source_pdf_page ? (int) $due->source_pdf_page : null,
                'status' => 'HELD - PENDING AUDIT',
            ];

            // 1. Invalid Amount
            if ($amtMinor <= 0) {
                $heldItem['category'] = 'invalid_amount';
                $heldItem['category_label'] = 'Invalid / Zero Amount';
                $heldItem['reason'] = 'Outstanding amount is zero or negative.';
                $heldItem['required_correction'] = 'Audit source manual challan; if invalid or settled, archive record as non-collectible.';
                $heldRecords[] = $heldItem;
                continue;
            }

            // 2. Unresolved Currency
            if ($due->is_currency_unresolved || $curr === '' || $curr === 'UNRESOLVED') {
                $heldItem['category'] = 'unresolved_currency';
                $heldItem['category_label'] = 'Unresolved Currency';
                $heldItem['reason'] = "Dues line has missing, blank, or unrecognized currency label ('" . ($due->currency_original ?: 'BLANK') . "').";
                $heldItem['required_correction'] = 'Review student enrollment country and campus on /migration/currency and assign valid ISO currency (SAR/AED/PKR/QAR/OMR).';
                $heldRecords[] = $heldItem;
                continue;
            }

            // 3. Missing Student
            if (!$student) {
                $heldItem['category'] = 'missing_student';
                $heldItem['category_label'] = 'Missing Student Record';
                $heldItem['reason'] = "Student GR {$due->gr_number} not found in QA students table.";
                $heldItem['required_correction'] = 'Confirm GR against admission registers and create missing student record on /manage-student/admission/new.';
                $heldRecords[] = $heldItem;
                continue;
            }

            // 4. Review Hold
            if ($student->status === 'review_hold') {
                $heldItem['category'] = 'review_hold_student';
                $heldItem['category_label'] = 'Student on Review Hold';
                $heldItem['reason'] = "Student {$student->name} (GR {$student->gr_number}) is on financial review hold pending currency or identity audit.";
                $heldItem['required_correction'] = 'Finance administrator must audit student admission and approve currency on /migration/currency and student profile before dues can be billed.';
                $heldRecords[] = $heldItem;
                continue;
            }

            // 5. Historical Hold
            if ($student->status === 'historical_hold') {
                $heldItem['category'] = 'historical_hold_student';
                $heldItem['category_label'] = 'Student on Historical Hold';
                $heldItem['reason'] = "Student {$student->name} (GR {$student->gr_number}) is on historical hold (legacy record absent from active audit).";
                $heldItem['required_correction'] = 'Verify enrollment status with School administration or confirm bad-debt write-off.';
                $heldRecords[] = $heldItem;
                continue;
            }

            // 6. Unknown Period
            $period = self::parseParticularPeriod($p);
            if (!$period) {
                $heldItem['category'] = 'unknown_period';
                $heldItem['category_label'] = 'Unknown / Missing Period';
                $heldItem['reason'] = "Fee particular '{$p}' does not contain a verified calendar month and 4-digit year. Default period was strictly not invented.";
                $heldItem['required_correction'] = 'Cross-reference original manual fee challan to determine exact billing month and year, then assign verified period.';
                $heldRecords[] = $heldItem;
                continue;
            }

            // 7 & 8. Department Determination & Enrollment Match
            $studentEnrollments = $enrollments->get($student->id, collect());
            $resolved = self::resolveTargetEnrollment($student, $studentEnrollments, $cls, $p, $schoolDeptId, $tuitionDeptId);

            if ($resolved['status'] === 'no_enrollment' || $resolved['status'] === 'conflict') {
                $heldItem['category'] = 'unmatched_enrollment';
                $heldItem['category_label'] = 'Unmatched Department Enrollment';
                $heldItem['reason'] = $resolved['reason'];
                $heldItem['required_correction'] = 'Review student department enrollment against fee evidence.';
                $heldRecords[] = $heldItem;
                continue;
            }

            if ($resolved['status'] === 'ambiguous') {
                $heldItem['category'] = 'ambiguous_department';
                $heldItem['category_label'] = 'Ambiguous Department';
                $heldItem['reason'] = $resolved['reason'];
                $heldItem['required_correction'] = 'Review fee particular and assign explicit department enrollment.';
                $heldRecords[] = $heldItem;
                continue;
            }

            $matchedEnrollment = $resolved['enrollment'];

            // 9. Currency Conflict
            if (!self::isVerifiedCurrency($curr, $matchedEnrollment, $student, $studentEnrollments, $studentCollectionCurrencies, $familyEnrollmentCurrencies)) {
                $heldItem['category'] = 'currency_mismatch';
                $heldItem['category_label'] = 'Currency Conflict';
                $heldItem['reason'] = "Dues currency '{$curr}' conflicts with enrollment currency '{$matchedEnrollment->currency}'.";
                $heldItem['required_correction'] = 'Reconcile currency difference between enrollment fee plan and source dues line.';
                $heldRecords[] = $heldItem;
                continue;
            }

            // 10. Policy Hold (Tuition Department or School Urdu Subject Fee pending owner policy decision)
            $deptId = (int) $matchedEnrollment->department_id;
            $policyHold = self::isHeldPendingPolicyDecision($batchId, $deptId, $tuitionDeptId, $p);
            if ($policyHold !== null) {
                $heldItem['category'] = $policyHold['category'];
                $heldItem['category_label'] = $policyHold['category_label'];
                $heldItem['reason'] = $policyHold['reason'];
                $heldItem['required_correction'] = $policyHold['required_correction'];
                $heldRecords[] = $heldItem;
                continue;
            }
        }

        return $heldRecords;
    }

    /**
     * Evaluate whether a valid staged due row should be held from automated opening-balance
     * posting pending the owner's policy decision (specifically the 66 Tuition rows and 6 Urdu subject-fee cases).
     */
    public static function isHeldPendingPolicyDecision(
        string $batchId,
        int $deptId,
        int $tuitionDeptId,
        string $particular
    ): ?array {
        if ($batchId !== ReviewedWorkbookImportService::V4_APPROVED_BATCH_ID) {
            return null;
        }

        if ($deptId === $tuitionDeptId && (bool) config('imports.hold_tuition_opening_balances', true)) {
            return [
                'category' => 'tuition_policy_hold',
                'category_label' => 'Tuition Opening Balance (Held Pending Policy Decision)',
                'reason' => 'Tuition department opening-balance row held from automated financial posting pending owner policy decision.',
                'required_correction' => 'Awaiting owner policy decision on Tuition opening-balance posting.',
            ];
        }

        $subjectTag = self::extractSubjectTag($particular);
        if ($subjectTag === 'urdu' && (bool) config('imports.hold_urdu_subject_opening_balances', true)) {
            return [
                'category' => 'urdu_subject_policy_hold',
                'category_label' => 'Urdu Subject Fee (Held Pending Policy Decision)',
                'reason' => "School Urdu subject-fee row ('{$particular}') held from automated financial posting pending owner policy decision.",
                'required_correction' => 'Awaiting owner policy decision on School Urdu subject-fee opening-balance posting.',
            ];
        }

        return null;
    }

    /**
     * Preload currency maps for fast, zero-query currency verification across batch rows.
     */
    public static function preloadCurrencyVerificationMaps($db, ?string $batchId, $students, $enrollments): array
    {
        $studentCollectionCurrencies = [];
        if ($db->getSchemaBuilder()->hasTable('qa_staged_collections')) {
            $collQuery = $db->table('qa_staged_collections')
                ->whereNotNull('currency')
                ->where('currency', '!=', '');
            if ($batchId !== null && trim($batchId) !== '') {
                $collQuery->where('batch_id', $batchId);
            }
            $studentCollectionCurrencies = $collQuery->select('gr_number', 'currency')
                ->distinct()
                ->get()
                ->groupBy('gr_number')
                ->map(fn($rows) => $rows->pluck('currency')->map(fn($c) => strtoupper(trim((string)$c)))->all())
                ->all();
        }

        $familyEnrollmentCurrencies = [];
        foreach ($students as $st) {
            $stEnrs = $enrollments->get($st->id, collect());
            foreach ($stEnrs as $enr) {
                if (!empty($enr->currency)) {
                    $familyEnrollmentCurrencies[$st->family_id][] = strtoupper(trim((string)$enr->currency));
                }
            }
        }

        return [$studentCollectionCurrencies, $familyEnrollmentCurrencies];
    }

    /**
     * Verify whether a dues currency is valid for a student:
     * - Matches current enrollment currency directly
     * - OR verified by historical collection payments in that currency
     * - OR verified by student having another enrollment in that currency
     * - OR verified by family having enrollment in that currency
     */
    public static function isVerifiedCurrency(
        string $dueCurrency,
        $matchedEnrollment,
        $student,
        $studentEnrollments,
        array $studentCollectionCurrencies,
        array $familyEnrollmentCurrencies
    ): bool {
        if (!$matchedEnrollment || empty($matchedEnrollment->currency)) {
            return true;
        }

        if (strtoupper(trim((string) $matchedEnrollment->currency)) === $dueCurrency) {
            return true;
        }

        // Verified if student has historical collection payments in this currency
        if ($student && isset($studentCollectionCurrencies[$student->gr_number]) && in_array($dueCurrency, $studentCollectionCurrencies[$student->gr_number], true)) {
            return true;
        }

        // Verified if student has another enrollment in this currency
        if ($studentEnrollments && $studentEnrollments->contains(fn($e) => strtoupper(trim((string)$e->currency)) === $dueCurrency)) {
            return true;
        }

        // Verified if student's family has enrollment in this currency
        if ($student && isset($familyEnrollmentCurrencies[$student->family_id]) && in_array($dueCurrency, $familyEnrollmentCurrencies[$student->family_id], true)) {
            return true;
        }

        return false;
    }

    /**
     * Build the 6-part currency-aware primary historical obligation key:
     * (batch_id, gr_number, fee_type_code, period_start, subject_tag, currency)
     */
    public static function makePrimaryObligationKey(
        string $batchId,
        string $grNumber,
        string $feeTypeCode,
        string $periodStart,
        ?string $subjectTag,
        string $currency
    ): string {
        return trim($batchId) . '|'
            . trim($grNumber) . '|'
            . self::normalizeFeeTypeCode($feeTypeCode) . '|'
            . trim($periodStart) . '|'
            . self::normalizeSubjectTag($subjectTag) . '|'
            . strtoupper(trim($currency));
    }

    /**
     * Build the 5-part period-scope historical lookup key (excluding currency):
     * (batch_id, gr_number, fee_type_code, period_start, subject_tag)
     *
     * Used exclusively to detect historical currency transitions and conflicts,
     * never to transfer or combine monetary amounts across currencies.
     */
    public static function makePeriodScopeKey(
        string $batchId,
        string $grNumber,
        string $feeTypeCode,
        string $periodStart,
        ?string $subjectTag = null
    ): string {
        return trim($batchId) . '|'
            . trim($grNumber) . '|'
            . self::normalizeFeeTypeCode($feeTypeCode) . '|'
            . trim($periodStart) . '|'
            . self::normalizeSubjectTag($subjectTag);
    }

    /**
     * Resolve and validate the active historical import batch ID from configuration.
     * Ensures historical lookups use the exact same batch identity as the approved workbook staging import.
     */
    public static function resolveActiveBatchId(?string $batchId = null): string
    {
        if ($batchId !== null) {
            $trimmed = trim($batchId);
            if ($trimmed === '') {
                throw new RuntimeException('CRITICAL SAFETY STOP: Historical import batch ID cannot be empty.');
            }
            return $trimmed;
        }

        $configuredBatch = trim((string) config('imports.active_batch_id', 'BATCH-20261023-FINAL-MYSKOOL'));
        if ($configuredBatch === '') {
            throw new RuntimeException('CRITICAL SAFETY STOP: Configured imports.active_batch_id cannot be empty.');
        }

        $activeVersion = trim((string) config('imports.active_version', ''));
        if ($activeVersion !== '') {
            $versionBatch = trim((string) config("imports.versions.{$activeVersion}.batch_id", ''));
            if ($versionBatch !== '' && $versionBatch !== $configuredBatch) {
                throw new RuntimeException(
                    "CRITICAL SAFETY STOP: Configured imports.active_batch_id ('{$configuredBatch}') conflicts with active version '{$activeVersion}' batch_id ('{$versionBatch}')."
                );
            }
        }

        return $configuredBatch;
    }

    /**
     * Preload currency-aware historical obligations from qa_staged_collections and qa_staged_dues
     * for a batch of student GR numbers in at most two batched queries.
     *
     * Never subtracts historical collections from residual outstanding dues, never converts or
     * mixes currencies, and never deduplicates separate genuine same-currency due lines (e.g. GR 6562).
     */
    public function buildHistoricalObligationMap(
        array $grNumbers,
        ?string $batchId = null,
        ?string $conn = null
    ): array {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(600);
        $conn = $conn ?: config('database.default', 'mysql');
        $this->validateQaConnection($conn);
        $db = DB::connection($conn);

        $explicitBatchId = $batchId !== null && trim($batchId) !== '';
        $batchId = self::resolveActiveBatchId($batchId);

        $grList = array_values(array_unique(array_filter(
            array_map(fn($g) => trim((string) $g), $grNumbers),
            fn($g) => $g !== ''
        )));

        $map = [
            'batch_id' => $batchId,
            'by_primary' => [],
            'by_period_scope' => [],
            'student_collection_currencies' => [],
            'student_period_currencies' => [],
        ];

        if (empty($grList)) {
            return $map;
        }

        $schema = $db->getSchemaBuilder();
        $hasCollectionsTable = $schema->hasTable('qa_staged_collections');
        $hasDuesTable = $schema->hasTable('qa_staged_dues');

        $collections = collect();
        $dues = collect();

        // 1. Query historical paid collections (qa_staged_collections)
        if ($hasCollectionsTable) {
            $collections = $db->table('qa_staged_collections')
                ->where('batch_id', $batchId)
                ->whereIn('gr_number', $grList)
                ->orderBy('source_row')
                ->get();
        }

        // 2. Query historical residual outstanding obligations (qa_staged_dues)
        if ($hasDuesTable) {
            $dues = $db->table('qa_staged_dues')
                ->where('batch_id', $batchId)
                ->whereIn('gr_number', $grList)
                ->orderBy('source_row')
                ->get();
        }

        // If no explicit batch was requested and the default config batch is not in staging,
        // check if the ledger has a single posted opening-balance lineage batch and use it.
        if (!$explicitBatchId && $collections->isEmpty() && $dues->isEmpty() && $schema->hasTable('qa_opening_balance_lineage')) {
            $batchExistsInStaging = ($hasCollectionsTable && $db->table('qa_staged_collections')->where('batch_id', $batchId)->exists())
                || ($hasDuesTable && $db->table('qa_staged_dues')->where('batch_id', $batchId)->exists());

            if (!$batchExistsInStaging) {
                $lineageBatches = $db->table('qa_opening_balance_lineage')->distinct()->pluck('batch_id')->filter()->values();
                if ($lineageBatches->count() === 1) {
                    $batchId = (string) $lineageBatches->first();
                    $map['batch_id'] = $batchId;
                    if ($hasCollectionsTable) {
                        $collections = $db->table('qa_staged_collections')
                            ->where('batch_id', $batchId)
                            ->whereIn('gr_number', $grList)
                            ->orderBy('source_row')
                            ->get();
                    }
                    if ($hasDuesTable) {
                        $dues = $db->table('qa_staged_dues')
                            ->where('batch_id', $batchId)
                            ->whereIn('gr_number', $grList)
                            ->orderBy('source_row')
                            ->get();
                    }
                }
            }
        }

        // Guard against a missing or incorrect staging batch silently making historical fees appear safe to regenerate:
        // If zero rows were matched for the requested GRs in $batchId, verify that $batchId itself is not mismatched
        // against existing staging rows or already-posted opening balance lineage.
        if ($collections->isEmpty() && $dues->isEmpty()) {
            $batchExistsInStaging = ($hasCollectionsTable && $db->table('qa_staged_collections')->where('batch_id', $batchId)->exists())
                || ($hasDuesTable && $db->table('qa_staged_dues')->where('batch_id', $batchId)->exists());

            if (!$batchExistsInStaging) {
                $anyStagingDataExists = ($hasCollectionsTable && $db->table('qa_staged_collections')->exists())
                    || ($hasDuesTable && $db->table('qa_staged_dues')->exists());

                if ($anyStagingDataExists) {
                    throw new RuntimeException(
                        "CRITICAL SAFETY STOP: Historical import batch '{$batchId}' was not found in staging tables while other staged batch records exist. " .
                        "Refusing to silently bypass historical fee protection."
                    );
                }

                if ($schema->hasTable('qa_opening_balance_lineage') && $db->table('qa_opening_balance_lineage')->where('batch_id', $batchId)->exists()) {
                    throw new RuntimeException(
                        "CRITICAL SAFETY STOP: Opening balance lineage exists for batch '{$batchId}', but staging tables contain zero rows for this batch. " .
                        "Refusing to silently bypass historical fee protection."
                    );
                }
            }
        }

        // 3. Index historical paid collections (qa_staged_collections)
        $restorationsByColId = [];
        if ($schema->hasTable('qa_myskool_payment_corrections') && $collections->isNotEmpty()) {
            $colIds = $collections->pluck('id')->filter()->values()->all();
            if (!empty($colIds)) {
                $restorationsByColId = $db->table('qa_myskool_payment_corrections')
                    ->whereIn('qa_staged_collection_id', $colIds)
                    ->where('action_type', 'restore_payment')
                    ->select('qa_staged_collection_id', \Illuminate\Support\Facades\DB::raw('MAX(cumulative_reversed_minor) as reversed_minor'))
                    ->groupBy('qa_staged_collection_id')
                    ->pluck('reversed_minor', 'qa_staged_collection_id')
                    ->all();
            }
        }

        foreach ($collections as $col) {
            $gr = trim((string) ($col->gr_number ?? ''));
            $particular = trim((string) ($col->fee_particular ?? ''));
            $amtMinor = (int) ($col->amount_minor ?? 0);

            $reversedMinor = (int) ($restorationsByColId[$col->id] ?? 0);
            $effectiveAmtMinor = max(0, $amtMinor - $reversedMinor);
            if ($gr === '' || $effectiveAmtMinor <= 0) {
                continue;
            }
            $amtMinor = $effectiveAmtMinor;

            $feeTypeCode = self::resolveFeeTypeCode($particular);
            $subjectTag = self::extractSubjectTag($particular);
            $period = self::parseParticularPeriod($particular);

            if (!$period) {
                if ($feeTypeCode === 'monthly') {
                    continue;
                }
                $period = [
                    'period_key' => "one_time:{$feeTypeCode}:ONE_TIME",
                    'period_start' => 'ONE_TIME',
                ];
            }

            $periodStartsToIndex = [$period['period_start']];
            if (in_array($feeTypeCode, ['admission', 'registration'], true) && $period['period_start'] !== 'ONE_TIME') {
                $periodStartsToIndex[] = 'ONE_TIME';
            }

            $rawCurr = strtoupper(trim((string) ($col->currency ?? '')));
            $isUnresolved = (bool) ($col->is_currency_unresolved ?? false)
                || $rawCurr === ''
                || $rawCurr === 'UNRESOLVED';
            $currKey = $isUnresolved ? 'UNRESOLVED' : $rawCurr;
            $regCurr = strtoupper(trim((string) ($col->registration_currency ?? '')));

            foreach ($periodStartsToIndex as $periodStart) {
                $primaryKey = self::makePrimaryObligationKey($batchId, $gr, $feeTypeCode, $periodStart, $subjectTag, $currKey);
                $scopeKey = self::makePeriodScopeKey($batchId, $gr, $feeTypeCode, $periodStart, $subjectTag);

                if (!isset($map['by_primary'][$primaryKey])) {
                    $map['by_primary'][$primaryKey] = [
                        'batch_id' => $batchId,
                        'gr_number' => $gr,
                        'fee_type_code' => $feeTypeCode,
                        'period_start' => $periodStart,
                        'period_key' => $periodStart === 'ONE_TIME' ? "one_time:{$feeTypeCode}:ONE_TIME" : $period['period_key'],
                        'subject_tag' => $subjectTag,
                        'currency' => $currKey,
                        'paid_minor' => 0,
                        'outstanding_minor' => 0,
                        'collection_rows_count' => 0,
                        'due_rows_count' => 0,
                        'collection_source_rows' => [],
                        'due_source_rows' => [],
                        'due_rows' => [],
                        'staged_collection_ids' => [],
                        'staged_due_ids' => [],
                        'slips' => [],
                        'registration_currencies' => [],
                        'has_unresolved_currency' => false,
                    ];
                }

                $map['by_primary'][$primaryKey]['paid_minor'] += $amtMinor;
                $map['by_primary'][$primaryKey]['collection_rows_count']++;
                if (isset($col->source_row)) {
                    $map['by_primary'][$primaryKey]['collection_source_rows'][] = (int) $col->source_row;
                }
                if (isset($col->id)) {
                    $map['by_primary'][$primaryKey]['staged_collection_ids'][] = (int) $col->id;
                }
                $slip = trim((string) ($col->slip_number ?? ''));
                if ($slip !== '' && !in_array($slip, $map['by_primary'][$primaryKey]['slips'], true)) {
                    $map['by_primary'][$primaryKey]['slips'][] = $slip;
                }
                if ($regCurr !== '' && $regCurr !== 'UNRESOLVED' && !in_array($regCurr, $map['by_primary'][$primaryKey]['registration_currencies'], true)) {
                    $map['by_primary'][$primaryKey]['registration_currencies'][] = $regCurr;
                }
                if ($isUnresolved) {
                    $map['by_primary'][$primaryKey]['has_unresolved_currency'] = true;
                }

                $map['by_period_scope'][$scopeKey][$currKey] = $map['by_primary'][$primaryKey];

                if (!$isUnresolved && $periodStart !== 'ONE_TIME') {
                    $map['student_period_currencies'][$gr][$feeTypeCode . '|' . $subjectTag][$periodStart][$currKey] = true;
                }
            }

            if (!$isUnresolved) {
                $map['student_collection_currencies'][$gr][$currKey] = ($map['student_collection_currencies'][$gr][$currKey] ?? 0) + 1;
            }
        }

        // 4. Index historical residual outstanding obligations (qa_staged_dues)
        foreach ($dues as $due) {
            $gr = trim((string) ($due->gr_number ?? ''));
            $particular = trim((string) ($due->fee_particular ?? ''));
            $amtMinor = (int) ($due->outstanding_amount_minor ?? 0);

            if ($gr === '' || $amtMinor <= 0) {
                continue;
            }

            $feeTypeCode = self::resolveFeeTypeCode($particular);
            $subjectTag = self::extractSubjectTag($particular);
            $period = self::parseParticularPeriod($particular);

            if (!$period) {
                if ($feeTypeCode === 'monthly') {
                    continue;
                }
                $period = [
                    'period_key' => "one_time:{$feeTypeCode}:ONE_TIME",
                    'period_start' => 'ONE_TIME',
                ];
            }

            $periodStartsToIndex = [$period['period_start']];
            if (in_array($feeTypeCode, ['admission', 'registration'], true) && $period['period_start'] !== 'ONE_TIME') {
                $periodStartsToIndex[] = 'ONE_TIME';
            }

            $rawCurr = strtoupper(trim((string) ($due->currency ?? '')));
            $isUnresolved = (bool) ($due->is_currency_unresolved ?? false)
                || $rawCurr === ''
                || $rawCurr === 'UNRESOLVED';
            $currKey = $isUnresolved ? 'UNRESOLVED' : $rawCurr;
            $regCurr = strtoupper(trim((string) ($due->registration_currency ?? '')));

            foreach ($periodStartsToIndex as $periodStart) {
                $primaryKey = self::makePrimaryObligationKey($batchId, $gr, $feeTypeCode, $periodStart, $subjectTag, $currKey);
                $scopeKey = self::makePeriodScopeKey($batchId, $gr, $feeTypeCode, $periodStart, $subjectTag);

                if (!isset($map['by_primary'][$primaryKey])) {
                    $map['by_primary'][$primaryKey] = [
                        'batch_id' => $batchId,
                        'gr_number' => $gr,
                        'fee_type_code' => $feeTypeCode,
                        'period_start' => $periodStart,
                        'period_key' => $periodStart === 'ONE_TIME' ? "one_time:{$feeTypeCode}:ONE_TIME" : $period['period_key'],
                        'subject_tag' => $subjectTag,
                        'currency' => $currKey,
                        'paid_minor' => 0,
                        'outstanding_minor' => 0,
                        'collection_rows_count' => 0,
                        'due_rows_count' => 0,
                        'collection_source_rows' => [],
                        'due_source_rows' => [],
                        'due_rows' => [],
                        'staged_collection_ids' => [],
                        'staged_due_ids' => [],
                        'slips' => [],
                        'registration_currencies' => [],
                        'has_unresolved_currency' => false,
                    ];
                }

                // Preserve every residual due row without deduplication (e.g. GR 6562 rows 3496 & 3497 = AED 400)
                // and never deduct paid_minor from outstanding_amount_minor again.
                $map['by_primary'][$primaryKey]['outstanding_minor'] += $amtMinor;
                $map['by_primary'][$primaryKey]['due_rows_count']++;
                if (isset($due->source_row)) {
                    $map['by_primary'][$primaryKey]['due_source_rows'][] = (int) $due->source_row;
                }
                $map['by_primary'][$primaryKey]['due_rows'][] = [
                    'source_row' => isset($due->source_row) ? (int) $due->source_row : null,
                    'staged_due_id' => isset($due->id) ? (int) $due->id : null,
                    'amount_minor' => $amtMinor,
                    'currency' => $currKey,
                    'fee_particular' => $particular,
                ];
                if (isset($due->id)) {
                    $map['by_primary'][$primaryKey]['staged_due_ids'][] = (int) $due->id;
                }
                if ($regCurr !== '' && $regCurr !== 'UNRESOLVED' && !in_array($regCurr, $map['by_primary'][$primaryKey]['registration_currencies'], true)) {
                    $map['by_primary'][$primaryKey]['registration_currencies'][] = $regCurr;
                }
                if ($isUnresolved) {
                    $map['by_primary'][$primaryKey]['has_unresolved_currency'] = true;
                }

                $map['by_period_scope'][$scopeKey][$currKey] = $map['by_primary'][$primaryKey];

                if (!$isUnresolved && $periodStart !== 'ONE_TIME') {
                    $map['student_period_currencies'][$gr][$feeTypeCode . '|' . $subjectTag][$periodStart][$currKey] = true;
                }
            }
        }

        return $map;
    }

    /**
     * Classify a candidate fee obligation into one of six currency-aware settlement states:
     * - 'settled_same_currency'        (A. Fully settled in requested currency)
     * - 'partial_same_currency'        (B. Partially paid in requested currency with positive residual)
     * - 'unpaid_same_currency'         (C. Unpaid historical obligation in requested currency)
     * - 'verified_currency_transition' (D. Verified historical obligation in prior currency before transition)
     * - 'cross_currency_conflict'      (E. Multi-currency or ambiguous cross-currency period requiring review)
     * - 'new_obligation'               (F. No matching historical obligation; ordinary billing permitted)
     */
    public static function classifyHistoricalObligation(
        array $map,
        string $grNumber,
        string $feeTypeCode,
        string $periodStart,
        string $targetCurrency,
        ?string $subjectTag = null,
        ?int $expectedAmountMinor = null
    ): array {
        if (
            !array_key_exists('batch_id', $map)
            || !array_key_exists('by_primary', $map)
            || !array_key_exists('by_period_scope', $map)
            || trim((string) $map['batch_id']) === ''
        ) {
            throw new RuntimeException(
                'CRITICAL SAFETY STOP: Historical obligation map is missing required batch_id or index structures. ' .
                'Use buildHistoricalObligationMap() before classifying obligations.'
            );
        }

        $batchId = trim((string) $map['batch_id']);
        $gr = trim($grNumber);
        $normFeeType = self::normalizeFeeTypeCode($feeTypeCode);
        $normPeriodStart = trim($periodStart);
        $normSubject = self::normalizeSubjectTag($subjectTag);
        $normCurrency = strtoupper(trim($targetCurrency));

        $primaryKey = self::makePrimaryObligationKey($batchId, $gr, $normFeeType, $normPeriodStart, $normSubject, $normCurrency);
        $scopeKey = self::makePeriodScopeKey($batchId, $gr, $normFeeType, $normPeriodStart, $normSubject);
        $scopeEntries = $map['by_period_scope'][$scopeKey] ?? [];

        // F. New legitimate obligation: no historical payment or due exists for this period/type/subject
        if (empty($scopeEntries)) {
            return [
                'status' => 'new_obligation',
                'primary_key' => $primaryKey,
                'period_scope_key' => $scopeKey,
                'currency' => $normCurrency,
                'paid_minor' => 0,
                'outstanding_minor' => 0,
                'blocks_billing' => false,
                'review_required' => false,
                'is_settled' => false,
            ];
        }

        // E (Part 1). Cross-currency conflict: multiple currencies exist in the same period, or currency is unresolved
        if (count($scopeEntries) > 1 || isset($scopeEntries['UNRESOLVED']) || $normCurrency === '' || $normCurrency === 'UNRESOLVED') {
            $allCurrencies = array_keys($scopeEntries);
            $conflicting = array_values(array_filter($allCurrencies, fn($c) => $c !== $normCurrency));
            if (empty($conflicting)) {
                $conflicting = $allCurrencies;
            }

            return [
                'status' => 'cross_currency_conflict',
                'reason' => count($scopeEntries) > 1
                    ? 'Multiple currencies exist for the same student, fee type, and historical billing period.'
                    : 'Historical obligation has an unresolved or missing currency.',
                'primary_key' => $primaryKey,
                'period_scope_key' => $scopeKey,
                'target_currency' => $normCurrency,
                'currencies' => $allCurrencies,
                'conflicting_currencies' => $conflicting,
                'by_currency' => $scopeEntries,
                'blocks_billing' => true,
                'review_required' => true,
                'is_settled' => false,
            ];
        }

        // Exact 6-part primary key match in the same currency (A, B, or C)
        if (isset($scopeEntries[$normCurrency])) {
            $entry = $map['by_primary'][$primaryKey] ?? $scopeEntries[$normCurrency];
            $paidMinor = (int) ($entry['paid_minor'] ?? 0);
            $outMinor = (int) ($entry['outstanding_minor'] ?? 0);

            if (!empty($entry['has_unresolved_currency'])) {
                return [
                    'status' => 'cross_currency_conflict',
                    'reason' => 'Historical obligation has an unresolved currency flag.',
                    'primary_key' => $primaryKey,
                    'period_scope_key' => $scopeKey,
                    'target_currency' => $normCurrency,
                    'currencies' => [$normCurrency],
                    'conflicting_currencies' => [$normCurrency],
                    'by_currency' => $scopeEntries,
                    'blocks_billing' => true,
                    'review_required' => true,
                    'is_settled' => false,
                ];
            }

            if ($paidMinor > 0 && $outMinor === 0) {
                // If an expected charge is known (e.g. from approved fee plan) and the payment is less than that charge,
                // this is a partial advance/historical payment, NOT a fully settled obligation!
                if ($expectedAmountMinor !== null && $expectedAmountMinor > 0 && $paidMinor < $expectedAmountMinor) {
                    $remaining = max(0, $expectedAmountMinor - $paidMinor);
                    return [
                        'status' => 'partial_advance_payment',
                        'primary_key' => $primaryKey,
                        'period_scope_key' => $scopeKey,
                        'currency' => $normCurrency,
                        'paid_minor' => $paidMinor,
                        'outstanding_minor' => $remaining,
                        'expected_obligation_minor' => $expectedAmountMinor,
                        'remaining_obligation_minor' => $remaining,
                        'collection_rows_count' => (int) ($entry['collection_rows_count'] ?? 0),
                        'due_rows_count' => 0,
                        'collection_source_rows' => $entry['collection_source_rows'] ?? [],
                        'due_source_rows' => [],
                        'due_rows' => [],
                        'staged_collection_ids' => $entry['staged_collection_ids'] ?? [],
                        'staged_due_ids' => [],
                        'slips' => $entry['slips'] ?? [],
                        'blocks_billing' => false,
                        'review_required' => false,
                        'is_settled' => false,
                        'is_partial' => true,
                    ];
                }

                // A. Fully settled — same currency
                return [
                    'status' => 'settled_same_currency',
                    'primary_key' => $primaryKey,
                    'period_scope_key' => $scopeKey,
                    'currency' => $normCurrency,
                    'paid_minor' => $paidMinor,
                    'outstanding_minor' => 0,
                    'collection_rows_count' => (int) ($entry['collection_rows_count'] ?? 0),
                    'due_rows_count' => 0,
                    'collection_source_rows' => $entry['collection_source_rows'] ?? [],
                    'due_source_rows' => [],
                    'due_rows' => [],
                    'staged_collection_ids' => $entry['staged_collection_ids'] ?? [],
                    'staged_due_ids' => [],
                    'slips' => $entry['slips'] ?? [],
                    'blocks_billing' => true,
                    'review_required' => false,
                    'is_settled' => true,
                ];
            }

            if ($paidMinor > 0 && $outMinor > 0) {
                // B. Partially paid — same currency (preserve residual without double deduction)
                return [
                    'status' => 'partial_same_currency',
                    'primary_key' => $primaryKey,
                    'period_scope_key' => $scopeKey,
                    'currency' => $normCurrency,
                    'paid_minor' => $paidMinor,
                    'outstanding_minor' => $outMinor,
                    'collection_rows_count' => (int) ($entry['collection_rows_count'] ?? 0),
                    'due_rows_count' => (int) ($entry['due_rows_count'] ?? 0),
                    'collection_source_rows' => $entry['collection_source_rows'] ?? [],
                    'due_source_rows' => $entry['due_source_rows'] ?? [],
                    'due_rows' => $entry['due_rows'] ?? [],
                    'staged_collection_ids' => $entry['staged_collection_ids'] ?? [],
                    'staged_due_ids' => $entry['staged_due_ids'] ?? [],
                    'slips' => $entry['slips'] ?? [],
                    'blocks_billing' => true,
                    'review_required' => false,
                    'is_settled' => false,
                ];
            }

            // C. Unpaid historical obligation — same currency
            return [
                'status' => 'unpaid_same_currency',
                'primary_key' => $primaryKey,
                'period_scope_key' => $scopeKey,
                'currency' => $normCurrency,
                'paid_minor' => 0,
                'outstanding_minor' => $outMinor,
                'collection_rows_count' => 0,
                'due_rows_count' => (int) ($entry['due_rows_count'] ?? 0),
                'collection_source_rows' => [],
                'due_source_rows' => $entry['due_source_rows'] ?? [],
                'due_rows' => $entry['due_rows'] ?? [],
                'staged_collection_ids' => [],
                'staged_due_ids' => $entry['staged_due_ids'] ?? [],
                'slips' => [],
                'blocks_billing' => true,
                'review_required' => false,
                'is_settled' => false,
            ];
        }

        // Single foreign currency exists in PeriodScopeKey ($histCurrency !== $normCurrency):
        // Evaluate D (Verified historical currency transition) vs E (Cross-currency conflict).
        $histCurrency = (string) array_key_first($scopeEntries);
        $entry = $scopeEntries[$histCurrency];
        $paidMinor = (int) ($entry['paid_minor'] ?? 0);
        $outMinor = (int) ($entry['outstanding_minor'] ?? 0);

        // Require sufficient historical period/currency evidence before classifying as a verified transition:
        // 1. Do not treat current enrollment currency alone as proof that an unpaid row in $histCurrency is valid:
        //    require historical payment evidence in $histCurrency for this student.
        $hasHistoricalPaymentEvidence = ($paidMinor > 0)
            || !empty($map['student_collection_currencies'][$gr][$histCurrency]);

        // 2. Chronological consistency across periods for this student/feeType/subject:
        //    - $normCurrency must not appear in an earlier period (< $normPeriodStart).
        //    - Check whether $normCurrency appears in a subsequent historical period (> $normPeriodStart).
        $hasTargetCurrencyInEarlierPeriod = false;
        $hasTargetCurrencyInLaterPeriod = false;
        $periodTimeline = $map['student_period_currencies'][$gr][$normFeeType . '|' . $normSubject] ?? [];
        foreach ($periodTimeline as $pStart => $currsInP) {
            if ($pStart < $normPeriodStart && isset($currsInP[$normCurrency])) {
                $hasTargetCurrencyInEarlierPeriod = true;
            }
            if ($pStart > $normPeriodStart && isset($currsInP[$normCurrency])) {
                $hasTargetCurrencyInLaterPeriod = true;
            }
        }

        // 3. Positive transition evidence linking $histCurrency -> $normCurrency:
        //    Either the staged record explicitly attests registration_currency == $normCurrency (and not $histCurrency),
        //    or (when registration_currency is absent on the staged row) the student's multi-period history shows
        //    $histCurrency in earlier periods followed by $normCurrency in later periods.
        $regCurrs = $entry['registration_currencies'] ?? [];
        $hasExplicitRegCurrTransition = !empty($regCurrs)
            && in_array($normCurrency, $regCurrs, true)
            && !in_array($histCurrency, $regCurrs, true);
        $hasChronologicalTransition = empty($regCurrs) && $hasTargetCurrencyInLaterPeriod;
        $hasPositiveTransitionEvidence = $hasExplicitRegCurrTransition || $hasChronologicalTransition;

        if (
            empty($entry['has_unresolved_currency'])
            && $hasHistoricalPaymentEvidence
            && !$hasTargetCurrencyInEarlierPeriod
            && $hasPositiveTransitionEvidence
        ) {
            // D. Verified historical currency transition:
            // Preserve the historical obligation in $histCurrency without currency conversion,
            // block re-billing the transitioned month in $normCurrency, and never mark $normCurrency itself as paid.
            $histState = ($outMinor > 0 && $paidMinor > 0)
                ? 'partial_due'
                : ($outMinor > 0 ? 'unpaid_due' : 'settled');

            return [
                'status' => 'verified_currency_transition',
                'historical_state' => $histState,
                'primary_key' => self::makePrimaryObligationKey($batchId, $gr, $normFeeType, $normPeriodStart, $normSubject, $histCurrency),
                'requested_primary_key' => $primaryKey,
                'period_scope_key' => $scopeKey,
                'historical_currency' => $histCurrency,
                'target_currency' => $normCurrency,
                'paid_minor' => $paidMinor,
                'outstanding_minor' => $outMinor,
                'collection_rows_count' => (int) ($entry['collection_rows_count'] ?? 0),
                'due_rows_count' => (int) ($entry['due_rows_count'] ?? 0),
                'collection_source_rows' => $entry['collection_source_rows'] ?? [],
                'due_source_rows' => $entry['due_source_rows'] ?? [],
                'due_rows' => $entry['due_rows'] ?? [],
                'staged_collection_ids' => $entry['staged_collection_ids'] ?? [],
                'staged_due_ids' => $entry['staged_due_ids'] ?? [],
                'slips' => $entry['slips'] ?? [],
                'blocks_billing' => true,
                'review_required' => false,
                'is_settled' => false,
            ];
        }

        // E (Part 2). Ambiguous cross-currency relationship held for review
        return [
            'status' => 'cross_currency_conflict',
            'reason' => !$hasHistoricalPaymentEvidence
                ? "Unpaid historical obligation in '{$histCurrency}' lacks corroborating historical payment evidence for transition to '{$normCurrency}'."
                : "Historical obligation in '{$histCurrency}' for period '{$normPeriodStart}' cannot settle or auto-transition to '{$normCurrency}' without verified transition evidence.",
            'primary_key' => $primaryKey,
            'period_scope_key' => $scopeKey,
            'target_currency' => $normCurrency,
            'currencies' => [$histCurrency],
            'conflicting_currencies' => [$histCurrency],
            'by_currency' => $scopeEntries,
            'blocks_billing' => true,
            'review_required' => true,
            'is_settled' => false,
        ];
    }

    /**
     * Convenience guard returning null when the obligation is a new legitimate charge ('new_obligation')
     * and returning the classification array when historical protection applies.
     */
    public static function checkHistoricalObligation(
        array $map,
        string $grNumber,
        string $feeTypeCode,
        string $periodStart,
        string $targetCurrency,
        ?string $subjectTag = null,
        ?int $expectedAmountMinor = null
    ): ?array {
        $result = self::classifyHistoricalObligation($map, $grNumber, $feeTypeCode, $periodStart, $targetCurrency, $subjectTag, $expectedAmountMinor);
        if (($result['status'] ?? 'new_obligation') === 'new_obligation') {
            return null;
        }

        return $result;
    }


    /**
     * Evaluate whether a voided invoice item for a given student, fee type, and period
     * is an eligible unpaid imported obligation (safe to regenerate with current settings)
     * or must remain blocked due to historical payments, partial settlements, or genuine currency/data conflicts.
     */
    public static function evaluateVoidedImportedObligation(
        $db,
        array $historicalMap,
        object $voidedItem,
        string $grNumber,
        string $feeTypeCode,
        string $periodStart,
        string $targetCurrency,
        ?string $subjectTag = null
    ): array {
        $voidedItemId = (int) $voidedItem->id;
        $origCurrency = strtoupper(trim((string) ($voidedItem->currency ?: $targetCurrency)));
        $origAmountMinor = (int) ($voidedItem->amount_minor ?? 0);
        $origDiscountMinor = (int) ($voidedItem->discount_minor ?? 0);
        $origExemptionMinor = (int) ($voidedItem->exemption_minor ?? 0);
        $origNetMinor = max(0, $origAmountMinor - $origDiscountMinor - $origExemptionMinor);

        if ($origNetMinor <= 0) {
            $voidAdjPrev = (int) ($db->table('fee_adjustments')
                ->where('invoice_item_id', $voidedItemId)
                ->where('kind', 'void')
                ->orderByDesc('id')
                ->value('previous_amount_minor') ?? 0);
            if ($voidAdjPrev > 0) {
                $origNetMinor = $voidAdjPrev;
            }
        }

        // 1. Check active payment allocations or paid_minor on the voided item itself
        $hasAllocations = (int) ($voidedItem->paid_minor ?? 0) > 0
            || $db->table('payment_allocations')
                ->where('invoice_item_id', $voidedItemId)
                ->where('amount_minor', '>', 0)
                ->exists();

        if ($hasAllocations) {
            $msg = "Blocked: Active payment allocations exist on voided item #{$voidedItemId}. Reverse receipts first.";
            return [
                'can_recreate' => false,
                'block_category' => 'active_allocation',
                'status_code' => 'financial_conflict',
                'reason' => $msg,
                'recreate_block_reason' => $msg,
                'paid_minor' => (int) ($voidedItem->paid_minor ?? 0),
                'outstanding_minor' => $origNetMinor,
                'historical_currency' => $origCurrency,
            ];
        }

        $schema = $db->getSchemaBuilder();

        // 2. Check applied historical settlements in qa_historical_settlements
        if ($schema->hasTable('qa_historical_settlements')) {
            $periodKey = (string) ($voidedItem->period_key ?? '');
            $settlement = $db->table('qa_historical_settlements')
                ->where('status', 'applied')
                ->where(function ($q) use ($voidedItemId, $grNumber, $periodStart, $periodKey) {
                    $q->where('invoice_item_id', $voidedItemId);
                    if ($grNumber !== '') {
                        $q->orWhere(function ($sq) use ($grNumber, $periodStart, $periodKey) {
                            $sq->where('student_gr', $grNumber)
                               ->where(function ($pq) use ($periodStart, $periodKey) {
                                   if ($periodKey !== '') {
                                       $pq->where('period_key', $periodKey)
                                          ->orWhere('period_key', 'LIKE', "%{$periodStart}%");
                                   } else {
                                       $pq->where('period_key', 'LIKE', "%{$periodStart}%");
                                   }
                               });
                        });
                    }
                })
                ->first();

            if ($settlement) {
                $settledMinor = (int) ($settlement->settled_amount_minor ?? 0);
                $remMinor = (int) ($settlement->remaining_obligation_minor ?? 0);
                $origObMinor = (int) ($settlement->original_obligation_minor ?? $origNetMinor);
                $sCurr = strtoupper(trim((string) ($settlement->currency ?: $origCurrency)));
                $paidFmt = number_format($settledMinor / 100, 2, '.', '');
                $invLabel = !empty($voidedItem->invoice_number) ? " on {$voidedItem->invoice_number}" : '';

                if ($remMinor > 0 || ($origObMinor > 0 && $settledMinor > 0 && $settledMinor < $origObMinor)) {
                    $effectiveRem = $remMinor > 0 ? $remMinor : max(0, $origObMinor - $settledMinor);
                    $remFmt = number_format($effectiveRem / 100, 2, '.', '');
                    return [
                        'can_recreate' => false,
                        'block_category' => 'historical_payment_partial',
                        'status_code' => 'restoration_review',
                        'reason' => "This fee obligation was previously voided (Item #{$voidedItemId}{$invLabel}) but possesses partial historical MySkool payment evidence ({$sCurr} {$paidFmt} settled, {$sCurr} {$remFmt} remaining). Preserve the settled portion; requires administrative restoration review and safe reconciliation rather than recreation.",
                        'recreate_block_reason' => "Recreation blocked: Partial historical MySkool payment exists ({$sCurr} {$paidFmt} settled, {$sCurr} {$remFmt} remaining). Preserve the settled portion and use safe reconciliation rather than recreating the full amount.",
                        'paid_minor' => $settledMinor,
                        'outstanding_minor' => $effectiveRem,
                        'historical_currency' => $sCurr,
                    ];
                }

                return [
                    'can_recreate' => false,
                    'block_category' => 'historical_payment_full',
                    'status_code' => 'restoration_review',
                    'reason' => "This fee obligation was previously voided (Item #{$voidedItemId}{$invLabel}) but possesses verified historical MySkool payment evidence ({$sCurr} {$paidFmt}). Requires administrative restoration review rather than recreation.",
                    'recreate_block_reason' => "Recreation blocked: Obligation possesses verified historical MySkool payment evidence ({$sCurr} {$paidFmt}).",
                    'paid_minor' => $settledMinor,
                    'outstanding_minor' => 0,
                    'historical_currency' => $sCurr,
                ];
            }
        }

        // 3. Verify lineage integrity if qa_opening_balance_lineage references this item
        $hasLineageTable = $schema->hasTable('qa_opening_balance_lineage');
        $hasDuesTable = $schema->hasTable('qa_staged_dues');
        $itemLineage = $hasLineageTable
            ? $db->table('qa_opening_balance_lineage')->where('invoice_item_id', $voidedItemId)->first()
            : null;

        if ($itemLineage) {
            if (!$hasDuesTable) {
                $msg = "Recreation blocked: Source MySkool staged dues table is unavailable to verify unpaid lineage for item #{$voidedItemId}.";
                return [
                    'can_recreate' => false,
                    'block_category' => 'insufficient_evidence',
                    'status_code' => 'financial_conflict',
                    'reason' => $msg,
                    'recreate_block_reason' => $msg,
                    'paid_minor' => 0,
                    'outstanding_minor' => $origNetMinor,
                    'historical_currency' => $origCurrency,
                ];
            }
            $linkedDue = $db->table('qa_staged_dues')->where('id', $itemLineage->qa_staged_due_id)->first();
            if (!$linkedDue) {
                $msg = "Recreation blocked: Source MySkool staged due record (#{$itemLineage->qa_staged_due_id}) is missing and cannot prove unpaid status.";
                return [
                    'can_recreate' => false,
                    'block_category' => 'insufficient_evidence',
                    'status_code' => 'financial_conflict',
                    'reason' => $msg,
                    'recreate_block_reason' => $msg,
                    'paid_minor' => 0,
                    'outstanding_minor' => $origNetMinor,
                    'historical_currency' => $origCurrency,
                ];
            }
            $linkedDueCurr = strtoupper(trim((string) ($linkedDue->currency ?? '')));
            if (
                !empty($linkedDue->is_currency_unresolved)
                || !empty($linkedDue->has_currency_conflict)
                || $linkedDueCurr === ''
                || $linkedDueCurr === 'UNRESOLVED'
                || ($origCurrency !== '' && $linkedDueCurr !== $origCurrency)
            ) {
                $msg = "Recreation blocked: Historical MySkool staged due record (#{$linkedDue->id}) has a currency conflict or unresolved currency ({$linkedDueCurr} vs {$origCurrency}).";
                return [
                    'can_recreate' => false,
                    'block_category' => 'currency_conflict',
                    'status_code' => 'financial_conflict',
                    'reason' => $msg,
                    'recreate_block_reason' => $msg,
                    'paid_minor' => 0,
                    'outstanding_minor' => (int) ($linkedDue->outstanding_amount_minor ?? $origNetMinor),
                    'historical_currency' => $linkedDueCurr ?: $origCurrency,
                ];
            }
        }

        if (
            empty($historicalMap)
            || empty($historicalMap['batch_id'])
            || trim($grNumber) === ''
        ) {
            return [
                'can_recreate' => true,
                'block_category' => null,
                'status_code' => 'previously_voided',
                'reason' => null,
                'recreate_block_reason' => null,
                'paid_minor' => 0,
                'outstanding_minor' => 0,
                'historical_currency' => $origCurrency,
            ];
        }

        $batchId = trim((string) $historicalMap['batch_id']);
        $normFeeType = self::normalizeFeeTypeCode($feeTypeCode);
        $scopeKey = self::makePeriodScopeKey($batchId, $grNumber, $normFeeType, $periodStart, $subjectTag);
        $scopeEntries = $historicalMap['by_period_scope'][$scopeKey] ?? [];

        if (empty($scopeEntries) && !in_array($normFeeType, ['monthly', 'tuition'], true)) {
            $oneTimeScopeKey = self::makePeriodScopeKey($batchId, $grNumber, $normFeeType, 'ONE_TIME', $subjectTag);
            $scopeEntries = $historicalMap['by_period_scope'][$oneTimeScopeKey] ?? [];
        }

        if (empty($scopeEntries)) {
            return [
                'can_recreate' => true,
                'block_category' => null,
                'status_code' => 'previously_voided',
                'reason' => null,
                'recreate_block_reason' => null,
                'paid_minor' => 0,
                'outstanding_minor' => 0,
                'historical_currency' => $origCurrency,
            ];
        }

        // 4a. Check for unresolved currency in scopeEntries
        if (isset($scopeEntries['UNRESOLVED'])) {
            $msg = 'Recreation blocked: Historical MySkool obligation has an unresolved or missing currency for this period.';
            return [
                'can_recreate' => false,
                'block_category' => 'currency_conflict',
                'status_code' => 'financial_conflict',
                'reason' => $msg,
                'recreate_block_reason' => $msg,
                'paid_minor' => 0,
                'outstanding_minor' => 0,
                'historical_currency' => 'UNRESOLVED',
            ];
        }

        foreach ($scopeEntries as $entryCurr => $entry) {
            if (!empty($entry['has_unresolved_currency'])) {
                $msg = "Recreation blocked: Historical MySkool obligation in {$entryCurr} has an unresolved currency flag.";
                return [
                    'can_recreate' => false,
                    'block_category' => 'currency_conflict',
                    'status_code' => 'financial_conflict',
                    'reason' => $msg,
                    'recreate_block_reason' => $msg,
                    'paid_minor' => (int) ($entry['paid_minor'] ?? 0),
                    'outstanding_minor' => (int) ($entry['outstanding_minor'] ?? 0),
                    'historical_currency' => (string) $entryCurr,
                ];
            }
        }

        // 4b. Check for ANY historical payments (qa_staged_collections) in ANY currency for this period
        foreach ($scopeEntries as $entryCurr => $entry) {
            $paidMinor = (int) ($entry['paid_minor'] ?? 0);
            $outMinor = (int) ($entry['outstanding_minor'] ?? 0);
            $colCount = (int) ($entry['collection_rows_count'] ?? 0);

            if ($paidMinor > 0) {
                $entryCurrStr = strtoupper(trim((string) $entryCurr));
                $paidFmt = number_format($paidMinor / 100, 2, '.', '');
                $invLabel = !empty($voidedItem->invoice_number) ? " on {$voidedItem->invoice_number}" : '';

                if ($origCurrency !== '' && $entryCurrStr !== $origCurrency) {
                    $msg = "Recreation blocked: Historical MySkool payment ({$entryCurrStr} {$paidFmt}) exists in a conflicting currency ({$entryCurrStr} vs {$origCurrency}) for this period.";
                    return [
                        'can_recreate' => false,
                        'block_category' => 'currency_conflict',
                        'status_code' => 'financial_conflict',
                        'reason' => $msg,
                        'recreate_block_reason' => $msg,
                        'paid_minor' => $paidMinor,
                        'outstanding_minor' => $outMinor,
                        'historical_currency' => $entryCurrStr,
                    ];
                }

                $isPartial = ($outMinor > 0) || ($origNetMinor > 0 && $paidMinor < $origNetMinor);
                if ($isPartial) {
                    $effectiveRem = $outMinor > 0 ? $outMinor : max(0, $origNetMinor - $paidMinor);
                    $remFmt = number_format($effectiveRem / 100, 2, '.', '');
                    return [
                        'can_recreate' => false,
                        'block_category' => 'historical_payment_partial',
                        'status_code' => 'restoration_review',
                        'reason' => "This fee obligation was previously voided (Item #{$voidedItemId}{$invLabel}) but possesses partial historical MySkool payment evidence ({$entryCurrStr} {$paidFmt} paid, {$entryCurrStr} {$remFmt} remaining). Preserve the settled portion; requires administrative restoration review and safe reconciliation rather than recreation.",
                        'recreate_block_reason' => "Recreation blocked: Partial historical MySkool payment exists ({$entryCurrStr} {$paidFmt} paid, {$entryCurrStr} {$remFmt} remaining). Preserve the settled portion and use safe reconciliation rather than recreating the full amount.",
                        'paid_minor' => $paidMinor,
                        'outstanding_minor' => $effectiveRem,
                        'historical_currency' => $entryCurrStr,
                    ];
                }

                return [
                    'can_recreate' => false,
                    'block_category' => 'historical_payment_full',
                    'status_code' => 'restoration_review',
                    'reason' => "This fee obligation was previously voided (Item #{$voidedItemId}{$invLabel}) but possesses verified historical MySkool payment evidence ({$entryCurrStr} {$paidFmt}). Requires administrative restoration review rather than recreation.",
                    'recreate_block_reason' => "Recreation blocked: Obligation possesses verified historical MySkool payment evidence ({$entryCurrStr} {$paidFmt}).",
                    'paid_minor' => $paidMinor,
                    'outstanding_minor' => 0,
                    'historical_currency' => $entryCurrStr,
                ];
            }
        }

        // 4c. Multiple currencies in staged dues for the same period = genuine historical currency conflict
        if (count($scopeEntries) > 1) {
            $currs = implode(', ', array_keys($scopeEntries));
            $msg = "Recreation blocked: Multiple historical MySkool currencies ({$currs}) exist in staged dues for this period.";
            return [
                'block_category' => 'currency_conflict',
                'status_code' => 'financial_conflict',
                'reason' => $msg,
                'recreate_block_reason' => $msg,
                'paid_minor' => 0,
                'outstanding_minor' => 0,
                'historical_currency' => $currs,
            ];
        }

        $stagedCurr = (string) array_key_first($scopeEntries);
        $stagedEntry = $scopeEntries[$stagedCurr];
        $stagedDueIds = $stagedEntry['staged_due_ids'] ?? [];
        $stagedOutMinor = (int) ($stagedEntry['outstanding_minor'] ?? 0);

        if ($hasDuesTable && !empty($stagedDueIds)) {
            $hasFlaggedDue = $db->table('qa_staged_dues')
                ->whereIn('id', $stagedDueIds)
                ->where(function ($q) {
                    $q->where('is_currency_unresolved', true)
                      ->orWhere('has_currency_conflict', true);
                })
                ->exists();
            if ($hasFlaggedDue) {
                $msg = "Recreation blocked: Historical MySkool staged due in {$stagedCurr} is flagged with a currency conflict or unresolved currency.";
                return [
                    'can_recreate' => false,
                    'block_category' => 'currency_conflict',
                    'status_code' => 'financial_conflict',
                    'reason' => $msg,
                    'recreate_block_reason' => $msg,
                    'paid_minor' => 0,
                    'outstanding_minor' => $stagedOutMinor,
                    'historical_currency' => $stagedCurr,
                ];
            }
        }

        return [
            'can_recreate' => true,
            'block_category' => null,
            'status_code' => 'previously_voided',
            'reason' => null,
            'recreate_block_reason' => null,
            'paid_minor' => 0,
            'outstanding_minor' => $stagedOutMinor,
            'historical_currency' => $stagedCurr,
        ];
    }

    /**
     * Safely corrects an incomplete imported invoice item to the verified full approved fee obligation,
     * preserving historical source data, existing payment allocations, and recording an audit trail.
     */
    public static function correctIncompleteObligation(
        $db,
        int $invoiceItemId,
        int $actorId,
        string $reason = 'Corrected incomplete historical obligation to approved fee plan'
    ): array {
        $item = $db->table('invoice_items')->where('id', $invoiceItemId)->first();
        if (!$item) {
            throw new \RuntimeException("Invoice item #{$invoiceItemId} not found.");
        }

        $student = $db->table('students')->where('id', $item->student_id)->first();
        $feeType = $db->table('fee_types')->where('id', $item->fee_type_id)->first();
        if (!$student || !$feeType) {
            throw new \RuntimeException("Student or fee type not found for invoice item #{$invoiceItemId}.");
        }

        $enrollment = !empty($item->enrollment_id)
            ? $db->table('enrollments')->where('id', $item->enrollment_id)->first()
            : $db->table('enrollments')->where('student_id', $item->student_id)->where('status', 'active')->first();
        $targetEntity = $enrollment ?: $student;

        $connName = $db->getName();
        $approvedFee = \App\Services\InvoiceService::resolveFeeAndCurrency($targetEntity, $feeType, $connName);
        $appAmount = (int) $approvedFee['amount_minor'];
        $appDiscount = (int) $approvedFee['discount_minor'];
        $appNet = (int) $approvedFee['net_minor'];
        $appCurrency = strtoupper(trim((string) $approvedFee['currency']));
        $itemCurrency = strtoupper(trim((string) ($item->currency ?: $appCurrency)));

        if ($appAmount <= 0) {
            throw new \RuntimeException("No approved fee plan found to correct invoice item #{$invoiceItemId}.");
        }

        if ($appCurrency !== $itemCurrency) {
            throw new \RuntimeException("Currency mismatch: cannot correct {$itemCurrency} item to {$appCurrency} without explicit currency migration.");
        }

        $currentNet = (int) $item->net_minor;
        $currentPaid = (int) $item->paid_minor;

        if ($currentNet >= $appNet) {
            return [
                'success' => false,
                'message' => "Invoice item #{$invoiceItemId} already represents a full or higher obligation ({$itemCurrency} " . number_format($currentNet / 100, 2) . ").",
                'item_id' => $invoiceItemId,
            ];
        }

        $now = now();
        $newStatus = ($currentPaid >= $appNet) ? 'paid' : ($currentPaid > 0 ? 'partial' : 'active');

        $db->table('invoice_items')->where('id', $invoiceItemId)->update([
            'amount_minor' => $appAmount,
            'discount_minor' => $appDiscount,
            'net_minor' => $appNet,
            'status' => $newStatus,
            'updated_at' => $now,
        ]);

        if ($db->getSchemaBuilder()->hasTable('fee_adjustments')) {
            $db->table('fee_adjustments')->insert([
                'invoice_item_id' => $invoiceItemId,
                'user_id' => $actorId,
                'kind' => 'adjustment',
                'amount_minor' => $appNet,
                'previous_amount_minor' => $currentNet,
                'new_amount_minor' => $appNet,
                'reason' => $reason,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($db->getSchemaBuilder()->hasTable('qa_historical_settlements')) {
            $settlement = $db->table('qa_historical_settlements')
                ->where('invoice_item_id', $invoiceItemId)
                ->where('status', 'applied')
                ->first();
            if ($settlement) {
                $db->table('qa_historical_settlements')->where('id', $settlement->id)->update([
                    'original_obligation_minor' => $appNet,
                    'remaining_obligation_minor' => max(0, $appNet - (int) $settlement->settled_amount_minor),
                    'reconciliation_classification' => ((int) $settlement->settled_amount_minor >= $appNet)
                        ? 'POST_CUTOFF_FULL_SETTLEMENT'
                        : 'POST_CUTOFF_PARTIAL_PAYMENT',
                    'guard_class' => ((int) $settlement->settled_amount_minor >= $appNet)
                        ? 'CANDIDATE_FULL_SETTLEMENT'
                        : 'CANDIDATE_PARTIAL_SETTLEMENT',
                    'updated_at' => $now,
                ]);
            }
        }

        $invSvc = app(\App\Services\InvoiceService::class);
        $invSvc->refreshInvoice((int) $item->invoice_id, $connName);

        return [
            'success' => true,
            'item_id' => $invoiceItemId,
            'invoice_id' => (int) $item->invoice_id,
            'previous_net_minor' => $currentNet,
            'new_net_minor' => $appNet,
            'paid_minor' => $currentPaid,
            'remaining_outstanding_minor' => max(0, $appNet - $currentPaid),
            'currency' => $appCurrency,
            'status' => $newStatus,
        ];
    }

    private function validateQaConnection(string $conn): void
    {
        if (config("database.connections.{$conn}.driver") === 'sqlite') {
            return;
        }

        DatabaseSafetyGuard::assertTargetDatabase($conn);
        DatabaseSafetyGuard::assertNotQuarantineDatabase($conn);
        DatabaseSafetyGuard::assertNotStagingDatabase($conn);
    }
}
