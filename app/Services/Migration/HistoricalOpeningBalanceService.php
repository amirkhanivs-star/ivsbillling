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
    public function preview(?string $conn = null): array
    {
        $conn = $conn ?: (config('database.default') === 'sqlite' ? 'sqlite' : 'qa');
        $this->validateQaConnection($conn);
        $db = DB::connection($conn);

        // 1. Fetch all staged dues
        $dues = $db->table('qa_staged_dues')->orderBy('source_row')->get();
        if ($dues->isEmpty()) {
            return [
                'status' => 'empty',
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
                    'samples' => [],
                ],
                'sample_invoices' => [],
                'safety_status' => [
                    'ledger_posting_blocked' => true,
                    'posting_authorization_required' => true,
                    'message' => 'SAFEGUARD ACTIVE: Opening balance ledger posting is strictly stopped pending administrative verification and explicit authorization. No staged dues found in current test database.',
                ],
            ];
        }

        // 2. Preload active QA students and enrollments
        $students = $db->table('students as s')
            ->leftJoin('enrollments as e', 'e.student_id', '=', 's.id')
            ->select(
                's.id as student_id',
                's.gr_number',
                's.family_id',
                's.status as student_status',
                'e.id as enrollment_id',
                'e.campus_id',
                'e.department_id',
                'e.currency as enrollment_currency',
                'e.status as enrollment_status'
            )
            ->get()
            ->keyBy('gr_number');

        $eligibleRows = [];
        $excludedRows = [
            'unresolved_currency' => [],
            'review_hold_student' => [],
            'historical_hold_student' => [],
            'missing_student' => [],
            'invalid_amount' => [],
            'currency_mismatch' => [],
        ];

        $totalsByCurrency = [];
        $totalsByDepartment = [];
        $invoicesGrouped = [];

        foreach ($dues as $due) {
            $amtMinor = (int) $due->outstanding_amount_minor;
            $curr = strtoupper(trim((string) $due->currency));

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
                    'reason' => 'Unresolved currency',
                    'amount_minor' => $amtMinor,
                    'raw_currency' => $due->currency_original,
                ];
                continue;
            }

            // Check 3: Student Existence & Approval Status
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

            // Check 4: Review Hold / Financial Approval Hold
            if ($student->student_status === 'review_hold' || $student->enrollment_status === 'review_hold') {
                $excludedRows['review_hold_student'][] = [
                    'source_row' => $due->source_row,
                    'gr_number' => $due->gr_number,
                    'reason' => 'Student is on review hold (unapproved currency/identity)',
                    'amount_minor' => $amtMinor,
                    'currency' => $curr,
                ];
                continue;
            }

            // Check 5: Historical Hold
            if ($student->student_status === 'historical_hold' || $student->enrollment_status === 'historical_review') {
                $excludedRows['historical_hold_student'][] = [
                    'source_row' => $due->source_row,
                    'gr_number' => $due->gr_number,
                    'reason' => 'Student is on historical hold (absent from reviewed workbook)',
                    'amount_minor' => $amtMinor,
                    'currency' => $curr,
                ];
                continue;
            }

            // Check 6: Currency Conflict
            if ($due->has_currency_conflict || ($student->enrollment_currency && $student->enrollment_currency !== $curr)) {
                $excludedRows['currency_mismatch'][] = [
                    'source_row' => $due->source_row,
                    'gr_number' => $due->gr_number,
                    'reason' => "Dues currency '{$curr}' conflicts with student enrollment currency '{$student->enrollment_currency}'",
                    'amount_minor' => $amtMinor,
                ];
                continue;
            }

            // Parse Period & Fee Particular
            $period = self::parseParticularPeriod($due->fee_particular);

            // Grouping Key: family_id | department_id | campus_id | currency | period_key
            $deptId = $student->department_id ?: 1;
            $campusId = $student->campus_id ?: 1;
            $familyId = $student->family_id;
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
                'student_id' => $student->student_id,
                'enrollment_id' => $student->enrollment_id,
                'gr_number' => $due->gr_number,
                'fee_particular' => $due->fee_particular,
                'currency' => $curr,
                'amount_minor' => $amtMinor,
            ];

            // Aggregations
            $totalsByCurrency[$curr] = ($totalsByCurrency[$curr] ?? 0) + $amtMinor;
            $deptName = $deptId == 2 ? 'Tuition' : 'School';
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
                'total_minor' => $minor,
                'total_decimal' => number_format($minor / 100, 2, '.', ''),
            ];
        }
        foreach ($invoicesGrouped as $inv) {
            $formattedCurrencyTotals[$inv['currency']]['lines'] += $inv['items_count'];
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
     * Parse fee particular string into standard billing period dates.
     * E.g. 'Monthly Fee - Oct-2026', 'Tution Fee Sep 2026', 'Monthly Fee - Dec-2025'
     */
    public static function parseParticularPeriod(string $particular): array
    {
        $months = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
            'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        ];

        // Search for Month-Year pattern like Oct-2026 or Sep 2026
        if (preg_match('/(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*[\s\-_]+(20\d\d)/i', $particular, $m)) {
            $monthNum = $months[strtolower(substr($m[1], 0, 3))];
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

        // Fallback for non-monthly opening balances
        return [
            'period_key' => 'opening_balance:historical',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'due_date' => '2026-09-25',
        ];
    }

    /**
     * Guarded posting execution.
     * Throws an exception unless posting is explicitly authorized via config.
     */
    public function postOpeningBalances(?string $conn = null): array
    {
        if (!config('imports.allow_opening_balance_posting', false)) {
            throw new RuntimeException(
                'CRITICAL SAFETY STOP: Opening balance ledger posting is STRICTLY BLOCKED. ' .
                'A complete preview must be inspected and approved by finance before ledger mutation is authorized.'
            );
        }

        throw new RuntimeException('Ledger posting authorization disabled.');
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
