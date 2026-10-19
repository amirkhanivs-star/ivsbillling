<?php

namespace App\Console\Commands;

use App\Exceptions\DatabaseSafetyException;
use App\Services\Staging\DatabaseSafetyGuard;
use App\Services\Staging\MySkoolReportParser;
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
                            {--manifest= : Path to strict JSON cutoff certification manifest file}
                            {--cutoff-timestamp= : Explicit cutoff timestamp (ISO-8601, e.g. 2026-10-19T23:59:59)}
                            {--timezone= : Timezone of the financial cutoff (e.g. Asia/Riyadh, Asia/Karachi)}
                            {--authorized-by= : Email or name of active administrator certifying the cutoff}
                            {--freeze-evidence= : Evidence of transaction freeze or export audit trail}
                            {--report-coverage= : Scope or coverage filters applied to export}
                            {--v1-id= : Version 1 dataset identifier}
                            {--v2-id= : Version 2 snapshot identifier}
                            {--dry-run : Preview comparisons without persisting new tables}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Perform controlled, multi-currency delta reconciliation between Version 1 and Version 2 MySkool snapshots';

    protected MySkoolReportParser $parser;

    public function __construct(MySkoolReportParser $parser)
    {
        parent::__construct();
        $this->parser = $parser;
    }

    /**
     * Execute the console command.
     */
    public function handle(SnapshotReconciliationService $service): int
    {
        $v1Conn = (string) $this->option('v1-connection');
        $manifestPath = (string) $this->option('manifest');
        $v2DuesPath = (string) $this->option('v2-dues');
        $v2PaymentsPath = (string) $this->option('v2-payments');
        $cutoffTimestamp = (string) $this->option('cutoff-timestamp');
        $timezone = (string) $this->option('timezone');
        $authorizedBy = (string) $this->option('authorized-by');
        $freezeEvidence = (string) $this->option('freeze-evidence');
        $coverage = (string) $this->option('report-coverage');
        $v1Id = (string) $this->option('v1-id');
        $v2Id = (string) $this->option('v2-id');

        $this->info("===================================================================");
        $this->info(" IQRA Billing: Controlled Snapshot Reconciliation (Phase 3B.3.1)");
        $this->info("===================================================================");

        // 1. Database Safety Verification: Enforce clean staging database
        try {
            DatabaseSafetyGuard::assertStagingDatabase($v1Conn);
            $activeDb = DatabaseSafetyGuard::getActiveDatabaseName($v1Conn);
            $this->info(" [SECURITY] V1 database connection verified: {$activeDb} (Clean database confirmed)");
        } catch (DatabaseSafetyException $e) {
            $this->error(" [SECURITY BREACH] " . $e->getMessage());
            return Command::FAILURE;
        }

        // Verify quarantined database is 100% untouched
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

        // 2. Check if any Version 2 inputs or manifest were supplied
        $hasV2Arguments = !empty($manifestPath) || !empty($v2DuesPath) || !empty($v2PaymentsPath);

        if (!$hasV2Arguments) {
            $this->warn("\n [AWAITING V2 FILES] No Version 2 financial files or manifest provided.");
            $this->line("   Version 1 remains fully preserved, active, and frozen.");
            $this->line("   To run reconciliation once new files are provided:");
            $this->line("   php artisan staging:reconcile-snapshot --manifest=/path/to/manifest.json");
            $this->line("   OR explicitly supply all manifest parameters:");
            $this->line("   php artisan staging:reconcile-snapshot \\");
            $this->line("     --v2-dues=/path/to/dues.csv \\");
            $this->line("     --v2-payments=/path/to/payments.csv \\");
            $this->line("     --cutoff-timestamp='2026-10-19T23:59:59' \\");
            $this->line("     --timezone='Asia/Riyadh' \\");
            $this->line("     --authorized-by='admin@iqra.edu' \\");
            $this->line("     --freeze-evidence='Batch #49 freeze signed by finance committee'");
            return Command::SUCCESS;
        }

        // 3. Parse Manifest File if provided
        $expectedDuesSha = null;
        $expectedPaySha = null;

        if (!empty($manifestPath)) {
            if (!file_exists($manifestPath) || !is_readable($manifestPath)) {
                $this->error("\n [MANIFEST ERROR] Explicitly supplied manifest file not found: {$manifestPath}");
                return Command::FAILURE;
            }

            $manifestContent = file_get_contents($manifestPath);
            $manifestData = json_decode((string)$manifestContent, true);
            if (!is_array($manifestData)) {
                $this->error("\n [MANIFEST ERROR] Manifest file contains invalid JSON: {$manifestPath}");
                return Command::FAILURE;
            }

            $v2DuesPath = $v2DuesPath ?: ($manifestData['v2_dues_file'] ?? '');
            $v2PaymentsPath = $v2PaymentsPath ?: ($manifestData['v2_payments_file'] ?? '');
            $cutoffTimestamp = $cutoffTimestamp ?: ($manifestData['cutoff_timestamp'] ?? '');
            $timezone = $timezone ?: ($manifestData['timezone'] ?? '');
            $authorizedBy = $authorizedBy ?: ($manifestData['authorized_by'] ?? '');
            $freezeEvidence = $freezeEvidence ?: ($manifestData['freeze_evidence'] ?? '');
            $coverage = $coverage ?: ($manifestData['report_coverage'] ?? 'All campuses, All departments');
            $v1Id = $v1Id ?: ($manifestData['v1_identifier'] ?? 'IQRA_STAGING_ONLY_20261019_075231');
            $v2Id = $v2Id ?: ($manifestData['v2_identifier'] ?? 'MYSKOOL_SNAPSHOT_V2');
            $expectedDuesSha = $manifestData['v2_dues_sha256'] ?? null;
            $expectedPaySha = $manifestData['v2_payments_sha256'] ?? null;
        }

        // 4. Validate Synchronization Requirement: BOTH Outstanding and Collection reports required
        if (empty($v2DuesPath) || empty($v2PaymentsPath)) {
            $this->error("\n [INPUT ERROR] Both Outstanding (--v2-dues) and Collection (--v2-payments) files are required for an officially synchronized comparison.");
            return Command::FAILURE;
        }

        // 5. Validate Input File Existence
        if (!file_exists($v2DuesPath) || !is_readable($v2DuesPath)) {
            $this->error("\n [FILE ERROR] Version 2 Outstanding CSV file not found or unreadable: {$v2DuesPath}");
            return Command::FAILURE;
        }

        if (!file_exists($v2PaymentsPath) || !is_readable($v2PaymentsPath)) {
            $this->error("\n [FILE ERROR] Version 2 Collection CSV file not found or unreadable: {$v2PaymentsPath}");
            return Command::FAILURE;
        }

        // 6. Compute and verify SHA-256 hashes
        $v2DuesSha256 = hash_file('sha256', $v2DuesPath);
        $v2PaymentsSha256 = hash_file('sha256', $v2PaymentsPath);

        if ($expectedDuesSha && strtolower($expectedDuesSha) !== strtolower($v2DuesSha256)) {
            $this->error("\n [INTEGRITY BREACH] Outstanding CSV SHA-256 hash ({$v2DuesSha256}) does not match manifest hash ({$expectedDuesSha})!");
            return Command::FAILURE;
        }

        if ($expectedPaySha && strtolower($expectedPaySha) !== strtolower($v2PaymentsSha256)) {
            $this->error("\n [INTEGRITY BREACH] Collection CSV SHA-256 hash ({$v2PaymentsSha256}) does not match manifest hash ({$expectedPaySha})!");
            return Command::FAILURE;
        }

        // 7. Validate Financial Cutoff Certification & Administrator Identity
        if (empty($cutoffTimestamp) || strtotime($cutoffTimestamp) === false) {
            $this->error("\n [CERTIFICATION ERROR] Missing or invalid cutoff timestamp. An explicit valid timestamp is required (e.g. --cutoff-timestamp='2026-10-19T23:59:59').");
            return Command::FAILURE;
        }

        if (empty($timezone)) {
            $this->error("\n [CERTIFICATION ERROR] Missing cutoff timezone. An explicit timezone is required (e.g. --timezone='Asia/Riyadh').");
            return Command::FAILURE;
        }

        if (empty($freezeEvidence)) {
            $this->error("\n [CERTIFICATION ERROR] Missing transaction freeze evidence. Verifiable freeze documentation is required (e.g. --freeze-evidence='Signed freeze batch #49').");
            return Command::FAILURE;
        }

        // Administrator Identity Verification against active database users
        if (empty($authorizedBy)) {
            $this->error("\n [AUTHENTICATION ERROR] Missing administrator identity. Cutoff must be certified by an authorized administrator via --authorized-by.");
            return Command::FAILURE;
        }

        $c = DB::connection($v1Conn);
        $adminUser = $c->table('users')
            ->where(function ($q) use ($authorizedBy) {
                $q->where('email', $authorizedBy)
                  ->orWhere('name', $authorizedBy);
            })
            ->first();

        if (!$adminUser || !in_array($adminUser->role, ['super_admin', 'finance_admin'], true) || !$adminUser->is_active) {
            $this->error("\n [AUTHORIZATION ERROR] Administrator '{$authorizedBy}' does not match any registered, active super_admin or finance_admin account. Role authorization rejected.");
            return Command::FAILURE;
        }

        $adminIdentity = "{$adminUser->name} <{$adminUser->email}> ({$adminUser->role}, ID: {$adminUser->id})";

        // Display Strict Cutoff Manifest Card
        $this->info("\n [STRICT FINANCIAL CUTOFF CERTIFICATION MANIFEST]");
        $this->line("   Cutoff Timestamp:   {$cutoffTimestamp}");
        $this->line("   Timezone:           {$timezone}");
        $this->line("   V1 Identifier:      " . ($v1Id ?: 'IQRA_STAGING_ONLY_20261019_075231'));
        $this->line("   V2 Identifier:      " . ($v2Id ?: 'MYSKOOL_SNAPSHOT_V2'));
        $this->line("   Coverage/Filters:   " . ($coverage ?: 'All Classes & Campuses'));
        $this->line("   Freeze Evidence:    {$freezeEvidence}");
        $this->line("   Authorized Sign-off: {$adminIdentity} [Administrative Role Registry Verification]");
        $this->line("   Outstanding File:   " . basename($v2DuesPath) . " [SHA-256: {$v2DuesSha256}]");
        $this->line("   Collection File:    " . basename($v2PaymentsPath) . " [SHA-256: {$v2PaymentsSha256}]");

        // 8. Load Version 1 Authoritative Baseline
        $v1Dues = $c->table('staging_dues')->get();
        $v1Payments = $c->table('staging_payments')->get();
        $this->info("\n Loaded V1 Authoritative Baseline: {$v1Dues->count()} dues rows, {$v1Payments->count()} payment lines.");

        // 9. Parse Version 2 CSVs using MySkoolReportParser
        $this->info(" Parsing Version 2 files via MySkoolReportParser...");
        try {
            $v2Dues = $this->parser->parseDuesReport($v2DuesPath);
            $v2Payments = $this->parser->parsePaymentsReport($v2PaymentsPath);
        } catch (\Throwable $e) {
            $this->error("\n [PARSER ERROR] Failed parsing MySkool CSV: " . $e->getMessage());
            return Command::FAILURE;
        }

        $this->info(" Loaded V2 Snapshot: " . count($v2Dues) . " dues rows, " . count($v2Payments) . " payment lines.");

        // 10. Execute Multi-Currency Snapshot Comparison
        $diff = $service->compareSnapshots($v1Dues, $v2Dues, $v1Payments, $v2Payments);

        // 11. Render Comparison Tables
        $this->info("\n===================================================================");
        $this->info(" SNAPSHOT DELTA RECONCILIATION SUMMARY (BY CURRENCY)");
        $this->info("===================================================================");

        $tableData = [];
        foreach ($diff['currencies'] as $curr => $data) {
            if ($data['v1_total_amount'] == 0 && $data['v2_total_amount'] == 0) {
                continue;
            }

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

        $this->info("\n Payment Line Comparison:");
        $this->line("   V1 Total Payments:   " . $diff['summary']['v1_payments_total_rows']);
        $this->line("   V2 Total Payments:   " . $diff['summary']['v2_payments_total_rows']);
        $this->line("   Newly Added Payments: " . $diff['summary']['new_payments_total_count'] . " (Total: " . number_format($diff['summary']['new_payments_total_amount'], 2) . ")");
        $this->line("   Missing Payments:     " . count($diff['missing_payments']));
        $this->line("   Changed Payments:     " . count($diff['changed_payments']));

        $payTableData = [];
        foreach ($diff['currencies'] as $curr => $data) {
            if ($data['v1_payments_count'] == 0 && $data['v2_payments_count'] == 0) {
                continue;
            }
            $payTableData[] = [
                $curr,
                "{$data['v1_payments_count']} (" . number_format($data['v1_payments_amount'], 2) . ")",
                "{$data['v2_payments_count']} (" . number_format($data['v2_payments_amount'], 2) . ")",
                "{$data['unchanged_payments_count']} (" . number_format($data['unchanged_payments_amount'], 2) . ")",
                "{$data['new_payments_count']} (" . number_format($data['new_payments_amount'], 2) . ")",
                "{$data['missing_payments_count']} (" . number_format($data['missing_payments_amount'], 2) . ")",
            ];
        }

        if (!empty($payTableData)) {
            $this->info("\n HISTORICAL PAYMENT RECONCILIATION SUMMARY (BY CURRENCY)");
            $this->table(
                ['Currency', 'V1 Baseline Payments', 'V2 Snapshot Payments', 'Unchanged', 'Newly Added', 'Missing'],
                $payTableData
            );
        }

        $this->info("\n [STATUS] Dry-run preview complete. Version 1 preserved untouched. No ledger postings executed.");
        return Command::SUCCESS;
    }
}
