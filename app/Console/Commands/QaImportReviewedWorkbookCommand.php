<?php

namespace App\Console\Commands;

use App\Services\Migration\ReviewedWorkbookImportService;
use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Console\Command;
use Throwable;

class QaImportReviewedWorkbookCommand extends Command
{
    protected $signature = 'qa:import-reviewed-workbook
                            {path? : Absolute or relative path to the approved workbook XLSX}
                            {--preview : Preview workbook metrics and reconciliation without writing to database}
                            {--commit : Execute the import against iqra_billing}
                            {--conn=mysql : Database connection name}
                            {--qa-conn= : Legacy connection alias}
                            {--skip-hash : Skip SHA-256 verification (strictly for testing fictional fixtures)}';

    protected $description = 'Ingest the approved MySkool workbook (Students, Paid details, and Outstanding) into the portal database';

    public function handle(ReviewedWorkbookImportService $service): int
    {
        $this->line('');
        $this->info('================================================================');
        $this->info('  IQRA Virtual School — Approved MySkool Workbook Importer      ');
        $this->info('================================================================');

        $activeKey = config('imports.active_version', ReviewedWorkbookImportService::APPROVED_VERSION_KEY);
        $activeConfig = config("imports.versions.{$activeKey}", []);
        $defaultPath = base_path($activeConfig['relative_path'] ?? 'storage/app/imports/My skool fully updated (1).xlsx');
        $path = $this->argument('path') ?: $defaultPath;
        $conn = (string) ($this->option('conn') ?: ($this->option('qa-conn') ?: config('database.default', 'mysql')));
        $commit = (bool) $this->option('commit');
        $expectedHash = $this->option('skip-hash') ? null : ($activeConfig['expected_sha256'] ?? ReviewedWorkbookImportService::APPROVED_WORKBOOK_SHA256);

        $this->line('');
        $this->info("Active Import Version: {$activeKey} (" . ($activeConfig['label'] ?? 'Approved MySkool Workbook') . ")");
        $this->line("Target Workbook: {$path}");

        // Guard against disallowed legacy CSV inputs
        $disallowed = config('imports.disallowed_legacy_sources', []);
        $norm = str_replace('\\', '/', $path);
        foreach ($disallowed as $legacy) {
            if (str_ends_with($norm, $legacy) || basename($path) === basename($legacy)) {
                $this->error("UNSAFE SOURCE BLOCKED: Legacy CSV '{$path}' is archived and disallowed.");
                $this->error("The active authoritative source is '{$defaultPath}' (version: {$activeKey}).");
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
            if ($actualHash !== strtolower($expectedHash) && $actualHash !== strtolower(ReviewedWorkbookImportService::APPROVED_WORKBOOK_SHA256)) {
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
            if (config("database.connections.{$conn}.driver") === 'mysql') {
                DatabaseSafetyGuard::assertTargetDatabase($conn);
                $activeDb = DatabaseSafetyGuard::getActiveDatabaseName($conn);
                $this->info(" [OK] Target database verified: {$activeDb}");
            } else {
                $this->info(" [OK] Target database using SQLite test environment.");
            }
        } catch (Throwable $e) {
            $this->error("DATABASE SAFETY BREACH: " . $e->getMessage());
            return self::FAILURE;
        }

        // 3. Preview (Read-Only)
        $this->line('');
        $this->line("Reading workbook sheets and analyzing reconciliation (READ-ONLY)...");

        try {
            $preview = $service->preview($path, $expectedHash, $conn);
        } catch (Throwable $e) {
            $this->error("Preview failed: " . $e->getMessage());
            return self::FAILURE;
        }

        $reg = $preview['registration'];
        $col = $preview['collection'];
        $due = $preview['outstanding'];
        $sheets = $preview['sheets_resolved'] ?? [];

        $this->line('');
        $studentSheetName = $sheets['students'] ?? 'Students';
        $this->comment("Student Roster Reconciliation ({$studentSheetName}):");
        $this->table(
            ['Metric / Reconciliation Category', 'Count'],
            [
                ['Total Data Rows in Workbook', number_format($reg['total_rows'])],
                ['Shared GRs Matched with Current Baseline', number_format($reg['shared_grs_count'])],
                ['Newly Appearing GRs (To Add)', number_format($reg['new_grs_count'])],
                ['Current Students Absent from Workbook (To Hold)', number_format($reg['old_only_grs_count'])],
                ['Approved / Resolved Currencies', number_format($reg['approved_count'])],
                ['Blank / Unresolved Currency Rows', number_format($reg['blank_currency_count'])],
                ['School Department Registrations', number_format($reg['departments']['school'] ?? 0)],
                ['Tuition Department Registrations', number_format($reg['departments']['tuition'] ?? 0)],
                ['Zero Monthly Fee Enrollments', number_format($reg['zero_fee_count'] ?? 0)],
            ]
        );

        $this->line('');
        $this->comment('Student Registration Currencies:');
        $currRows = [];
        foreach ($reg['currencies'] as $curr => $cnt) {
            $currRows[] = [$curr, number_format($cnt)];
        }
        if ($reg['blank_currency_count'] > 0) {
            $currRows[] = ['BLANK / UNRESOLVED', number_format($reg['blank_currency_count'])];
        }
        $this->table(['Currency', 'Student Count'], $currRows);

        $colSheetName = $sheets['collections'] ?? 'Paid details';
        $dueSheetName = $sheets['outstanding'] ?? 'Outstanding (Compiled Valid)';

        $this->line('');
        $this->comment('Historical Financial Sheets (Preserved Without Ledger Posting):');
        $this->table(
            ['Worksheet', 'Data Rows', 'Unresolved Currencies', 'Ledger Invoices / Payments'],
            [
                [$colSheetName, number_format($col['total_rows']), number_format($col['unresolved_currency_rows']), '0 (Zero posting)'],
                [$dueSheetName, number_format($due['total_rows']), number_format($due['unresolved_currency_rows']), '0 (Zero posting)'],
            ]
        );

        $this->line('');
        $this->comment("Historical Collections Breakdown by Currency ({$colSheetName}):");
        $colCurrRows = [];
        foreach ($col['totals_by_currency'] as $curr => $total) {
            $count = $col['counts_by_currency'][$curr] ?? 0;
            $colCurrRows[] = [$curr, number_format($count), number_format($total, 2)];
        }
        $this->table(['Currency', 'Collection Lines', 'Total Amount'], $colCurrRows);

        $this->line('');
        $this->comment("Historical Outstanding Fees Breakdown by Currency ({$dueSheetName}):");
        $dueCurrRows = [];
        foreach ($due['totals_by_currency'] as $curr => $total) {
            $count = $due['counts_by_currency'][$curr] ?? 0;
            $dueCurrRows[] = [$curr, number_format($count), number_format($total, 2)];
        }
        $this->table(['Currency', 'Outstanding Lines', 'Total Balance'], $dueCurrRows);

        if (!$commit) {
            $this->line('');
            $this->warn('DRY RUN ONLY. No changes written to database.');
            $this->line('To execute the import against iqra_billing in a future approved phase, run with --commit:');
            $this->line("  php artisan qa:import-reviewed-workbook \"{$path}\" --commit");
            return self::SUCCESS;
        }

        // 4. Execute Commit
        $this->line('');
        $this->info("Executing import transaction into portal database...");

        try {
            $batchId = $isRevised ? 'BATCH-20261022-PORTAL-V2-REVISED' : (string) config('imports.active_batch_id', ReviewedWorkbookImportService::APPROVED_BATCH_ID);
            $stats = $service->import($path, $expectedHash, $conn, $batchId);
        } catch (Throwable $e) {
            $this->error("IMPORT TRANSACTION FAILED: " . $e->getMessage());
            return self::FAILURE;
        }

        $this->line('');
        $this->info("IMPORT SUCCESSFUL! Target Database State:");
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
                ['Historical Collections Staged', number_format($stats['collections_staged'])],
                ['Historical Dues Staged', number_format($stats['dues_staged'])],
                ['Staging Exceptions Logged', number_format($stats['exceptions_logged'])],
                [' - Unresolved Collection Currency Exceptions', number_format($stats['unresolved_collections'])],
                [' - Unresolved Dues Currency Exceptions', number_format($stats['unresolved_dues'])],
                ['Financial Ledger Invoices Generated', '0 (Strictly isolated)'],
                ['Financial Ledger Payments Generated', '0 (Strictly isolated)'],
                ['Financial Ledger Allocations Generated', '0 (Strictly isolated)'],
            ]
        );

        $this->line('');
        $this->info("Ingestion complete: {$stats['registration_rows']} students imported, {$stats['collections_staged']} collections staged, {$stats['dues_staged']} dues staged, and 0 ledger rows posted.");
        return self::SUCCESS;
    }
}

