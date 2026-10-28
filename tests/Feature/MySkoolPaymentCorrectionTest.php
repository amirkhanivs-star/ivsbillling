<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BatchDispatchLock;
use App\Services\FeeHistoryService;
use App\Services\Migration\HistoricalPaymentReconciliationService;
use App\Services\Migration\MySkoolPaymentCorrectionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MySkoolPaymentCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private string $batchId = 'BATCH-TEST-MYSKOOL-CORR-20261028';
    private string $cutoffDate = '2026-10-22';
    private int $campusId;
    private int $deptId;
    private int $monthlyFeeTypeId;
    private int $obRunId;
    private int $octRunId;
    private int $novRunId;
    private User $adminUser;
    private User $cashierUser;
    private User $auditorUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        Config::set('reconciliation.allow_apply', true);
        Config::set('imports.active_batch_id', $this->batchId);

        $this->campusId = (int) DB::table('campuses')->value('id');
        $this->deptId = (int) DB::table('departments')->where('code', 'school')->value('id');
        $this->monthlyFeeTypeId = (int) DB::table('fee_types')->where('code', 'monthly')->value('id');

        $this->adminUser = User::firstOrFail();
        DB::table('users')->where('id', $this->adminUser->id)->update([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $this->adminUser->refresh();

        $cashierId = (int) DB::table('users')->insertGetId([
            'name' => 'Test Cashier',
            'email' => 'cashier-myskool-test@iqra.local',
            'password' => bcrypt('password'),
            'role' => 'cashier',
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->cashierUser = User::findOrFail($cashierId);

        $auditorId = (int) DB::table('users')->insertGetId([
            'name' => 'Test Auditor',
            'email' => 'auditor-myskool-test@iqra.local',
            'password' => bcrypt('password'),
            'role' => 'auditor',
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->auditorUser = User::findOrFail($auditorId);

        $this->actingAs($this->adminUser);

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

        $this->octRunId = (int) DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'monthly',
            'period_key' => 'regular:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-10',
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
    }

    private function createFamilyAndStudent(string $familyCode, string $grNumber, string $name, string $currency = 'SAR', int $monthlyFeeMinor = 28000): array
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
            'grade_label' => 'Grade 4',
            'currency' => $currency,
            'monthly_fee_minor' => $monthlyFeeMinor,
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
                'invoice_number' => 'SCH-OB-2026-' . uniqid(),
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
        int $amountMinor,
        array $overrides = []
    ): int {
        return (int) DB::table('qa_staged_collections')->insertGetId(array_merge([
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
        ], $overrides));
    }

    /**
     * 1. Imported receipt inspection is strictly read-only and returns all 14 required forensic fields.
     */
    public function test_01_imported_receipt_inspection_is_read_only_and_returns_all_14_forensic_fields(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('5929', '5929', 'Khadija Feroz', 'QAR', 28000);
        [$invId, $itemId] = $this->createObInvoiceItem($famId, $stuId, $enrId, '5929', 'Monthly Fee - Oct-2026', 'QAR', 28000);
        $colId = $this->insertStagedCollection(34702, '27328', '5929', '5929', 'Monthly Fee - Oct-2026', '2026-10-24', 'QAR', 28000, [
            'student_name' => 'Khadija Feroz',
        ]);

        app(HistoricalPaymentReconciliationService::class)->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => '5929',
        ]);

        $beforeCorrectionsCount = DB::table('qa_myskool_payment_corrections')->count();
        $beforeAuditCount = DB::table('audit_logs')->count();

        $resp = $this->actingAs($this->adminUser)
            ->getJson("/challan/inspect-myskool-payment/{$colId}");

        $resp->assertOk()
            ->assertJson([
                'exists' => true,
                'slip_number' => '27328',
                'qa_staged_collection_id' => $colId,
                'source_row' => 34702,
                'student_name' => 'Khadija Feroz',
                'student_gr' => '5929',
                'family_code' => '5929',
                'fee_particular' => 'Monthly Fee - Oct-2026',
                'period_key' => 'monthly:2026-10-01..2026-10-31',
                'currency' => 'QAR',
                'amount_minor' => 28000,
                'amount_formatted' => '280.00',
                'payment_date' => '2026-10-24',
                'is_pre_cutoff' => false,
                'is_reconciled' => true,
                'invoice_item_id' => $itemId,
                'invoice_id' => $invId,
                'item_balance_minor' => 0,
                'is_safe_to_reverse' => true,
                'case_classification' => MySkoolPaymentCorrectionService::CASE_A_RECONCILED_SETTLEMENT,
            ]);

        $json = $resp->json();
        $this->assertArrayHasKey('proposed_effects', $json);
        $this->assertSame(28000, $json['proposed_effects']['reverse_settlement']['before']['item_paid_minor']);
        $this->assertSame(0, $json['proposed_effects']['reverse_settlement']['after']['item_paid_minor']);
        $this->assertSame(28000, $json['proposed_effects']['reverse_settlement']['after']['item_balance_minor']);

        // Confirm inspection was 100% read-only
        $this->assertSame($beforeCorrectionsCount, DB::table('qa_myskool_payment_corrections')->count());
        $this->assertSame($beforeAuditCount, DB::table('audit_logs')->count());
        $this->assertSame(28000, (int) DB::table('invoice_items')->where('id', $itemId)->value('paid_minor'));
    }

    /**
     * 2. Authorized correction and settlement reversal (Case A).
     */
    public function test_02_authorized_correction_and_settlement_reversal_case_a(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('6100', '6100', 'Ahmad Tariq', 'SAR', 30000);
        [$invId, $itemId] = $this->createObInvoiceItem($famId, $stuId, $enrId, '6100', 'Monthly Fee - Oct-2026', 'SAR', 30000);
        $colId = $this->insertStagedCollection(501, '29001', '6100', '6100', 'Monthly Fee - Oct-2026', '2026-10-25', 'SAR', 30000);

        // Downstream November 2026 invoice with snapshot
        $novInvId = (int) DB::table('invoices')->insertGetId([
            'billing_run_id' => $this->novRunId,
            'family_id' => $famId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'invoice_number' => 'SCH-2026-NOV-6100',
            'issued_on' => '2026-11-01',
            'due_date' => '2026-11-10',
            'total_minor' => 30000,
            'paid_minor' => 0,
            'previous_dues_snapshot_minor' => 30000,
            'status' => 'unpaid',
            'approved_to_send' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(HistoricalPaymentReconciliationService::class)->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => '6100',
        ]);

        $this->assertSame(30000, (int) DB::table('invoice_items')->where('id', $itemId)->value('paid_minor'));
        $this->assertSame('paid', DB::table('invoice_items')->where('id', $itemId)->value('status'));
        $this->assertSame(0, (int) DB::table('invoices')->where('id', $novInvId)->value('previous_dues_snapshot_minor'));

        $invoiceCountBefore = DB::table('invoices')->count();
        $paymentsCountBefore = DB::table('payments')->count();

        // Execute authorized reversal via controller
        $resp = $this->actingAs($this->adminUser)->postJson('/challan/correct-myskool-payment', [
            'qa_staged_collection_id' => $colId,
            'action_type' => MySkoolPaymentCorrectionService::ACTION_REVERSE_SETTLEMENT,
            'reason' => 'Receipt #29001 verified as dishonored bank transfer; reversing historical settlement.',
        ]);

        $resp->assertOk()
            ->assertJson([
                'success' => true,
                'action_type' => MySkoolPaymentCorrectionService::ACTION_REVERSE_SETTLEMENT,
                'resulting_status' => MySkoolPaymentCorrectionService::STATUS_REVERSED,
                'invoice_item_id' => $itemId,
                'invoice_id' => $invId,
            ]);

        // Verify outstanding balance restored on existing invoice item and invoice
        $item = DB::table('invoice_items')->where('id', $itemId)->first();
        $invoice = DB::table('invoices')->where('id', $invId)->first();
        $this->assertSame(0, (int) $item->paid_minor);
        $this->assertSame('active', $item->status);
        $this->assertSame(0, (int) $invoice->paid_minor);
        $this->assertSame(30000, (int) $invoice->total_minor);

        // Downstream snapshot restored
        $this->assertSame(30000, (int) DB::table('invoices')->where('id', $novInvId)->value('previous_dues_snapshot_minor'));

        // No second invoice or synthetic portal payment created
        $this->assertSame($invoiceCountBefore, DB::table('invoices')->count());
        $this->assertSame($paymentsCountBefore, DB::table('payments')->count());
    }

    /**
     * 3. Unauthorized correction is blocked for cashiers, auditors, inactive users, and missing reasons.
     */
    public function test_03_unauthorized_correction_blocked_for_cashier_auditor_and_missing_reason(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('6200', '6200', 'Bilal Khan', 'SAR', 28000);
        [$invId, $itemId] = $this->createObInvoiceItem($famId, $stuId, $enrId, '6200', 'Monthly Fee - Oct-2026', 'SAR', 28000);
        $colId = $this->insertStagedCollection(502, '29002', '6200', '6200', 'Monthly Fee - Oct-2026', '2026-10-25', 'SAR', 28000);

        app(HistoricalPaymentReconciliationService::class)->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => '6200',
        ]);

        // Cashier cannot inspect or execute MySkool corrections
        $this->actingAs($this->cashierUser)
            ->getJson("/challan/inspect-myskool-payment/{$colId}")
            ->assertStatus(403);

        $this->actingAs($this->cashierUser)
            ->postJson('/challan/correct-myskool-payment', [
                'qa_staged_collection_id' => $colId,
                'action_type' => MySkoolPaymentCorrectionService::ACTION_REVERSE_SETTLEMENT,
                'reason' => 'Cashier attempting historical reversal',
            ])
            ->assertStatus(403);

        // Cashier does not see the Review / Correct MySkool Payment button on /challan
        $cashierPage = $this->actingAs($this->cashierUser)->get('/challan?gr=6200');
        $cashierPage->assertOk();
        $cashierPage->assertDontSee('Review / Correct MySkool Payment');
        $cashierPage->assertDontSee('myskool-payment-correction-dialog');

        // Auditor is also blocked
        $this->actingAs($this->auditorUser)
            ->postJson('/challan/correct-myskool-payment', [
                'qa_staged_collection_id' => $colId,
                'action_type' => MySkoolPaymentCorrectionService::ACTION_REVERSE_SETTLEMENT,
                'reason' => 'Auditor attempting historical reversal',
            ])
            ->assertStatus(403);

        // Admin without mandatory reason (or too short reason) is rejected with 422
        $this->actingAs($this->adminUser)
            ->postJson('/challan/correct-myskool-payment', [
                'qa_staged_collection_id' => $colId,
                'action_type' => MySkoolPaymentCorrectionService::ACTION_REVERSE_SETTLEMENT,
                'reason' => 'bad',
            ])
            ->assertStatus(422);

        // Verify item remains paid
        $this->assertSame(28000, (int) DB::table('invoice_items')->where('id', $itemId)->value('paid_minor'));
    }

    /**
     * 4. Shared slip 27328 partial correction (Task 6 & Case C):
     *    GR 5929 paid QAR 280 and GR 5930 paid QAR 230 under one MySkool slip 27328.
     *    Reversing GR 5929's allocation leaves GR 5930's financial history 100% intact
     *    and accurately recalculates the shared parent invoice and downstream snapshot.
     */
    public function test_04_shared_slip_27328_partial_correction_gr_5929_and_gr_5930_case_c(): void
    {
        [$famId, $stu5929, $enr5929] = $this->createFamilyAndStudent('5929', '5929', 'Khadija Feroz', 'QAR', 28000);
        [, $stu5930, $enr5930] = $this->createFamilyAndStudent('5929', '5930', 'Zahra Feroz', 'QAR', 28000);

        [$sharedObInvId, $item5929] = $this->createObInvoiceItem($famId, $stu5929, $enr5929, '5929', 'Monthly Fee - Oct-2026', 'QAR', 28000);
        [, $item5930] = $this->createObInvoiceItem($famId, $stu5930, $enr5930, '5930', 'Monthly Fee - Oct-2026', 'QAR', 28000);

        // Shared downstream November 2026 invoice for Family 5929
        $sharedNovInvId = (int) DB::table('invoices')->insertGetId([
            'billing_run_id' => $this->novRunId,
            'family_id' => $famId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'QAR',
            'invoice_number' => 'SCH-2026-0016199',
            'issued_on' => '2026-11-01',
            'due_date' => '2026-11-10',
            'total_minor' => 56000,
            'paid_minor' => 0,
            'previous_dues_snapshot_minor' => 56000,
            'status' => 'unpaid',
            'approved_to_send' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Two separate staged collection rows under shared MySkool slip 27328
        $col5929 = $this->insertStagedCollection(34702, '27328', '5929', '5929', 'Monthly Fee - Oct-2026', '2026-10-24', 'QAR', 28000, [
            'student_name' => 'Khadija Feroz',
        ]);
        $col5930 = $this->insertStagedCollection(34703, '27328', '5930', '5929', 'Monthly Fee - Oct-2026', '2026-10-24', 'QAR', 23000, [
            'student_name' => 'Zahra Feroz',
        ]);

        // Apply historical reconciliation for Family 5929
        $reconSvc = app(HistoricalPaymentReconciliationService::class);
        $applyRes = $reconSvc->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'family_code' => '5929',
        ]);
        $this->assertSame(2, $applyRes['applied_count']);

        // Verify post-reconciliation initial state:
        // GR 5929: 28000 paid (status=paid), GR 5930: 23000 paid (status=partial, 5000 remaining)
        // Shared OB invoice: total=56000, paid=51000, status=partial, remaining=5000
        // Shared Nov invoice snapshot: 5000
        $this->assertSame(51000, (int) DB::table('invoices')->where('id', $sharedObInvId)->value('paid_minor'));
        $this->assertSame(5000, (int) DB::table('invoices')->where('id', $sharedNovInvId)->value('previous_dues_snapshot_minor'));

        // Inspect GR 5929's row on shared slip 27328
        $corrSvc = app(MySkoolPaymentCorrectionService::class);
        $inspect5929 = $corrSvc->inspect($col5929);
        $this->assertTrue($inspect5929['is_shared_slip']);
        $this->assertCount(2, $inspect5929['shared_slip_allocations']);
        $this->assertSame(MySkoolPaymentCorrectionService::CASE_C_SHARED_SLIP_ALLOCATION, $inspect5929['case_classification']);
        $this->assertTrue($inspect5929['is_safe_to_reverse']);

        // Reverse ONLY GR 5929's allocation (col5929)
        $revResult = $corrSvc->executeCorrection($col5929, [
            'action_type' => MySkoolPaymentCorrectionService::ACTION_REVERSE_SETTLEMENT,
            'reason' => 'Reversing only GR 5929 allocation on shared slip 27328 for verification.',
        ], $this->adminUser->id);

        $this->assertSame(MySkoolPaymentCorrectionService::STATUS_REVERSED, $revResult['resulting_status']);

        // 1. Verify GR 5929's item is now unpaid (paid_minor = 0, status = active)
        $row5929 = DB::table('invoice_items')->where('id', $item5929)->first();
        $this->assertSame(0, (int) $row5929->paid_minor);
        $this->assertSame('active', $row5929->status);

        // 2. Verify GR 5930's item, settlement, and source row are 100% INTACT!
        $row5930 = DB::table('invoice_items')->where('id', $item5930)->first();
        $this->assertSame(23000, (int) $row5930->paid_minor);
        $this->assertSame('partial', $row5930->status);

        $set5930 = DB::table('qa_historical_settlements')->where('invoice_item_id', $item5930)->first();
        $this->assertSame('applied', $set5930->status);
        $this->assertSame(23000, (int) $set5930->settled_amount_minor);

        // 3. Verify shared parent OB invoice recalculated to paid_minor = 23000 (remaining = 33000)
        $parentInv = DB::table('invoices')->where('id', $sharedObInvId)->first();
        $this->assertSame(56000, (int) $parentInv->total_minor);
        $this->assertSame(23000, (int) $parentInv->paid_minor);
        $this->assertSame('partial', $parentInv->status);

        // 4. Verify downstream Nov-2026 invoice previous_dues_snapshot_minor updated from 5000 to 33000 (not 56000!)
        $this->assertSame(33000, (int) DB::table('invoices')->where('id', $sharedNovInvId)->value('previous_dues_snapshot_minor'));

        // 5. Verify Fee History shows the shared slip as "Partially Reversed" without removing it
        $history = app(FeeHistoryService::class)->getFamilyFeeHistory($famId, [], $this->adminUser);
        $slip27328 = collect($history->items())->firstWhere('slip_number', '27328');
        $this->assertNotNull($slip27328);
        $this->assertSame('partially_reversed', $slip27328->correction_status);
        $this->assertSame('Partially Reversed', $slip27328->correction_badge);
    }

    /**
     * 5. Original receipt preservation in qa_staged_collections and immutable correction audit trail.
     */
    public function test_05_original_staged_collection_preservation_and_immutable_audit_trail(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('6300', '6300', 'Zainab Ali', 'SAR', 25000);
        [$invId, $itemId] = $this->createObInvoiceItem($famId, $stuId, $enrId, '6300', 'Monthly Fee - Oct-2026', 'SAR', 25000);
        $colId = $this->insertStagedCollection(601, '30001', '6300', '6300', 'Monthly Fee - Oct-2026', '2026-10-26', 'SAR', 25000);

        $originalColSnapshot = (array) DB::table('qa_staged_collections')->where('id', $colId)->first();

        app(HistoricalPaymentReconciliationService::class)->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => '6300',
        ]);

        $corrSvc = app(MySkoolPaymentCorrectionService::class);

        // Event 1: Reverse settlement
        $corrSvc->executeCorrection($colId, [
            'action_type' => MySkoolPaymentCorrectionService::ACTION_REVERSE_SETTLEMENT,
            'reason' => 'First audit event: reversing settlement for verification.',
        ], $this->adminUser->id);

        // Event 2: Mark under review
        $corrSvc->executeCorrection($colId, [
            'action_type' => MySkoolPaymentCorrectionService::ACTION_MARK_UNDER_REVIEW,
            'reason' => 'Second audit event: placing under finance review.',
        ], $this->adminUser->id);

        // Verify qa_staged_collections row is 100% unchanged
        $afterColSnapshot = (array) DB::table('qa_staged_collections')->where('id', $colId)->first();
        $this->assertSame($originalColSnapshot, $afterColSnapshot);

        // Verify immutable sequence in qa_myskool_payment_corrections
        $corrRows = DB::table('qa_myskool_payment_corrections')
            ->where('qa_staged_collection_id', $colId)
            ->orderBy('sequence_no')
            ->get();

        $this->assertCount(2, $corrRows);
        $this->assertSame(1, (int) $corrRows[0]->sequence_no);
        $this->assertSame(MySkoolPaymentCorrectionService::ACTION_REVERSE_SETTLEMENT, $corrRows[0]->action_type);
        $this->assertSame(2, (int) $corrRows[1]->sequence_no);
        $this->assertSame($corrRows[0]->correction_id, $corrRows[1]->previous_correction_id);
        $this->assertSame(MySkoolPaymentCorrectionService::ACTION_MARK_UNDER_REVIEW, $corrRows[1]->action_type);

        // Verify audit_logs entries
        $auditActions = DB::table('audit_logs')
            ->where('subject_type', 'qa_staged_collection')
            ->where('subject_id', $colId)
            ->pluck('action')
            ->all();
        $this->assertContains('myskool_payment_reverse_settlement', $auditActions);
        $this->assertContains('myskool_payment_mark_under_review', $auditActions);
    }

    /**
     * 6. Repeated reversal prevention and safe reapplication (Case E).
     */
    public function test_06_repeated_reversal_prevention_and_safe_reapplication_case_e(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('6400', '6400', 'Hamza Siddiqui', 'SAR', 28000);
        [$invId, $itemId] = $this->createObInvoiceItem($famId, $stuId, $enrId, '6400', 'Monthly Fee - Oct-2026', 'SAR', 28000);
        $colId = $this->insertStagedCollection(602, '30002', '6400', '6400', 'Monthly Fee - Oct-2026', '2026-10-26', 'SAR', 28000);

        app(HistoricalPaymentReconciliationService::class)->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => '6400',
        ]);

        $corrSvc = app(MySkoolPaymentCorrectionService::class);

        // 1. Reverse settlement
        $corrSvc->executeCorrection($colId, [
            'action_type' => MySkoolPaymentCorrectionService::ACTION_REVERSE_SETTLEMENT,
            'reason' => 'Reversing settlement in error.',
        ], $this->adminUser->id);

        // 2. Repeated reversal attempt must be blocked
        $this->expectException(ValidationException::class);
        try {
            $corrSvc->executeCorrection($colId, [
                'action_type' => MySkoolPaymentCorrectionService::ACTION_REVERSE_SETTLEMENT,
                'reason' => 'Attempting double reversal on already reversed receipt.',
            ], $this->adminUser->id);
        } catch (ValidationException $e) {
            // Now test Case E: Safe Reapplication
            $reapplyRes = $corrSvc->executeCorrection($colId, [
                'action_type' => MySkoolPaymentCorrectionService::ACTION_REAPPLY_SETTLEMENT,
                'reason' => 'Undoing erroneous reversal after confirming bank slip #30002.',
            ], $this->adminUser->id);

            $this->assertSame(MySkoolPaymentCorrectionService::STATUS_REAPPLIED, $reapplyRes['resulting_status']);
            $this->assertSame(28000, (int) DB::table('invoice_items')->where('id', $itemId)->value('paid_minor'));
            $this->assertSame('paid', DB::table('invoice_items')->where('id', $itemId)->value('status'));
            $this->assertSame('applied', DB::table('qa_historical_settlements')->where('invoice_item_id', $itemId)->value('status'));

            // Double reapplication when already applied must be blocked
            try {
                $corrSvc->executeCorrection($colId, [
                    'action_type' => MySkoolPaymentCorrectionService::ACTION_REAPPLY_SETTLEMENT,
                    'reason' => 'Attempting duplicate reapplication.',
                ], $this->adminUser->id);
                $this->fail('Expected ValidationException on duplicate reapplication.');
            } catch (ValidationException $dupEx) {
                $this->assertNotEmpty($dupEx->errors());
            }

            throw $e;
        }
    }

    /**
     * 7. Task 6 Example Verification — GR 8857:
     *    - Genuine Portal receipt for October 2026.
     *    - Earlier imported MySkool payments.
     *    - Current November fee outstanding.
     *    Ensure correcting an imported MySkool receipt does not alter the genuine October Portal receipt
     *    or November obligation, and leaves cashier daily collections unchanged.
     */
    public function test_07_gr_8857_existing_portal_cashier_receipt_and_november_obligation_protected(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('8857', '8857', 'Usman Ghani', 'SAR', 28000);

        // 1. Earlier OB obligation settled via post-cutoff MySkool slip
        [$obInvId, $obItemId] = $this->createObInvoiceItem($famId, $stuId, $enrId, '8857', 'Monthly Fee - Sep-2026', 'SAR', 28000, [
            'period_key' => 'monthly:2026-09-01..2026-09-30',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
        ]);
        DB::table('qa_opening_balance_lineage')->where('invoice_item_id', $obItemId)->update([
            'period_key' => 'monthly:2026-09-01..2026-09-30',
        ]);
        $msColId = $this->insertStagedCollection(701, '26800', '8857', '8857', 'Monthly Fee - Sep-2026', '2026-10-24', 'SAR', 28000);

        app(HistoricalPaymentReconciliationService::class)->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => '8857',
        ]);

        // 2. Genuine Portal receipt for October 2026
        $octInvId = (int) DB::table('invoices')->insertGetId([
            'billing_run_id' => $this->octRunId,
            'family_id' => $famId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'invoice_number' => 'SCH-2026-OCT-8857',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-10',
            'total_minor' => 28000,
            'paid_minor' => 28000,
            'previous_dues_snapshot_minor' => 0,
            'status' => 'paid',
            'approved_to_send' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $octItemId = (int) DB::table('invoice_items')->insertGetId([
            'invoice_id' => $octInvId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee',
            'period_key' => 'regular:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'SAR',
            'amount_minor' => 28000,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => 28000,
            'paid_minor' => 28000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $portalPaymentId = (int) DB::table('payments')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'receipt_number' => 'RCP-2026-0008857',
            'received_on' => '2026-10-26',
            'currency' => 'SAR',
            'amount_minor' => 28000,
            'unallocated_minor' => 0,
            'status' => 'received',
            'received_by' => $this->cashierUser->id,
            'idempotency_key' => 'PORTAL-8857-OCT',
            'request_hash' => 'hash-8857-oct',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $portalAllocId = (int) DB::table('payment_allocations')->insertGetId([
            'payment_id' => $portalPaymentId,
            'invoice_item_id' => $octItemId,
            'amount_minor' => 28000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Current November 2026 fee obligation (unpaid)
        $novInvId = (int) DB::table('invoices')->insertGetId([
            'billing_run_id' => $this->novRunId,
            'family_id' => $famId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'invoice_number' => 'SCH-2026-NOV-8857',
            'issued_on' => '2026-11-01',
            'due_date' => '2026-11-10',
            'total_minor' => 28000,
            'paid_minor' => 0,
            'previous_dues_snapshot_minor' => 0,
            'status' => 'unpaid',
            'approved_to_send' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $novItemId = (int) DB::table('invoice_items')->insertGetId([
            'invoice_id' => $novInvId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'currency' => 'SAR',
            'amount_minor' => 28000,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => 28000,
            'paid_minor' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $portalPayBefore = (array) DB::table('payments')->where('id', $portalPaymentId)->first();
        $portalAllocBefore = (array) DB::table('payment_allocations')->where('id', $portalAllocId)->first();

        // Reverse the imported MySkool receipt (#26800)
        $corrSvc = app(MySkoolPaymentCorrectionService::class);
        $corrSvc->executeCorrection($msColId, [
            'action_type' => MySkoolPaymentCorrectionService::ACTION_REVERSE_SETTLEMENT,
            'reason' => 'Reversing earlier MySkool payment for GR 8857 while preserving October Portal receipt and November due.',
        ], $this->adminUser->id);

        // Verify genuine October 2026 Portal receipt and allocation are 100% untouched
        $this->assertSame($portalPayBefore, (array) DB::table('payments')->where('id', $portalPaymentId)->first());
        $this->assertSame($portalAllocBefore, (array) DB::table('payment_allocations')->where('id', $portalAllocId)->first());
        $this->assertSame(28000, (int) DB::table('invoice_items')->where('id', $octItemId)->value('paid_minor'));
        $this->assertSame('paid', DB::table('invoices')->where('id', $octInvId)->value('status'));

        // Verify November 2026 obligation is untouched (net=28000, paid=0, status=active)
        $novItemAfter = DB::table('invoice_items')->where('id', $novItemId)->first();
        $this->assertSame(28000, (int) $novItemAfter->net_minor);
        $this->assertSame(0, (int) $novItemAfter->paid_minor);
        $this->assertSame('active', $novItemAfter->status);
    }

    /**
     * 8. Pre-cutoff receipt already reflected in opening-balance snapshot is blocked from direct reversal (Case B).
     */
    public function test_08_pre_cutoff_receipt_reflected_in_snapshot_blocked_from_direct_reversal_case_b(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('6500', '6500', 'Mariam Noor', 'SAR', 28000);
        [$invId, $itemId] = $this->createObInvoiceItem($famId, $stuId, $enrId, '6500', 'Monthly Fee - Oct-2026', 'SAR', 14000);

        // Pre-cutoff receipt (2026-10-15 <= 2026-10-22) already netted into the 14000 residual OB due
        $preCutoffColId = $this->insertStagedCollection(801, '24100', '6500', '6500', 'Monthly Fee - Oct-2026', '2026-10-15', 'SAR', 14000);

        $corrSvc = app(MySkoolPaymentCorrectionService::class);
        $inspection = $corrSvc->inspect($preCutoffColId);

        $this->assertTrue($inspection['is_pre_cutoff']);
        $this->assertFalse($inspection['is_reconciled']);
        $this->assertFalse($inspection['is_safe_to_reverse']);
        $this->assertSame(MySkoolPaymentCorrectionService::CASE_B_UNRECONCILED_PRE_CUTOFF, $inspection['case_classification']);
        $this->assertSame(MySkoolPaymentCorrectionService::SAFETY_BLOCKED_PRE_CUTOFF_SNAPSHOT, $inspection['safety_status']);

        // Direct reversal must be blocked
        try {
            $corrSvc->executeCorrection($preCutoffColId, [
                'action_type' => MySkoolPaymentCorrectionService::ACTION_REVERSE_SETTLEMENT,
                'reason' => 'Attempting unsafe direct reversal on pre-cutoff receipt.',
            ], $this->adminUser->id);
            $this->fail('Expected ValidationException when reversing pre-cutoff receipt.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        // Controlled manual review / pre-cutoff compensation proposal succeeds without blindly mutating paid_minor
        $res = $corrSvc->executeCorrection($preCutoffColId, [
            'action_type' => MySkoolPaymentCorrectionService::ACTION_PROPOSE_PRE_CUTOFF_COMPENSATION,
            'proposed_compensation_minor' => 14000,
            'reason' => 'Pre-cutoff receipt #24100 disputed by bank; logging compensating obligation adjustment review.',
        ], $this->adminUser->id);

        $this->assertSame(MySkoolPaymentCorrectionService::STATUS_UNDER_REVIEW, $res['resulting_status']);
        $this->assertSame(0, (int) DB::table('invoice_items')->where('id', $itemId)->value('paid_minor'));
        $this->assertSame(14000, (int) DB::table('invoice_items')->where('id', $itemId)->value('net_minor'));
    }

    /**
     * 9. Task 6 Example Verification — GR 8620 cross-currency conflict and Case D disputed/reassignment workflow.
     */
    public function test_09_gr_8620_cross_currency_conflict_and_disputed_reassignment_workflow_case_d(): void
    {
        [$fam8620, $stu8620, $enr8620] = $this->createFamilyAndStudent('8620', '8620', 'Omar Farooq', 'AED', 22000);
        [$inv8620, $item8620] = $this->createObInvoiceItem($fam8620, $stu8620, $enr8620, '8620', 'Monthly Fee - Oct-2026', 'AED', 22000);

        // Cross-currency MySkool collection row in SAR for AED student GR 8620
        $conflictColId = $this->insertStagedCollection(901, '28620', '8620', '8620', 'Monthly Fee - Oct-2026', '2026-10-25', 'SAR', 22000, [
            'has_currency_conflict' => true,
        ]);

        $corrSvc = app(MySkoolPaymentCorrectionService::class);
        $inspection = $corrSvc->inspect($conflictColId);

        $this->assertTrue($inspection['has_currency_conflict']);
        $this->assertSame('SAR', $inspection['currency']);
        $this->assertSame('AED', $inspection['student_expected_currency']);
        $this->assertFalse($inspection['is_safe_to_reverse']);
        $this->assertSame(MySkoolPaymentCorrectionService::SAFETY_BLOCKED_CURRENCY_CONFLICT, $inspection['safety_status']);

        // Confirm no unsafe manual currency reassignment is permitted
        try {
            $corrSvc->executeCorrection($conflictColId, [
                'action_type' => MySkoolPaymentCorrectionService::ACTION_MARK_CORRECTED,
                'proposed_currency' => 'AED',
                'reason' => 'Attempting unsafe manual currency reassignment from SAR to AED.',
            ], $this->adminUser->id);
            $this->fail('Expected ValidationException on manual currency reassignment.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('currency', $e->errors());
        }

        // Marking the cross-currency conflicted row as Disputed succeeds and preserves AED invoice untouched
        $disputeRes = $corrSvc->executeCorrection($conflictColId, [
            'action_type' => MySkoolPaymentCorrectionService::ACTION_MARK_DISPUTED,
            'reason' => 'Currency conflict between SAR slip #28620 and AED obligation on GR 8620; holding as disputed.',
        ], $this->adminUser->id);

        $this->assertSame(MySkoolPaymentCorrectionService::STATUS_DISPUTED, $disputeRes['resulting_status']);
        $this->assertSame('AED', DB::table('invoice_items')->where('id', $item8620)->value('currency'));
        $this->assertSame(0, (int) DB::table('invoice_items')->where('id', $item8620)->value('paid_minor'));
    }

    /**
     * 10. Integration with Fee Collection, Invoice PDFs, Consolidated Challan PDFs, and Concurrency Locks (Tasks 5 & 7).
     */
    public function test_10_fee_collection_invoice_pdf_consolidated_challan_and_concurrency_integration(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('6600', '6600', 'Fatima Zahra', 'SAR', 28000);
        [$invId, $itemId] = $this->createObInvoiceItem($famId, $stuId, $enrId, '6600', 'Monthly Fee - Oct-2026', 'SAR', 28000);
        $colId = $this->insertStagedCollection(950, '31000', '6600', '6600', 'Monthly Fee - Oct-2026', '2026-10-25', 'SAR', 28000);

        app(HistoricalPaymentReconciliationService::class)->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => '6600',
        ]);

        // Admin views /challan?gr=6600 and sees "Review / Correct MySkool Payment" button
        $pageBefore = $this->actingAs($this->adminUser)->get('/challan?gr=6600');
        $pageBefore->assertOk();
        $pageBefore->assertSee('Review / Correct MySkool Payment');
        $pageBefore->assertSee('RECONCILED');

        // Reverse settlement
        $corrSvc = app(MySkoolPaymentCorrectionService::class);
        $corrSvc->executeCorrection($colId, [
            'action_type' => MySkoolPaymentCorrectionService::ACTION_REVERSE_SETTLEMENT,
            'reason' => 'Reversing historical settlement to verify Fee Collection and PDF integration.',
        ], $this->adminUser->id);

        // After reversal, /challan?gr=6600 displays the restored remaining fee (280.00) and REVERSED badge while keeping slip #31000 in Fee History
        $pageAfter = $this->actingAs($this->adminUser)->get('/challan?gr=6600');
        $pageAfter->assertOk();
        $pageAfter->assertSee('31000');
        $pageAfter->assertSee('REVERSED');
        $pageAfter->assertSee('280.00');

        // Verify Invoice PDF and Family Consolidated Challan PDF render cleanly with restored balance
        $this->actingAs($this->adminUser)->get("/invoices/{$invId}/pdf")->assertOk();
        $this->actingAs($this->adminUser)->get("/families/{$famId}/challan/pdf")->assertOk();

        // Verify concurrency lock blocks simultaneous correction request on the same staged collection row
        $heldLock = new BatchDispatchLock();
        $this->assertTrue($heldLock->acquire("hist_col_alloc_{$colId}"));
        try {
            $corrSvc->executeCorrection($colId, [
                'action_type' => MySkoolPaymentCorrectionService::ACTION_REAPPLY_SETTLEMENT,
                'reason' => 'Concurrent request while lock is held.',
            ], $this->adminUser->id);
            $this->fail('Expected ValidationException when concurrency lock is held.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('concurrency', $e->errors());
        } finally {
            $heldLock->release();
        }

        // Verify database-level unique constraint on (qa_staged_collection_id, sequence_no) blocks duplicate sequence insertion
        $this->expectException(QueryException::class);
        DB::table('qa_myskool_payment_corrections')->insert([
            'correction_id' => 'MSCORR-DUP-SEQ-TEST',
            'batch_id' => $this->batchId,
            'qa_staged_collection_id' => $colId,
            'sequence_no' => 1, // Already used by first correction!
            'student_gr' => '6600',
            'currency' => 'SAR',
            'original_amount_minor' => 28000,
            'case_classification' => MySkoolPaymentCorrectionService::CASE_A_RECONCILED_SETTLEMENT,
            'action_type' => MySkoolPaymentCorrectionService::ACTION_MARK_UNDER_REVIEW,
            'resulting_status' => MySkoolPaymentCorrectionService::STATUS_UNDER_REVIEW,
            'before_state_json' => '{}',
            'after_state_json' => '{}',
            'reason' => 'Duplicate sequence number test',
            'acted_by' => $this->adminUser->id,
            'acted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 11. Case D reassignment proposal workflow and consumed-collection hold protection (Task 3 Case D & Task 5).
     */
    public function test_11_case_d_reassignment_proposal_and_consumed_hold_protection(): void
    {
        [$famA, $stuA, $enrA] = $this->createFamilyAndStudent('6701', '6701', 'Student A', 'SAR', 28000);
        [$famB, $stuB, $enrB] = $this->createFamilyAndStudent('6702', '6702', 'Student B', 'SAR', 28000);
        [$invA, $itemA] = $this->createObInvoiceItem($famA, $stuA, $enrA, '6701', 'Monthly Fee - Oct-2026', 'SAR', 28000);
        [$invB, $itemB] = $this->createObInvoiceItem($famB, $stuB, $enrB, '6702', 'Monthly Fee - Oct-2026', 'SAR', 28000);

        // Receipt imported under GR 6701 that actually belongs to GR 6702
        $colId = $this->insertStagedCollection(960, '32001', '6701', '6701', 'Monthly Fee - Oct-2026', '2026-10-26', 'SAR', 28000);

        $corrSvc = app(MySkoolPaymentCorrectionService::class);

        // Proposing reassignment to non-existent student GR must fail
        try {
            $corrSvc->executeCorrection($colId, [
                'action_type' => MySkoolPaymentCorrectionService::ACTION_PROPOSE_REASSIGNMENT,
                'proposed_student_gr' => '999999',
                'reason' => 'Attempting reassignment to non-existent student GR.',
            ], $this->adminUser->id);
            $this->fail('Expected ValidationException for non-existent target student GR.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('proposed_student_gr', $e->errors());
        }

        // Proposing reassignment to valid same-currency student GR 6702 succeeds and keeps original staged row unchanged
        $res = $corrSvc->executeCorrection($colId, [
            'action_type' => MySkoolPaymentCorrectionService::ACTION_PROPOSE_REASSIGNMENT,
            'proposed_student_gr' => '6702',
            'proposed_period_key' => 'monthly:2026-10-01..2026-10-31',
            'proposed_fee_particular' => 'Monthly Fee - Oct-2026',
            'reason' => 'Slip #32001 was entered under GR 6701 in MySkool Excel; verified physical receipt belongs to GR 6702.',
        ], $this->adminUser->id);

        $this->assertSame(MySkoolPaymentCorrectionService::STATUS_CORRECTED, $res['resulting_status']);
        // Original staged row still has gr_number = 6701
        $this->assertSame('6701', DB::table('qa_staged_collections')->where('id', $colId)->value('gr_number'));

        // Because colId now has an active correction hold ('corrected'), automated reconciliation for GR 6701 cannot consume it!
        $reconSvc = app(HistoricalPaymentReconciliationService::class);
        $applyRes = $reconSvc->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => '6701',
        ]);
        $this->assertSame(0, $applyRes['applied_count']);
        $this->assertSame(1, $applyRes['failed_count']);
        $this->assertSame(0, (int) DB::table('invoice_items')->where('id', $itemA)->value('paid_minor'));
    }

    /**
     * 12. Case E reapplication blocked when target obligation was subsequently settled via Portal cashier payment.
     */
    public function test_12_case_e_reapplication_blocked_when_portal_payment_exists_on_target_item(): void
    {
        [$famId, $stuId, $enrId] = $this->createFamilyAndStudent('6800', '6800', 'Yusuf Ibrahim', 'SAR', 28000);
        [$invId, $itemId] = $this->createObInvoiceItem($famId, $stuId, $enrId, '6800', 'Monthly Fee - Oct-2026', 'SAR', 28000);
        $colId = $this->insertStagedCollection(970, '33001', '6800', '6800', 'Monthly Fee - Oct-2026', '2026-10-25', 'SAR', 28000);

        app(HistoricalPaymentReconciliationService::class)->apply([
            'confirm_cutoff' => true,
            'confirm_owner' => true,
            'cutoff_date' => $this->cutoffDate,
            'batch_id' => $this->batchId,
            'gr' => '6800',
        ]);

        $corrSvc = app(MySkoolPaymentCorrectionService::class);

        // Reverse historical settlement
        $corrSvc->executeCorrection($colId, [
            'action_type' => MySkoolPaymentCorrectionService::ACTION_REVERSE_SETTLEMENT,
            'reason' => 'Reversing historical settlement before cashier collects cash.',
        ], $this->adminUser->id);

        // Now cashier collects a live Portal payment against the restored OB item
        $paymentId = (int) DB::table('payments')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'receipt_number' => 'RCP-2026-0006800',
            'received_on' => '2026-10-28',
            'currency' => 'SAR',
            'amount_minor' => 28000,
            'unallocated_minor' => 0,
            'status' => 'received',
            'received_by' => $this->cashierUser->id,
            'idempotency_key' => 'PORTAL-6800-OB',
            'request_hash' => 'hash-6800-ob',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $itemId,
            'amount_minor' => 28000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_items')->where('id', $itemId)->update([
            'paid_minor' => 28000,
            'status' => 'paid',
        ]);
        DB::table('invoices')->where('id', $invId)->update([
            'paid_minor' => 28000,
            'status' => 'paid',
        ]);

        // Attempting to reapply the historical settlement now must be blocked to prevent duplicate recognition!
        $this->expectException(ValidationException::class);
        $corrSvc->executeCorrection($colId, [
            'action_type' => MySkoolPaymentCorrectionService::ACTION_REAPPLY_SETTLEMENT,
            'reason' => 'Attempting reapplication after cashier already collected portal payment.',
        ], $this->adminUser->id);
    }
}

