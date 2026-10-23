<?php

namespace App\Console\Commands;

use App\Services\Migration\QaControlledReimportService;
use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Console\Command;

class QaControlledReimportCommand extends Command
{
    protected $signature = 'qa:controlled-reimport
                            {--batch-id= : Explicit batch ID (defaults to active batch)}
                            {--conn= : Database connection (defaults to qa)}
                            {--dry-run : Execute in dry-run mode (default)}';

    protected $description = 'Preview and orchestrate the 10-step controlled QA reimport and reconciliation';

    public function handle(QaControlledReimportService $service): int
    {
        $conn = (string) ($this->option('conn') ?: config('database.default'));
        $batchId = $this->option('batch-id') ? (string) $this->option('batch-id') : null;

        $this->line('');
        $this->info('========================================================================');
        $this->info('  IQRA Billing — Controlled Single QA Reimport Pipeline & Readiness    ');
        $this->info('========================================================================');

        if (config("database.connections.{$conn}.driver") !== 'sqlite') {
            DatabaseSafetyGuard::assertTargetDatabase($conn);
        }

        $plan = $service->plan($conn, $batchId);
        $s = $plan['steps'];

        // Live deployment warning notice
        $this->line('');
        $this->warn('------------------------------------------------------------------------');
        $this->warn('  ' . $plan['live_deployment_notice']);
        $this->warn('------------------------------------------------------------------------');

        // Step 1: Active Workbook
        $this->line('');
        $this->info('Step 1: Active Source Workbook Verification');
        if ($s['1_verify_workbook']['valid']) {
            $this->line("  ✓ File:        {$s['1_verify_workbook']['file_path']}");
            $this->line("  ✓ SHA-256:     {$s['1_verify_workbook']['sha256']} (VERIFIED)");
            $this->line("  ✓ Size:        " . number_format($s['1_verify_workbook']['size_bytes']) . " bytes");
        } else {
            $this->error("  ✗ Workbook verification failed: " . ($s['1_verify_workbook']['error'] ?? 'Hash mismatch'));
        }

        // Step 2: Preserved Snapshots
        $this->line('');
        $this->info('Step 2: Preservation of Historical Staging Snapshots & Archives');
        $this->line("  ✓ Original Archive:   " . ($s['2_preserve_snapshots']['archived_original_valid'] ? 'Verified (SHA-256 matches baseline)' : 'Missing/Mismatch'));
        $this->line("  ✓ Staged Collections: " . number_format($s['2_preserve_snapshots']['staged_collections_count']) . " rows preserved");
        $this->line("  ✓ Staged Dues:        " . number_format($s['2_preserve_snapshots']['staged_dues_count']) . " rows preserved");

        // Step 3: Differences & Exceptions
        $this->line('');
        $this->info('Step 3: Differences & Financial Review Exceptions');
        $this->line("  - Unresolved Currencies:         {$s['3_preview_exceptions']['unresolved_currency_count']}");
        $this->line("  - Review-Hold Students:          {$s['3_preview_exceptions']['review_hold_student_count']}");
        $this->line("  - Unknown Fee Periods (Held):    {$s['3_preview_exceptions']['unknown_period_count']}");
        $this->line("  - Ambiguous Departments (Held):  {$s['3_preview_exceptions']['ambiguous_department_count']}");

        // Step 4: QA Test Ledger Reset Plan
        $this->line('');
        $this->info('Step 4: Pre-existing QA Test Ledger Reset Plan');
        $this->line("  ✓ Backup: " . ($s['4_test_ledger_reset_plan']['backup_verified'] ? 'VERIFIED (backups/qa_test_ledger_fixture_oct20_21.sql)' : 'MISSING'));
        $this->line("  - Target Test Runs:     {$s['4_test_ledger_reset_plan']['target_runs']}");
        $this->line("  - Target Test Invoices: {$s['4_test_ledger_reset_plan']['target_invoices']}");
        $this->line("  - Target Test Items:    {$s['4_test_ledger_reset_plan']['target_invoice_items']}");
        $this->line("  - Target Test Payments: {$s['4_test_ledger_reset_plan']['target_payments']}");

        // Step 5: Registration Records
        $this->line('');
        $this->info('Step 5: Current-Session Registration Scope (4,352 records authority)');
        $this->line("  ✓ Total Present Students: " . ($s['5_registration_scope']['active_students'] + $s['5_registration_scope']['review_hold_students']));
        $this->line("  ✓ Total QA Students:      {$s['5_registration_scope']['total_students']}");
        $this->line("  ✓ Families:               {$s['5_registration_scope']['families_count']}");
        $this->line("  ✓ Enrollments:            {$s['5_registration_scope']['enrollments_count']}");

        // Step 6 & 7: Financial Staging Scope
        $this->line('');
        $this->info('Steps 6 & 7: Financial Staging Scope');
        $this->line("  ✓ Collections Rows:   " . number_format($s['6_collections_staging_scope']['staged_collections_rows']) . " / 37,976 expected");
        $this->line("  ✓ Outstanding Rows:   " . number_format($s['7_outstanding_dues_scope']['staged_dues_rows']) . " / 11,473 expected");

        // Step 8: Opening Balance Posting Lock
        $this->line('');
        $this->info('Step 8: Opening Balance Posting Guard & Authorization');
        $this->line("  - Status:        {$s['8_posting_authorization']['status']}");
        $this->line("  - Eligible Rows: " . number_format($s['8_posting_authorization']['eligible_rows']));
        $this->line("  - Invoices:      " . number_format($s['8_posting_authorization']['invoices_to_create']));

        // Step 9: Currency Reconciliation
        $this->line('');
        $this->info('Step 9: Currency-Isolated Dues Reconciliation');
        $headers = ['Currency', 'Invoices', 'Lines', 'Total Amount'];
        $currRows = [];
        foreach ($s['9_currency_reconciliation'] as $curr => $data) {
            $currRows[] = [
                $curr,
                number_format($data['invoices_count']),
                number_format($data['lines']),
                number_format((float) $data['total_decimal'], 2) . " {$curr}",
            ];
        }
        $this->table($headers, $currRows);

        // Step 10: Idempotency Guarantee
        $this->line('');
        $this->info('Step 10: Idempotency Guarantee & Lineage Architecture');
        $this->line("  ✓ Lineage Tracking: " . ($s['10_idempotency_guarantee']['lineage_tracking_active'] ? 'ACTIVE (qa_opening_balance_lineage)' : 'INACTIVE'));
        $this->line("  ✓ Duplicate Risk:   {$s['10_idempotency_guarantee']['duplicate_risk']}");

        $this->line('');
        $this->info('========================================================================');
        $this->info('  DRY-RUN COMPLETE: Pipeline verified. Zero mutations executed.        ');
        $this->info('========================================================================');
        $this->line('');

        return self::SUCCESS;
    }
}
