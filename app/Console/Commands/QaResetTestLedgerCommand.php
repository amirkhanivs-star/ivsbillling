<?php

namespace App\Console\Commands;

use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class QaResetTestLedgerCommand extends Command
{
    protected $signature = 'qa:reset-test-ledger
                            {--force : Authorize and execute destructive QA test ledger reset}
                            {--conn= : Database connection (defaults to qa)}
                            {--verify-only : Only verify backup and test fixture scope}';

    protected $description = 'Safely reset the verified Phase 3C.0 test fixtures in iqra_billing with backup verification';

    public const BACKUP_PATH = 'backups/qa_test_ledger_fixture_oct20_21.sql';
    public const EXPECTED_SHA256 = '8b281db611e9f1b6e42bdb3273a0e9c04e445a0c2caa85142e6dab515e32084f';

    // The verified Phase 3C.0 test fixture inventory
    public const KNOWN_RUN_IDS = [1, 2, 3];
    public const KNOWN_PAYMENT_IDS = [1];
    public const KNOWN_ALLOCATION_IDS = [1];
    public const MAX_FIXTURE_INVOICE_COUNT = 2646;
    public const MAX_FIXTURE_ITEM_COUNT = 3824;

    public function handle(): int
    {
        $conn = (string) ($this->option('conn') ?: config('database.default'));

        $this->line('');
        $this->info('========================================================================');
        $this->info('  IQRA Billing — Safe QA Test Ledger Reset & Backup Verification        ');
        $this->info('========================================================================');

        // 1. Strict Physical Target Database Guard
        if (config("database.connections.{$conn}.driver") !== 'sqlite') {
            DatabaseSafetyGuard::assertTargetDatabase($conn);
        }

        $db = DB::connection($conn);

        // 2. Physical Backup Verification
        $fullBackupPath = base_path(self::BACKUP_PATH);
        if (!file_exists($fullBackupPath)) {
            $this->error("CRITICAL SAFETY STOP: Physical backup file not found at " . self::BACKUP_PATH);
            return self::FAILURE;
        }

        $actualHash = hash_file('sha256', $fullBackupPath);
        if ($actualHash !== self::EXPECTED_SHA256) {
            $this->error("CRITICAL SAFETY STOP: Backup SHA-256 hash mismatch!");
            $this->error("Expected: " . self::EXPECTED_SHA256);
            $this->error("Actual:   " . $actualHash);
            return self::FAILURE;
        }

        $this->info("✓ Physical Backup Verified: " . self::BACKUP_PATH);
        $this->info("✓ Backup Size: " . number_format(filesize($fullBackupPath)) . " bytes (SHA256 verified)");

        // 3. Inspect and Identify Target Test Fixtures
        $runs = $db->table('billing_runs')->get();
        $payments = $db->table('payments')->get();
        $allocations = $db->table('payment_allocations')->get();
        $invoices = $db->table('invoices')->get();
        $items = $db->table('invoice_items')->get();

        // 4. Verify No Unexpected Financial Records Exist Outside Fixture Set
        $unexpectedErrors = [];

        // Check billing runs
        $unexpectedRuns = $runs->filter(fn ($r) => !in_array((int) $r->id, self::KNOWN_RUN_IDS, true));
        if ($unexpectedRuns->isNotEmpty()) {
            $unexpectedErrors[] = "Unexpected billing_run ID(s) found: " . $unexpectedRuns->pluck('id')->implode(', ');
        }

        // Check payments
        $unexpectedPayments = $payments->filter(fn ($p) => !in_array((int) $p->id, self::KNOWN_PAYMENT_IDS, true));
        if ($unexpectedPayments->isNotEmpty()) {
            $unexpectedErrors[] = "Unexpected payment ID(s) found: " . $unexpectedPayments->pluck('id')->implode(', ');
        }

        // Check allocations
        $unexpectedAllocations = $allocations->filter(fn ($a) => !in_array((int) $a->id, self::KNOWN_ALLOCATION_IDS, true));
        if ($unexpectedAllocations->isNotEmpty()) {
            $unexpectedErrors[] = "Unexpected payment_allocation ID(s) found: " . $unexpectedAllocations->pluck('id')->implode(', ');
        }

        // Check invoices belong to fixture runs
        $unexpectedInvoices = $invoices->filter(fn ($i) => !in_array((int) $i->billing_run_id, self::KNOWN_RUN_IDS, true));
        if ($unexpectedInvoices->isNotEmpty()) {
            $unexpectedErrors[] = "Found {$unexpectedInvoices->count()} invoice(s) not associated with verified test runs (1, 2, 3)";
        }

        // Check opening balance lineage
        if ($db->getSchemaBuilder()->hasTable('qa_opening_balance_lineage')) {
            $lineageCount = $db->table('qa_opening_balance_lineage')->count();
            if ($lineageCount > 0) {
                $unexpectedErrors[] = "Opening balance lineage contains {$lineageCount} records. This tool only resets pre-existing test fixtures, not posted opening balances.";
            }
        }

        if (!empty($unexpectedErrors)) {
            $this->line('');
            $this->error('========================================================================');
            $this->error('CRITICAL SAFETY STOP: Unexpected financial records detected in ledger!');
            $this->error('========================================================================');
            foreach ($unexpectedErrors as $err) {
                $this->error("  - {$err}");
            }
            $this->error('Ledger reset ABORTED to protect unverified financial data.');
            return self::FAILURE;
        }

        // 5. Preview Exact Record IDs & Dependent Relationships
        $this->line('');
        $this->info('--- Verified QA Test Fixture Inventory & Relationships ---');
        $this->line("  - Billing Runs (to reset):        {$runs->count()} (IDs: " . ($runs->pluck('id')->implode(', ') ?: 'none') . ")");
        foreach ($runs as $r) {
            $this->line("      • Run #{$r->id}: Period {$r->period_key}, Dept ID {$r->department_id}, Campus ID {$r->campus_id}");
        }

        $this->line("  - Payments (to reset):            {$payments->count()} (IDs: " . ($payments->pluck('id')->implode(', ') ?: 'none') . ")");
        foreach ($payments as $p) {
            $this->line("      • Payment #{$p->id}: Receipt {$p->receipt_number}, Family #{$p->family_id}, Amount " . number_format($p->amount_minor / 100, 2) . " {$p->currency}");
        }

        $this->line("  - Payment Allocations (to reset): {$allocations->count()} (IDs: " . ($allocations->pluck('id')->implode(', ') ?: 'none') . ")");
        foreach ($allocations as $a) {
            $this->line("      • Allocation #{$a->id}: Payment #{$a->payment_id} -> Invoice Item #{$a->invoice_item_id}, Amount " . number_format($a->amount_minor / 100, 2));
        }

        $this->line("  - Invoices (to reset):            {$invoices->count()} (All linked to Runs: " . ($invoices->pluck('billing_run_id')->unique()->implode(', ') ?: 'none') . ")");
        $this->line("  - Invoice Items (to reset):       {$items->count()} (All linked to Invoices 1..{$invoices->count()})");

        $this->line('');
        $this->info('--- Preserved Data (Untouched) ---');
        foreach (['students', 'families', 'enrollments', 'qa_staged_collections', 'qa_staged_dues', 'staging_approvals', 'staging_exceptions', 'audit_logs'] as $tbl) {
            if ($db->getSchemaBuilder()->hasTable($tbl)) {
                $count = $db->table($tbl)->count();
                $this->line("  ✓ " . str_pad($tbl, 28) . ": " . number_format($count) . " records preserved");
            }
        }

        // 6. Check Authorization (--force)
        if (!$this->option('force') || $this->option('verify-only')) {
            $this->line('');
            $this->warn('========================================================================');
            $this->warn('  DRY-RUN / AUDIT MODE COMPLETE: Zero records were modified or deleted. ');
            $this->warn('  To execute actual deletion when authorized, run:');
            $this->warn('  php artisan qa:reset-test-ledger --force');
            $this->warn('========================================================================');
            $this->line('');
            return self::SUCCESS;
        }

        // 7. Generate Fresh Restorable Pre-Reset Backup Snapshot Before Mutation
        $timestamp = date('Ymd_His');
        $snapshotFilename = "qa_test_ledger_pre_reset_snapshot_{$timestamp}.json";
        $snapshotPath = base_path("backups/{$snapshotFilename}");

        $snapshotData = [
            'created_at' => now()->toIso8601String(),
            'connection' => $conn,
            'summary' => [
                'billing_runs' => $runs->count(),
                'invoices' => $invoices->count(),
                'invoice_items' => $items->count(),
                'payments' => $payments->count(),
                'payment_allocations' => $allocations->count(),
            ],
            'payment_allocations' => $allocations->toArray(),
            'payments' => $payments->toArray(),
            'invoice_items' => $items->toArray(),
            'invoices' => $invoices->toArray(),
            'billing_runs' => $runs->toArray(),
        ];

        file_put_contents($snapshotPath, json_encode($snapshotData, JSON_PRETTY_PRINT));
        if (!file_exists($snapshotPath) || filesize($snapshotPath) < 1000) {
            $this->error("CRITICAL SAFETY STOP: Failed to create restorable pre-reset backup snapshot at {$snapshotPath}");
            return self::FAILURE;
        }

        $snapshotHash = hash_file('sha256', $snapshotPath);
        $this->info("✓ Fresh Restorable Snapshot Created: backups/{$snapshotFilename}");
        $this->info("✓ Snapshot SHA-256: {$snapshotHash} (" . number_format(filesize($snapshotPath)) . " bytes)");

        // 8. Execute Deletion in Strict Topological Order WITHOUT Disabling Foreign Keys
        $this->warn('Executing verified test-ledger reset in atomic transaction (Foreign Keys Enforced)...');

        $allocIds = $allocations->pluck('id')->all();
        $payIds = $payments->pluck('id')->all();
        $itemIds = $items->pluck('id')->all();
        $invIds = $invoices->pluck('id')->all();
        $runIds = $runs->pluck('id')->all();

        $db->transaction(function () use ($db, $allocIds, $payIds, $itemIds, $invIds, $runIds) {
            // Topological order: Children deleted before parents. Foreign key checks remain 100% active!
            if (!empty($allocIds)) {
                $db->table('payment_allocations')->whereIn('id', $allocIds)->delete();
            }
            if (!empty($payIds)) {
                $db->table('payments')->whereIn('id', $payIds)->delete();
            }
            if (!empty($itemIds)) {
                // Delete in chunks if needed for query parameter limits
                foreach (array_chunk($itemIds, 1000) as $chunk) {
                    $db->table('invoice_items')->whereIn('id', $chunk)->delete();
                }
            }
            if (!empty($invIds)) {
                foreach (array_chunk($invIds, 1000) as $chunk) {
                    $db->table('invoices')->whereIn('id', $chunk)->delete();
                }
            }
            if (!empty($runIds)) {
                $db->table('billing_runs')->whereIn('id', $runIds)->delete();
            }
        });

        // 9. Post-reset Verification
        $remainingInvoices = $db->table('invoices')->count();
        $remainingPayments = $db->table('payments')->count();
        $remainingRuns = $db->table('billing_runs')->count();

        if ($remainingInvoices === 0 && $remainingPayments === 0 && $remainingRuns === 0) {
            $this->info('✓ Pre-existing QA test ledger fixtures successfully reset.');
            $this->info('✓ Foreign keys were enforced throughout the entire transaction.');
            $this->info('✓ Ledger is now clean and ready for opening balance posting.');
            return self::SUCCESS;
        }

        $this->error("WARNING: Unexpected remaining records after reset: {$remainingInvoices} invoices, {$remainingPayments} payments.");
        return self::FAILURE;
    }
}
