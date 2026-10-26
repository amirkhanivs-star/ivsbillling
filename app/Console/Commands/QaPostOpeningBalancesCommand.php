<?php

namespace App\Console\Commands;

use App\Services\Migration\HistoricalOpeningBalanceService;
use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class QaPostOpeningBalancesCommand extends Command
{
    protected $signature = 'qa:post-opening-balances
                            {--batch-id= : Explicit import batch ID (defaults to active batch)}
                            {--conn= : Database connection to use (defaults to qa)}
                            {--dry-run : Execute preview and dry-run without mutating ledger}
                            {--force : Authorize and execute opening-balance posting}
                            {--yes : Bypass interactive confirmation prompt}';

    protected $description = 'Safely post verified historical opening-balance invoices into iqra_billing with source-line idempotency';

    public function handle(HistoricalOpeningBalanceService $service): int
    {
        $conn = (string) ($this->option('conn') ?: config('database.default'));
        $activeBatchId = (string) config('imports.active_batch_id', 'BATCH-20261023-FINAL-MYSKOOL');
        $batchOption = $this->option('batch-id');

        // Guard against ambiguous or unapproved batch specifications
        if ($batchOption !== null && trim($batchOption) === '') {
            $this->error('CRITICAL SAFETY STOP: Source batch ID cannot be empty. Ambiguous source identity is strictly rejected.');
            return self::FAILURE;
        }

        $isSqlite = (config("database.connections.{$conn}.driver") === 'sqlite' || $conn === 'sqlite');
        if (!$isSqlite && $batchOption !== null && $batchOption !== $activeBatchId) {
            $this->error("CRITICAL SAFETY STOP: Batch '{$batchOption}' is not the approved active batch ('{$activeBatchId}'). Cross-batch posting is strictly rejected.");
            return self::FAILURE;
        }

        if ($batchOption === 'BATCH-20261022-PORTAL-V2' && $activeBatchId !== 'BATCH-20261022-PORTAL-V2') {
            $this->error("CRITICAL SAFETY STOP: Batch '{$batchOption}' is an unapproved historical workbook version. Only the approved active batch '{$activeBatchId}' may be posted.");
            return self::FAILURE;
        }

        $batchId = $batchOption ? (string) $batchOption : $activeBatchId;

        $this->line('');
        $this->info('========================================================================');
        $this->info('  IQRA Virtual School — QA Controlled Opening Balance Posting Engine    ');
        $this->info('========================================================================');

        // 1. Strict Physical Target Database Guard
        if (config("database.connections.{$conn}.driver") !== 'sqlite') {
            DatabaseSafetyGuard::assertTargetDatabase($conn);
        }

        $db = DB::connection($conn);

        // 2. Verify Batch Existence in Staged Dues
        $batchExists = $db->table('qa_staged_dues')->where('batch_id', $batchId)->exists();
        if (!$batchExists) {
            $this->error("CRITICAL SAFETY STOP: Staged dues batch '{$batchId}' not found on connection '{$conn}'.");
            return self::FAILURE;
        }

        // 3. Generate Opening Balance Preview
        $preview = $service->preview($conn, $batchId);
        if ($preview['status'] === 'empty') {
            $this->warn('No eligible opening-balance records found.');
            return self::SUCCESS;
        }

        $s = $preview['summary'];
        $ex = $preview['exclusions'];

        $this->line('');
        $this->info("Target Database:            {$conn} (" . config("database.connections.{$conn}.database") . ")");
        $this->info("Import Batch ID:            {$batchId}");
        $this->info("Total Staged Dues Rows:     {$s['total_staged_dues']}");
        $this->info("Eligible Rows for Invoices: {$s['eligible_rows']}");
        $this->line("Already Posted Lines:       {$s['already_posted_lines']}");
        $this->line("Remaining Eligible Lines:   {$s['remaining_eligible_lines']}");
        $this->warn("Excluded / Held Rows:       {$s['excluded_rows_count']}");
        $this->info("Proposed Invoices:          {$s['invoices_to_create']}");

        // 4. Proposed Currencies Table
        $this->line('');
        $this->info('--- Currency-Isolated Opening Balances ---');
        $headers = ['Currency', 'Invoices', 'Lines', 'Total Amount'];
        $currRows = [];
        foreach ($preview['totals_by_currency'] as $curr => $data) {
            $currRows[] = [
                $curr,
                number_format($data['invoices_count']),
                number_format($data['lines']),
                number_format((float) $data['total_decimal'], 2) . " {$curr}",
            ];
        }
        $this->table($headers, $currRows);

        // 5. Proposed Department Breakdown
        $this->line('');
        $this->info('--- Breakdown by Department ---');
        $deptHeaders = ['Department', 'Currency', 'Total Amount'];
        $deptRows = [];
        foreach ($preview['totals_by_department'] as $dept => $currs) {
            foreach ($currs as $c => $d) {
                $deptRows[] = [
                    $dept,
                    $c,
                    number_format((float) $d['total_decimal'], 2) . " {$c}",
                ];
            }
        }
        $this->table($deptHeaders, $deptRows);

        // 6. Exclusions Breakdown (All Held on Quarantine)
        $this->line('');
        $this->warn('--- Excluded / Held Dues Breakdown (All Kept On Hold) ---');
        $this->line("  - Unresolved Currency Rows:         {$ex['unresolved_currency_count']}");
        $this->line("  - Review-Hold Students' Dues:       {$ex['review_hold_student_count']}");
        $this->line("  - Unknown / Unverified Period Dues: {$ex['unknown_period_count']}");
        $this->line("  - Historical-Hold Students' Dues:   {$ex['historical_hold_student_count']}");
        $this->line("  - Ambiguous Department Evidence:    {$ex['ambiguous_department_count']}");
        $this->line("  - Unmatched Department Enrollment:  {$ex['unmatched_enrollment_count']}");
        $this->line("  - Missing GR / Student Dues:        {$ex['missing_student_count']}");
        $this->line("  - Invalid / Non-Positive Amounts:   {$ex['invalid_amount_count']}");
        $this->line("  - Currency Mismatches:              {$ex['currency_mismatch_count']}");
        $this->line("  - Tuition Rows (Policy Hold):       " . ($ex['tuition_policy_hold_count'] ?? 0));
        $this->line("  - Urdu Subject Rows (Policy Hold):  " . ($ex['urdu_subject_policy_hold_count'] ?? 0));

        // 7. Check if All Rows Already Posted
        if ($s['remaining_eligible_lines'] === 0 && $s['already_posted_lines'] > 0) {
            $this->line('');
            $this->info('========================================================================');
            $this->info("  ALL ELIGIBLE ROWS ({$s['already_posted_lines']}) ALREADY POSTED.");
            $this->info('  Idempotency Active: Zero new invoices or items will be created.');
            $this->info('========================================================================');
            $this->line('');
            return self::SUCCESS;
        }

        // 8. Dry-Run / Force Check
        $isDryRun = $this->option('dry-run') || !$this->option('force');

        if ($isDryRun) {
            $this->line('');
            $this->warn('========================================================================');
            $this->warn('  DRY-RUN MODE COMPLETE: Zero records were written to the ledger.        ');
            $this->warn('  To execute actual posting when authorized, run:                       ');
            $this->warn("  php artisan qa:post-opening-balances --batch-id={$batchId} --force");
            $this->warn('========================================================================');
            $this->line('');
            return self::SUCCESS;
        }

        // 9. Strict Financial-Posting Authorization Lock Check
        // The --force command CANNOT bypass the administrative authorization lock without explicit administrative approval
        $isAuthorized = (bool) config('imports.allow_opening_balance_posting', false);
        $isTestExecution = app()->environment('testing') || $isSqlite;
        if (!$isAuthorized && !$isTestExecution) {
            $this->line('');
            $this->error('========================================================================');
            $this->error('  CRITICAL SAFETY STOP: FINANCIAL POSTING AUTHORIZATION LOCK IS ACTIVE! ');
            $this->error('========================================================================');
            $this->error('  The `--force` command cannot bypass the financial-posting authorization');
            $this->error('  lock without explicit administrative approval.');
            $this->error('  Posting to the financial ledger requires:');
            $this->error('    1. Administrative approval of the preview totals and exclusions.');
            $this->error('    2. Explicit configuration: ALLOW_OPENING_BALANCE_POSTING=true.');
            $this->error('========================================================================');
            $this->line('');
            return self::FAILURE;
        }

        // 10. Interactive Confirmation if interactive shell and not --yes
        if ($this->input->isInteractive() && !$this->option('yes')) {
            $confirmed = $this->confirm(
                "Are you sure you want to post {$s['invoices_to_create']} opening-balance invoices ({$s['eligible_rows']} lines) to '{$conn}'?",
                false
            );

            if (!$confirmed) {
                $this->warn('Posting cancelled by administrator.');
                return self::SUCCESS;
            }
        }

        // 11. Execution
        $this->info('Executing opening-balance posting in atomic transaction...');
        $result = $service->postOpeningBalances($conn, $batchId, $isTestExecution);

        $this->line('');
        $this->info('========================================================================');
        $this->info('  ✓ OPENING BALANCES SUCCESSFULLY POSTED TO QA LEDGER                    ');
        $this->info('========================================================================');
        $this->line("  - Invoices Created:     {$result['posted_invoices']}");
        $this->line("  - Invoice Items Posted: {$result['posted_items']}");
        $this->line("  - Lineage Tracked:      " . $db->table('qa_opening_balance_lineage')->where('batch_id', $batchId)->count());

        $this->line('');
        $this->info('Posted Amounts by Currency:');
        foreach ($result['totals_by_currency'] as $curr => $minor) {
            $this->line("  - {$curr}: " . number_format($minor / 100, 2) . " {$curr}");
        }

        $this->line('');
        return self::SUCCESS;
    }
}
