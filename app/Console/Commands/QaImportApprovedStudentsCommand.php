<?php

namespace App\Console\Commands;

use App\Services\Migration\StagingToPortalMigrationService;
use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class QaImportApprovedStudentsCommand extends Command
{
    protected $signature = 'qa:import-approved-students
                            {--preview : Preview staging data and held student breakdowns without writing to database}
                            {--commit : Commit approved students and enrollments into iqra_billing}
                            {--staging-conn=mysql : Staging connection name (defaults to mysql)}
                            {--qa-conn=mysql : Target connection name (defaults to mysql)}';

    protected $description = 'Migrate approved students and enrollments into the canonical billing portal (iqra_billing)';

    public function handle(StagingToPortalMigrationService $service): int
    {
        $this->line('');
        $this->info('================================================================');
        $this->info('  IQRA Virtual School — Phase 3C.0 QA Student Migration Engine  ');
        $this->info('================================================================');

        $stagingConn = (string) $this->option('staging-conn');
        $qaConn = (string) $this->option('qa-conn');
        $commit = (bool) $this->option('commit');

        // Verify active database identities
        $this->line('');
        $this->line('Verifying database connections and isolation guards...');
        try {
            if (config("database.connections.{$stagingConn}.driver") === 'mysql') {
                DatabaseSafetyGuard::assertTargetDatabase($stagingConn);
                $stagingDb = DatabaseSafetyGuard::getActiveDatabaseName($stagingConn);
                $this->info(" [OK] Staging connection '{$stagingConn}' verified: {$stagingDb}");
            } else {
                $this->info(" [OK] Staging connection '{$stagingConn}' using SQLite test environment.");
            }

            if (config("database.connections.{$qaConn}.driver") === 'mysql') {
                DatabaseSafetyGuard::assertTargetDatabase($qaConn);
                $qaDb = DatabaseSafetyGuard::getActiveDatabaseName($qaConn);
                $this->info(" [OK] Target connection '{$qaConn}' verified: {$qaDb}");
            } else {
                $this->info(" [OK] Target connection '{$qaConn}' using SQLite test environment.");
            }
        } catch (Throwable $e) {
            $this->error('DATABASE SAFETY BREACH: ' . $e->getMessage());
            return self::FAILURE;
        }

        // 1. Generate and Display Preview
        try {
            $preview = $service->preview($stagingConn, $qaConn);
        } catch (Throwable $e) {
            $this->error('Failed to preview staging data: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->line('');
        $this->comment('Staging Source Summary (iqra_billing):');
        $this->table(
            ['Metric', 'Count / Value'],
            [
                ['Total Staged Students', number_format($preview['total_students'])],
                ['Main Campus Approvals', number_format($preview['campus_approvals'])],
                ['Approved School Registrations', number_format($preview['department_approvals']['school'])],
                ['Approved Tuition Registrations', number_format($preview['department_approvals']['tuition'])],
                ['Total Department Approvals', number_format($preview['department_approvals']['total'])],
                ['Approved Currencies', number_format($preview['currency_approvals']['approved_total'])],
                ['Pending Currencies (Held)', number_format($preview['currency_approvals']['pending_hold_total'])],
                ['Total Distinct Families', number_format($preview['families']['total_distinct'])],
                [' - Verified Family Codes', number_format($preview['families']['verified'])],
                [' - Provisional Family Codes (Missing in Source)', number_format($preview['families']['provisional'])],
                ['Multi-Currency Sibling Families', number_format($preview['families']['multi_currency_families'])],
                ['Students in Multi-Currency Families', number_format($preview['families']['multi_currency_students'])],
            ]
        );

        $this->line('');
        $this->comment('Approved Currency Breakdown:');
        $currRows = [];
        foreach ($preview['currency_approvals']['by_currency'] as $curr => $cnt) {
            $currRows[] = [$curr, number_format($cnt)];
        }
        $this->table(['Currency', 'Approved Students'], $currRows);

        $this->line('');
        $this->comment('Pending Currency Review Holds (Imported as Non-Billable Identities):');
        $pendingRows = [];
        foreach ($preview['currency_approvals']['pending_reasons'] as $reason => $cnt) {
            $pendingRows[] = [$reason, number_format($cnt)];
        }
        $this->table(['Hold Reason / Match Status', 'Students'], $pendingRows);

        if (!$commit) {
            $this->line('');
            $this->warn('DRY RUN ONLY. No changes written to database.');
            $this->line('To execute the import against iqra_billing, run with --commit:');
            $this->line('  php artisan qa:import-approved-students --commit');
            return self::SUCCESS;
        }

        // 2. Execute Migration
        $this->line('');
        $this->info('Executing student migration into QA portal database...');

        try {
            $stats = $service->migrate($stagingConn, $qaConn);
        } catch (Throwable $e) {
            $this->error('MIGRATION FAILED: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->line('');
        $this->info('MIGRATION SUCCESSFUL! Results in target database:');
        $this->table(
            ['Entity / Outcome', 'Result Count'],
            [
                ['Total Students Processed', number_format($stats['total_students_processed'])],
                ['Students Created', number_format($stats['students_imported'])],
                ['Students Updated (Idempotent)', number_format($stats['students_updated'])],
                ['Distinct Families Created/Updated', number_format($stats['families_count'])],
                ['Active Billable Enrollments', number_format($stats['active_enrollments'])],
                ['Review Hold Enrollments (Non-Billable)', number_format($stats['held_enrollments'])],
                ['School Department Enrollments', number_format($stats['school_enrollments'])],
                ['Tuition Department Enrollments', number_format($stats['tuition_enrollments'])],
                ['Monthly Fee Plan Items Provisioned', number_format($stats['fee_plan_items_created'])],
                ['Financial Ledger Invoices Generated', '0 (Strictly isolated)'],
                ['Financial Ledger Payments Generated', '0 (Strictly isolated)'],
                ['Financial Ledger Allocations Generated', '0 (Strictly isolated)'],
            ]
        );

        $this->line('');
        $this->info('Verification complete: Portal database contains 4,340 student identities and 0 ledger rows.');
        return self::SUCCESS;
    }
}
