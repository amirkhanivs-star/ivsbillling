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
        $conn = $conn ?: (config('database.default') === 'sqlite' ? 'sqlite' : 'qa');
        $this->validateQaConnection($conn);
        $db = DB::connection($conn);

        $batchId = $batchId ?: (string) config('imports.active_batch_id', 'BATCH-20261022-PORTAL-V2-REVISED');

        // Resolve dynamic department IDs (never hard-code IDs)
        $deptCodes = $db->table('departments')->pluck('id', 'code')->all();
        $schoolDeptId = (int) ($deptCodes['school'] ?? 1);
        $tuitionDeptId = (int) ($deptCodes['tuition'] ?? 2);

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

            // Check 7: Department Determination & Evidence
            $targetDeptId = self::determineDepartmentId($cls, $p, $schoolDeptId, $tuitionDeptId);
            if ($targetDeptId === null) {
                $excludedRows['ambiguous_department'][] = [
                    'source_row' => $due->source_row,
                    'gr_number' => $due->gr_number,
                    'class' => $cls,
                    'particular' => $p,
                    'reason' => 'Unable to unambiguously determine School vs Tuition department from source evidence',
                    'amount_minor' => $amtMinor,
                ];
                continue;
            }

            // Check 8: Match to student's verified enrollment in target department
            $studentEnrollments = $enrollments->get($student->id, collect());
            $matchedEnrollment = $studentEnrollments->first(fn($e) => (int) $e->department_id === $targetDeptId);

            if (!$matchedEnrollment) {
                $excludedRows['unmatched_enrollment'][] = [
                    'source_row' => $due->source_row,
                    'gr_number' => $due->gr_number,
                    'target_department_id' => $targetDeptId,
                    'target_department' => $targetDeptId === 2 ? 'Tuition' : 'School',
                    'student_enrolled_departments' => $studentEnrollments->pluck('department_id')->all(),
                    'reason' => 'Student has no active enrollment matching the target department',
                    'amount_minor' => $amtMinor,
                ];
                continue;
            }

            // Check 9: Currency Conflict between Enrollment and Dues
            if ($matchedEnrollment->currency && $matchedEnrollment->currency !== $curr) {
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
            $deptName = $deptId === 2 ? 'Tuition' : 'School';
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

        return [
            'status' => 'success',
            'batch_id' => $batchId,
            'summary' => [
                'total_staged_dues' => count($dues),
                'eligible_rows' => count($eligibleRows),
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
        $conn = $conn ?: (config('database.default') === 'sqlite' ? 'sqlite' : 'qa');
        $this->validateQaConnection($conn);

        // Security Lock: Only allowed if config authorizes it or explicit test flag is active
        if (!$allowTestExecution && !config('imports.allow_opening_balance_posting', false)) {
            throw new RuntimeException(
                'CRITICAL SAFETY STOP: Opening balance ledger posting is STRICTLY BLOCKED. ' .
                'The test-ledger reset decision and preview must be approved by the administrator before ledger mutation is authorized.'
            );
        }

        $batchId = $batchId ?: (string) config('imports.active_batch_id', 'BATCH-20261022-PORTAL-V2-REVISED');
        $preview = $this->preview($conn, $batchId);

        if ($preview['status'] === 'empty' || empty($preview['summary']['eligible_rows'])) {
            return [
                'status' => 'empty',
                'message' => 'No eligible opening balance records to post.',
                'posted_invoices' => 0,
                'posted_items' => 0,
            ];
        }

        $db = DB::connection($conn);

        return $db->transaction(function () use ($db, $conn, $batchId, $preview) {
            $now = now();
            $postedInvoicesCount = 0;
            $postedItemsCount = 0;
            $totalsPostedByCurrency = [];

            // Group invoices from preview
            $grouped = $this->buildInvoiceGroups($db, $batchId, $conn);

            // Fetch default fee type for historical opening balance
            $feeType = $db->table('fee_types')->where('code', 'monthly')->first();
            $feeTypeId = $feeType ? $feeType->id : 1;

            foreach ($grouped as $groupKey => $group) {
                // Check if any items in this group were already posted (source-line idempotency)
                $stagedDueIds = collect($group['items'])->pluck('source_staged_due_id')->all();
                $alreadyPostedCount = $db->table('qa_opening_balance_lineage')
                    ->whereIn('qa_staged_due_id', $stagedDueIds)
                    ->count();

                if ($alreadyPostedCount === count($stagedDueIds)) {
                    // Entire invoice was already posted in a prior run
                    continue;
                }

                // 1. Create Opening Balance Billing Run
                $userId = (int) ($db->table('users')->value('id') ?: 1);
                if (!$db->table('users')->where('id', $userId)->exists()) {
                    $userId = $db->table('users')->insertGetId([
                        'name' => 'System Migration',
                        'email' => 'system_migration@example.test',
                        'password' => bcrypt('secret'),
                        'role' => 'super_admin',
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $billingRunId = $db->table('billing_runs')->insertGetId([
                    'campus_id' => $group['campus_id'],
                    'department_id' => $group['department_id'],
                    'frequency' => 'opening_balance',
                    'period_key' => $group['period_key'],
                    'period_start' => $group['period_start'],
                    'period_end' => $group['period_end'],
                    'due_date' => $group['due_date'],
                    'fee_type_ids' => json_encode([$feeTypeId]),
                    'status' => 'generated',
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // 2. Generate unique opening balance invoice number
                $prefix = $group['department_id'] === 2 ? 'TUI-OB' : 'SCH-OB';
                $year = date('Y', strtotime($group['period_start']));
                $invNumber = sprintf('%s-%s-%07d', $prefix, $year, $billingRunId);

                // Ensure uniqueness
                $seq = 1;
                while ($db->table('invoices')->where('invoice_number', $invNumber)->exists()) {
                    $invNumber = sprintf('%s-%s-%07d-%d', $prefix, $year, $billingRunId, $seq++);
                }

                $invoiceId = $db->table('invoices')->insertGetId([
                    'billing_run_id' => $billingRunId,
                    'family_id' => $group['family_id'],
                    'campus_id' => $group['campus_id'],
                    'department_id' => $group['department_id'],
                    'currency' => $group['currency'],
                    'invoice_number' => $invNumber,
                    'issued_on' => $group['period_start'],
                    'due_date' => $group['due_date'],
                    'total_minor' => $group['total_minor'],
                    'paid_minor' => 0,
                    'previous_dues_snapshot_minor' => 0,
                    'status' => 'generated',
                    'approved_to_send' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $postedInvoicesCount++;

                // 3. Create invoice items and lineage
                foreach ($group['items'] as $item) {
                    // Check if specific source line was already posted
                    $exists = $db->table('qa_opening_balance_lineage')
                        ->where('qa_staged_due_id', $item['source_staged_due_id'])
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $itemId = $db->table('invoice_items')->insertGetId([
                        'invoice_id' => $invoiceId,
                        'student_id' => $item['student_id'],
                        'enrollment_id' => $item['enrollment_id'],
                        'fee_plan_item_id' => null,
                        'fee_type_id' => $feeTypeId,
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
        $tuitionDeptId = (int) ($deptCodes['tuition'] ?? 2);

        $students = $db->table('students')->get()->keyBy('gr_number');
        $enrollments = $db->table('enrollments')->get()->groupBy('student_id');

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

            $targetDeptId = self::determineDepartmentId($cls, $p, $schoolDeptId, $tuitionDeptId);
            if ($targetDeptId === null) {
                continue;
            }

            $studentEnrollments = $enrollments->get($student->id, collect());
            $matchedEnrollment = $studentEnrollments->first(fn($e) => (int) $e->department_id === $targetDeptId);

            if (!$matchedEnrollment || ($matchedEnrollment->currency && $matchedEnrollment->currency !== $curr)) {
                continue;
            }

            $deptId = (int) $matchedEnrollment->department_id;
            $campusId = (int) $matchedEnrollment->campus_id;
            $familyId = (int) $student->family_id;

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

    private function validateQaConnection(string $conn): void
    {
        if (config("database.connections.{$conn}.driver") === 'sqlite') {
            return;
        }

        DatabaseSafetyGuard::assertQaDatabase($conn);
        DatabaseSafetyGuard::assertNotQuarantineDatabase($conn);
        DatabaseSafetyGuard::assertNotStagingDatabase($conn);
    }
}
