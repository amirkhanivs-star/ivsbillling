<?php

namespace Tests\Feature;

use App\Services\Migration\MySkoolReconciliationAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class MySkoolReconciliationAuditTest extends TestCase
{
    use RefreshDatabase;

    private string $batchId = 'BATCH-TEST-RECON-20261027';
    private int $campusId;
    private int $deptId;
    private int $monthlyFeeTypeId;
    private int $tuitionFeeTypeId;
    private int $obRunId;
    private int $novRunId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->campusId = (int) DB::table('campuses')->value('id');
        $this->deptId = (int) DB::table('departments')->where('code', 'school')->value('id');
        $this->monthlyFeeTypeId = (int) DB::table('fee_types')->where('code', 'monthly')->value('id');
        $this->tuitionFeeTypeId = (int) (DB::table('fee_types')->where('code', 'tuition')->value('id') ?: $this->monthlyFeeTypeId);
        $this->userId = (int) DB::table('users')->value('id');

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

    public function test_classifies_post_cutoff_full_settlement_and_pre_cutoff_residual_correctly(): void
    {
        // 1. Post-cutoff full settlement (paid Oct 23, 2026 > Oct 22, 2026 cutoff)
        [$fam1, $stu1, $enr1] = $this->createFamilyAndStudent('FAM-1001', 'GR-1001', 'Fictional Student One', 'SAR');
        $this->createObInvoiceItem($fam1, $stu1, $enr1, 'Monthly Fee - Oct-2026', 'SAR', 28000);
        $this->insertStagedCollection(1, '99001', 'GR-1001', 'FAM-1001', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 28000);

        // Also create a November 2026 invoice with a snapshot of 28000 to verify subsequent snapshot reporting
        DB::table('invoices')->insert([
            'billing_run_id' => $this->novRunId,
            'family_id' => $fam1,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'invoice_number' => 'SCH-NOV-1001',
            'issued_on' => '2026-10-26',
            'due_date' => '2026-11-10',
            'total_minor' => 28000,
            'paid_minor' => 0,
            'previous_dues_snapshot_minor' => 28000,
            'status' => 'generated',
            'approved_to_send' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Genuine pre-cutoff residual balance (paid Oct 15, 2026 <= Oct 22, 2026 cutoff)
        [$fam2, $stu2, $enr2] = $this->createFamilyAndStudent('FAM-1002', 'GR-1002', 'Fictional Student Two', 'SAR');
        $this->createObInvoiceItem($fam2, $stu2, $enr2, 'Monthly Fee - Oct-2026', 'SAR', 10000);
        $this->insertStagedCollection(2, '98001', 'GR-1002', 'FAM-1002', 'Monthly Fee - Oct-2026', '2026-10-15', 'SAR', 18000);

        $service = new MySkoolReconciliationAuditService();
        $result = $service->audit(null, $this->batchId, '2026-10-22');

        $this->assertSame(2, $result['summary']['total_overlapping_items']);
        $this->assertSame(1, $result['summary']['post_cutoff_full_settlements']);
        $this->assertSame(1, $result['summary']['pre_cutoff_residuals']);

        $byGr = collect($result['records'])->keyBy('student_gr');

        $r1 = $byGr->get('GR-1001');
        $this->assertSame(MySkoolReconciliationAuditService::CLASS_POST_CUTOFF_FULL, $r1['reconciliation_classification']);
        $this->assertSame('280.00', $r1['original_opening_balance']);
        $this->assertSame('0.00', $r1['historical_payments_before_cutoff']);
        $this->assertSame('280.00', $r1['historical_payments_after_cutoff']);
        $this->assertSame('0.00', $r1['potential_remaining_balance']);
        $this->assertStringContainsString('SCH-NOV-1001', $r1['subsequent_snapshot_invoices']);

        $r2 = $byGr->get('GR-1002');
        $this->assertSame(MySkoolReconciliationAuditService::CLASS_PRE_CUTOFF_RESIDUAL, $r2['reconciliation_classification']);
        $this->assertSame('100.00', $r2['original_opening_balance']);
        $this->assertSame('180.00', $r2['historical_payments_before_cutoff']);
        $this->assertSame('0.00', $r2['historical_payments_after_cutoff']);
        $this->assertSame('100.00', $r2['potential_remaining_balance']);

        $this->assertSame(1, $result['subsequent_snapshots']['november_2026_affected_invoices_count']);
        $this->assertSame(28000, $result['subsequent_snapshots']['overstatement_minor_by_currency']['SAR']);
    }

    public function test_distinguishes_partial_payments_and_sibling_concessions(): void
    {
        // 1. Ad-hoc partial payment by single student (paid 75 out of 300 after cutoff)
        [$famPartial, $stuPartial, $enrPartial] = $this->createFamilyAndStudent('FAM-PARTIAL', 'GR-PARTIAL', 'Partial Payer', 'AED');
        $this->createObInvoiceItem($famPartial, $stuPartial, $enrPartial, 'Monthly Fee - Oct-2026', 'AED', 30000);
        $this->insertStagedCollection(10, '99100', 'GR-PARTIAL', 'FAM-PARTIAL', 'Monthly Fee - Oct-2026', '2026-10-23', 'AED', 7500);

        // 2. Sibling concession: 2 siblings in FAM-SIB, both paid on Slip 99200 on 2026-10-24;
        //    older sibling paid full 280 QAR, younger sibling paid 230 QAR (50 QAR sibling concession)
        [$famSib, $stuSib1, $enrSib1] = $this->createFamilyAndStudent('FAM-SIB', 'GR-SIB-1', 'Older Sibling', 'QAR');
        [, $stuSib2, $enrSib2] = $this->createFamilyAndStudent('FAM-SIB', 'GR-SIB-2', 'Younger Sibling', 'QAR');

        $this->createObInvoiceItem($famSib, $stuSib1, $enrSib1, 'Monthly Fee - Oct-2026', 'QAR', 28000);
        $this->createObInvoiceItem($famSib, $stuSib2, $enrSib2, 'Monthly Fee - Oct-2026', 'QAR', 28000);

        $this->insertStagedCollection(11, '99200', 'GR-SIB-1', 'FAM-SIB', 'Monthly Fee - Oct-2026', '2026-10-24', 'QAR', 28000);
        $this->insertStagedCollection(12, '99200', 'GR-SIB-2', 'FAM-SIB', 'Monthly Fee - Oct-2026', '2026-10-24', 'QAR', 23000);

        $service = new MySkoolReconciliationAuditService();
        $result = $service->audit(null, $this->batchId, '2026-10-22');

        $this->assertSame(2, $result['summary']['post_cutoff_partial_or_concessions']);
        $this->assertSame(1, $result['summary']['post_cutoff_partial_payments']);
        $this->assertSame(1, $result['summary']['post_cutoff_sibling_concessions']);

        $byGr = collect($result['records'])->keyBy('student_gr');
        $this->assertSame(
            MySkoolReconciliationAuditService::CLASS_POST_CUTOFF_PARTIAL,
            $byGr->get('GR-PARTIAL')['reconciliation_classification']
        );
        $this->assertSame('225.00', $byGr->get('GR-PARTIAL')['potential_remaining_balance']);

        $this->assertSame(
            MySkoolReconciliationAuditService::CLASS_POST_CUTOFF_CONCESSION,
            $byGr->get('GR-SIB-2')['reconciliation_classification']
        );
        $this->assertSame('50.00', $byGr->get('GR-SIB-2')['potential_remaining_balance']);
    }

    public function test_aggregates_multiple_receipts_and_prevents_duplicate_allocation_double_counting(): void
    {
        // 1. Two separate valid post-cutoff receipts (150 + 100 = 250) + one pre-cutoff receipt (50)
        [$famMulti, $stuMulti, $enrMulti] = $this->createFamilyAndStudent('FAM-MULTI', 'GR-MULTI', 'Multi Receipt Student', 'SAR');
        $this->createObInvoiceItem($famMulti, $stuMulti, $enrMulti, 'Monthly Fee - Oct-2026', 'SAR', 25000);

        $this->insertStagedCollection(20, '97001', 'GR-MULTI', 'FAM-MULTI', 'Monthly Fee - Oct-2026', '2026-10-10', 'SAR', 5000);
        $this->insertStagedCollection(21, '99301', 'GR-MULTI', 'FAM-MULTI', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 15000);
        $this->insertStagedCollection(22, '99302', 'GR-MULTI', 'FAM-MULTI', 'Monthly Fee - Oct-2026', '2026-10-24', 'SAR', 10000);
        // Duplicate staged row with the same source_row = 22 (must NOT be double-counted)
        $this->insertStagedCollection(22, '99302', 'GR-MULTI', 'FAM-MULTI', 'Monthly Fee - Oct-2026', '2026-10-24', 'SAR', 10000);

        // 2. Subject-specific differentiation: Tuition (Math) should NOT match Tuition (English)
        [$famSub, $stuSub, $enrSub] = $this->createFamilyAndStudent('FAM-SUB', 'GR-SUB', 'Subject Student', 'SAR');
        $this->createObInvoiceItem($famSub, $stuSub, $enrSub, 'Tuition Fee (Math) - Oct-2026', 'SAR', 20000, [
            'fee_type_id' => $this->tuitionFeeTypeId,
        ]);
        $this->insertStagedCollection(23, '99305', 'GR-SUB', 'FAM-SUB', 'Tuition Fee (English) - Oct-2026', '2026-10-23', 'SAR', 20000);

        $service = new MySkoolReconciliationAuditService();
        $result = $service->audit(null, $this->batchId, '2026-10-22');

        $this->assertSame(1, $result['summary']['total_overlapping_items']);
        $this->assertSame(1, $result['summary']['unmatched_ob_items']);

        $r = $result['records'][0];
        $this->assertSame('GR-MULTI', $r['student_gr']);
        $this->assertSame(MySkoolReconciliationAuditService::CLASS_POST_CUTOFF_FULL, $r['reconciliation_classification']);
        $this->assertSame('50.00', $r['historical_payments_before_cutoff']);
        $this->assertSame('250.00', $r['historical_payments_after_cutoff']);
        $this->assertSame('0.00', $r['potential_remaining_balance']);
    }

    public function test_protects_items_with_existing_portal_payments_or_fee_adjustments(): void
    {
        // 1. Item with existing live portal payment allocation
        [$famPay, $stuPay, $enrPay] = $this->createFamilyAndStudent('FAM-PORTAL-PAY', 'GR-PORTAL-PAY', 'Portal Paid Student', 'SAR');
        [, $itemPayId] = $this->createObInvoiceItem(
            $famPay,
            $stuPay,
            $enrPay,
            'Monthly Fee - Oct-2026',
            'SAR',
            30000,
            ['paid_minor' => 10000, 'status' => 'active']
        );

        $paymentId = (int) DB::table('payments')->insertGetId([
            'family_id' => $famPay,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'receipt_number' => 'RCP-PORTAL-001',
            'amount_minor' => 10000,
            'unallocated_minor' => 0,
            'received_on' => '2026-10-26',
            'idempotency_key' => 'idem-portal-001',
            'request_hash' => hash('sha256', 'idem-portal-001'),
            'status' => 'received',
            'received_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $itemPayId,
            'amount_minor' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertStagedCollection(30, '99401', 'GR-PORTAL-PAY', 'FAM-PORTAL-PAY', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 30000);

        // 2. Item with existing portal fee adjustment (exemption/discount/void)
        [$famAdj, $stuAdj, $enrAdj] = $this->createFamilyAndStudent('FAM-PORTAL-ADJ', 'GR-PORTAL-ADJ', 'Portal Adjusted Student', 'SAR');
        [, $itemAdjId] = $this->createObInvoiceItem(
            $famAdj,
            $stuAdj,
            $enrAdj,
            'Monthly Fee - Oct-2026',
            'SAR',
            30000,
            ['exemption_minor' => 5000, 'net_minor' => 25000]
        );

        DB::table('fee_adjustments')->insert([
            'invoice_item_id' => $itemAdjId,
            'user_id' => $this->userId,
            'kind' => 'exemption',
            'amount_minor' => 5000,
            'previous_amount_minor' => 30000,
            'new_amount_minor' => 25000,
            'reason' => 'Approved hardship exemption',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertStagedCollection(31, '99402', 'GR-PORTAL-ADJ', 'FAM-PORTAL-ADJ', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 25000);

        $service = new MySkoolReconciliationAuditService();
        $result = $service->audit(null, $this->batchId, '2026-10-22');

        $this->assertSame(0, $result['summary']['post_cutoff_full_settlements']);
        $this->assertSame(2, $result['summary']['anomalies_and_conflicts_requiring_review']);
        $this->assertSame(2, $result['classifications'][MySkoolReconciliationAuditService::CLASS_CONFLICT_PORTAL_ACTIVITY]);

        $byGr = collect($result['records'])->keyBy('student_gr');
        $this->assertStringStartsWith('YES', $byGr->get('GR-PORTAL-PAY')['existing_portal_payment_indicator']);
        $this->assertSame('NO', $byGr->get('GR-PORTAL-PAY')['existing_adjustment_indicator']);
        $this->assertSame('NO', $byGr->get('GR-PORTAL-ADJ')['existing_portal_payment_indicator']);
        $this->assertStringStartsWith('YES', $byGr->get('GR-PORTAL-ADJ')['existing_adjustment_indicator']);
    }

    public function test_detects_currency_mismatches_and_ambiguous_or_missing_evidence(): void
    {
        // 1. Currency mismatch (OB is AED, staged collection is SAR)
        [$famCurr, $stuCurr, $enrCurr] = $this->createFamilyAndStudent('FAM-CURR', 'GR-CURR', 'Currency Mismatch Student', 'AED');
        $this->createObInvoiceItem($famCurr, $stuCurr, $enrCurr, 'Monthly Fee - Oct-2026', 'AED', 33000);
        $this->insertStagedCollection(40, '99501', 'GR-CURR', 'FAM-CURR', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 33000);

        // 2a. Genuine split pre-cutoff residual (GR 4814 pattern: AED 210 + AED 250 + AED 40 pre-cutoff paid = AED 500 monthly fee)
        [$fam4814, $stu4814, $enr4814] = $this->createFamilyAndStudent('4814', '4814', 'Beenal Seetamonee', 'AED');
        DB::table('enrollments')->where('id', $enr4814)->update(['monthly_fee_minor' => 50000]);
        $this->createObInvoiceItem($fam4814, $stu4814, $enr4814, 'Monthly Fee - May-2026', 'AED', 21000, [
            'period_key' => 'monthly:2026-05-01..2026-05-31',
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
        ]);
        $this->createObInvoiceItem($fam4814, $stu4814, $enr4814, 'Monthly Fee - May-2026', 'AED', 25000, [
            'period_key' => 'monthly:2026-05-01..2026-05-31',
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
        ]);
        $this->insertStagedCollection(41, '24127', '4814', '4814', 'Monthly Fee - May-2026', '2026-07-03', 'AED', 4000);

        // 2b. Suspected duplicate OB items for same student & period (GR 6562 pattern: AED 200 + AED 200 = AED 400 vs AED 200 monthly fee, zero Oct-2026 collection)
        [$fam6562, $stu6562, $enr6562] = $this->createFamilyAndStudent('6562', '6562', 'Maryam Kashif', 'AED');
        DB::table('enrollments')->where('id', $enr6562)->update(['monthly_fee_minor' => 20000]);
        $this->createObInvoiceItem($fam6562, $stu6562, $enr6562, 'Monthly Fee - Oct-2026', 'AED', 20000);
        $this->createObInvoiceItem($fam6562, $stu6562, $enr6562, 'Monthly Fee - Oct-2026', 'AED', 20000);

        // 3. Family code mismatch between OB family_code and staged collection family_code
        [$famMis, $stuMis, $enrMis] = $this->createFamilyAndStudent('FAM-REAL-01', 'GR-FAM-MIS', 'Family Code Mismatch Student', 'SAR');
        $this->createObInvoiceItem($famMis, $stuMis, $enrMis, 'Monthly Fee - Oct-2026', 'SAR', 20000);
        $this->insertStagedCollection(42, '99503', 'GR-FAM-MIS', 'FAM-WRONG-99', 'Monthly Fee - Oct-2026', '2026-10-23', 'SAR', 20000);

        // 4. Missing parsed payment date in staged collection
        [$famNoDate, $stuNoDate, $enrNoDate] = $this->createFamilyAndStudent('FAM-NODATE', 'GR-NODATE', 'Missing Date Student', 'SAR');
        $this->createObInvoiceItem($famNoDate, $stuNoDate, $enrNoDate, 'Monthly Fee - Oct-2026', 'SAR', 20000);
        $this->insertStagedCollection(43, '99504', 'GR-NODATE', 'FAM-NODATE', 'Monthly Fee - Oct-2026', null, 'SAR', 20000);

        $service = new MySkoolReconciliationAuditService();
        $result = $service->audit(null, $this->batchId, '2026-10-22');

        $this->assertSame(2, $result['summary']['pre_cutoff_residuals']);
        $this->assertSame(5, $result['summary']['anomalies_and_conflicts_requiring_review']);
        $this->assertSame(2, $result['classifications'][MySkoolReconciliationAuditService::CLASS_PRE_CUTOFF_RESIDUAL]);
        $this->assertSame(1, $result['classifications'][MySkoolReconciliationAuditService::CLASS_REVIEW_CURRENCY_MISMATCH]);
        $this->assertSame(3, $result['classifications'][MySkoolReconciliationAuditService::CLASS_REVIEW_AMBIGUOUS_MATCH]);
        $this->assertSame(1, $result['classifications'][MySkoolReconciliationAuditService::CLASS_REVIEW_MISSING_EVIDENCE]);

        $records4814 = collect($result['records'])->where('student_gr', '4814')->values();
        $this->assertCount(2, $records4814);
        $this->assertSame(MySkoolReconciliationAuditService::GUARD_KEEP_GENUINE_RESIDUAL, $records4814[0]['guard_class']);
        $this->assertSame(MySkoolReconciliationAuditService::GUARD_KEEP_GENUINE_RESIDUAL, $records4814[1]['guard_class']);

        $records6562 = collect($result['records'])->where('student_gr', '6562')->values();
        $this->assertCount(2, $records6562);
        $this->assertSame(MySkoolReconciliationAuditService::GUARD_DUPLICATE_SOURCE_HOLD, $records6562[0]['guard_class']);
        $this->assertSame(MySkoolReconciliationAuditService::GUARD_DUPLICATE_SOURCE_HOLD, $records6562[1]['guard_class']);
    }

    public function test_command_exports_csv_and_enforces_strictly_read_only_behavior(): void
    {
        [$fam, $stu, $enr] = $this->createFamilyAndStudent('FAM-CMD', 'GR-CMD', 'Command Audit Student', 'SAR');
        $this->createObInvoiceItem($fam, $stu, $enr, 'Monthly Fee - Oct-2026', 'SAR', 31500);
        $this->insertStagedCollection(50, '99600', 'GR-CMD', 'FAM-CMD', 'Monthly Fee - Oct-2026', '2026-10-24', 'SAR', 31500);

        $csvPath = storage_path('app/reconciliation/test_audit_export.csv');
        if (File::exists($csvPath)) {
            File::delete($csvPath);
        }

        // Snapshot financial table counts and sums before running command
        $beforeInvoices = DB::table('invoices')->get()->toArray();
        $beforeItems = DB::table('invoice_items')->get()->toArray();
        $beforePayments = DB::table('payments')->count();
        $beforeAllocations = DB::table('payment_allocations')->count();
        $beforeAdjustments = DB::table('fee_adjustments')->count();

        // Listen for any SQL write statements during command execution
        $writeStatements = [];
        DB::listen(function ($query) use (&$writeStatements) {
            $sql = ltrim(strtoupper($query->sql));
            if (preg_match('/^(INSERT|UPDATE|DELETE|ALTER|DROP|TRUNCATE|REPLACE)\b/', $sql)) {
                $writeStatements[] = $query->sql;
            }
        });

        $this->artisan('billing:audit-myskool-reconciliation', [
            '--batch-id' => $this->batchId,
            '--export' => 'csv',
            '--output' => $csvPath,
        ])->assertExitCode(0);

        // Verify zero SQL write statements were executed
        $this->assertEmpty($writeStatements, 'Audit command must not execute any SQL write statements.');

        // Verify financial records are completely untouched
        $this->assertEquals($beforeInvoices, DB::table('invoices')->get()->toArray());
        $this->assertEquals($beforeItems, DB::table('invoice_items')->get()->toArray());
        $this->assertSame($beforePayments, DB::table('payments')->count());
        $this->assertSame($beforeAllocations, DB::table('payment_allocations')->count());
        $this->assertSame($beforeAdjustments, DB::table('fee_adjustments')->count());

        // Verify CSV file was generated with expected headers and content
        $this->assertFileExists($csvPath);
        $lines = array_map('str_getcsv', file($csvPath));
        $this->assertCount(2, $lines);
        $this->assertSame('Student GR', $lines[0][0]);
        $this->assertSame('Stable family code', $lines[0][1]);
        $this->assertSame('Reconciliation classification', $lines[0][12]);
        $this->assertSame('GR-CMD', $lines[1][0]);
        $this->assertSame('FAM-CMD', $lines[1][1]);
        $this->assertSame(MySkoolReconciliationAuditService::CLASS_POST_CUTOFF_FULL, $lines[1][12]);

        File::delete($csvPath);
    }

    public function test_fails_closed_when_staged_collection_batch_evidence_is_missing(): void
    {
        $service = new MySkoolReconciliationAuditService();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Zero staged collection records found");

        $service->audit(null, 'BATCH-NON-EXISTENT-999', '2026-10-22');
    }
}
