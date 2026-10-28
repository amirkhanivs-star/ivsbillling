<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FeeAdjustmentService;
use App\Services\FeeEligibilityService;
use App\Services\FeeHistoryService;
use App\Services\InvoiceService;
use App\Services\Migration\HistoricalPaymentReconciliationService;
use App\Services\Migration\MySkoolReconciliationAuditService;
use App\Services\VoidedFeeRestorationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class VoidedFeeRestorationTest extends TestCase
{
    use RefreshDatabase;

    private string $batchId = 'BATCH-20261025-FINAL-MYSKOOL';
    private string $cutoffDate = '2026-10-22';
    private int $campusId;
    private int $deptId;
    private int $monthlyFeeTypeId;
    private int $obRunId;
    private int $novRunId;
    private User $adminUser;
    private User $cashierUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        Config::set('reconciliation.allow_apply', true);

        $this->batchId = (string) config('imports.active_batch_id', 'BATCH-20261025-FINAL-MYSKOOL');
        $this->campusId = (int) DB::table('campuses')->value('id');
        $this->deptId = (int) DB::table('departments')->where('code', 'school')->value('id');
        $this->monthlyFeeTypeId = (int) DB::table('fee_types')->where('code', 'monthly')->value('id');

        $this->adminUser = User::first() ?? User::create([
            'name' => 'System Admin',
            'email' => 'admin@iqraschool.edu.sa',
            'password' => bcrypt('StrongSecret2026!'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->cashierUser = User::create([
            'name' => 'Counter Cashier',
            'email' => 'cashier@iqraschool.edu.sa',
            'password' => bcrypt('StrongSecret2026!'),
            'role' => 'cashier',
            'is_active' => true,
        ]);

        $this->obRunId = (int) DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'opening_balance',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'status' => 'generated',
            'created_by' => $this->adminUser->id,
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
            'created_by' => $this->adminUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Default catalog rate
        DB::table('fee_catalog_rates')->updateOrInsert(
            [
                'fee_type_id' => $this->monthlyFeeTypeId,
                'department_id' => $this->deptId,
                'class_label' => 'Standard',
                'currency' => 'SAR',
            ],
            [
                'amount_minor' => 30000,
                'frequency' => 'monthly',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('fee_catalog_rates')->updateOrInsert(
            [
                'fee_type_id' => $this->monthlyFeeTypeId,
                'department_id' => $this->deptId,
                'class_label' => 'Standard',
                'currency' => 'QAR',
            ],
            [
                'amount_minor' => 28000,
                'frequency' => 'monthly',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function createFamilyAndStudent(string $familyCode, string $grNumber, string $name, string $currency = 'SAR', int $monthlyFeeMinor = 30000): array
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
            'monthly_fee_minor' => $monthlyFeeMinor,
            'grade_label' => 'Standard',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$familyId, $studentId, $enrollmentId];
    }

    private function createInvoiceAndItem(
        int $familyId,
        int $studentId,
        int $enrollmentId,
        string $description,
        string $currency,
        int $netMinor,
        string $periodStart = '2026-10-01',
        string $periodEnd = '2026-10-31',
        ?int $billingRunId = null
    ): array {
        $runId = $billingRunId ?: $this->obRunId;

        $invoiceId = (int) DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => $currency,
            'invoice_number' => 'INV-TEST-' . uniqid(),
            'issued_on' => $periodStart,
            'due_date' => date('Y-m-d', strtotime($periodStart . ' +20 days')),
            'total_minor' => $netMinor,
            'paid_minor' => 0,
            'status' => 'generated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = (int) DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId,
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => $description,
            'period_key' => "monthly:{$periodStart}..{$periodEnd}",
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'currency' => $currency,
            'amount_minor' => $netMinor,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => $netMinor,
            'paid_minor' => 0,
            'status' => 'active',
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
     * 1. Create Fee preview and Save consistency across the 7 distinct eligibility statuses.
     */
    public function test_01_create_fee_preview_and_save_consistency_across_statuses(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('FAM-P01', 'GRP01', 'Preview Student', 'SAR', 30000);

        $svc = app(FeeEligibilityService::class);

        // Case A: Fresh student with rate -> Eligible for creation
        $resEligible = $svc->evaluateEligibility([
            'family_id' => $famId,
            'student_ids' => [$stuId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);
        $this->assertTrue($resEligible['items'][0]['is_eligible']);
        $this->assertEquals(FeeEligibilityService::STATUS_ELIGIBLE, $resEligible['items'][0]['status']);
        $this->assertEquals('eligible', $resEligible['items'][0]['status_code']);

        // Case B: Create the November fee -> Already invoiced
        [$invId, $itemId] = $this->createInvoiceAndItem($famId, $stuId, $enrId, 'Monthly Fee - Nov 2026', 'SAR', 30000, '2026-11-01', '2026-11-30', $this->novRunId);

        $resAlreadyInvoiced = $svc->evaluateEligibility([
            'family_id' => $famId,
            'student_ids' => [$stuId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);
        $this->assertFalse($resAlreadyInvoiced['items'][0]['is_eligible']);
        $this->assertEquals(FeeEligibilityService::STATUS_ALREADY_INVOICED, $resAlreadyInvoiced['items'][0]['status']);
        $this->assertEquals('already_invoiced', $resAlreadyInvoiced['items'][0]['status_code']);

        // Case C: Void the item -> Previously voided
        $adjService = app(FeeAdjustmentService::class);
        $adjService->voidFee($itemId, 'Accidental billing mistake', $this->adminUser->id);

        $resPreviouslyVoided = $svc->evaluateEligibility([
            'family_id' => $famId,
            'student_ids' => [$stuId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);
        $this->assertFalse($resPreviouslyVoided['items'][0]['is_eligible']);
        $this->assertEquals(FeeEligibilityService::STATUS_PREVIOUSLY_VOIDED, $resPreviouslyVoided['items'][0]['status']);
        $this->assertEquals('previously_voided', $resPreviouslyVoided['items'][0]['status_code']);
        $this->assertEquals($itemId, $resPreviouslyVoided['items'][0]['voided_item_id']);

        // Case D: Historical MySkool payment exists
        $this->insertStagedCollection(201, 'REC-991', 'GRP01', 'FAM-P01', 'Monthly Fee - Oct-2026', '2026-10-24', 'SAR', 30000);

        // For October 2026 where historical collection exists
        $resHistPayment = $svc->evaluateEligibility([
            'family_id' => $famId,
            'student_ids' => [$stuId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
        ]);
        $this->assertFalse($resHistPayment['items'][0]['is_eligible']);
        $this->assertEquals(FeeEligibilityService::STATUS_HISTORICAL_PAYMENT, $resHistPayment['items'][0]['status']);
        $this->assertEquals('historical_payment', $resHistPayment['items'][0]['status_code']);

        // Case E: Historical opening balance exists
        DB::table('qa_staged_dues')->insert([
            'batch_id' => $this->batchId,
            'source_row' => 301,
            'gr_number' => 'GRP01',
            'student_name' => 'Preview Student',
            'family_code_final' => 'FAM-P01',
            'fee_particular' => 'Tuition Fee - Sep-2026',
            'currency' => 'SAR',
            'outstanding_amount' => 300.00,
            'outstanding_amount_minor' => 30000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resHistOB = $svc->evaluateEligibility([
            'family_id' => $famId,
            'student_ids' => [$stuId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [9],
            'year' => 2026,
        ]);
        $this->assertFalse($resHistOB['items'][0]['is_eligible']);
        $this->assertEquals(FeeEligibilityService::STATUS_HISTORICAL_OB, $resHistOB['items'][0]['status']);
        $this->assertEquals('historical_ob', $resHistOB['items'][0]['status_code']);

        // Case F: Requires restoration review (voided item with historical payment)
        [$obInvId, $obItemId] = $this->createInvoiceAndItem($famId, $stuId, $enrId, 'Monthly Fee - Oct 2026', 'SAR', 30000, '2026-10-01', '2026-10-31', $this->obRunId);
        $adjService->voidFee($obItemId, 'Accidental void of October fee', $this->adminUser->id);

        $resReview = $svc->evaluateEligibility([
            'family_id' => $famId,
            'student_ids' => [$stuId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
        ]);
        $this->assertFalse($resReview['items'][0]['is_eligible']);
        $this->assertEquals(FeeEligibilityService::STATUS_RESTORATION_REVIEW, $resReview['items'][0]['status']);
        $this->assertEquals('restoration_review', $resReview['items'][0]['status_code']);
        $this->assertEquals($obItemId, $resReview['items'][0]['voided_item_id']);

        // Case G: Financial conflict (e.g. review hold)
        DB::table('students')->where('id', $stuId)->update(['status' => 'review_hold']);

        $resConflict = $svc->evaluateEligibility([
            'family_id' => $famId,
            'student_ids' => [$stuId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [12],
            'year' => 2026,
        ]);
        $this->assertFalse($resConflict['items'][0]['is_eligible']);
        $this->assertEquals(FeeEligibilityService::STATUS_FINANCIAL_CONFLICT, $resConflict['items'][0]['status']);
        $this->assertEquals('financial_conflict', $resConflict['items'][0]['status_code']);

        // Verify POST /challan/preview-fee endpoint returns identical structured statuses
        DB::table('students')->where('id', $stuId)->update(['status' => 'present']);
        $previewResponse = $this->actingAs($this->adminUser)->postJson('/challan/preview-fee', [
            'family_id' => $famId,
            'student_ids' => [$stuId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10, 11],
            'year' => 2026,
        ]);
        $previewResponse->assertStatus(200);
        $previewResponse->assertJsonFragment(['status_code' => 'restoration_review']);
        $previewResponse->assertJsonFragment(['status_code' => 'previously_voided']);

        // Verify Create Fee Save rejects non-eligible items cleanly
        $saveResponse = $this->actingAs($this->adminUser)->post('/challan/create-fee', [
            'family_id' => $famId,
            'student_ids' => [$stuId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
        ]);
        $saveResponse->assertStatus(302);
        $saveResponse->assertSessionHasErrors('fee_type_ids');
        $errorMsg = session('errors')->first('fee_type_ids');
        $this->assertStringContainsString('Requires administrative restoration review', $errorMsg);
    }

    /**
     * 2. Normal accidental void restoration (preserves approved values, parent recalculates, adjustment recorded).
     */
    public function test_02_normal_accidental_void_restoration(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('FAM-N01', 'GRN01', 'Normal Student', 'SAR', 35000);
        [$invId, $itemId] = $this->createInvoiceAndItem($famId, $stuId, $enrId, 'Monthly Fee - Nov 2026', 'SAR', 35000, '2026-11-01', '2026-11-30', $this->novRunId);

        // 1. Accidental void
        $adjService = app(FeeAdjustmentService::class);
        $adjService->voidFee($itemId, 'Mistakenly voided by cashier', $this->adminUser->id);

        $voidedItem = DB::table('invoice_items')->where('id', $itemId)->first();
        $this->assertEquals('voided', $voidedItem->status);
        $this->assertEquals(0, $voidedItem->net_minor);

        $parentInvoice = DB::table('invoices')->where('id', $invId)->first();
        $this->assertEquals('voided', $parentInvoice->status);
        $this->assertEquals(0, $parentInvoice->total_minor);

        // 2. Inspect voided fee
        $restorationService = app(VoidedFeeRestorationService::class);
        $inspection = $restorationService->inspect($itemId);

        $this->assertTrue($inspection['is_safe_to_restore']);
        $this->assertEquals(VoidedFeeRestorationService::SAFETY_SAFE_NORMAL, $inspection['safety_status']);
        $this->assertEquals(35000, $inspection['original_approved_values']['net_minor']);
        $this->assertEquals('active', $inspection['proposed_restoration']['target_status']);
        $this->assertEquals(0, $inspection['proposed_restoration']['settled_paid_minor']);

        // 3. Restore fee
        $result = $restorationService->restore($itemId, [
            'reason' => 'Administrative correction of accidental void',
        ], $this->adminUser->id);

        $this->assertTrue($result['success']);
        $this->assertEquals(35000, $result['restored_net_minor']);
        $this->assertEquals(0, $result['restored_paid_minor']);
        $this->assertEquals('active', $result['status']);

        // 4. Verify DB state
        $restoredItem = DB::table('invoice_items')->where('id', $itemId)->first();
        $this->assertEquals('active', $restoredItem->status);
        $this->assertEquals(35000, $restoredItem->net_minor);
        $this->assertEquals(0, $restoredItem->paid_minor);

        // Parent invoice refreshed to 35000 and status 'generated'
        $refreshedInvoice = DB::table('invoices')->where('id', $invId)->first();
        $this->assertEquals(35000, $refreshedInvoice->total_minor);
        $this->assertEquals(0, $refreshedInvoice->paid_minor);
        $this->assertEquals('generated', $refreshedInvoice->status);

        // Compensating fee_adjustment recorded
        $restorationAdj = DB::table('fee_adjustments')->where('id', $result['adjustment_id'])->first();
        $this->assertEquals('restoration', $restorationAdj->kind);
        $this->assertEquals(35000, $restorationAdj->amount_minor);
        $this->assertEquals('Administrative correction of accidental void', $restorationAdj->reason);

        // Original void adjustment is preserved intact
        $voidAdj = DB::table('fee_adjustments')->where('invoice_item_id', $itemId)->where('kind', 'void')->first();
        $this->assertNotNull($voidAdj);
        $this->assertEquals('Mistakenly voided by cashier', $voidAdj->reason);

        // Audit log recorded
        $auditLog = DB::table('audit_logs')->where('action', 'fee_restored')->where('subject_id', $itemId)->first();
        $this->assertNotNull($auditLog);
        $meta = json_decode($auditLog->metadata, true);
        $this->assertEquals(35000, $meta['restored_net_minor']);
    }

    /**
     * 3. Reference case GR 5929: Historically settled voided fee.
     * Atomic restoration directly to paid with zero collectible balance and no cashier cash increase.
     */
    public function test_03_historically_settled_voided_fee_reference_case_gr_5929(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('5929', '5929', 'Hafsa Feroz', 'QAR', 28000);
        [$invId, $itemId] = $this->createInvoiceAndItem($famId, $stuId, $enrId, 'Monthly Fee - Oct-2026', 'QAR', 28000, '2026-10-01', '2026-10-31', $this->obRunId);

        // Historical MySkool payment: Slip 27328 (source_row 34702), QAR 280 on 2026-10-24
        $colId = $this->insertStagedCollection(34702, '27328', '5929', '5929', 'Monthly Fee - Oct-2026', '2026-10-24', 'QAR', 28000);

        // Accidental void of original fee
        $adjService = app(FeeAdjustmentService::class);
        $adjService->voidFee($itemId, 'Accidental void before reconciliation', $this->adminUser->id);

        // Inspect voided fee
        $restorationService = app(VoidedFeeRestorationService::class);
        $inspection = $restorationService->inspect($itemId);

        $this->assertTrue($inspection['is_safe_to_restore']);
        $this->assertEquals(VoidedFeeRestorationService::SAFETY_SAFE_ATOMIC_SETTLED, $inspection['safety_status']);
        $this->assertEquals('paid', $inspection['proposed_restoration']['target_status']);
        $this->assertEquals(28000, $inspection['proposed_restoration']['settled_paid_minor']);
        $this->assertEquals(0, $inspection['proposed_restoration']['remaining_collectible_minor']);

        // Execute atomic restoration
        $initialCashierPaymentsCount = DB::table('payments')->count();

        $result = $restorationService->restore($itemId, [
            'reason' => 'Restoring GR 5929 with atomic historical MySkool settlement (Slip 27328)',
        ], $this->adminUser->id);

        $this->assertTrue($result['success']);
        $this->assertEquals('paid', $result['status']);
        $this->assertEquals(28000, $result['restored_net_minor']);
        $this->assertEquals(28000, $result['restored_paid_minor']);

        // Verify DB item
        $restoredItem = DB::table('invoice_items')->where('id', $itemId)->first();
        $this->assertEquals('paid', $restoredItem->status);
        $this->assertEquals(28000, $restoredItem->net_minor);
        $this->assertEquals(28000, $restoredItem->paid_minor);

        // Parent invoice is paid
        $parentInvoice = DB::table('invoices')->where('id', $invId)->first();
        $this->assertEquals('paid', $parentInvoice->status);
        $this->assertEquals(28000, $parentInvoice->total_minor);
        $this->assertEquals(28000, $parentInvoice->paid_minor);

        // Settlement record exists in qa_historical_settlements
        $settlement = DB::table('qa_historical_settlements')->where('invoice_item_id', $itemId)->first();
        $this->assertNotNull($settlement);
        $this->assertEquals('applied', $settlement->status);
        $this->assertEquals(28000, $settlement->settled_amount_minor);
        $this->assertEquals('27328', $settlement->myskool_slip_numbers);
        $this->assertEquals(MySkoolReconciliationAuditService::CLASS_POST_CUTOFF_FULL, $settlement->reconciliation_classification);

        // No increase in cashier payments table
        $this->assertEquals($initialCashierPaymentsCount, DB::table('payments')->count());

        // Fee History service displays historical slip
        $historySvc = app(FeeHistoryService::class);
        $history = $historySvc->getFamilyFeeHistory($famId);
        $historicalEntries = collect($history->items())->filter(fn($e) => ($e->source ?? '') === 'myskool');
        $this->assertTrue($historicalEntries->isNotEmpty());
        $this->assertEquals('27328', $historicalEntries->first()->slip_number ?? '');

        // Subsequent Create Fee preview for October 2026 shows already invoiced, never offered again
        $eligibilitySvc = app(FeeEligibilityService::class);
        $subsequentPreview = $eligibilitySvc->evaluateEligibility([
            'family_id' => $famId,
            'student_ids' => [$stuId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
        ]);
        $this->assertFalse($subsequentPreview['items'][0]['is_eligible']);
        $this->assertEquals(FeeEligibilityService::STATUS_ALREADY_INVOICED, $subsequentPreview['items'][0]['status']);
    }

    /**
     * 4. Reference case GR 5930: Partially settled historical fee under shared Slip 27328.
     * Preserves QAR 230 payment, QAR 50 balance under concession review, and November invoices separate.
     */
    public function test_04_partially_settled_historical_fee_reference_case_gr_5930(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('5929', '5930', 'Zahra Feroz', 'QAR', 28000);
        [$invId, $itemId] = $this->createInvoiceAndItem($famId, $stuId, $enrId, 'Monthly Fee - Oct-2026', 'QAR', 28000, '2026-10-01', '2026-10-31', $this->obRunId);

        // Historical MySkool payment: Slip 27328 (source_row 34703), QAR 230 (concession QAR 50 pending)
        $this->insertStagedCollection(34703, '27328', '5930', '5929', 'Monthly Fee - Oct-2026', '2026-10-24', 'QAR', 23000);

        // Accidental void of original fee
        $adjService = app(FeeAdjustmentService::class);
        $adjService->voidFee($itemId, 'Accidental void before concession resolution', $this->adminUser->id);

        // Inspect voided fee
        $restorationService = app(VoidedFeeRestorationService::class);
        $inspection = $restorationService->inspect($itemId);

        $this->assertTrue($inspection['is_safe_to_restore']);
        $this->assertEquals(VoidedFeeRestorationService::SAFETY_SAFE_PARTIAL_SETTLED, $inspection['safety_status']);
        $this->assertEquals('partial', $inspection['proposed_restoration']['target_status']);
        $this->assertEquals(23000, $inspection['proposed_restoration']['settled_paid_minor']);
        $this->assertEquals(5000, $inspection['proposed_restoration']['remaining_collectible_minor']);
        $this->assertTrue($inspection['proposed_restoration']['requires_concession_review']);

        // Execute atomic restoration
        $result = $restorationService->restore($itemId, [
            'reason' => 'Restoring GR 5930 with partial historical settlement (Slip 27328) and QAR 50 concession review hold',
        ], $this->adminUser->id);

        $this->assertTrue($result['success']);
        $this->assertEquals('partial', $result['status']);
        $this->assertEquals(28000, $result['restored_net_minor']);
        $this->assertEquals(23000, $result['restored_paid_minor']);

        // Verify parent invoice
        $parentInvoice = DB::table('invoices')->where('id', $invId)->first();
        $this->assertEquals('partial', $parentInvoice->status);
        $this->assertEquals(28000, $parentInvoice->total_minor);
        $this->assertEquals(23000, $parentInvoice->paid_minor);

        // Sibling concession review note in settlement record
        $settlement = DB::table('qa_historical_settlements')->where('invoice_item_id', $itemId)->first();
        $this->assertEquals(23000, $settlement->settled_amount_minor);
        $this->assertEquals(5000, $settlement->remaining_obligation_minor);
        $this->assertEquals('27328', $settlement->myskool_slip_numbers);

        // Verify November invoice remains separate
        [$novInvId, $novItemId] = $this->createInvoiceAndItem($famId, $stuId, $enrId, 'Monthly Fee - Nov-2026', 'QAR', 28000, '2026-11-01', '2026-11-30', $this->novRunId);

        $novInv = DB::table('invoices')->where('id', $novInvId)->first();
        $this->assertEquals(28000, $novInv->total_minor);
        $this->assertEquals(0, $novInv->paid_minor);
        $this->assertEquals('generated', $novInv->status);
        $this->assertNotEquals($invId, $novInvId);
    }

    /**
     * 5. Existing portal cashier activity blocks restoration and reversal.
     */
    public function test_05_existing_portal_cashier_activity_blocks_restoration_and_reversal(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('FAM-C01', 'GRC01', 'Cashier Student', 'SAR', 30000);
        [$invId, $itemId] = $this->createInvoiceAndItem($famId, $stuId, $enrId, 'Monthly Fee - Nov 2026', 'SAR', 30000, '2026-11-01', '2026-11-30', $this->novRunId);

        // Accidental void
        $adjService = app(FeeAdjustmentService::class);
        $adjService->voidFee($itemId, 'Mistake void', $this->adminUser->id);

        // Simulate an intervening cashier payment allocated to this invoice item
        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'receipt_number' => 'REC-CASH-' . uniqid(),
            'amount_minor' => 15000,
            'unallocated_minor' => 0,
            'received_on' => '2026-11-05',
            'idempotency_key' => 'IDEM-' . uniqid(),
            'request_hash' => hash('sha256', uniqid()),
            'received_by' => $this->cashierUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $itemId,
            'amount_minor' => 15000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $restorationService = app(VoidedFeeRestorationService::class);
        $inspection = $restorationService->inspect($itemId);

        // Must be blocked due to intervening cashier payments
        $this->assertFalse($inspection['is_safe_to_restore']);
        $this->assertEquals(VoidedFeeRestorationService::SAFETY_BLOCKED_INTERVENING_PAYMENTS, $inspection['safety_status']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restoration blocked');
        $restorationService->restore($itemId, ['reason' => 'Unsafe restoration attempt'], $this->adminUser->id);
    }

    /**
     * 6. Existing discount or exemption is preserved on restoration (no guessed gross amounts).
     */
    public function test_06_existing_discount_and_exemption_preserved_accurately(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('FAM-D01', 'GRD01', 'Discount Student', 'SAR', 40000);

        $invoiceId = (int) DB::table('invoices')->insertGetId([
            'billing_run_id' => $this->novRunId,
            'family_id' => $famId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'invoice_number' => 'INV-DISC-' . uniqid(),
            'issued_on' => '2026-11-01',
            'due_date' => '2026-11-20',
            'total_minor' => 30000,
            'paid_minor' => 0,
            'status' => 'generated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = (int) DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee - Nov 2026 (With 100 SAR Discount)',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'currency' => 'SAR',
            'amount_minor' => 40000,
            'discount_minor' => 10000,
            'exemption_minor' => 0,
            'net_minor' => 30000,
            'paid_minor' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Void the discounted item
        $adjService = app(FeeAdjustmentService::class);
        $adjService->voidFee($itemId, 'Accidental void', $this->adminUser->id);

        $restorationService = app(VoidedFeeRestorationService::class);
        $inspection = $restorationService->inspect($itemId);

        // Approved net must be 30000 (40000 gross - 10000 discount)
        $this->assertEquals(30000, $inspection['original_approved_values']['net_minor']);
        $this->assertEquals(10000, $inspection['original_approved_values']['discount_minor']);

        $result = $restorationService->restore($itemId, ['reason' => 'Restoring approved net obligation'], $this->adminUser->id);
        $this->assertEquals(30000, $result['restored_net_minor']);

        $restoredItem = DB::table('invoice_items')->where('id', $itemId)->first();
        $this->assertEquals(30000, $restoredItem->net_minor);
        $this->assertEquals(10000, $restoredItem->discount_minor);
        $this->assertEquals(40000, $restoredItem->amount_minor);
    }

    /**
     * 7. Duplicate restoration attempt is rejected (idempotency).
     */
    public function test_07_duplicate_restoration_attempt_rejected(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('FAM-DUP', 'GRDUP', 'Duplicate Test', 'SAR', 30000);
        [$invId, $itemId] = $this->createInvoiceAndItem($famId, $stuId, $enrId, 'Monthly Fee - Nov 2026', 'SAR', 30000, '2026-11-01', '2026-11-30', $this->novRunId);

        $adjService = app(FeeAdjustmentService::class);
        $adjService->voidFee($itemId, 'Mistaken void', $this->adminUser->id);

        $restorationService = app(VoidedFeeRestorationService::class);

        // First restoration succeeds
        $result = $restorationService->restore($itemId, ['reason' => 'First restoration'], $this->adminUser->id);
        $this->assertTrue($result['success']);

        // Second restoration attempt must fail with BLOCKED_ALREADY_ACTIVE
        $secondInspection = $restorationService->inspect($itemId);
        $this->assertFalse($secondInspection['is_safe_to_restore']);
        $this->assertEquals(VoidedFeeRestorationService::SAFETY_BLOCKED_ALREADY_ACTIVE, $secondInspection['safety_status']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restoration blocked');
        $restorationService->restore($itemId, ['reason' => 'Repeated restoration'], $this->adminUser->id);
    }

    /**
     * 8. Unauthorized restoration is blocked by role gate (HTTP 403 / abort).
     */
    public function test_08_unauthorized_restoration_blocked_by_role_gate(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('FAM-AUTH', 'GRAUTH', 'Auth Student', 'SAR', 30000);
        [$invId, $itemId] = $this->createInvoiceAndItem($famId, $stuId, $enrId, 'Monthly Fee - Nov 2026', 'SAR', 30000, '2026-11-01', '2026-11-30', $this->novRunId);

        $adjService = app(FeeAdjustmentService::class);
        $adjService->voidFee($itemId, 'Mistaken void', $this->adminUser->id);

        // Cashier user attempting restoration endpoint -> 403 Forbidden
        $response = $this->actingAs($this->cashierUser)->postJson('/challan/restore-voided-fee', [
            'invoice_item_id' => $itemId,
            'reason' => 'Unauthorized cashier restoration',
            'confirm_authorization' => true,
        ]);

        $response->assertStatus(403);
    }

    /**
     * 9. Invoice constraint conflicts block restoration (active duplicate on another invoice).
     */
    public function test_09_invoice_constraint_conflicts_block_restoration(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('FAM-CON', 'GRCON', 'Conflict Student', 'SAR', 30000);
        [$inv1Id, $item1Id] = $this->createInvoiceAndItem($famId, $stuId, $enrId, 'Monthly Fee - Nov 2026', 'SAR', 30000, '2026-11-01', '2026-11-30', $this->novRunId);

        $adjService = app(FeeAdjustmentService::class);
        $adjService->voidFee($item1Id, 'Mistaken void', $this->adminUser->id);

        // Another invoice item was subsequently created on a separate billing run for the same student, fee type, and period
        $secondNovRunId = (int) DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-11-01..2026-11-30:replacement',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-15',
            'status' => 'generated',
            'created_by' => $this->adminUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$inv2Id, $item2Id] = $this->createInvoiceAndItem($famId, $stuId, $enrId, 'Monthly Fee - Nov 2026 Replacement', 'SAR', 30000, '2026-11-01', '2026-11-30', $secondNovRunId);

        $restorationService = app(VoidedFeeRestorationService::class);
        $inspection = $restorationService->inspect($item1Id);

        // Restoration of item1 must be blocked because active duplicate item2 exists
        $this->assertFalse($inspection['is_safe_to_restore']);
        $this->assertEquals(VoidedFeeRestorationService::SAFETY_BLOCKED_DUPLICATE_ACTIVE, $inspection['safety_status']);
        $this->assertStringContainsString('already exists', $inspection['error']);
    }

    /**
     * 10. Parent invoice total recalculation across multiple items.
     */
    public function test_10_parent_invoice_total_recalculation_across_multiple_items(): void
    {
        [$famId, $stuA, $enrA] = $this->createFamilyAndStudent('FAM-M01', 'GRMA', 'Student A', 'SAR', 30000);
        [, $stuB, $enrB] = $this->createFamilyAndStudent('FAM-M01', 'GRMB', 'Student B', 'SAR', 20000);

        $invoiceId = (int) DB::table('invoices')->insertGetId([
            'billing_run_id' => $this->novRunId,
            'family_id' => $famId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'invoice_number' => 'INV-MULTI-' . uniqid(),
            'issued_on' => '2026-11-01',
            'due_date' => '2026-11-20',
            'total_minor' => 50000,
            'paid_minor' => 0,
            'status' => 'generated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemA = (int) DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId,
            'student_id' => $stuA,
            'enrollment_id' => $enrA,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Student A Fee',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'currency' => 'SAR',
            'amount_minor' => 30000,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => 30000,
            'paid_minor' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemB = (int) DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId,
            'student_id' => $stuB,
            'enrollment_id' => $enrB,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Student B Fee',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'currency' => 'SAR',
            'amount_minor' => 20000,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => 20000,
            'paid_minor' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Void item B
        $adjService = app(FeeAdjustmentService::class);
        $adjService->voidFee($itemB, 'Voided student B', $this->adminUser->id);

        $invAfterVoid = DB::table('invoices')->where('id', $invoiceId)->first();
        $this->assertEquals(30000, $invAfterVoid->total_minor);

        // Restore item B
        $restorationService = app(VoidedFeeRestorationService::class);
        $restorationService->restore($itemB, ['reason' => 'Restored student B'], $this->adminUser->id);

        $invAfterRestore = DB::table('invoices')->where('id', $invoiceId)->first();
        $this->assertEquals(50000, $invAfterRestore->total_minor);
        $this->assertEquals('generated', $invAfterRestore->status);
    }

    /**
     * 11. Cross-currency conflict is detected and held.
     */
    public function test_11_cross_currency_conflict_blocked(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('FAM-CURR', 'GRCURR', 'Currency Student', 'SAR', 30000);
        [$invId, $itemId] = $this->createInvoiceAndItem($famId, $stuId, $enrId, 'Monthly Fee - Oct-2026', 'SAR', 30000, '2026-10-01', '2026-10-31', $this->obRunId);

        // Historical collection in QAR (mismatches invoice item SAR currency)
        $this->insertStagedCollection(701, 'SLIP-CURR', 'GRCURR', 'FAM-CURR', 'Monthly Fee - Oct-2026', '2026-10-24', 'QAR', 30000);

        $adjService = app(FeeAdjustmentService::class);
        $adjService->voidFee($itemId, 'Voided for review', $this->adminUser->id);

        $restorationService = app(VoidedFeeRestorationService::class);
        $inspection = $restorationService->inspect($itemId);

        // Mismatched currency collection must be blocked with SAFETY_BLOCKED_CURRENCY_CONFLICT and never restored as unpaid or settled
        $this->assertFalse($inspection['is_safe_to_restore']);
        $this->assertEquals(0, $inspection['proposed_restoration']['settled_paid_minor']);
        $this->assertEquals(VoidedFeeRestorationService::SAFETY_BLOCKED_CURRENCY_CONFLICT, $inspection['safety_status']);
    }

    /**
     * 12. Duplicate historical source collection allocation across different invoice items is strictly blocked.
     */
    public function test_12_duplicate_historical_source_allocation_blocked(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('FAM-SRC', 'GRSRC', 'Source Student', 'QAR', 28000);

        $obRun2Id = (int) DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'opening_balance',
            'period_key' => 'monthly:2026-10-01..2026-10-31:second',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'status' => 'generated',
            'created_by' => $this->adminUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$inv1Id, $item1Id] = $this->createInvoiceAndItem($famId, $stuId, $enrId, 'Monthly Fee - Oct-2026 A', 'QAR', 28000, '2026-10-01', '2026-10-31', $this->obRunId);
        [$inv2Id, $item2Id] = $this->createInvoiceAndItem($famId, $stuId, $enrId, 'Monthly Fee - Oct-2026 B', 'QAR', 28000, '2026-10-01', '2026-10-31', $obRun2Id);

        $colId = $this->insertStagedCollection(801, 'SLIP-DUP-SRC', 'GRSRC', 'FAM-SRC', 'Monthly Fee - Oct-2026', '2026-10-24', 'QAR', 28000);

        // Simulate item1 already settled this collection
        DB::table('qa_historical_settlements')->insert([
            'reconciliation_id' => 'RECON-EXISTING-SRC',
            'batch_id' => $this->batchId,
            'cutoff_date' => $this->cutoffDate,
            'invoice_item_id' => $item1Id,
            'invoice_id' => $inv1Id,
            'student_id' => $stuId,
            'family_id' => $famId,
            'student_gr' => 'GRSRC',
            'family_code' => 'FAM-SRC',
            'currency' => 'QAR',
            'fee_description' => 'Monthly Fee - Oct-2026 A',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'original_obligation_minor' => 28000,
            'settled_amount_minor' => 28000,
            'remaining_obligation_minor' => 0,
            'unmatched_overpayment_minor' => 0,
            'previous_item_paid_minor' => 0,
            'new_item_paid_minor' => 28000,
            'previous_item_status' => 'active',
            'new_item_status' => 'paid',
            'previous_invoice_paid_minor' => 0,
            'new_invoice_paid_minor' => 28000,
            'previous_invoice_status' => 'generated',
            'new_invoice_status' => 'paid',
            'qa_staged_due_id' => null,
            'qa_staged_collection_ids' => json_encode([$colId]),
            'myskool_slip_numbers' => 'SLIP-DUP-SRC',
            'reconciliation_classification' => MySkoolReconciliationAuditService::CLASS_POST_CUTOFF_FULL,
            'guard_class' => MySkoolReconciliationAuditService::GUARD_CANDIDATE_FULL_SETTLEMENT,
            'status' => 'applied',
            'applied_by' => $this->adminUser->id,
            'applied_at' => now(),
            'notes' => 'Existing settlement',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adjService = app(FeeAdjustmentService::class);
        $adjService->voidFee($item1Id, 'Voided item 1', $this->adminUser->id);
        $adjService->voidFee($item2Id, 'Voided item 2', $this->adminUser->id);

        $restorationService = app(VoidedFeeRestorationService::class);

        // Attempting to consume the already-consumed collection for item2 must throw critical integrity violation
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Historical source collection has already been consumed by another invoice item');
        $restorationService->restore($item2Id, ['reason' => 'Attempting duplicate source consumption'], $this->adminUser->id);
    }

    /**
     * 13. Interrupted restoration rolls back atomically (no partial state persists).
     */
    public function test_13_interrupted_restoration_rolls_back_atomically(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('FAM-RB', 'GRRB', 'Rollback Student', 'SAR', 30000);
        [$invId, $itemId] = $this->createInvoiceAndItem($famId, $stuId, $enrId, 'Monthly Fee - Nov 2026', 'SAR', 30000, '2026-11-01', '2026-11-30', $this->novRunId);

        $adjService = app(FeeAdjustmentService::class);
        $adjService->voidFee($itemId, 'Accidental void', $this->adminUser->id);

        // Empty reason throws validation exception and triggers rollback
        try {
            $restorationService = app(VoidedFeeRestorationService::class);
            $restorationService->restore($itemId, ['reason' => ''], $this->adminUser->id);
            $this->fail('Expected ValidationException was not thrown');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        // Verify item is still voided and parent is still voided
        $item = DB::table('invoice_items')->where('id', $itemId)->first();
        $this->assertEquals('voided', $item->status);
        $this->assertEquals(0, $item->net_minor);

        $parent = DB::table('invoices')->where('id', $invId)->first();
        $this->assertEquals('voided', $parent->status);
    }

    /**
     * 14. Artisan command billing:restore-voided-fee dry-run matches apply.
     */
    public function test_14_artisan_cli_dry_run_matches_apply(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('FAM-CLI', 'GRCLI', 'CLI Student', 'SAR', 35000);
        [$invId, $itemId] = $this->createInvoiceAndItem($famId, $stuId, $enrId, 'Monthly Fee - Nov 2026', 'SAR', 35000, '2026-11-01', '2026-11-30', $this->novRunId);

        $adjService = app(FeeAdjustmentService::class);
        $adjService->voidFee($itemId, 'CLI void test', $this->adminUser->id);

        // Dry-run mode
        $this->artisan('billing:restore-voided-fee', [
            'invoice_item_id' => $itemId,
        ])
        ->expectsOutputToContain('DRY-RUN (INSPECT ONLY)')
        ->expectsOutputToContain('DRY-RUN COMPLETE')
        ->assertExitCode(0);

        // Confirm DB is still untouched (voided)
        $this->assertEquals('voided', DB::table('invoice_items')->where('id', $itemId)->value('status'));

        // Apply mode without confirm-owner fails
        $this->artisan('billing:restore-voided-fee', [
            'invoice_item_id' => $itemId,
            '--apply' => true,
            '--reason' => 'Authorized CLI restoration',
        ])->assertExitCode(1);

        // Apply mode without explicit --actor-id fails (no silent fallback to user #1)
        $this->artisan('billing:restore-voided-fee', [
            'invoice_item_id' => $itemId,
            '--apply' => true,
            '--confirm-owner' => true,
            '--reason' => 'Authorized CLI restoration',
        ])->assertExitCode(1);

        // Apply mode with proper flags including explicit --actor-id succeeds
        $this->artisan('billing:restore-voided-fee', [
            'invoice_item_id' => $itemId,
            '--apply' => true,
            '--confirm-owner' => true,
            '--actor-id' => $this->adminUser->id,
            '--reason' => 'Authorized CLI restoration',
        ])
        ->expectsOutputToContain('RESTORATION SUCCESSFUL')
        ->assertExitCode(0);

        // Confirm DB item is now restored
        $this->assertEquals('active', DB::table('invoice_items')->where('id', $itemId)->value('status'));
        $this->assertEquals(35000, DB::table('invoice_items')->where('id', $itemId)->value('net_minor'));
    }

    /**
     * 15. Web route endpoints for forensic inspection and restoration modal.
     */
    public function test_15_web_route_inspect_and_restore_endpoints(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('FAM-WEB', 'GRWEB', 'Web Student', 'SAR', 30000);
        [$invId, $itemId] = $this->createInvoiceAndItem($famId, $stuId, $enrId, 'Monthly Fee - Nov 2026', 'SAR', 30000, '2026-11-01', '2026-11-30', $this->novRunId);

        $adjService = app(FeeAdjustmentService::class);
        $adjService->voidFee($itemId, 'Mistake void', $this->adminUser->id);

        // Inspect endpoint
        $inspectResponse = $this->actingAs($this->adminUser)->getJson("/challan/inspect-voided-fee/{$itemId}");
        $inspectResponse->assertStatus(200);
        $inspectResponse->assertJsonFragment(['is_safe_to_restore' => true]);
        $inspectResponse->assertJsonFragment(['safety_status' => 'SAFE_NORMAL_RESTORATION']);

        // Restore endpoint
        $restoreResponse = $this->actingAs($this->adminUser)->postJson('/challan/restore-voided-fee', [
            'invoice_item_id' => $itemId,
            'reason' => 'Restored via UI modal',
            'confirm_authorization' => true,
        ]);
        $restoreResponse->assertStatus(200);
        $restoreResponse->assertJsonFragment(['success' => true]);

        $this->assertEquals('active', DB::table('invoice_items')->where('id', $itemId)->value('status'));
    }

    /**
     * 16. Shared MySkool Slip #27328 across siblings GR 5929 and GR 5930:
     * Distinguishes individual source collection rows (34702 vs 34703) while blocking reuse of a consumed row.
     */
    public function test_16_shared_slip_27328_across_siblings_gr_5929_and_gr_5930_distinguishes_source_rows_and_prevents_double_consumption(): void
    {
        [$famId, $stu5929, $enr5929] = $this->createFamilyAndStudent('5929', '5929', 'Hafsa Feroz', 'QAR', 28000);
        [, $stu5930, $enr5930] = $this->createFamilyAndStudent('5929', '5930', 'Zahra Feroz', 'QAR', 28000);

        // Both siblings are on the same family opening-balance invoice (INV #2802 equivalent)
        $invoiceId = (int) DB::table('invoices')->insertGetId([
            'billing_run_id' => $this->obRunId,
            'family_id' => $famId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'QAR',
            'invoice_number' => 'INV-OB-5929',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 56000,
            'paid_minor' => 0,
            'status' => 'generated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $item5929 = (int) DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId,
            'student_id' => $stu5929,
            'enrollment_id' => $enr5929,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee - Oct-2026',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'QAR',
            'amount_minor' => 28000,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => 28000,
            'paid_minor' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $item5930 = (int) DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId,
            'student_id' => $stu5930,
            'enrollment_id' => $enr5930,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee - Oct-2026',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'QAR',
            'amount_minor' => 28000,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => 28000,
            'paid_minor' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Both siblings paid under the SAME MySkool slip #27328 on 2026-10-24, on distinct source rows:
        $colId5929 = $this->insertStagedCollection(34702, '27328', '5929', '5929', 'Monthly Fee - Oct-2026', '2026-10-24', 'QAR', 28000);
        $colId5930 = $this->insertStagedCollection(34703, '27328', '5930', '5929', 'Monthly Fee - Oct-2026', '2026-10-24', 'QAR', 23000);

        $adjService = app(FeeAdjustmentService::class);
        $adjService->voidFee($item5929, 'Voided GR 5929 Oct-2026', $this->adminUser->id);
        $adjService->voidFee($item5930, 'Voided GR 5930 Oct-2026', $this->adminUser->id);

        $restorationService = app(VoidedFeeRestorationService::class);

        // Restore GR 5929 first -> consumes colId5929 (QAR 280.00 -> paid)
        $res5929 = $restorationService->restore($item5929, [
            'reason' => 'Restore GR 5929 under shared slip 27328 (row 34702)',
        ], $this->adminUser->id);
        $this->assertTrue($res5929['success']);
        $this->assertEquals('paid', $res5929['status']);
        $this->assertEquals(28000, $res5929['restored_paid_minor']);

        // Inspect GR 5930 next -> must NOT be blocked by GR 5929 sharing slip #27328 because colId5930 is a distinct source row!
        $inspect5930 = $restorationService->inspect($item5930);
        $this->assertTrue($inspect5930['is_safe_to_restore']);
        $this->assertEquals(VoidedFeeRestorationService::SAFETY_SAFE_PARTIAL_SETTLED, $inspect5930['safety_status']);
        $this->assertEquals([$colId5930], $inspect5930['historical_evidence']['staged_collection_ids']);

        // Restore GR 5930 -> consumes colId5930 (QAR 230.00 -> partial, QAR 50.00 remaining)
        $res5930 = $restorationService->restore($item5930, [
            'reason' => 'Restore GR 5930 under shared slip 27328 (row 34703)',
        ], $this->adminUser->id);
        $this->assertTrue($res5930['success']);
        $this->assertEquals('partial', $res5930['status']);
        $this->assertEquals(23000, $res5930['restored_paid_minor']);

        // Parent invoice #2802 equivalent has total 56000, paid 51000 (28000 + 23000), status 'partial'
        $parentInv = DB::table('invoices')->where('id', $invoiceId)->first();
        $this->assertEquals(56000, (int) $parentInv->total_minor);
        $this->assertEquals(51000, (int) $parentInv->paid_minor);
        $this->assertEquals('partial', $parentInv->status);
    }

    /**
     * 17. Pre-cutoff historical obligation blocks unpaid restoration and restoration requires explicit admin actor.
     */
    public function test_17_pre_cutoff_historical_obligation_blocks_unpaid_restoration_and_requires_explicit_admin(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('FAM-PRE', 'GRPRE', 'Pre-Cutoff Student', 'SAR', 30000);

        // Regular (non-OB) invoice item for Sep 2026 that was voided because Sep 2026 was already paid in MySkool before cutoff
        [$invId, $itemId] = $this->createInvoiceAndItem($famId, $stuId, $enrId, 'Monthly Fee - Sep-2026', 'SAR', 30000, '2026-09-01', '2026-09-30', $this->novRunId);
        $this->insertStagedCollection(1701, 'SLIP-PRE-01', 'GRPRE', 'FAM-PRE', 'Monthly Fee - Sep-2026', '2026-09-15', 'SAR', 30000);

        $adjService = app(FeeAdjustmentService::class);
        $adjService->voidFee($itemId, 'Duplicate of pre-cutoff MySkool payment', $this->adminUser->id);

        $restorationService = app(VoidedFeeRestorationService::class);
        $inspection = $restorationService->inspect($itemId);

        // Must be blocked so restoration cannot bypass historical obligation protections
        $this->assertFalse($inspection['is_safe_to_restore']);
        $this->assertEquals(VoidedFeeRestorationService::SAFETY_BLOCKED_CONSTRAINT_CONFLICT, $inspection['safety_status']);

        // Also verify that calling restore() without an explicit admin actor throws RuntimeException (no fallback to arbitrary user)
        [$famNovId, $stuNovId, $enrNovId] = $this->createFamilyAndStudent('FAM-NOACTOR', 'GRNOACTOR', 'No Actor Student', 'SAR', 30000);
        [$invNovId, $itemNovId] = $this->createInvoiceAndItem($famNovId, $stuNovId, $enrNovId, 'Monthly Fee - Nov-2026', 'SAR', 30000, '2026-11-01', '2026-11-30', $this->novRunId);
        $adjService->voidFee($itemNovId, 'Accidental void', $this->adminUser->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Voided fee restoration requires an explicitly authenticated or specified administrator');
        $restorationService->restore($itemNovId, ['reason' => 'Missing actor test'], null);
    }
}
