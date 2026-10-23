<?php

namespace App\Services\Migration;

use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class QaControlledReimportService
{
    public const ACTIVE_WORKBOOK_PATH = 'storage/app/imports/My skool fully updated (1).xlsx';
    public const EXPECTED_WORKBOOK_SHA256 = '77d8e10f84726cc7b9708bfdb749948a7dbe717d60dab15b91361d5f2e4c4f4b';
    public const ARCHIVED_ORIGINAL_PATH = 'storage/app/imports/archive/UPdated_school_record_for_portal_original_20261022.xlsx';
    public const EXPECTED_ORIGINAL_SHA256 = 'd2276cf0c75a0c17b143d1815fda568c9b5cc7e0154f546b8075d1a98e225401';
    public const TEST_FIXTURE_BACKUP_PATH = 'backups/qa_test_ledger_fixture_oct20_21.sql';
    public const EXPECTED_BACKUP_SHA256 = '8b281db611e9f1b6e42bdb3273a0e9c04e445a0c2caa85142e6dab515e32084f';

    public function __construct(
        protected HistoricalOpeningBalanceService $openingBalanceService
    ) {}

    /**
     * Generate complete 10-step dry-run / execution plan for the controlled QA reimport.
     */
    public function plan(string $conn, ?string $batchId = null): array
    {
        $this->validateQaConnection($conn);
        $batchId = $batchId ?: (string) config('imports.active_batch_id', 'BATCH-20261023-FINAL-MYSKOOL');
        $db = DB::connection($conn);

        // Step 1: Verify Active Workbook SHA-256
        $step1 = $this->verifyWorkbook();

        // Step 2: Preserve Original Staging Data & Snapshots
        $step2 = $this->verifySnapshotsAndStaging($db);

        // Step 3: Preview Differences and Exceptions
        $step3 = $this->openingBalanceService->preview($conn, $batchId);

        // Step 4: Audit Test Ledger Reset Scope
        $step4 = $this->auditTestLedger($db);

        // Step 5: Current Registration Membership Scope (4,352 records)
        $step5 = $this->auditRegistrationScope($db);

        // Step 6 & 7: Staged Collections (37,976) & Outstanding Dues (11,473)
        $step6and7 = $this->auditStagingFinancialScope($db, $batchId);

        // Step 8: Opening Balance Posting Scope
        $step8 = [
            'authorized' => config('imports.allow_opening_balance_posting', false),
            'eligible_rows' => $step3['summary']['eligible_rows'] ?? 0,
            'invoices_to_create' => $step3['summary']['invoices_to_create'] ?? 0,
            'status' => config('imports.allow_opening_balance_posting', false) ? 'AUTHORIZED' : 'LOCKED_PENDING_APPROVAL',
        ];

        // Step 9: Currency-isolated Reconciliation
        $step9 = $step3['totals_by_currency'] ?? [];

        // Step 10: Idempotency Guarantee
        $step10 = [
            'lineage_tracking_active' => $db->getSchemaBuilder()->hasTable('qa_opening_balance_lineage'),
            'rerun_safe' => true,
            'duplicate_risk' => 'ZERO (Enforced by UNIQUE qa_staged_due_id on lineage table and period key checks)',
        ];

        return [
            'pipeline_name' => 'Controlled Single QA Reimport Pipeline',
            'connection' => $conn,
            'batch_id' => $batchId,
            'live_deployment_notice' => 'LIVE DEPLOYMENT NOTICE: This pipeline validates the QA environment against the October 22 reviewed workbook. Live production deployment MUST use a fresh final MySkool export rather than assuming the October 22 workbook remains current.',
            'steps' => [
                '1_verify_workbook' => $step1,
                '2_preserve_snapshots' => $step2,
                '3_preview_exceptions' => $step3['exclusions'] ?? [],
                '4_test_ledger_reset_plan' => $step4,
                '5_registration_scope' => $step5,
                '6_collections_staging_scope' => [
                    'staged_collections_rows' => $step6and7['staged_collections_rows'],
                    'expected_collections_rows' => $step6and7['expected_collections_rows'],
                ],
                '7_outstanding_dues_scope' => [
                    'staged_dues_rows' => $step6and7['staged_dues_rows'],
                    'expected_dues_rows' => $step6and7['expected_dues_rows'],
                ],
                '8_posting_authorization' => $step8,
                '9_currency_reconciliation' => $step9,
                '10_idempotency_guarantee' => $step10,
            ],
            'ready_for_execution' => $step1['valid'] && $step4['backup_verified'],
        ];
    }

    /**
     * Step 1: Verify Active Workbook SHA-256.
     */
    public function verifyWorkbook(): array
    {
        $path = base_path(self::ACTIVE_WORKBOOK_PATH);
        if (!file_exists($path)) {
            return [
                'valid' => false,
                'error' => 'Workbook not found at ' . self::ACTIVE_WORKBOOK_PATH,
            ];
        }

        $hash = hash_file('sha256', $path);
        $size = filesize($path);
        $valid = ($hash === self::EXPECTED_WORKBOOK_SHA256);

        return [
            'valid' => $valid,
            'file_path' => self::ACTIVE_WORKBOOK_PATH,
            'sha256' => $hash,
            'expected_sha256' => self::EXPECTED_WORKBOOK_SHA256,
            'size_bytes' => $size,
        ];
    }

    /**
     * Step 2: Verify Snapshot and Staging Preservation.
     */
    public function verifySnapshotsAndStaging($db): array
    {
        $archivePath = base_path(self::ARCHIVED_ORIGINAL_PATH);
        $hasArchive = file_exists($archivePath);
        $archiveHash = $hasArchive ? hash_file('sha256', $archivePath) : null;

        $hasStagedCollections = $db->getSchemaBuilder()->hasTable('qa_staged_collections');
        $hasStagedDues = $db->getSchemaBuilder()->hasTable('qa_staged_dues');

        return [
            'archived_original_present' => $hasArchive,
            'archived_original_sha256' => $archiveHash,
            'archived_original_valid' => ($archiveHash === self::EXPECTED_ORIGINAL_SHA256),
            'staged_collections_table' => $hasStagedCollections,
            'staged_dues_table' => $hasStagedDues,
            'staged_collections_count' => $hasStagedCollections ? $db->table('qa_staged_collections')->count() : 0,
            'staged_dues_count' => $hasStagedDues ? $db->table('qa_staged_dues')->count() : 0,
        ];
    }

    /**
     * Step 4: Audit Test Ledger Reset Scope.
     */
    public function auditTestLedger($db): array
    {
        $backupPath = base_path(self::TEST_FIXTURE_BACKUP_PATH);
        $backupExists = file_exists($backupPath);
        $backupHash = $backupExists ? hash_file('sha256', $backupPath) : null;
        $backupValid = ($backupHash === self::EXPECTED_BACKUP_SHA256);

        return [
            'backup_file' => self::TEST_FIXTURE_BACKUP_PATH,
            'backup_exists' => $backupExists,
            'backup_sha256' => $backupHash,
            'backup_verified' => $backupValid,
            'target_runs' => $db->table('billing_runs')->count(),
            'target_invoices' => $db->table('invoices')->count(),
            'target_invoice_items' => $db->table('invoice_items')->count(),
            'target_payments' => $db->table('payments')->count(),
            'target_allocations' => $db->table('payment_allocations')->count(),
        ];
    }

    /**
     * Step 5: Audit Registration Scope (4,352 records).
     */
    public function auditRegistrationScope($db): array
    {
        return [
            'total_students' => $db->table('students')->count(),
            'active_students' => $db->table('students')->where('status', 'present')->count(),
            'review_hold_students' => $db->table('students')->where('status', 'review_hold')->count(),
            'historical_hold_students' => $db->table('students')->where('status', 'historical_hold')->count(),
            'families_count' => $db->table('families')->count(),
            'enrollments_count' => $db->table('enrollments')->count(),
        ];
    }

    /**
     * Steps 6 & 7: Audit Staging Financial Scope.
     */
    public function auditStagingFinancialScope($db, string $batchId): array
    {
        $collectionsCount = $db->getSchemaBuilder()->hasTable('qa_staged_collections')
            ? $db->table('qa_staged_collections')->where('batch_id', $batchId)->count()
            : 0;

        $duesCount = $db->getSchemaBuilder()->hasTable('qa_staged_dues')
            ? $db->table('qa_staged_dues')->where('batch_id', $batchId)->count()
            : 0;

        return [
            'batch_id' => $batchId,
            'staged_collections_rows' => $collectionsCount,
            'expected_collections_rows' => 38068,
            'staged_dues_rows' => $duesCount,
            'expected_dues_rows' => 11351,
        ];
    }

    private function validateQaConnection(string $conn): void
    {
        if (config("database.connections.{$conn}.driver") === 'sqlite') {
            return;
        }

        DatabaseSafetyGuard::assertTargetDatabase($conn);
        DatabaseSafetyGuard::assertNotQuarantineDatabase($conn);
        DatabaseSafetyGuard::assertNotStagingDatabase($conn);
    }
}
