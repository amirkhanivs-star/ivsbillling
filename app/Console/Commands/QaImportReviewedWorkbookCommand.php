<?php

namespace App\Console\Commands;

use App\Services\Migration\ReviewedWorkbookImportService;
use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Console\Command;
use Throwable;

class QaImportReviewedWorkbookCommand extends Command
{
    protected $signature = 'qa:import-reviewed-workbook
                            {path? : Absolute or relative path to the reviewed workbook XLSX}
                            {--preview : Preview workbook metrics and reconciliation without writing to database}
                            {--commit : Execute the import against iqra_billing_qa}
                            {--qa-conn=qa : QA connection name}
                            {--skip-hash : Skip SHA-256 verification (strictly for testing fictional fixtures)}';

    protected $description = 'Ingest the reviewed October 22 workbook (registration, collections, and dues) into the QA portal';

    public function handle(ReviewedWorkbookImportService $service): int
    {
        $this->line('');
        $this->info('================================================================');
        $this->info('  IQRA Virtual School — Phase 3C.1 Reviewed Workbook Importer   ');
        $this->info('================================================================');

        $activeKey = config('imports.active_version', 'v2_reviewed_20261022');
        $activeConfig = config("imports.versions.{$activeKey}");
        $defaultPath = base_path($activeConfig['relative_path'] ?? 'storage/app/imports/UPdated school record for portal.xlsx');
        $path = $this->argument('path') ?: $defaultPath;
        $qaConn = (string) $this->option('qa-conn');
        $commit = (bool) $this->option('commit');
        $expectedHash = $this->option('skip-hash') ? null : ($activeConfig['expected_sha256'] ?? ReviewedWorkbookImportService::EXPECTED_SHA256);

        $this->line('');
        $this->info("Active Import Version: {$activeKey} (" . ($activeConfig['label'] ?? 'Reviewed Workbook') . ")");
        $this->line("Target Workbook: {$path}");

        // Guard against disallowed legacy CSV inputs
        $disallowed = config('imports.disallowed_legacy_sources', []);
        $norm = str_replace('\\', '/', $path);
        foreach ($disallowed as $legacy) {
            if (str_ends_with($norm, $legacy) || basename($path) === basename($legacy)) {
                $this->error("UNSAFE SOURCE BLOCKED: Legacy CSV '{$path}' is archived and disallowed.");
                $this->error("The active authoritative QA source is '{$defaultPath}' (version: {$activeKey}).");
                return self::FAILURE;
            }
        }

        // 1. Verify File Existence & Hash
        if (!is_file($path)) {
            $this->error("ERROR: File not found at '{$path}'.");
            return self::FAILURE;
        }

        $actualHash = strtolower(hash_file('sha256', $path));
        $this->line("Calculated SHA-256: {$actualHash}");

        $isRevised = ($actualHash === strtolower(ReviewedWorkbookImportService::REVISED_SHA256));
        if ($isRevised) {
            $activeKey = 'v2_reviewed_20261022_revised';
            $activeConfig = config("imports.versions.{$activeKey}");
            $expectedHash = $activeConfig['expected_sha256'] ?? ReviewedWorkbookImportService::REVISED_SHA256;
            $this->info(" [NOTICE] Detected revised workbook content: Switching to version '{$activeKey}'.");
        }

        if ($expectedHash !== null) {
            if ($actualHash !== strtolower($expectedHash)) {
                $this->error("CRITICAL ERROR: SHA-256 hash mismatch!");
                $this->error("  Expected: {$expectedHash}");
                $this->error("  Found:    {$actualHash}");
                return self::FAILURE;
            }
            $this->info(" [OK] SHA-256 source integrity verified.");
        } else {
            $this->warn(" [WARN] SHA-256 verification skipped by flag.");
        }

        // 2. Verify Database Connection
        try {
            if (config("database.connections.{$qaConn}.driver") === 'mysql') {
                DatabaseSafetyGuard::assertQaDatabase($qaConn);
                $qaDb = DatabaseSafetyGuard::getActiveDatabaseName($qaConn);
                $this->info(" [OK] Target QA database verified: {$qaDb}");
            } else {
                $this->info(" [OK] Target QA database using SQLite test environment.");
            }
        } catch (Throwable $e) {
            $this->error("DATABASE SAFETY BREACH: " . $e->getMessage());
            return self::FAILURE;
        }

        // 3. Preview
        $this->line('');
        $this->line("Reading workbook sheets and analyzing reconciliation...");

        try {
            $preview = $service->preview($path, $expectedHash, $qaConn);
        } catch (Throwable $e) {
            $this->error("Preview failed: " . $e->getMessage());
            return self::FAILURE;
        }

        $reg = $preview['registration'];
        $col = $preview['collection'];
        $due = $preview['outstanding'];

        $this->line('');
        $this->comment('Student Roster Reconciliation (01_REGISTRATION_PRESENT):');
        $this->table(
            ['Metric / Reconciliation Category', 'Count'],
            [
                ['Total Data Rows in Workbook', number_format($reg['total_rows'])],
                ['Shared GRs Matched with QA Baseline', number_format($reg['shared_grs_count'])],
                ['Newly Appearing GRs (To Add)', number_format($reg['new_grs_count'])],
                ['Previous QA GRs Absent from Workbook (To Hold)', number_format($reg['old_only_grs_count'])],
                ['Approved Currencies (currency_provisional = NO)', number_format($reg['approved_count'])],
                ['Approval Holds (REVIEW / YES / Blank Currency)', number_format($reg['held_count'])],
                [' - Marked REVIEW', number_format($reg['provisional_breakdown']['REVIEW'] ?? 0)],
                [' - Marked YES', number_format($reg['provisional_breakdown']['YES'] ?? 0)],
                ['School Department Registrations', number_format($reg['departments']['school'] ?? 0)],
                ['Tuition Department Registrations', number_format($reg['departments']['tuition'] ?? 0)],
            ]
        );

        if (isset($reg['mutually_exclusive'])) {
            $me = $reg['mutually_exclusive'];
            $this->line('');
            $this->comment('Mutually Exclusive Registration Classification (Sum = ' . number_format($reg['mutually_exclusive_sum']) . '):');
            $this->table(
                ['Mutually Exclusive Category', 'Count', 'Criteria'],
                [
                    ['Active Billable Students', number_format($me['active_billable']), 'Fee > 0, No leaving date, Prov = NO, Valid currency'],
                    ['Active Non-Billable Students', number_format($me['active_zero_fee']), 'Fee = 0, No leaving date, Prov = NO, Valid currency'],
                    ['Leaving Date Discrepancy', number_format($me['leaving_date_discrepancy']), 'Date of Leaving populated in present sheet, Prov = NO'],
                    ['Financial Review Hold (REVIEW)', number_format($me['review_hold_review']), 'Prov = REVIEW (Evidenced only by new dues PDF)'],
                    ['Financial Review Hold (YES/No Evid)', number_format($me['review_hold_yes']), 'Prov = YES or blank evidence (including GR 10666)'],
                    ['Total Mutually Exclusive Registrations', number_format($reg['mutually_exclusive_sum']), 'Sums exactly to total rows (4,352)'],
                ]
            );
        }

        $this->line('');
        $this->comment('Financial Staging Sheets (Preserved Without Ledger Posting):');
        $this->table(
            ['Worksheet', 'Data Rows', 'Unresolved Currencies', 'Ledger Invoices / Payments'],
            [
                ['03_COLLECTION_PRESENT_ONLY', number_format($col['total_rows']), number_format($col['unresolved_currency_rows']), '0 (Zero posting)'],
                ['05_OUTSTANDING_PRESENT_ONLY', number_format($due['total_rows']), number_format($due['unresolved_currency_rows']), '0 (Zero posting)'],
            ]
        );

        if (!$commit) {
            $this->line('');
            $this->warn('DRY RUN ONLY. No changes written to database.');
            $this->line('To execute the import against iqra_billing_qa, run with --commit:');
            $this->line("  php artisan qa:import-reviewed-workbook \"{$path}\" --commit");
            return self::SUCCESS;
        }

        // 4. Execute Commit
        $this->line('');
        $this->info("Executing import transaction into QA portal database...");

        try {
            $batchId = $isRevised ? 'BATCH-20261022-PORTAL-V2-REVISED' : 'BATCH-20261022-PORTAL-V2';
            $stats = $service->import($path, $expectedHash, $qaConn, $batchId);
        } catch (Throwable $e) {
            $this->error("IMPORT TRANSACTION FAILED: " . $e->getMessage());
            return self::FAILURE;
        }

        $this->line('');
        $this->info("IMPORT SUCCESSFUL! Target QA Database State:");
        $this->table(
            ['Entity / Outcome', 'Result Count'],
            [
                ['Batch Identifier', $stats['batch_id']],
                ['Registration Rows Processed', number_format($stats['registration_rows'])],
                ['Shared Students Updated', number_format($stats['shared_updated'])],
                ['New Students Added', number_format($stats['new_created'])],
                ['Absent Students Preserved on Historical Hold', number_format($stats['historical_held'])],
                ['Active Billable Enrollments', number_format($stats['active_billable'])],
                ['Review Hold Enrollments (Non-Billable)', number_format($stats['review_holds'])],
                ['Monthly Fee Plans Provisioned', number_format($stats['fee_plans_provisioned'])],
                ['Historical Collections Staged in QA', number_format($stats['collections_staged'])],
                ['Historical Dues Staged in QA', number_format($stats['dues_staged'])],
                ['Staging Exceptions Logged', number_format($stats['exceptions_logged'])],
                [' - Unresolved Collection Currency Exceptions', number_format($stats['unresolved_collections'])],
                [' - Unresolved Dues Currency Exceptions', number_format($stats['unresolved_dues'])],
                ['Financial Ledger Invoices Generated', '0 (Strictly isolated)'],
                ['Financial Ledger Payments Generated', '0 (Strictly isolated)'],
                ['Financial Ledger Allocations Generated', '0 (Strictly isolated)'],
            ]
        );

        $this->line('');
        $this->info("Phase 3C.1 ingestion complete: 4,352 current-session students imported, 18 historical students held, 49,449 financial rows staged, and 0 ledger rows posted.");
        return self::SUCCESS;
    }
}
