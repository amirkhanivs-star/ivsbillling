<?php

namespace App\Console\Commands;

use App\Exceptions\DatabaseSafetyException;
use App\Services\Staging\DatabaseSafetyGuard;
use App\Services\Staging\SnapshotReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StagingReconcileSnapshotCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'staging:reconcile-snapshot
                            {--v1-connection=staging : Connection for Version 1 authoritative staging data}
                            {--v2-dues= : Path to new Version 2 MySkool Outstanding Report CSV}
                            {--v2-payments= : Path to new Version 2 MySkool Collection Report CSV}
                            {--certified-by= : Administrator who certified the Version 2 financial cutoff}
                            {--dry-run : Preview comparisons without persisting new tables}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Perform controlled, multi-currency delta reconciliation between Version 1 and Version 2 MySkool snapshots';

    /**
     * Execute the console command.
     */
    public function handle(SnapshotReconciliationService $service): int
    {
        $v1Conn = (string) $this->option('v1-connection');
        $v2DuesPath = (string) $this->option('v2-dues');
        $v2PaymentsPath = (string) $this->option('v2-payments');
        $certifiedBy = (string) $this->option('certified-by');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("===================================================================");
        $this->info(" IQRA Billing: Controlled Snapshot Reconciliation (Phase 3B.3)");
        $this->info("===================================================================");

        // 1. Database Safety Verification
        try {
            DatabaseSafetyGuard::assertStagingDatabase($v1Conn);
            $activeDb = DatabaseSafetyGuard::getActiveDatabaseName($v1Conn);
            $this->info(" [SECURITY] V1 database connection verified: {$activeDb} (Clean database confirmed)");
        } catch (DatabaseSafetyException $e) {
            $this->error(" [SECURITY BREACH] " . $e->getMessage());
            return Command::FAILURE;
        }

        // Verify quarantined database is untouched
        if (DB::connection($v1Conn)->getDriverName() !== 'sqlite') {
            $qConn = DB::connection('mysql');
            $qStu = $qConn->table('students')->count();
            $qInv = $qConn->table('invoices')->count();
            $qPay = $qConn->table('payments')->count();
            if ($qStu !== 4349 || $qInv !== 8558 || $qPay !== 31086) {
                $this->error(" [SECURITY ALERT] Quarantined database iqra_billing was modified!");
                return Command::FAILURE;
            }
            $this->info(" [SECURITY] Quarantined database iqra_billing verified untouched (Students: {$qStu}, Invoices: {$qInv}, Payments: {$qPay})");
        }

        // 2. Check Version 2 Inputs
        if (empty($v2DuesPath) || !file_exists($v2DuesPath)) {
            $this->warn("\n [AWAITING V2 FILES] No valid Version 2 Outstanding CSV provided via --v2-dues.");
            $this->line("   Version 1 remains fully preserved, active, and frozen.");
            $this->line("   To run reconciliation once new files are provided:");
            $this->line("   php artisan staging:reconcile-snapshot --v2-dues=/path/to/outstanding.csv --v2-payments=/path/to/collection.csv --certified-by='Admin Name'");
            return Command::SUCCESS;
        }

        // 3. Cutoff Certification Verification
        if (empty($certifiedBy)) {
            $this->error("\n [CUTOFF UNVERIFIED] Version 2 requires explicit administrator certification of the cutoff timestamp via --certified-by.");
            return Command::FAILURE;
        }

        $v2DuesSha256 = hash_file('sha256', $v2DuesPath);
        $this->info("\n [V2 SOURCE VALIDATION]");
        $this->line("   Dues File: {$v2DuesPath}");
        $this->line("   Dues SHA-256: {$v2DuesSha256}");
        $this->line("   Cutoff Certified By: {$certifiedBy}");

        $v2PaymentsSha256 = null;
        if (!empty($v2PaymentsPath) && file_exists($v2PaymentsPath)) {
            $v2PaymentsSha256 = hash_file('sha256', $v2PaymentsPath);
            $this->line("   Payments File: {$v2PaymentsPath}");
            $this->line("   Payments SHA-256: {$v2PaymentsSha256}");
        }

        // 4. Load V1 Dues
        $v1Dues = DB::connection($v1Conn)->table('staging_dues')->get();
        $v1Payments = DB::connection($v1Conn)->table('staging_payments')->get();
        $this->info("\n Loaded V1 Baseline: {$v1Dues->count()} dues rows, {$v1Payments->count()} payment lines.");

        // 5. Parse V2 Dues CSV
        $v2Dues = $this->parseCsvRows($v2DuesPath, 'dues');
        $this->info(" Loaded V2 Snapshot: " . count($v2Dues) . " dues rows.");

        $v2Payments = [];
        if (!empty($v2PaymentsPath) && file_exists($v2PaymentsPath)) {
            $v2Payments = $this->parseCsvRows($v2PaymentsPath, 'payments');
            $this->info(" Loaded V2 Snapshot: " . count($v2Payments) . " payment lines.");
        }

        // 6. Execute Comparison
        $diff = $service->compareSnapshots($v1Dues, $v2Dues, $v1Payments, $v2Payments);

        // 7. Render Multi-Currency Comparison Tables
        $this->info("\n===================================================================");
        $this->info(" SNAPSHOT DELTA RECONCILIATION SUMMARY (BY CURRENCY)");
        $this->info("===================================================================");

        $tableData = [];
        foreach ($diff['currencies'] as $curr => $data) {
            if ($data['v1_total_amount'] == 0 && $data['v2_total_amount'] == 0) continue;

            $tableData[] = [
                $curr,
                number_format($data['v1_total_amount'], 2),
                number_format($data['v2_total_amount'], 2),
                number_format($data['net_variance'], 2),
                "{$data['unchanged_count']} (" . number_format($data['unchanged_amount'], 2) . ")",
                "{$data['increased_count']} (+" . number_format($data['increased_delta'], 2) . ")",
                "{$data['decreased_count']} (-" . number_format($data['decreased_delta'], 2) . ")",
                "{$data['newly_appearing_count']} (" . number_format($data['newly_appearing_amount'], 2) . ")",
                "{$data['disappearing_count']} (" . number_format($data['disappearing_amount'], 2) . ")",
            ];
        }

        $this->table(
            ['Currency', 'V1 Dues Total', 'V2 Dues Total', 'Net Variance', 'Unchanged', 'Increased', 'Decreased', 'Newly Appearing', 'Disappearing'],
            $tableData
        );

        $this->info("\n Classification Conflicts Detected: " . $diff['summary']['total_classification_conflicts']);
        if ($diff['summary']['total_classification_conflicts'] > 0) {
            foreach (array_slice($diff['conflicts'], 0, 5) as $conf) {
                $this->warn("   - Conflict [{$conf['type']}]: GR {$conf['gr_number']} ({$conf['key']})");
            }
        }

        $this->info("\n [STATUS] Dry-run preview complete. No database changes or financial postings executed.");
        return Command::SUCCESS;
    }

    /**
     * Simple parser for raw CSV rows.
     */
    protected function parseCsvRows(string $path, string $type): array
    {
        $rows = [];
        $fp = fopen($path, 'r');
        $header = fgetcsv($fp);
        while (($line = fgetcsv($fp)) !== false) {
            if (empty(array_filter($line))) continue;
            if ($type === 'dues') {
                $rows[] = [
                    'report_class' => $line[0] ?? '',
                    'gr_number' => $line[1] ?? '',
                    'student_name' => $line[2] ?? '',
                    'particular_raw' => $line[4] ?? '',
                    'currency_iso' => strtoupper(trim((string)($line[5] ?? ''))),
                    'outstanding_amount' => (float)($line[6] ?? 0),
                    'period_key' => $this->extractPeriodKey($line[4] ?? ''),
                ];
            } else {
                $rows[] = [
                    'slip_number' => $line[1] ?? '',
                    'gr_number' => $line[2] ?? '',
                    'student_name' => $line[3] ?? '',
                    'particular_raw' => $line[4] ?? '',
                    'payment_date_iso' => $line[5] ?? '',
                    'currency_iso' => strtoupper(trim((string)($line[6] ?? ''))),
                    'paid_amount' => (float)($line[7] ?? 0),
                ];
            }
        }
        fclose($fp);
        return $rows;
    }

    protected function extractPeriodKey(string $particular): string
    {
        if (preg_match('/(Nov|Dec|Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct)-(\d{4})/i', $particular, $m)) {
            $month = date('m', strtotime($m[1] . ' 1 2000'));
            return "{$m[2]}-{$month}";
        }
        return '';
    }
}
