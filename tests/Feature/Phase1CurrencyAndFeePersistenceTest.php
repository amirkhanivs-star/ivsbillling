<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase1CurrencyAndFeePersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $financeAdmin;
    protected int $campusId;
    protected int $departmentId;
    protected int $monthlyFeeTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->campusId = DB::table('campuses')->value('id') ?? DB::table('campuses')->insertGetId([
            'name' => 'Main Campus', 'code' => 'MAIN', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->departmentId = DB::table('departments')->where('code', 'school')->value('id')
            ?? DB::table('departments')->insertGetId([
                'name' => 'School', 'code' => 'school', 'created_at' => now(), 'updated_at' => now(),
            ]);

        $this->monthlyFeeTypeId = DB::table('fee_types')->where('code', 'monthly')->value('id')
            ?? DB::table('fee_types')->insertGetId([
                'name' => 'Monthly Fee', 'code' => 'monthly', 'created_at' => now(), 'updated_at' => now(),
            ]);

        $this->superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin.p1@iqra.test',
            'password' => 'secret123',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->financeAdmin = User::create([
            'name' => 'Finance Admin',
            'email' => 'finance.p1@iqra.test',
            'password' => 'secret123',
            'role' => 'finance_admin',
            'is_active' => true,
        ]);
    }

    private function createStudentWithFee(int $amountMinor = 28000, string $gr = 'GR-P1-01', string $currency = 'SAR', ?int $familyId = null): array
    {
        $now = now();
        if (!$familyId) {
            $familyId = DB::table('families')->insertGetId([
                'family_code' => 'FAM-' . $gr,
                'guardian_name' => 'Parent of ' . $gr,
                'email' => 'family.' . $gr . '@example.test',
                'whatsapp' => '+923001234567',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $studentId = DB::table('students')->insertGetId([
            'gr_number' => $gr,
            'name' => 'Student ' . $gr,
            'family_id' => $familyId,
            'status' => 'present',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $enrollmentId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'grade_label' => 'Grade 5',
            'currency' => $currency,
            'monthly_fee_minor' => $amountMinor,
            'status' => 'active',
            'started_on' => '2026-09-01',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $planItemId = DB::table('fee_plan_items')->insertGetId([
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee',
            'frequency' => 'monthly',
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'discount_kind' => null,
            'discount_value' => 0,
            'effective_from' => '2026-09-01',
            'effective_to' => null,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('student_registration_details')->insert([
            'student_id' => $studentId,
            'father_name' => 'Father ' . $gr,
            'registration_number' => $gr,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return compact('familyId', 'studentId', 'enrollmentId', 'planItemId');
    }

    private function generateInvoiceForMonth(array $studentData, int $year, int $month, int $amountMinor = 28000, string $currency = 'SAR'): int
    {
        $periodStart = sprintf('%04d-%02d-01', $year, $month);
        $periodEnd = date('Y-m-t', strtotime($periodStart));
        $dueDate = sprintf('%04d-%02d-25', $year, $month);
        $periodKey = "monthly:{$periodStart}..{$periodEnd}";

        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'frequency' => 'monthly',
            'period_key' => $periodKey,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'due_date' => $dueDate,
            'status' => 'generated',
            'created_by' => $this->superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoiceId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $studentData['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'currency' => $currency,
            'invoice_number' => 'SCH-' . $year . '-' . str_pad((string) mt_rand(1000, 99999), 7, '0', STR_PAD_LEFT),
            'issued_on' => $periodStart,
            'due_date' => $dueDate,
            'total_minor' => $amountMinor,
            'paid_minor' => 0,
            'previous_dues_snapshot_minor' => 0,
            'status' => 'generated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $invoiceId,
            'student_id' => $studentData['studentId'],
            'enrollment_id' => $studentData['enrollmentId'],
            'fee_plan_item_id' => $studentData['planItemId'],
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee',
            'period_key' => $periodKey,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'discount_minor' => 0,
            'net_minor' => $amountMinor,
            'paid_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $invoiceId;
    }

    private function editPayload(int $studentId, array $overrides = []): array
    {
        $student = DB::table('students')->where('id', $studentId)->first();
        $family = DB::table('families')->where('id', $student->family_id)->first();
        $details = DB::table('student_registration_details')->where('student_id', $studentId)->first();
        $en = DB::table('enrollments')->where('student_id', $studentId)->first();

        return array_replace([
            'name' => $student->name,
            'father_name' => $details->father_name ?? 'Father',
            'registration_number' => $student->gr_number,
            'family_code' => $family->family_code ?? 'FAM-CODE',
            'family_email' => $family->email ?? 'test@example.test',
            'family_whatsapp' => $family->whatsapp ?? '+923001234567',
            'grade_label' => $en->grade_label ?? 'Grade 5',
            'monthly_fee' => '280.00',
            'currency' => $en->currency ?? 'SAR',
        ], $overrides);
    }

    /**
     * Test 1: Update currency, generate a future invoice and confirm the new currency.
     */
    public function test_1_update_currency_generate_future_invoice_confirms_new_currency(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-TEST1-01', 'SAR');

        // Existing October invoice at SAR 280
        $octInvId = $this->generateInvoiceForMonth($data, 2026, 10, 28000, 'SAR');

        // Update currency to AED via authorized admin
        $payload = $this->editPayload($data['studentId'], [
            'currency' => 'AED',
            'monthly_fee' => '280.00',
        ]);
        $resp = $this->actingAs($this->superAdmin)->put("/manage-student/{$data['studentId']}", $payload);
        $resp->assertRedirect("/manage-student/{$data['studentId']}");

        // Generate future invoice for December 2026 via billing run
        $decRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-12-01..2026-12-31',
            'period_start' => '2026-12-01',
            'period_end' => '2026-12-31',
            'due_date' => '2026-12-25',
            'status' => 'draft',
            'created_by' => $this->superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = app(InvoiceService::class);
        $result = $svc->generate($decRunId);
        $this->assertGreaterThan(0, $result['created']);

        // Confirm December invoice is generated in AED
        $decInvoice = DB::table('invoices')
            ->where('billing_run_id', $decRunId)
            ->where('family_id', $data['familyId'])
            ->first();
        $this->assertNotNull($decInvoice);
        $this->assertEquals('AED', $decInvoice->currency);
        $this->assertEquals(28000, $decInvoice->total_minor);

        $decItem = DB::table('invoice_items')->where('invoice_id', $decInvoice->id)->first();
        $this->assertEquals('AED', $decItem->currency);
        $this->assertEquals(28000, $decItem->amount_minor);

        // Confirm previous October invoice remains untouched in SAR
        $octInv = DB::table('invoices')->where('id', $octInvId)->first();
        $this->assertEquals('SAR', $octInv->currency);
        $this->assertEquals(28000, $octInv->total_minor);
    }

    /**
     * Test 2: Update currency, delete or void an eligible unpaid older invoice, regenerate and confirm the new currency.
     */
    public function test_2_update_currency_delete_or_void_older_unpaid_invoice_regenerate_confirms_new_currency(): void
    {
        // Scenario A: Deleting an unpaid older invoice, regenerating confirms the new currency
        $dataA = $this->createStudentWithFee(28000, 'GR-TEST2-DEL', 'SAR');
        $octInvId = $this->generateInvoiceForMonth($dataA, 2026, 10, 28000, 'SAR');

        // Update currency to AED
        $payloadA = $this->editPayload($dataA['studentId'], [
            'currency' => 'AED',
            'monthly_fee' => '280.00',
        ]);
        $this->actingAs($this->superAdmin)->put("/manage-student/{$dataA['studentId']}", $payloadA);

        // Delete the unpaid October invoice and its items
        DB::table('invoice_items')->where('invoice_id', $octInvId)->delete();
        DB::table('invoices')->where('id', $octInvId)->delete();

        // Regenerate October invoice via Create Fee
        $respA = $this->actingAs($this->superAdmin)->post('/challan/create-fee', [
            'family_id' => $dataA['familyId'],
            'student_ids' => [$dataA['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
        ]);
        $respA->assertSessionHas('notice');

        $regenItemA = DB::table('invoice_items')
            ->where('student_id', $dataA['studentId'])
            ->where('period_start', '2026-10-01')
            ->first();
        $this->assertNotNull($regenItemA);
        $this->assertEquals('AED', $regenItemA->currency, 'Regenerated older invoice item must use new approved currency AED');
        $this->assertEquals(28000, $regenItemA->amount_minor);

        $regenInvA = DB::table('invoices')->where('id', $regenItemA->invoice_id)->first();
        $this->assertEquals('AED', $regenInvA->currency, 'Parent invoice must use AED currency');

        // Scenario B: Voiding an unpaid older invoice, regenerating with reissue approval confirms new currency
        $dataB = $this->createStudentWithFee(28000, 'GR-TEST2-VOID', 'SAR');
        $novInvId = $this->generateInvoiceForMonth($dataB, 2026, 11, 28000, 'SAR');
        $novItemId = DB::table('invoice_items')->where('invoice_id', $novInvId)->value('id');

        // Void the unpaid November item
        $this->actingAs($this->superAdmin)->post('/challan/void', [
            'invoice_item_id' => $novItemId,
            'reason' => 'Voiding for currency update test',
        ]);

        // Update currency to AED
        $payloadB = $this->editPayload($dataB['studentId'], [
            'currency' => 'AED',
            'monthly_fee' => '280.00',
        ]);
        $this->actingAs($this->superAdmin)->put("/manage-student/{$dataB['studentId']}", $payloadB);

        // Regenerate voided November fee with reissue approval
        $respB = $this->actingAs($this->superAdmin)->post('/challan/create-fee', [
            'family_id' => $dataB['familyId'],
            'student_ids' => [$dataB['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
            'reissue_approved' => true,
        ]);
        $respB->assertSessionHas('notice');

        $origVoidedItemB = DB::table('invoice_items')->where('id', $novItemId)->first();
        $this->assertEquals('voided', $origVoidedItemB->status, 'Original item must remain voided for audit');

        $regenItemB = DB::table('invoice_items')
            ->where('student_id', $dataB['studentId'])
            ->where('period_start', '2026-11-01')
            ->where('status', 'active')
            ->first();
        $this->assertNotNull($regenItemB);
        $this->assertEquals('active', $regenItemB->status);
        $this->assertEquals('AED', $regenItemB->currency, 'Voided item recreated with new currency AED');
        $this->assertEquals(28000, $regenItemB->amount_minor);
        $this->assertEquals($novItemId, $regenItemB->replaces_invoice_item_id);
    }

    /**
     * Test 3: Update monthly fee, regenerate an eligible older invoice and confirm the updated amount.
     */
    public function test_3_update_monthly_fee_regenerate_older_invoice_confirms_updated_amount(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-TEST3-FEE', 'SAR');

        // Generate October invoice at SAR 280 (unpaid)
        $octInvId = $this->generateInvoiceForMonth($data, 2026, 10, 28000, 'SAR');

        // Update monthly fee from SAR 280 to SAR 350
        $payload = $this->editPayload($data['studentId'], [
            'monthly_fee' => '350.00',
            'currency' => 'SAR',
        ]);
        $this->actingAs($this->superAdmin)->put("/manage-student/{$data['studentId']}", $payload);

        // Delete the unpaid October invoice
        DB::table('invoice_items')->where('invoice_id', $octInvId)->delete();
        DB::table('invoices')->where('id', $octInvId)->delete();

        // Regenerate October invoice via Create Fee
        $resp = $this->actingAs($this->superAdmin)->post('/challan/create-fee', [
            'family_id' => $data['familyId'],
            'student_ids' => [$data['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
        ]);
        $resp->assertSessionHas('notice');

        // Verify regenerated October invoice has SAR 350
        $regenItem = DB::table('invoice_items')
            ->where('student_id', $data['studentId'])
            ->where('period_start', '2026-10-01')
            ->first();
        $this->assertNotNull($regenItem);
        $this->assertEquals(35000, $regenItem->amount_minor, 'Regenerated older invoice must use new fee 35000 minor (SAR 350)');
        $this->assertEquals(35000, $regenItem->net_minor);
        $this->assertEquals('SAR', $regenItem->currency);

        $regenInv = DB::table('invoices')->where('id', $regenItem->invoice_id)->first();
        $this->assertEquals(35000, $regenInv->total_minor);
    }

    /**
     * Test 4: Change both currency and fee, regenerate and confirm both updated values.
     */
    public function test_4_change_both_currency_and_fee_regenerate_confirms_both_updated_values(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-TEST4-BOTH', 'SAR');

        // Generate October invoice at SAR 280 (unpaid)
        $octInvId = $this->generateInvoiceForMonth($data, 2026, 10, 28000, 'SAR');

        // Update both currency to USD and monthly fee to 120.00
        $payload = $this->editPayload($data['studentId'], [
            'currency' => 'USD',
            'monthly_fee' => '120.00',
        ]);
        $this->actingAs($this->superAdmin)->put("/manage-student/{$data['studentId']}", $payload);

        // Delete the unpaid October invoice
        DB::table('invoice_items')->where('invoice_id', $octInvId)->delete();
        DB::table('invoices')->where('id', $octInvId)->delete();

        // Regenerate October invoice via Create Fee
        $resp = $this->actingAs($this->superAdmin)->post('/challan/create-fee', [
            'family_id' => $data['familyId'],
            'student_ids' => [$data['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
        ]);
        $resp->assertSessionHas('notice');

        // Verify regenerated invoice item has currency USD and amount 12000
        $regenItem = DB::table('invoice_items')
            ->where('student_id', $data['studentId'])
            ->where('period_start', '2026-10-01')
            ->first();
        $this->assertNotNull($regenItem);
        $this->assertEquals('USD', $regenItem->currency, 'Must use updated currency USD');
        $this->assertEquals(12000, $regenItem->amount_minor, 'Must use updated amount 12000 minor');
        $this->assertEquals(12000, $regenItem->net_minor);

        $regenInv = DB::table('invoices')->where('id', $regenItem->invoice_id)->first();
        $this->assertEquals('USD', $regenInv->currency);
        $this->assertEquals(12000, $regenInv->total_minor);
    }

    /**
     * Test 5: Generate invoices for siblings with different approved currencies.
     */
    public function test_5_generate_invoices_for_siblings_with_different_approved_currencies(): void
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-MIXED-CURR',
            'guardian_name' => 'Mixed Currency Guardian',
            'email' => 'mixed@example.test',
            'whatsapp' => '+923009998888',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sibling A: SAR 280
        $sibA = $this->createStudentWithFee(28000, 'GR-SIB-SAR', 'SAR', $familyId);

        // Sibling B: AED 320
        $sibB = $this->createStudentWithFee(32000, 'GR-SIB-AED', 'AED', $familyId);

        // Generate November 2026 via Create Fee for both siblings
        $resp = $this->actingAs($this->superAdmin)->post('/challan/create-fee', [
            'family_id' => $familyId,
            'student_ids' => [$sibA['studentId'], $sibB['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);
        $resp->assertSessionHas('notice');

        // Check Sibling A's invoice
        $itemA = DB::table('invoice_items')
            ->where('student_id', $sibA['studentId'])
            ->where('period_start', '2026-11-01')
            ->first();
        $this->assertNotNull($itemA);
        $this->assertEquals('SAR', $itemA->currency);
        $this->assertEquals(28000, $itemA->amount_minor);

        $invA = DB::table('invoices')->where('id', $itemA->invoice_id)->first();
        $this->assertEquals('SAR', $invA->currency);
        $this->assertEquals(28000, $invA->total_minor);

        // Check Sibling B's invoice
        $itemB = DB::table('invoice_items')
            ->where('student_id', $sibB['studentId'])
            ->where('period_start', '2026-11-01')
            ->first();
        $this->assertNotNull($itemB);
        $this->assertEquals('AED', $itemB->currency);
        $this->assertEquals(32000, $itemB->amount_minor);

        $invB = DB::table('invoices')->where('id', $itemB->invoice_id)->first();
        $this->assertEquals('AED', $invB->currency);
        $this->assertEquals(32000, $invB->total_minor);

        // Confirm they are two separate invoices on the family ledger
        $this->assertNotEquals($invA->id, $invB->id, 'Siblings with different currencies must produce separate invoices');
        $this->assertEquals($familyId, $invA->family_id);
        $this->assertEquals($familyId, $invB->family_id);
    }

    /**
     * Test 6: Confirm paid invoices and historical transactions remain unchanged.
     */
    public function test_6_confirm_paid_invoices_and_historical_transactions_remain_unchanged(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-TEST6-PAID', 'SAR');

        // Generate September invoice at SAR 280
        $sepInvId = $this->generateInvoiceForMonth($data, 2026, 9, 28000, 'SAR');
        $sepItemId = DB::table('invoice_items')->where('invoice_id', $sepInvId)->value('id');

        // Receive payment for September invoice
        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $data['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'currency' => 'SAR',
            'receipt_number' => 'REC-P1-SEP-001',
            'amount_minor' => 28000,
            'unallocated_minor' => 0,
            'received_on' => '2026-09-15',
            'idempotency_key' => 'IDEM-SEP-001',
            'request_hash' => 'HASH-SEP-001',
            'received_by' => $this->superAdmin->id,
            'status' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $sepItemId,
            'amount_minor' => 28000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_items')->where('id', $sepItemId)->update([
            'paid_minor' => 28000,
            'status' => 'paid',
            'updated_at' => now(),
        ]);

        DB::table('invoices')->where('id', $sepInvId)->update([
            'paid_minor' => 28000,
            'status' => 'paid',
            'updated_at' => now(),
        ]);

        // Update fee to AED 320
        $payload = $this->editPayload($data['studentId'], [
            'currency' => 'AED',
            'monthly_fee' => '320.00',
        ]);
        $this->actingAs($this->superAdmin)->put("/manage-student/{$data['studentId']}", $payload);

        // Generate October invoice in AED
        $this->actingAs($this->superAdmin)->post('/challan/create-fee', [
            'family_id' => $data['familyId'],
            'student_ids' => [$data['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
        ]);

        // Assert September invoice remains unchanged
        $sepInv = DB::table('invoices')->where('id', $sepInvId)->first();
        $this->assertEquals('paid', $sepInv->status);
        $this->assertEquals('SAR', $sepInv->currency);
        $this->assertEquals(28000, $sepInv->total_minor);
        $this->assertEquals(28000, $sepInv->paid_minor);

        $sepItem = DB::table('invoice_items')->where('id', $sepItemId)->first();
        $this->assertEquals('paid', $sepItem->status);
        $this->assertEquals('SAR', $sepItem->currency);
        $this->assertEquals(28000, $sepItem->amount_minor);
        $this->assertEquals(28000, $sepItem->paid_minor);

        // Assert payment record remains unchanged
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        $this->assertEquals('SAR', $payment->currency);
        $this->assertEquals(28000, $payment->amount_minor);
        $this->assertEquals(0, $payment->unallocated_minor);

        // Assert allocation remains unchanged
        $allocation = DB::table('payment_allocations')->where('payment_id', $paymentId)->first();
        $this->assertEquals(28000, $allocation->amount_minor);
        $this->assertEquals($sepItemId, $allocation->invoice_item_id);
    }

    /**
     * Test 7: Confirm repeated generation cannot create duplicate charges.
     */
    public function test_7_confirm_repeated_generation_cannot_create_duplicate_charges(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-TEST7-DUP', 'SAR');

        // 1. Initial generation for October 2026 via Create Fee
        $firstResp = $this->actingAs($this->superAdmin)->post('/challan/create-fee', [
            'family_id' => $data['familyId'],
            'student_ids' => [$data['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
        ]);
        $firstResp->assertSessionHas('notice');

        $initialCount = DB::table('invoice_items')
            ->where('student_id', $data['studentId'])
            ->where('period_start', '2026-10-01')
            ->count();
        $this->assertEquals(1, $initialCount, 'Exactly 1 invoice item created initially');

        // 2. Repeated generation via Create Fee returns validation error and does not create duplicate
        $repeatResp = $this->actingAs($this->superAdmin)->post('/challan/create-fee', [
            'family_id' => $data['familyId'],
            'student_ids' => [$data['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
        ]);
        $repeatResp->assertSessionHasErrors('fee_type_ids');

        $afterCount = DB::table('invoice_items')
            ->where('student_id', $data['studentId'])
            ->where('period_start', '2026-10-01')
            ->count();
        $this->assertEquals(1, $afterCount, 'Count remains exactly 1; no duplicates allowed');

        // 3. Repeated generation via billing run (InvoiceService) skips and creates 0 invoices
        $octRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'status' => 'draft',
            'created_by' => $this->superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = app(InvoiceService::class);
        $result = $svc->generate($octRunId);

        $this->assertEquals(0, $result['created'], 'Billing run must create 0 duplicate invoices');
        $this->assertGreaterThan(0, $result['skipped'], 'Billing run must skip previously billed item');

        $finalCount = DB::table('invoice_items')
            ->where('student_id', $data['studentId'])
            ->where('period_start', '2026-10-01')
            ->count();
        $this->assertEquals(1, $finalCount, 'Final count remains exactly 1');
    }
}
