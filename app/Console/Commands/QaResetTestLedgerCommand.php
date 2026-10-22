<?php

namespace App\Console\Commands;

use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class QaResetTestLedgerCommand extends Command
{
    protected $signature = 'qa:reset-test-ledger
                            {--force : Authorize and execute destructive QA test ledger reset}
                            {--conn= : Database connection (defaults to qa)}';

    protected $description = 'Safely reset the pre-existing Phase 3C.0 test fixtures in iqra_billing_qa after backup verification';

    public const BACKUP_PATH = 'backups/qa_test_ledger_fixture_oct20_21.sql';
    public const EXPECTED_SHA256 = '8b281db611e9f1b6e42bdb3273a0e9c04e445a0c2caa85142e6dab515e32084f';

    public function handle(): int
    {
        $conn = (string) ($this->option('conn') ?: config('database.default'));

        $this->line('');
        $this->info('========================================================================');
        $this->info('  IQRA Billing — QA Test Ledger Fixture Cleanup Plan & Tool            ');
        $this->info('========================================================================');

        // 1. Strict Physical QA Database Guard
        if (config("database.connections.{$conn}.driver") !== 'sqlite') {
            DatabaseSafetyGuard::assertQaDatabase($conn);
            DatabaseSafetyGuard::assertNotQuarantineDatabase($conn);
            DatabaseSafetyGuard::assertNotStagingDatabase($conn);
        }

        $db = DB::connection($conn);

        // 2. Backup Integrity Verification
        $fullBackupPath = base_path(self::BACKUP_PATH);
        if (!file_exists($fullBackupPath)) {
            $this->error("CRITICAL SAFETY STOP: Backup file not found at " . self::BACKUP_PATH);
            return self::FAILURE;
        }

        $actualHash = hash_file('sha256', $fullBackupPath);
        if ($actualHash !== self::EXPECTED_SHA256) {
            $this->error("CRITICAL SAFETY STOP: Backup hash mismatch!");
            $this->error("Expected: " . self::EXPECTED_SHA256);
            $this->error("Actual:   " . $actualHash);
            return self::FAILURE;
        }

        $this->info("✓ Physical Backup Verified: " . self::BACKUP_PATH);
        $this->info("✓ Backup Size: " . number_format(filesize($fullBackupPath)) . " bytes (SHA256 verified)");

        // 3. Inspect Pre-existing Fixtures
        $runs = $db->table('billing_runs')->where('created_at', '<', '2026-10-22')->count();
        $invoices = $db->table('invoices')->where('created_at', '<', '2026-10-22')->count();
        $items = $db->table('invoice_items')->where('created_at', '<', '2026-10-22')->count();
        $payments = $db->table('payments')->where('created_at', '<', '2026-10-22')->count();
        $allocations = $db->table('payment_allocations')->where('created_at', '<', '2026-10-22')->count();

        $this->line('');
        $this->info('Target Pre-existing Test Records to Reset:');
        $this->line("  - Payment Allocations: {$allocations}");
        $this->line("  - Payments:            {$payments}");
        $this->line("  - Invoice Items:       {$items}");
        $this->line("  - Invoices:            {$invoices}");
        $this->line("  - Billing Runs:        {$runs}");

        // 4. Force Check
        if (!$this->option('force')) {
            $this->line('');
            $this->warn('========================================================================');
            $this->warn('  DRY-RUN / AUDIT MODE COMPLETE: Zero records were modified or deleted. ');
            $this->warn('  To execute actual deletion, explicit administrative approval is required.');
            $this->warn('  Command to run when authorized: php artisan qa:reset-test-ledger --force');
            $this->warn('========================================================================');
            $this->line('');
            return self::SUCCESS;
        }

        // 5. Execution under confirmation
        $this->warn('Executing destructive test-ledger reset...');
        $db->transaction(function () use ($db) {
            $db->statement('SET FOREIGN_KEY_CHECKS=0;');
            $db->table('payment_allocations')->where('created_at', '<', '2026-10-22')->delete();
            $db->table('payments')->where('created_at', '<', '2026-10-22')->delete();
            $db->table('invoice_items')->where('created_at', '<', '2026-10-22')->delete();
            $db->table('invoices')->where('created_at', '<', '2026-10-22')->delete();
            $db->table('billing_runs')->where('created_at', '<', '2026-10-22')->delete();
            $db->statement('SET FOREIGN_KEY_CHECKS=1;');
        });

        $this->info('✓ Pre-existing QA test ledger fixtures successfully reset.');
        return self::SUCCESS;
    }
}
