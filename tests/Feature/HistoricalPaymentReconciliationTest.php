<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FeeHistoryService;
use App\Services\Migration\HistoricalPaymentReconciliationService;
use App\Services\Migration\MySkoolReconciliationAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class HistoricalPaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private string $batchId = 'BATCH-TEST-HIST-RECON-20261028';
    private string $cutoffDate = '2026-10-22';
    private int $campusId;
    private int $deptId;
    private int $monthlyFeeTypeId;
    private int $obRunId;
    private int $novRunId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        Config::set('reconciliation.allow_apply', true);

        $this->campusId = (int) DB::table('campuses')->value('id');
        $this->deptId = (int) DB::table('departments')->where('code', 'school')->value('id');
        $this->monthlyFeeTypeId = (int) DB::table('fee_types')->where('code', 'monthly')->value('id');
        $this->userId = (int) DB::table('users')->value('id');
        $this->actingAs(User::findOrFail($this->userId));

        $this->obRunId = (int) DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'opening_balance',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'status' => 'generated',
            'created_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->novRunId = (int) DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-10',
            'status' => 'generated',
            'created_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createFamilyAndStudent(string $familyCode, string $grNumber, string $name, string $currency = 'SAR'): array
    {
        $existingFam = DB::table('families')->where('family_code', $familyCode)->first();
        $familyId = $existingFam ? (int) $existingFam->id : (int) DB::table('families')->insertGetId([
            'family_code' => $familyCode,
            'guardian_name' => "Guardian {$familyCode}",
            'is_provisional' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = (int) DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => $grNumber,
            'name' => $name,
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = (int) DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => $currency,
            'monthly_fee_minor' => 30000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$familyId, $studentId, $enrollmentId];
    }

    private function createObInvoiceItem(
        int $familyId,
        int $studentId,
        int $enrollmentId,
        string $grNumber,
        string $description,
        string $currency,
        int $netMinor,
        array $itemOverrides = [],
        array $invoiceOverrides = []
    ): array {
        $existingInvoice = DB::table('invoices')
            ->where('billing_run_id', $this->obRunId)
            ->where('family_id', $familyId)
            ->where('currency', $currency)
            ->first();

        if ($existingInvoice && empty($invoiceOverrides)) {
            $invoiceId = (int) $existingInvoice->id;
            DB::table('invoices')->where('id', $invoiceId)->increment('total_minor', $netMinor);
        } else {
            $invoiceId = (int) DB::table('invoices')->insertGetId(array_merge([
                'billing_run_id' => $this->obRunId,
                'family_id' => $familyId,
                'campus_id' => $this->campusId,
                'department_id' => $this->deptId,
                'currency' => $currency,
                'invoice_number' => 'SCH-OB-TEST-' . uniqid(),
                'issued_on' => '2026-10-01',
                'due_date' => '2026-10-25',
                'total_minor' => $netMinor,
                'paid_minor' => 0,
                'previous_dues_snapshot_minor' => 0,
                'status' => 'generated',
                'approved_to_send' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ], $invoiceOverrides));
        }

        $itemId = (int) DB::table('invoice_items')->insertGetId(array_merge([
            'invoice_id' => $invoiceId,
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => $description,
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => $currency,
            'amount_minor' => $netMinor,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => $netMinor,
            'paid_minor' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $itemOverrides));

        // Create opening balance lineage row
        $stagedDueId = (int) DB::table('qa_staged_dues')->insertGetId([
            'batch_id' => $this->batchId,
            'source_row' => rand(1000, 9999),
            'gr_number' => $grNumber,
            'student_name' => "Student {$grNumber}",
            'family_code_final' => (string) DB::table('families')->where('id', $familyId)->value('family_code'),
            'fee_particular' => $description,
            'currency' => $currency,
            'outstanding_amount' => $netMinor / 100,
            'outstanding_amount_minor' => $netMinor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('qa_opening_balance_lineage')->insert([
            'batch_id' => $this->batchId,
            'qa_staged_due_id' => $stagedDueId,
            'source_row' => rand(1000, 9999),
            'gr_number' => $grNumber,
            'invoice_id' => $invoiceId,
            'invoice_item_id' => $itemId,
            'family_id' => $familyId,
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'currency' => $currency,
            'amount_minor' => $netMinor,
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$invoiceId, $itemId];
    }

    private function insertStagedCollection(
        int $sourceRow,
        string $slipNumber,
        string $grNumber,
        string $familyCode,
        string $particular,
        ?string $paymentDateParsed,
        string $currency,
        int $amountMinor
    ): int {
        return (int) DB::table('qa_staged_collections')->insertGetId([
            'batch_id' => $this->batchId,
            'source_row' => $sourceRow,
            'slip_number' => $slipNumber,
            'gr_number' => $grNumber,
            'student_name' => "Student {$grNumber}",
            'family_code' => $familyCode,
            'fee_particular' => $particular,
            'payment_date_raw' => $paymentDateParsed ?? '',
            'payment_date_parsed' => $paymentDateParsed,
            'payment_method' => 'Cash',
            'currency' => $currency,
            'amount' => $amountMinor / 100,
            'amount_minor' => $amountMinor,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 1. GR 5929 equivalent, fully settled historical obligation.
     */
    public function test_01_gr_5929_equivalent_fully_settled_historical_obligation(): void
    {
        [$fam, $stu, $enr] = $this->createFamilyAndStudent('5929', '5929', 'Khadija Feroz', 'QAR');
        [$invId, $itemId] = $this->createObInvoiceItem($fam, $stu, $enr, '5929', 'Monthly Fee - Oct-2026', 'QAR', 28000);
        $this->insertStagedCollection(101, '27328', '5929', '5929', 'Monthly Fee - Oct-2026', '2026-10-24', 'QAR', 28000);

        $service = app(HistoricalPaymentReconciliationService::class);
        $preview = $service->preview(['gr' => '5929'], $this->cutoffDate, $this->batchId);

        $this->assertCount(1, $preview['records']);
        $this->assertSame(MySkoolReconciliationAuditService::GUARD_CANDIDATE_FULL_SETTLEMENT, $preview['records'][0]['guard_class']);
        $this->assertSame(28000, $preview['records'][0]['verified_settlement_minor']);
        $this->assertSame(0, $preview['records'][0]['remaining_obligation_minor']);

        // Apply reconciliation
        $result = $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => '5929',
        ]);

        $this->assertSame(1, $result['applied_count']);

        // Verify item and parent invoice state
        $updatedItem = DB::table('invoice_items')->where('id', $itemId)->first();
        $this->assertSame(28000, (int) $updatedItem->paid_minor);
        $this->assertSame('paid', $updatedItem->status);

        $updatedInvoice = DB::table('invoices')->where('id', $invId)->first();
        $this->assertSame(28000, (int) $updatedInvoice->paid_minor);
        $this->assertSame('paid', $updatedInvoice->status);
    }

    /**
     * 2. GR 5930 equivalent, partially settled with unapproved possible concession.
     */
    public function test_02_gr_5930_equivalent_partially_settled_with_concession_pattern(): void
    {
        [$fam, $stu1, $enr1] = $this->createFamilyAndStudent('5929', '5929', 'Khadija Feroz', 'QAR');
        [, $stu2, $enr2] = $this->createFamilyAndStudent('5929', '5930', 'Zahra Feroz', 'QAR');

        $this->createObInvoiceItem($fam, $stu1, $enr1, '5929', 'Monthly Fee - Oct-2026', 'QAR', 28000);
        $this->createObInvoiceItem($fam, $stu2, $enr2, '5930', 'Monthly Fee - Oct-2026', 'QAR', 28000);

        $this->insertStagedCollection(201, '27328', '5929', '5929', 'Monthly Fee - Oct-2026', '2026-10-24', 'QAR', 28000);
        $this->insertStagedCollection(202, '27328', '5930', '5929', 'Monthly Fee - Oct-2026', '2026-10-24', 'QAR', 23000);

        $service = app(HistoricalPaymentReconciliationService::class);
        $preview = $service->preview(['gr' => '5930'], $this->cutoffDate, $this->batchId);

        $this->assertCount(1, $preview['records']);
        $r = $preview['records'][0];
        $this->assertSame(MySkoolReconciliationAuditService::GUARD_CONCESSION_REVIEW, $r['guard_class']);
        $this->assertSame(23000, $r['verified_settlement_minor']);
        $this->assertSame(5000, $r['remaining_obligation_minor']);
        $this->assertSame(0, $r['approved_adjustments_minor']);
        // Verify invariant
        $this->assertSame(28000, $r['verified_settlement_minor'] + $r['remaining_obligation_minor'] + $r['approved_adjustments_minor']);

        // Default apply targets only CANDIDATE_FULL_SETTLEMENT, so CONCESSION_REVIEW must be skipped
        $applyRes = $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => '5930',
        ]);
        $this->assertSame(0, $applyRes['applied_count']);
        $this->assertSame(1, $applyRes['skipped_count']);
    }

    /**
     * 3. Legitimate pre-cutoff partial balance, equivalent to GR 4814 (two split lines: AED 210 + AED 250 after AED 40 payment against AED 500 fee).
     */
    public function test_03_legitimate_pre_cutoff_partial_balance_gr_4814(): void
    {
        [$fam, $stu, $enr] = $this->createFamilyAndStudent('4814', '4814', 'Beenal Seetamonee', 'AED');
        DB::table('enrollments')->where('id', $enr)->update(['monthly_fee_minor' => 50000]);

        [$invId, $item1Id] = $this->createObInvoiceItem($fam, $stu, $enr, '4814', 'Monthly Fee - May-2026', 'AED', 21000, [
            'period_key' => 'monthly:2026-05-01..2026-05-31',
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
        ]);
        [, $item2Id] = $this->createObInvoiceItem($fam, $stu, $enr, '4814', 'Monthly Fee - May-2026', 'AED', 25000, [
            'period_key' => 'monthly:2026-05-01..2026-05-31',
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
        ]);
        // Paid 40.00 on 2026-07-03 (before cutoff 2026-10-22); 40 + 210 + 250 = 500 AED monthly fee
        $this->insertStagedCollection(301, '24127', '4814', '4814', 'Monthly Fee - May-2026', '2026-07-03', 'AED', 4000);

        $service = app(HistoricalPaymentReconciliationService::class);
        $preview = $service->preview(['gr' => '4814'], $this->cutoffDate, $this->batchId);

        $this->assertCount(2, $preview['records']);
        $this->assertSame(MySkoolReconciliationAuditService::GUARD_KEEP_GENUINE_RESIDUAL, $preview['records'][0]['guard_class']);
        $this->assertSame(MySkoolReconciliationAuditService::GUARD_KEEP_GENUINE_RESIDUAL, $preview['records'][1]['guard_class']);
        $this->assertSame(0, $preview['records'][0]['verified_settlement_minor']);
        $this->assertSame(21000, $preview['records'][0]['remaining_obligation_minor']);
        $this->assertSame(0, $preview['records'][1]['verified_settlement_minor']);
        $this->assertSame(25000, $preview['records'][1]['remaining_obligation_minor']);

        // Apply must NOT mutate genuine pre-cutoff residual
        $applyRes = $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => '4814',
        ]);
        $this->assertSame(0, $applyRes['applied_count']);
        $this->assertSame(2, $applyRes['skipped_count']);

        foreach ([$item1Id, $item2Id] as $id) {
            $itemAfter = DB::table('invoice_items')->where('id', $id)->first();
            $this->assertSame(0, (int) $itemAfter->paid_minor);
            $this->assertSame('active', $itemAfter->status);
        }
    }

    /**
     * 4. Split historical receipts.
     */
    public function test_04_split_historical_receipts_aggregate_to_settlement(): void
    {
        [$fam, $stu, $enr] = $this->createFamilyAndStudent('6555', '6555', 'Syed Anas Ali', 'SAR');
        [$invId, $itemId] = $this->createObInvoiceItem($fam, $stu, $enr, '6555', 'Monthly Fee - Aug-2026', 'SAR', 27000);

        // Split across two rows on slip 27173 on Oct 23: 13500 + 13500 = 27000
        $this->insertStagedCollection(401, '27173', '6555', '6555', 'Monthly Fee - Aug-2026', '2026-10-23', 'SAR', 13500);
        $this->insertStagedCollection(402, '27173', '6555', '6555', 'Monthly Fee - Aug-2026', '2026-10-23', 'SAR', 13500);

        $service = app(HistoricalPaymentReconciliationService::class);
        $preview = $service->preview(['gr' => '6555'], $this->cutoffDate, $this->batchId);

        $this->assertCount(1, $preview['records']);
        $this->assertSame(MySkoolReconciliationAuditService::GUARD_CANDIDATE_FULL_SETTLEMENT, $preview['records'][0]['guard_class']);
        $this->assertSame(27000, $preview['records'][0]['verified_settlement_minor']);

        $res = $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => '6555',
        ]);
        $this->assertSame(1, $res['applied_count']);

        $item = DB::table('invoice_items')->where('id', $itemId)->first();
        $this->assertSame(27000, (int) $item->paid_minor);
        $this->assertSame('paid', $item->status);
    }

    /**
     * 5. Overpayment capped to a verified obligation.
     */
    public function test_05_overpayment_capped_to_verified_obligation(): void
    {
        [$fam, $stu, $enr] = $this->createFamilyAndStudent('8858', '8858', 'Bahta Noor', 'SAR');
        [$invId, $itemId] = $this->createObInvoiceItem($fam, $stu, $enr, '8858', 'Monthly Fee - Sep-2026', 'SAR', 23300);

        // Historical payment of 260.00 (26000) against 233.00 (23300) opening balance
        $this->insertStagedCollection(501, '27257', '8858', '8858', 'Monthly Fee - Sep-2026', '2026-10-23', 'SAR', 26000);

        $service = app(HistoricalPaymentReconciliationService::class);
        $preview = $service->preview(['gr' => '8858'], $this->cutoffDate, $this->batchId);

        $this->assertCount(1, $preview['records']);
        $r = $preview['records'][0];
        $this->assertSame(23300, $r['verified_settlement_minor']);
        $this->assertSame(0, $r['remaining_obligation_minor']);
        $this->assertSame(2700, $r['unmatched_overpayment_minor']);
        $this->assertSame(23300, $r['verified_settlement_minor'] + $r['remaining_obligation_minor'] + $r['approved_adjustments_minor']);

        // Apply with allowed class
        $res = $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => '8858',
            'classes' => [MySkoolReconciliationAuditService::CLASS_REVIEW_OVERPAYMENT, MySkoolReconciliationAuditService::GUARD_CONCESSION_REVIEW],
        ]);

        $this->assertSame(1, $res['applied_count']);

        $item = DB::table('invoice_items')->where('id', $itemId)->first();
        $this->assertSame(23300, (int) $item->paid_minor);
        $this->assertSame('paid', $item->status);

        $settlement = DB::table('qa_historical_settlements')->where('invoice_item_id', $itemId)->first();
        $this->assertSame(23300, (int) $settlement->settled_amount_minor);
        $this->assertSame(2700, (int) $settlement->unmatched_overpayment_minor);
    }

    /**
     * 6. Existing portal cashier collection protection.
     */
    public function test_06_existing_portal_cashier_collection_triggers_live_activity_hold(): void
    {
        [$fam, $stu, $enr] = $this->createFamilyAndStudent('FAM-PORTAL', 'GR-PORTAL', 'Portal Student', 'SAR');
        [$invId, $itemId] = $this->createObInvoiceItem($fam, $stu, $enr, 'GR-PORTAL', 'Monthly Fee - Oct-2026', 'SAR', 30000);
        $this->insertStagedCollection(601, '99601', 'GR-PORTAL', 'FAM-PORTAL', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 30000);

        // Add a live portal cashier payment allocation
        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $fam,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'receipt_number' => 'RCP-PORTAL-01',
            'idempotency_key' => 'IDEMP-PORTAL-TEST-06',
            'request_hash' => hash('sha256', 'test-06'),
            'received_by' => $this->userId,
            'amount_minor' => 30000,
            'unallocated_minor' => 0,
            'received_on' => now()->toDateString(),
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $itemId,
            'amount_minor' => 30000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(HistoricalPaymentReconciliationService::class);
        $preview = $service->preview(['gr' => 'GR-PORTAL'], $this->cutoffDate, $this->batchId);

        $this->assertSame(MySkoolReconciliationAuditService::GUARD_LIVE_ACTIVITY_HOLD, $preview['records'][0]['guard_class']);

        // Apply attempt must be blocked
        $applyRes = $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => 'GR-PORTAL',
        ]);
        $this->assertSame(0, $applyRes['applied_count']);
        $this->assertSame(1, $applyRes['skipped_count']);
    }

    /**
     * 7. Previously voided historical obligation protection.
     */
    public function test_07_previously_voided_historical_obligation_protected(): void
    {
        [$fam, $stu, $enr] = $this->createFamilyAndStudent('FAM-VOID', 'GR-VOID', 'Void Student', 'SAR');
        [$invId, $itemId] = $this->createObInvoiceItem($fam, $stu, $enr, 'GR-VOID', 'Monthly Fee - Oct-2026', 'SAR', 30000, ['status' => 'void']);
        $this->insertStagedCollection(701, '99701', 'GR-VOID', 'FAM-VOID', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 30000);

        $service = app(HistoricalPaymentReconciliationService::class);
        $preview = $service->preview(['gr' => 'GR-VOID'], $this->cutoffDate, $this->batchId);

        $this->assertSame(MySkoolReconciliationAuditService::GUARD_LIVE_ACTIVITY_HOLD, $preview['records'][0]['guard_class']);
    }

    /**
     * 8. Duplicate historical settlement execution is idempotent.
     */
    public function test_08_duplicate_historical_settlement_execution_is_idempotent(): void
    {
        [$fam, $stu, $enr] = $this->createFamilyAndStudent('FAM-IDEMP', 'GR-IDEMP', 'Idempotent Student', 'SAR');
        [$invId, $itemId] = $this->createObInvoiceItem($fam, $stu, $enr, 'GR-IDEMP', 'Monthly Fee - Oct-2026', 'SAR', 25000);
        $this->insertStagedCollection(801, '99801', 'GR-IDEMP', 'FAM-IDEMP', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 25000);

        $service = app(HistoricalPaymentReconciliationService::class);

        // Run 1: Applies settlement
        $run1 = $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => 'GR-IDEMP',
        ]);
        $this->assertSame(1, $run1['applied_count']);

        // Run 2: Second run on same item
        $run2 = $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => 'GR-IDEMP',
        ]);
        $this->assertSame(0, $run2['applied_count']);
        $this->assertSame(1, $run2['skipped_count']);

        // Verify item was not double-settled
        $item = DB::table('invoice_items')->where('id', $itemId)->first();
        $this->assertSame(25000, (int) $item->paid_minor);
        $this->assertSame(1, DB::table('qa_historical_settlements')->where('invoice_item_id', $itemId)->count());
    }

    /**
     * 9. Cross-currency mismatch protection.
     */
    public function test_09_cross_currency_mismatch_held(): void
    {
        [$fam, $stu, $enr] = $this->createFamilyAndStudent('8563', '8620', 'Ibrahim Yahya', 'AED');
        [$invId, $itemId] = $this->createObInvoiceItem($fam, $stu, $enr, '8620', 'Monthly Fee - Oct-2026', 'AED', 33000);
        // Staged collection in SAR instead of AED
        $this->insertStagedCollection(901, '27256', '8620', '8563', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 33000);

        $service = app(HistoricalPaymentReconciliationService::class);
        $preview = $service->preview(['gr' => '8620'], $this->cutoffDate, $this->batchId);

        $this->assertSame(MySkoolReconciliationAuditService::GUARD_CURRENCY_CONFLICT_HOLD, $preview['records'][0]['guard_class']);
        $this->assertSame(0, $preview['records'][0]['verified_settlement_minor']);
        $this->assertSame(33000, $preview['records'][0]['remaining_obligation_minor']);
    }

    /**
     * 10. Duplicate source obligation protection (GR 6562: AED 200 + AED 200 vs AED 200 monthly fee, even with zero Oct-2026 collection).
     */
    public function test_10_duplicate_source_obligation_held(): void
    {
        [$fam, $stu, $enr] = $this->createFamilyAndStudent('6562', '6562', 'Maryam Kashif', 'AED');
        DB::table('enrollments')->where('id', $enr)->update(['monthly_fee_minor' => 20000]);

        // Two duplicate items for the same month (AED 200 + AED 200 = AED 400 vs AED 200 monthly fee), with only a pre-cutoff Jul-2026 collection
        $this->createObInvoiceItem($fam, $stu, $enr, '6562', 'Monthly Fee - Oct-2026', 'AED', 20000);
        $this->createObInvoiceItem($fam, $stu, $enr, '6562', 'Monthly Fee - Oct-2026', 'AED', 20000);
        $this->insertStagedCollection(1001, '25323', '6562', '6562', 'Monthly Fee - Jul-2026', '2026-09-24', 'AED', 20000);

        $service = app(HistoricalPaymentReconciliationService::class);
        $preview = $service->preview(['gr' => '6562'], $this->cutoffDate, $this->batchId);

        $this->assertCount(2, $preview['records']);
        $this->assertSame(MySkoolReconciliationAuditService::GUARD_DUPLICATE_SOURCE_HOLD, $preview['records'][0]['guard_class']);
        $this->assertSame(MySkoolReconciliationAuditService::GUARD_DUPLICATE_SOURCE_HOLD, $preview['records'][1]['guard_class']);
        $this->assertSame(0, $preview['records'][0]['verified_settlement_minor']);
        $this->assertSame(20000, $preview['records'][0]['remaining_obligation_minor']);
    }

    /**
     * 11. Multiple currencies within one family are isolated.
     */
    public function test_11_multiple_currencies_within_one_family_isolated(): void
    {
        [$fam, $stuSar, $enrSar] = $this->createFamilyAndStudent('FAM-MULTI-CURR', 'GR-SAR', 'SAR Child', 'SAR');
        [, $stuAed, $enrAed] = $this->createFamilyAndStudent('FAM-MULTI-CURR', 'GR-AED', 'AED Child', 'AED');

        [$invSarId, $itemSarId] = $this->createObInvoiceItem($fam, $stuSar, $enrSar, 'GR-SAR', 'Monthly Fee - Oct-2026', 'SAR', 30000);
        [$invAedId, $itemAedId] = $this->createObInvoiceItem($fam, $stuAed, $enrAed, 'GR-AED', 'Monthly Fee - Oct-2026', 'AED', 20000);

        $this->insertStagedCollection(1101, '99111', 'GR-SAR', 'FAM-MULTI-CURR', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 30000);
        $this->insertStagedCollection(1102, '99112', 'GR-AED', 'FAM-MULTI-CURR', 'Monthly Fee - Oct-2026', '2026-10-23', 'AED', 20000);

        $service = app(HistoricalPaymentReconciliationService::class);

        // Apply only SAR
        $resSar = $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'currency' => 'SAR',
        ]);
        $this->assertSame(1, $resSar['applied_count']);

        $itemSar = DB::table('invoice_items')->where('id', $itemSarId)->first();
        $this->assertSame(30000, (int) $itemSar->paid_minor);
        $this->assertSame('paid', $itemSar->status);

        $itemAed = DB::table('invoice_items')->where('id', $itemAedId)->first();
        $this->assertSame(0, (int) $itemAed->paid_minor);
        $this->assertSame('active', $itemAed->status);
    }

    /**
     * 12. Correct parent invoice totals and status.
     */
    public function test_12_correct_parent_invoice_totals_and_status(): void
    {
        [$fam, $stu1, $enr1] = $this->createFamilyAndStudent('FAM-PARENT', 'GR-P1', 'Child One', 'SAR');
        [, $stu2, $enr2] = $this->createFamilyAndStudent('FAM-PARENT', 'GR-P2', 'Child Two', 'SAR');

        [$invId, $item1Id] = $this->createObInvoiceItem($fam, $stu1, $enr1, 'GR-P1', 'Monthly Fee - Oct-2026', 'SAR', 20000);
        [, $item2Id] = $this->createObInvoiceItem($fam, $stu2, $enr2, 'GR-P2', 'Monthly Fee - Oct-2026', 'SAR', 30000);

        // Initially invoice total is 50000, paid 0, status generated
        $invBefore = DB::table('invoices')->where('id', $invId)->first();
        $this->assertSame(50000, (int) $invBefore->total_minor);
        $this->assertSame(0, (int) $invBefore->paid_minor);

        $this->insertStagedCollection(1201, '99121', 'GR-P1', 'FAM-PARENT', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 20000);

        $service = app(HistoricalPaymentReconciliationService::class);
        $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => 'GR-P1',
        ]);

        $invMid = DB::table('invoices')->where('id', $invId)->first();
        $this->assertSame(20000, (int) $invMid->paid_minor);
        $this->assertSame('partial', $invMid->status);

        // Now settle second child
        $this->insertStagedCollection(1202, '99122', 'GR-P2', 'FAM-PARENT', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 30000);
        $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => 'GR-P2',
        ]);

        $invFinal = DB::table('invoices')->where('id', $invId)->first();
        $this->assertSame(50000, (int) $invFinal->paid_minor);
        $this->assertSame('paid', $invFinal->status);
    }

    /**
     * 13. Correct Fee Collection and challan collectible balance totals.
     */
    public function test_13_correct_fee_collection_and_challan_totals(): void
    {
        [$fam, $stu, $enr] = $this->createFamilyAndStudent('FAM-CHALLAN', 'GR-CH', 'Challan Student', 'SAR');
        [$invId, $itemId] = $this->createObInvoiceItem($fam, $stu, $enr, 'GR-CH', 'Monthly Fee - Oct-2026', 'SAR', 35000);
        $this->insertStagedCollection(1301, '99131', 'GR-CH', 'FAM-CHALLAN', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 35000);

        // Before settlement, live collectible query returns 35000
        $balanceBefore = DB::table('invoice_items')
            ->where('invoice_id', $invId)
            ->whereRaw('net_minor > paid_minor')
            ->selectRaw('SUM(net_minor - paid_minor) as balance')
            ->value('balance');
        $this->assertSame(35000, (int) $balanceBefore);

        $service = app(HistoricalPaymentReconciliationService::class);
        $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => 'GR-CH',
        ]);

        // After settlement, collectible balance drops to 0
        $balanceAfter = DB::table('invoice_items')
            ->where('invoice_id', $invId)
            ->whereRaw('net_minor > paid_minor')
            ->selectRaw('COALESCE(SUM(net_minor - paid_minor), 0) as balance')
            ->value('balance');
        $this->assertSame(0, (int) $balanceAfter);
    }

    /**
     * 14. Correct historical receipt display in FeeHistoryService without duplicate slips.
     */
    public function test_14_fee_history_service_displays_historical_receipt_without_duplicates(): void
    {
        [$fam, $stu, $enr] = $this->createFamilyAndStudent('FAM-HIST', 'GR-HIST', 'Hist Student', 'SAR');
        $this->createObInvoiceItem($fam, $stu, $enr, 'GR-HIST', 'Monthly Fee - Oct-2026', 'SAR', 20000);
        $this->insertStagedCollection(1401, 'SLIP-HIST-01', 'GR-HIST', 'FAM-HIST', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 20000);

        $service = app(HistoricalPaymentReconciliationService::class);
        $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => 'GR-HIST',
        ]);

        Config::set('imports.active_batch_id', $this->batchId);

        // FeeHistoryService should fetch historical receipt from qa_staged_collections
        $feeHistoryService = app(FeeHistoryService::class);
        $history = $feeHistoryService->getStudentFeeHistory($stu);

        $this->assertSame(1, $history->total());
        $slip = $history->items()[0];
        $this->assertSame('SLIP-HIST-01', $slip->slip_number);
        $this->assertSame('myskool', $slip->source);
    }

    /**
     * 15. No increase in portal cash collection reports.
     */
    public function test_15_no_increase_in_portal_cash_collection_reports(): void
    {
        [$fam, $stu, $enr] = $this->createFamilyAndStudent('FAM-CASH', 'GR-CASH', 'Cash Student', 'SAR');
        $this->createObInvoiceItem($fam, $stu, $enr, 'GR-CASH', 'Monthly Fee - Oct-2026', 'SAR', 40000);
        $this->insertStagedCollection(1501, '99151', 'GR-CASH', 'FAM-CASH', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 40000);

        $initialCashierTotal = (int) DB::table('payments')->sum('amount_minor');
        $this->assertSame(0, $initialCashierTotal);

        $service = app(HistoricalPaymentReconciliationService::class);
        $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => 'GR-CASH',
        ]);

        // Cashier payments table must remain 0
        $finalCashierTotal = (int) DB::table('payments')->sum('amount_minor');
        $this->assertSame(0, $finalCashierTotal);
        $this->assertSame(0, DB::table('payments')->count());
    }

    /**
     * 16. No historical payment recorded twice.
     */
    public function test_16_no_historical_payment_recorded_twice_in_payments_table(): void
    {
        $paymentsBefore = DB::table('payments')->count();

        [$fam, $stu, $enr] = $this->createFamilyAndStudent('FAM-DUPPAY', 'GR-DUPPAY', 'No Dup Student', 'SAR');
        $this->createObInvoiceItem($fam, $stu, $enr, 'GR-DUPPAY', 'Monthly Fee - Oct-2026', 'SAR', 20000);
        $this->insertStagedCollection(1601, '99161', 'GR-DUPPAY', 'FAM-DUPPAY', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 20000);

        $service = app(HistoricalPaymentReconciliationService::class);
        $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => 'GR-DUPPAY',
        ]);

        $paymentsAfter = DB::table('payments')->count();
        $this->assertSame($paymentsBefore, $paymentsAfter);
    }

    /**
     * 17. Safe retry after an interrupted transaction.
     */
    public function test_17_safe_retry_after_interrupted_transaction(): void
    {
        [$fam, $stu, $enr] = $this->createFamilyAndStudent('FAM-TXN', 'GR-TXN', 'Txn Student', 'SAR');
        [$invId, $itemId] = $this->createObInvoiceItem($fam, $stu, $enr, 'GR-TXN', 'Monthly Fee - Oct-2026', 'SAR', 22000);
        $this->insertStagedCollection(1701, '99171', 'GR-TXN', 'FAM-TXN', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 22000);

        // Simulate an interrupted transaction
        try {
            DB::transaction(function () use ($itemId) {
                DB::table('invoice_items')->where('id', $itemId)->update(['paid_minor' => 11000]);
                throw new RuntimeException("Simulated mid-transaction crash");
            });
        } catch (\Throwable $e) {
            // Expected
        }

        // Verify database rolled back cleanly
        $item = DB::table('invoice_items')->where('id', $itemId)->first();
        $this->assertSame(0, (int) $item->paid_minor);

        // Now perform normal apply successfully
        $service = app(HistoricalPaymentReconciliationService::class);
        $res = $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => 'GR-TXN',
        ]);
        $this->assertSame(1, $res['applied_count']);

        $itemAfter = DB::table('invoice_items')->where('id', $itemId)->first();
        $this->assertSame(22000, (int) $itemAfter->paid_minor);
        $this->assertSame('paid', $itemAfter->status);
    }

    /**
     * 18. Reconciliation reversal preserving financial evidence.
     */
    public function test_18_reconciliation_reversal_preserves_financial_evidence(): void
    {
        [$fam, $stu, $enr] = $this->createFamilyAndStudent('FAM-REV', 'GR-REV', 'Reversal Student', 'SAR');
        [$invId, $itemId] = $this->createObInvoiceItem($fam, $stu, $enr, 'GR-REV', 'Monthly Fee - Oct-2026', 'SAR', 30000);
        $this->insertStagedCollection(1801, '99181', 'GR-REV', 'FAM-REV', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 30000);

        $service = app(HistoricalPaymentReconciliationService::class);
        $applyRes = $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => 'GR-REV',
        ]);
        $this->assertSame(1, $applyRes['applied_count']);
        $reconId = $applyRes['applied_settlements'][0]['reconciliation_id'];

        // Now reverse compensating action
        $reverseRes = $service->reverse($reconId, $this->userId);
        $this->assertSame('reversed', $reverseRes['status']);

        // Check restored item state
        $itemRestored = DB::table('invoice_items')->where('id', $itemId)->first();
        $this->assertSame(0, (int) $itemRestored->paid_minor);
        $this->assertSame('active', $itemRestored->status);

        // Check parent invoice restored state
        $invRestored = DB::table('invoices')->where('id', $invId)->first();
        $this->assertSame(0, (int) $invRestored->paid_minor);
        $this->assertSame('generated', $invRestored->status);

        // Check audit record preserved in qa_historical_settlements with status = 'reversed'
        $auditRow = DB::table('qa_historical_settlements')->where('reconciliation_id', $reconId)->first();
        $this->assertNotNull($auditRow);
        $this->assertSame('reversed', $auditRow->status);
        $this->assertSame($this->userId, (int) $auditRow->reversed_by);
        $this->assertNotNull($auditRow->reversed_at);
    }

    /**
     * 19. Shared MySkool slip #27328 across Family 5929 siblings (GR 5929 & GR 5930),
     * double-consumption prevention, and multi-item out-of-order reversal integrity.
     */
    public function test_19_shared_slip_27328_across_family_5929_siblings_and_multi_item_reversal_integrity(): void
    {
        [$fam, $stu5929, $enr5929] = $this->createFamilyAndStudent('5929', '5929', 'Hafsa Feroz', 'QAR');
        [, $stu5930, $enr5930] = $this->createFamilyAndStudent('5929', '5930', 'Zahra Feroz', 'QAR');

        [$invId, $item5929] = $this->createObInvoiceItem($fam, $stu5929, $enr5929, '5929', 'Monthly Fee - Oct-2026', 'QAR', 28000);
        [, $item5930] = $this->createObInvoiceItem($fam, $stu5930, $enr5930, '5930', 'Monthly Fee - Oct-2026', 'QAR', 28000);

        // Both siblings paid under MySkool slip #27328 on October 24, 2026 (distinct source rows 34702 and 34703)
        $col5929 = $this->insertStagedCollection(34702, '27328', '5929', '5929', 'Monthly Fee - Oct-2026', '2026-10-24', 'QAR', 28000);
        $col5930 = $this->insertStagedCollection(34703, '27328', '5930', '5929', 'Monthly Fee - Oct-2026', '2026-10-24', 'QAR', 23000);

        $service = app(HistoricalPaymentReconciliationService::class);

        // Settle GR 5929 (full QAR 280) and GR 5930 (partial QAR 230 with concession review class enabled)
        $res5929 = $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => '5929',
        ]);
        $this->assertSame(1, $res5929['applied_count']);
        $recon5929 = $res5929['applied_settlements'][0]['reconciliation_id'];

        $res5930 = $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => '5930',
            'classes' => [MySkoolReconciliationAuditService::GUARD_CONCESSION_REVIEW],
        ]);
        $this->assertSame(1, $res5930['applied_count']);
        $recon5930 = $res5930['applied_settlements'][0]['reconciliation_id'];

        // Verify parent invoice reflects both sibling settlements (28000 + 23000 = 51000 out of 56000 -> partial)
        $invAfterBoth = DB::table('invoices')->where('id', $invId)->first();
        $this->assertSame(56000, (int) $invAfterBoth->total_minor);
        $this->assertSame(51000, (int) $invAfterBoth->paid_minor);
        $this->assertSame('partial', $invAfterBoth->status);

        // Out-of-order reversal: reverse GR 5929 first while GR 5930 remains applied!
        // Parent invoice paid_minor must become 23000 (NOT 0 from previous_invoice_paid_minor!)
        $service->reverse($recon5929, $this->userId);

        $invAfterFirstRev = DB::table('invoices')->where('id', $invId)->first();
        $this->assertSame(56000, (int) $invAfterFirstRev->total_minor);
        $this->assertSame(23000, (int) $invAfterFirstRev->paid_minor);
        $this->assertSame('partial', $invAfterFirstRev->status);

        // Now reverse GR 5930 as well -> parent invoice returns to 0 paid and 'generated'
        $service->reverse($recon5930, $this->userId);

        $invAfterSecondRev = DB::table('invoices')->where('id', $invId)->first();
        $this->assertSame(56000, (int) $invAfterSecondRev->total_minor);
        $this->assertSame(0, (int) $invAfterSecondRev->paid_minor);
        $this->assertSame('generated', $invAfterSecondRev->status);
    }

    /**
     * 20. Database-level unique constraint protects source collection allocations across items,
     * and ON DELETE RESTRICT prevents silent cascading deletion of historical settlement audit evidence.
     */
    public function test_20_database_level_uniqueness_and_restrict_delete_protect_source_collections_and_audit_trail(): void
    {
        [$fam, $stu, $enr] = $this->createFamilyAndStudent('FAM-DBSAFE', 'GR-DBSAFE', 'DB Safety Student', 'QAR');
        [$invId, $item1Id] = $this->createObInvoiceItem($fam, $stu, $enr, 'GR-DBSAFE', 'Monthly Fee - Oct-2026', 'QAR', 28000);
        $colId = $this->insertStagedCollection(2001, 'SLIP-DBSAFE', 'GR-DBSAFE', 'FAM-DBSAFE', 'Monthly Fee - Oct-2026', '2026-10-24', 'QAR', 28000);

        $service = app(HistoricalPaymentReconciliationService::class);
        $applyRes = $service->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => 'GR-DBSAFE',
        ]);
        $this->assertSame(1, $applyRes['applied_count']);

        // 1. Verify companion allocation row was created with active_staged_collection_id = $colId
        $allocRow = DB::table('qa_historical_settlement_collections')
            ->where('qa_staged_collection_id', $colId)
            ->first();
        $this->assertNotNull($allocRow);
        $this->assertSame($colId, (int) $allocRow->active_staged_collection_id);
        $this->assertSame('applied', $allocRow->status);

        // 2. Verify database-level UNIQUE constraint rejects a second active allocation for the same staged collection
        [, $item2Id] = $this->createObInvoiceItem($fam, $stu, $enr, 'GR-DBSAFE', 'Monthly Fee - Sep-2026', 'QAR', 28000);
        try {
            DB::table('qa_historical_settlement_collections')->insert([
                'settlement_id' => $allocRow->settlement_id,
                'reconciliation_id' => 'RECON-DUP-TEST',
                'qa_staged_collection_id' => $colId,
                'invoice_item_id' => $item2Id,
                'active_staged_collection_id' => $colId,
                'status' => 'applied',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected QueryException for duplicate active_staged_collection_id was not thrown.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // 3. Verify ON DELETE RESTRICT prevents silent deletion of qa_historical_settlements when deleting invoice_items or invoices
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
        try {
            DB::table('invoice_items')->where('id', $item1Id)->delete();
            $this->fail('Expected QueryException when deleting an invoice_item referenced by qa_historical_settlements.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // Audit record remains 100% intact
        $this->assertSame(1, DB::table('qa_historical_settlements')->where('invoice_item_id', $item1Id)->count());
    }

    /**
     * 21. CLI and service mutations require an explicitly authorized administrator and reject missing or non-admin actors.
     */
    public function test_21_cli_and_service_require_explicit_authorized_administrator_no_arbitrary_fallback(): void
    {
        // Log out default test session so auth()->id() is null (simulating CLI execution)
        auth()->guard()->logout();

        [$fam, $stu, $enr] = $this->createFamilyAndStudent('FAM-CLI-ACTOR', 'GR-CLI-ACTOR', 'CLI Actor Student', 'SAR');
        [$invId, $itemId] = $this->createObInvoiceItem($fam, $stu, $enr, 'GR-CLI-ACTOR', 'Monthly Fee - Oct-2026', 'SAR', 30000);
        $this->insertStagedCollection(2101, 'SLIP-CLI-21', 'GR-CLI-ACTOR', 'FAM-CLI-ACTOR', 'Monthly Fee - Oct-2026', '2026-10-24', 'SAR', 30000);

        $cashier = User::create([
            'name' => 'Counter Cashier',
            'email' => 'cashier-cli@iqra.test',
            'password' => bcrypt('Secret2026!'),
            'role' => 'cashier',
            'is_active' => true,
        ]);

        // 1. CLI apply without --actor-id fails
        $this->artisan('reconciliation:myskool-historical', [
            '--apply' => true,
            '--confirm-cutoff' => true,
            '--confirm-owner' => true,
            '--batch-id' => $this->batchId,
            '--gr' => 'GR-CLI-ACTOR',
        ])->assertExitCode(1);

        // 2. CLI apply with non-admin cashier --actor-id fails
        $this->artisan('reconciliation:myskool-historical', [
            '--apply' => true,
            '--confirm-cutoff' => true,
            '--confirm-owner' => true,
            '--actor-id' => $cashier->id,
            '--batch-id' => $this->batchId,
            '--gr' => 'GR-CLI-ACTOR',
        ])->assertExitCode(1);

        // Confirm item is still unpaid
        $this->assertSame(0, (int) DB::table('invoice_items')->where('id', $itemId)->value('paid_minor'));

        // 3. CLI apply with valid admin --actor-id succeeds and records exact admin ID
        $this->artisan('reconciliation:myskool-historical', [
            '--apply' => true,
            '--confirm-cutoff' => true,
            '--confirm-owner' => true,
            '--actor-id' => $this->userId,
            '--batch-id' => $this->batchId,
            '--gr' => 'GR-CLI-ACTOR',
        ])->assertExitCode(0);

        $settlement = DB::table('qa_historical_settlements')->where('invoice_item_id', $itemId)->first();
        $this->assertNotNull($settlement);
        $this->assertSame($this->userId, (int) $settlement->applied_by);

        // 4. CLI rollback without --actor-id fails, with valid --actor-id succeeds
        $this->artisan('reconciliation:myskool-historical', [
            '--rollback' => true,
            '--reconcile-id' => $settlement->reconciliation_id,
            '--confirm-owner' => true,
        ])->assertExitCode(1);

        $this->artisan('reconciliation:myskool-historical', [
            '--rollback' => true,
            '--reconcile-id' => $settlement->reconciliation_id,
            '--confirm-owner' => true,
            '--actor-id' => $this->userId,
        ])->assertExitCode(0);

        $reversed = DB::table('qa_historical_settlements')->where('id', $settlement->id)->first();
        $this->assertSame('reversed', $reversed->status);
        $this->assertSame($this->userId, (int) $reversed->reversed_by);
    }
}
