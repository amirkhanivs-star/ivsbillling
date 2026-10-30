<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InvoiceService;
use App\Services\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentMonthlyFeeUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $financeAdmin;
    protected User $deptManager;
    protected User $cashier;
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
            'email' => 'superadmin@iqra.test',
            'password' => 'secret123',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->financeAdmin = User::create([
            'name' => 'Finance Admin',
            'email' => 'finance@iqra.test',
            'password' => 'secret123',
            'role' => 'finance_admin',
            'is_active' => true,
        ]);

        $this->deptManager = User::create([
            'name' => 'Dept Manager',
            'email' => 'manager@iqra.test',
            'password' => 'secret123',
            'role' => 'department_manager',
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'is_active' => true,
        ]);

        $this->cashier = User::create([
            'name' => 'Cashier User',
            'email' => 'cashier@iqra.test',
            'password' => 'secret123',
            'role' => 'cashier',
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'is_active' => true,
        ]);
    }

    private function createStudentWithFee(int $amountMinor = 28000, string $gr = 'GR-FEE-280'): array
    {
        $now = now();
        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-' . $gr,
            'guardian_name' => 'Tariq Guardian',
            'email' => 'family.' . $gr . '@example.test',
            'whatsapp' => '+923001234567',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $studentId = DB::table('students')->insertGetId([
            'gr_number' => $gr,
            'name' => 'Zayd ' . $gr,
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
            'currency' => 'SAR',
            'monthly_fee_minor' => $amountMinor,
            'status' => 'active',
            'started_on' => '2026-10-01',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $planItemId = DB::table('fee_plan_items')->insertGetId([
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee',
            'frequency' => 'monthly',
            'currency' => 'SAR',
            'amount_minor' => $amountMinor,
            'discount_kind' => null,
            'discount_value' => 0,
            'effective_from' => '2026-10-01',
            'effective_to' => null,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('student_registration_details')->insert([
            'student_id' => $studentId,
            'father_name' => 'Tariq Father',
            'registration_number' => $gr,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return compact('familyId', 'studentId', 'enrollmentId', 'planItemId');
    }

    private function generateInvoiceForMonth(array $studentData, int $year, int $month, int $amountMinor = 28000): int
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
            'currency' => 'SAR',
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
            'currency' => 'SAR',
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
            'monthly_fee' => '320.00',
        ], $overrides);
    }

    /**
     * Requirement 1: Edit form allows authorized admins to edit both monthly fee and currency, keeps readonly for unauthorized roles.
     */
    public function test_edit_form_allows_authorized_admins_to_edit_both_monthly_fee_and_currency(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-EDIT-01');

        // Super Admin views edit form: both monthly fee and currency are editable
        $adminResponse = $this->actingAs($this->superAdmin)->get("/manage-student/{$data['studentId']}/edit");
        $adminResponse->assertOk();
        $adminResponse->assertSee('value="280.00"', false);
        $adminResponse->assertSee('<select id="currency" name="currency"', false);
        $adminResponse->assertSee('value="SAR" selected', false);

        // Department Manager views edit form: both monthly fee and currency are readonly
        $dmResponse = $this->actingAs($this->deptManager)->get("/manage-student/{$data['studentId']}/edit");
        $dmResponse->assertOk();
        $dmResponse->assertSee('readonly', false);
        $dmResponse->assertSee('Currency is protected to prevent ledger corruption', false);
        $dmResponse->assertSee('Only Super Admin and Finance Admin can update fee rates', false);
    }

    /**
     * Requirement 2, 3, 10: Authorized admin updates monthly fee to SAR 320 with confirmation notice.
     */
    public function test_authorized_admin_updates_monthly_fee_to_320_and_sees_correct_confirmation_notice(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-UPDATE-02');

        // October (SAR 280) and November (SAR 280) invoices already generated
        $octInvId = $this->generateInvoiceForMonth($data, 2026, 10, 28000);
        $novInvId = $this->generateInvoiceForMonth($data, 2026, 11, 28000);

        $payload = $this->editPayload($data['studentId'], ['monthly_fee' => '320.00']);
        $response = $this->actingAs($this->superAdmin)
            ->put("/manage-student/{$data['studentId']}", $payload);

        $response->assertRedirect("/manage-student/{$data['studentId']}");

        $expectedNotice = 'Previous monthly fee: SAR 280. New monthly fee: SAR 320. Applies from December 2026. Existing invoices remain unchanged.';
        $response->assertSessionHas('notice', function ($notice) use ($expectedNotice) {
            return str_contains($notice, $expectedNotice);
        });
    }

    /**
     * Requirement 4: Never modify invoices or invoice items already created.
     */
    public function test_existing_october_and_november_invoices_and_items_remain_at_sar_280(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-PROT-03');

        $octInvId = $this->generateInvoiceForMonth($data, 2026, 10, 28000);
        $novInvId = $this->generateInvoiceForMonth($data, 2026, 11, 28000);

        // Update fee to SAR 320
        $payload = $this->editPayload($data['studentId'], ['monthly_fee' => '320.00']);
        $this->actingAs($this->financeAdmin)->put("/manage-student/{$data['studentId']}", $payload);

        // Verify October invoice and items remain untouched
        $octInv = DB::table('invoices')->where('id', $octInvId)->first();
        $octItem = DB::table('invoice_items')->where('invoice_id', $octInvId)->first();
        $this->assertEquals(28000, $octInv->total_minor, 'October invoice total must remain SAR 280 (28000 minor)');
        $this->assertEquals(28000, $octItem->amount_minor, 'October invoice item amount must remain SAR 280 (28000 minor)');
        $this->assertEquals(28000, $octItem->net_minor);

        // Verify November invoice and items remain untouched
        $novInv = DB::table('invoices')->where('id', $novInvId)->first();
        $novItem = DB::table('invoice_items')->where('invoice_id', $novInvId)->first();
        $this->assertEquals(28000, $novInv->total_minor, 'November invoice total must remain SAR 280 (28000 minor)');
        $this->assertEquals(28000, $novItem->amount_minor, 'November invoice item amount must remain SAR 280 (28000 minor)');
        $this->assertEquals(28000, $novItem->net_minor);
    }

    /**
     * Requirement 5: Historical receipts, allocations, and cashier balances remain unchanged.
     */
    public function test_historical_receipts_allocations_and_outstanding_balances_remain_unchanged(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-RECEIPT-04');

        $octInvId = $this->generateInvoiceForMonth($data, 2026, 10, 28000);
        $novInvId = $this->generateInvoiceForMonth($data, 2026, 11, 28000);

        $octItemId = DB::table('invoice_items')->where('invoice_id', $octInvId)->value('id');

        // Create a historical payment for October invoice
        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $data['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'currency' => 'SAR',
            'receipt_number' => 'REC-2026-OCT-001',
            'amount_minor' => 28000,
            'unallocated_minor' => 0,
            'received_on' => '2026-10-15',
            'idempotency_key' => 'IDEM-OCT-001',
            'request_hash' => 'HASH-OCT-001',
            'received_by' => $this->cashier->id,
            'status' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $octItemId,
            'amount_minor' => 28000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_items')->where('id', $octItemId)->update([
            'paid_minor' => 28000,
            'status' => 'paid',
            'updated_at' => now(),
        ]);

        DB::table('invoices')->where('id', $octInvId)->update([
            'paid_minor' => 28000,
            'status' => 'paid',
            'updated_at' => now(),
        ]);

        // Update fee to SAR 320
        $payload = $this->editPayload($data['studentId'], ['monthly_fee' => '320.00']);
        $this->actingAs($this->superAdmin)->put("/manage-student/{$data['studentId']}", $payload);

        // Verify payment is unchanged
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        $this->assertEquals(28000, $payment->amount_minor);
        $this->assertEquals(0, $payment->unallocated_minor);

        // Verify allocation is unchanged
        $allocation = DB::table('payment_allocations')->where('payment_id', $paymentId)->first();
        $this->assertEquals(28000, $allocation->amount_minor);
        $this->assertEquals($octItemId, $allocation->invoice_item_id);

        // Verify October invoice status and paid amount
        $octInv = DB::table('invoices')->where('id', $octInvId)->first();
        $this->assertEquals('paid', $octInv->status);
        $this->assertEquals(28000, $octInv->paid_minor);

        // Verify November invoice remains unpaid with SAR 280 balance
        $novInv = DB::table('invoices')->where('id', $novInvId)->first();
        $this->assertEquals(28000, $novInv->total_minor);
        $this->assertEquals(0, $novInv->paid_minor);
    }

    /**
     * Requirement 3, 6, 11: Future billing periods use SAR 320 for both billing runs and Create Fee.
     */
    public function test_new_december_and_later_invoices_use_updated_sar_320_rate(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-FUTURE-05');

        // October and November at SAR 280
        $this->generateInvoiceForMonth($data, 2026, 10, 28000);
        $this->generateInvoiceForMonth($data, 2026, 11, 28000);

        // Update fee to SAR 320
        $payload = $this->editPayload($data['studentId'], ['monthly_fee' => '320.00']);
        $this->actingAs($this->superAdmin)->put("/manage-student/{$data['studentId']}", $payload);

        // 1. Generate December 2026 via billing run (InvoiceService)
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

        $service = app(InvoiceService::class);
        $result = $service->generate($decRunId);

        $this->assertGreaterThan(0, $result['created']);
        $decInvoice = DB::table('invoices')
            ->where('billing_run_id', $decRunId)
            ->where('family_id', $data['familyId'])
            ->first();

        $this->assertNotNull($decInvoice);
        $this->assertEquals(32000, $decInvoice->total_minor, 'December invoice must be billed at SAR 320 (32000 minor)');

        $decItem = DB::table('invoice_items')->where('invoice_id', $decInvoice->id)->first();
        $this->assertEquals(32000, $decItem->amount_minor);
        $this->assertEquals(32000, $decItem->net_minor);

        // 2. Generate January 2027 via Create Fee (Challan Fee Creation)
        $createFeeResponse = $this->actingAs($this->superAdmin)->post('/challan/create-fee', [
            'family_id' => $data['familyId'],
            'student_ids' => [$data['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [1],
            'year' => 2027,
        ]);

        $createFeeResponse->assertSessionHas('notice');

        $janItem = DB::table('invoice_items')
            ->where('student_id', $data['studentId'])
            ->where('period_start', '2027-01-01')
            ->first();

        $this->assertNotNull($janItem);
        $this->assertEquals(32000, $janItem->amount_minor, 'January 2027 fee must use the SAR 320 rate');
    }

    /**
     * Requirement 7: Fee change history preserved in fee_rate_changes, fee_history, and audit_logs.
     */
    public function test_fee_change_history_preserved_with_administrator_and_timestamp(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-AUDIT-06');
        $this->generateInvoiceForMonth($data, 2026, 10, 28000);
        $this->generateInvoiceForMonth($data, 2026, 11, 28000);

        $payload = $this->editPayload($data['studentId'], ['monthly_fee' => '320.00']);
        $this->actingAs($this->superAdmin)->put("/manage-student/{$data['studentId']}", $payload);

        // Verify fee_rate_changes entry
        $change = DB::table('fee_rate_changes')
            ->where('fee_type_id', $this->monthlyFeeTypeId)
            ->where('currency', 'SAR')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($change);
        $this->assertEquals(28000, $change->before_minor);
        $this->assertEquals(32000, $change->after_minor);
        $this->assertEquals($this->superAdmin->id, $change->user_id);
        $this->assertEquals('student_plan', $change->target_kind);
        $this->assertStringContainsString('December 2026', (string)$change->reason);

        // Verify enrollment fee_history JSON
        $enrollment = DB::table('enrollments')->where('id', $data['enrollmentId'])->first();
        $this->assertEquals(32000, $enrollment->monthly_fee_minor);

        $history = json_decode((string)$enrollment->fee_history, true);
        $this->assertIsArray($history);
        $this->assertCount(1, $history);
        $this->assertEquals(28000, $history[0]['previous_minor']);
        $this->assertEquals(32000, $history[0]['new_minor']);
        $this->assertEquals('December 2026', $history[0]['effective_period']);
        $this->assertEquals($this->superAdmin->id, $history[0]['user_id']);

        // Verify audit log
        $audit = DB::table('audit_logs')
            ->where('action', 'student_monthly_fee_updated')
            ->where('subject_id', $data['studentId'])
            ->first();

        $this->assertNotNull($audit);
        $this->assertEquals($this->superAdmin->id, $audit->actor_id);
        $meta = json_decode((string)$audit->metadata, true);
        $this->assertEquals(28000, $meta['previous_minor']);
        $this->assertEquals(32000, $meta['new_minor']);
        $this->assertEquals('December 2026', $meta['effective_period']);
    }

    /**
     * Requirement: Unauthorized users cannot change the rate.
     */
    public function test_unauthorized_user_cannot_change_monthly_fee(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-UNAUTH-07');

        $payload = $this->editPayload($data['studentId'], ['monthly_fee' => '320.00']);

        // Department manager attempting to change fee rate
        $dmResponse = $this->actingAs($this->deptManager)
            ->put("/manage-student/{$data['studentId']}", $payload);

        $dmResponse->assertStatus(403);

        // Cashier attempting to change fee rate
        $cashierResponse = $this->actingAs($this->cashier)
            ->put("/manage-student/{$data['studentId']}", $payload);

        $cashierResponse->assertStatus(403);

        // Verify enrollment fee remains SAR 280
        $en = DB::table('enrollments')->where('id', $data['enrollmentId'])->first();
        $this->assertEquals(28000, $en->monthly_fee_minor);

        // Verify active plan item remains SAR 280
        $plan = DB::table('fee_plan_items')->where('id', $data['planItemId'])->first();
        $this->assertEquals(28000, $plan->amount_minor);
    }

    /**
     * When fee is not changed, standard notice is shown and no duplicate records are created.
     */
    public function test_when_fee_is_not_changed_standard_notice_is_shown(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-SAME-08');

        $payload = $this->editPayload($data['studentId'], [
            'monthly_fee' => '280.00',
            'father_name' => 'Updated Father Name',
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->put("/manage-student/{$data['studentId']}", $payload);

        $response->assertRedirect("/manage-student/{$data['studentId']}");
        $response->assertSessionHas('notice', 'Student details and family assignment updated successfully.');

        // No new fee plan items created
        $this->assertEquals(1, DB::table('fee_plan_items')->where('enrollment_id', $data['enrollmentId'])->count());

        // No fee rate changes recorded
        $this->assertEquals(0, DB::table('fee_rate_changes')->where('target_id', $data['planItemId'])->count());
    }

    /**
     * Quick Test 1 & 2 & 3: Change future fee to AED 320; existing SAR 280 invoices remain unchanged;
     * new invoices use AED 320 for both scheduled billing runs and Create Fee.
     */
    public function test_quick_test_change_future_fee_to_aed_320_and_verify_existing_and_future_invoices(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-QT-01');

        // Existing October invoice generated at SAR 280
        $octInvId = $this->generateInvoiceForMonth($data, 2026, 10, 28000);

        // Update fee to AED 320 from Edit Registration
        $payload = $this->editPayload($data['studentId'], [
            'monthly_fee' => '320.00',
            'currency' => 'AED',
        ]);
        $response = $this->actingAs($this->superAdmin)->put("/manage-student/{$data['studentId']}", $payload);
        $response->assertRedirect("/manage-student/{$data['studentId']}");

        $expectedNotice = 'Previous monthly fee: SAR 280. New monthly fee: AED 320. Applies from November 2026. Existing invoices remain unchanged.';
        $response->assertSessionHas('notice', function ($notice) use ($expectedNotice) {
            return str_contains($notice, $expectedNotice);
        });

        // 1. Existing SAR 280 invoice remains completely unchanged
        $octInv = DB::table('invoices')->where('id', $octInvId)->first();
        $octItem = DB::table('invoice_items')->where('invoice_id', $octInvId)->first();
        $this->assertEquals('SAR', $octInv->currency);
        $this->assertEquals(28000, $octInv->total_minor);
        $this->assertEquals('SAR', $octItem->currency);
        $this->assertEquals(28000, $octItem->amount_minor);
        $this->assertEquals(28000, $octItem->net_minor);

        // 2. Future fee plan item is created in AED 320
        $activePlan = DB::table('fee_plan_items')
            ->where('enrollment_id', $data['enrollmentId'])
            ->where('is_active', true)
            ->whereNull('effective_to')
            ->first();
        $this->assertNotNull($activePlan);
        $this->assertEquals('AED', $activePlan->currency);
        $this->assertEquals(32000, $activePlan->amount_minor);
        $this->assertEquals('2026-11-01', $activePlan->effective_from);

        // 3. New scheduled invoice for November 2026 uses AED 320
        $novRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-25',
            'status' => 'draft',
            'created_by' => $this->superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(InvoiceService::class);
        $result = $service->generate($novRunId);
        $this->assertGreaterThan(0, $result['created']);

        $novInv = DB::table('invoices')->where('billing_run_id', $novRunId)->where('family_id', $data['familyId'])->first();
        $this->assertNotNull($novInv);
        $this->assertEquals('AED', $novInv->currency);
        $this->assertEquals(32000, $novInv->total_minor);

        $novItem = DB::table('invoice_items')->where('invoice_id', $novInv->id)->first();
        $this->assertEquals('AED', $novItem->currency);
        $this->assertEquals(32000, $novItem->amount_minor);

        // 4. Create Fee for December 2026 uses AED 320
        $createFeeResponse = $this->actingAs($this->superAdmin)->post('/challan/create-fee', [
            'family_id' => $data['familyId'],
            'student_ids' => [$data['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [12],
            'year' => 2026,
        ]);
        $createFeeResponse->assertSessionHas('notice');

        $decItem = DB::table('invoice_items')
            ->where('student_id', $data['studentId'])
            ->where('period_start', '2026-12-01')
            ->first();
        $this->assertNotNull($decItem);
        $this->assertEquals('AED', $decItem->currency);
        $this->assertEquals(32000, $decItem->amount_minor);
    }

    /**
     * Quick Test 4 & 5: Void an unpaid fictional future invoice, then recreate it correctly at AED 320.
     * Verify no duplicate invoices.
     */
    public function test_quick_test_void_unpaid_future_invoice_then_recreate_correctly_at_aed_320(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-QT-VOID');

        // Create fictional future invoice for January 2027
        $cfResponse = $this->actingAs($this->superAdmin)->post('/challan/create-fee', [
            'family_id' => $data['familyId'],
            'student_ids' => [$data['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [1],
            'year' => 2027,
        ]);
        $cfResponse->assertSessionHas('notice');

        $janItem = DB::table('invoice_items')
            ->where('student_id', $data['studentId'])
            ->where('period_start', '2027-01-01')
            ->first();
        $this->assertNotNull($janItem);
        $this->assertEquals('active', $janItem->status);
        $this->assertEquals(28000, $janItem->amount_minor);

        // Void the unpaid invoice item via /challan/void
        $voidResponse = $this->actingAs($this->superAdmin)->post('/challan/void', [
            'invoice_item_id' => $janItem->id,
            'reason' => 'Voiding fictional future test invoice for recreation testing',
        ]);
        $voidResponse->assertSessionHas('notice');

        $voidedItem = DB::table('invoice_items')->where('id', $janItem->id)->first();
        $this->assertEquals('voided', $voidedItem->status);
        $this->assertEquals(0, $voidedItem->net_minor);

        // Update fee to AED 320
        $payload = $this->editPayload($data['studentId'], [
            'monthly_fee' => '320.00',
            'currency' => 'AED',
        ]);
        $this->actingAs($this->superAdmin)->put("/manage-student/{$data['studentId']}", $payload);

        // Authorized administrator recreates January 2027 fee via Create Fee workflow
        $recreateResponse = $this->actingAs($this->superAdmin)->post('/challan/create-fee', [
            'family_id' => $data['familyId'],
            'student_ids' => [$data['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [1],
            'year' => 2027,
        ]);
        $recreateResponse->assertSessionHas('notice');

        // Verify original voided item remains voided and replacement fee item is active
        $origVoided = DB::table('invoice_items')->where('id', $janItem->id)->first();
        $this->assertEquals('voided', $origVoided->status);

        $recreatedItem = DB::table('invoice_items')
            ->where('student_id', $data['studentId'])
            ->where('period_start', '2027-01-01')
            ->where('status', 'active')
            ->first();
        $this->assertNotNull($recreatedItem);
        $this->assertEquals('active', $recreatedItem->status);
        $this->assertEquals('AED', $recreatedItem->currency);
        $this->assertEquals(32000, $recreatedItem->amount_minor);
        $this->assertEquals(32000, $recreatedItem->net_minor);
        $this->assertEquals($janItem->id, $recreatedItem->replaces_invoice_item_id);

        // Verify parent invoice
        $parentInv = DB::table('invoices')->where('id', $recreatedItem->invoice_id)->first();
        $this->assertEquals('AED', $parentInv->currency);
        $this->assertEquals(32000, $parentInv->total_minor);

        // Verify audit trail preserved in fee_adjustments: both void and recreated records exist
        $adjustments = DB::table('fee_adjustments')
            ->where('invoice_item_id', $janItem->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $adjustments);
        $this->assertEquals('void', $adjustments[0]->kind);
        $this->assertEquals('recreated', $adjustments[1]->kind);
        $this->assertEquals(32000, $adjustments[1]->new_amount_minor);

        // Quick Test 5: Verify no duplicate active invoices or items
        $activeItems = DB::table('invoice_items')
            ->where('student_id', $data['studentId'])
            ->where('period_start', '2027-01-01')
            ->where('status', '!=', 'voided')
            ->count();
        $this->assertEquals(1, $activeItems, 'Exactly 1 active invoice item must exist for January 2027; no duplicates allowed');

        // Running Create Fee again skips it cleanly as duplicate
        $rerunResponse = $this->actingAs($this->superAdmin)->post('/challan/create-fee', [
            'family_id' => $data['familyId'],
            'student_ids' => [$data['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [1],
            'year' => 2027,
        ]);
        $rerunResponse->assertSessionHasErrors('fee_type_ids');
    }

    /**
     * Quick Test 6: Previously paid or historically settled invoices are protected.
     */
    public function test_quick_test_paid_and_historically_settled_invoices_are_protected(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-QT-PROT');

        $octInvId = $this->generateInvoiceForMonth($data, 2026, 10, 28000);
        $octItemId = DB::table('invoice_items')->where('invoice_id', $octInvId)->value('id');

        // Mark as paid
        DB::table('invoice_items')->where('id', $octItemId)->update([
            'paid_minor' => 28000,
            'status' => 'paid',
        ]);
        DB::table('invoices')->where('id', $octInvId)->update([
            'paid_minor' => 28000,
            'status' => 'paid',
        ]);

        // Attempting to void paid item is blocked
        $adjService = app(\App\Services\FeeAdjustmentService::class);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $adjService->voidFee($octItemId, 'Attempting to void paid item', $this->superAdmin->id);
    }

    /**
     * Quick Test 7: No cross-currency balance merging across Fee Collection and Challans.
     */
    public function test_quick_test_no_cross_currency_balance_merging(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-QT-MULTI');

        // October invoice in SAR 280
        $this->generateInvoiceForMonth($data, 2026, 10, 28000);

        // Update fee to AED 320
        $payload = $this->editPayload($data['studentId'], [
            'monthly_fee' => '320.00',
            'currency' => 'AED',
        ]);
        $this->actingAs($this->superAdmin)->put("/manage-student/{$data['studentId']}", $payload);

        // December invoice in AED 320 via Create Fee
        $this->actingAs($this->superAdmin)->post('/challan/create-fee', [
            'family_id' => $data['familyId'],
            'student_ids' => [$data['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [12],
            'year' => 2026,
        ]);

        $family = DB::table('families')->where('id', $data['familyId'])->first();

        // 1. Check Fee Collection view data
        $response = $this->actingAs($this->superAdmin)->get('/challan?family=' . urlencode($family->family_code));
        $response->assertOk();

        $metrics = $response->viewData('summaryMetrics');
        $this->assertTrue($metrics['is_multi_currency'], 'Summary must indicate multi-currency');
        $this->assertContains('SAR', $metrics['currencies']);
        $this->assertContains('AED', $metrics['currencies']);
        $this->assertNull($metrics['total_due_minor'], 'Cross-currency total due must be null to avoid balance merging');

        $byCurr = $metrics['by_currency'];
        $this->assertEquals(28000, $byCurr['SAR']['total_due_minor']);
        $this->assertEquals(32000, $byCurr['AED']['total_due_minor']);

        // 2. Check Consolidated Challan PDF endpoint generates cleanly with separate currencies
        $pdfResponse = $this->actingAs($this->superAdmin)->get("/families/{$data['familyId']}/challan/pdf");
        $pdfResponse->assertOk();
    }

    /**
     * Quick Test 8: Unauthorized users cannot change fee settings or recreate voided fees.
     */
    public function test_quick_test_unauthorized_users_cannot_change_fee_or_recreate_voided_fees(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-QT-UNAUTH');

        // Cashier attempting to change currency
        $cashierPayload = $this->editPayload($data['studentId'], [
            'currency' => 'AED',
        ]);
        $cResp = $this->actingAs($this->cashier)->put("/manage-student/{$data['studentId']}", $cashierPayload);
        $cResp->assertStatus(403);

        // Dept Manager attempting to change monthly fee
        $dmPayload = $this->editPayload($data['studentId'], [
            'monthly_fee' => '350.00',
        ]);
        $dmResp = $this->actingAs($this->deptManager)->put("/manage-student/{$data['studentId']}", $dmPayload);
        $dmResp->assertStatus(403);

        // Create and void an invoice
        $this->generateInvoiceForMonth($data, 2026, 10, 28000);
        $item = DB::table('invoice_items')->where('student_id', $data['studentId'])->first();
        app(\App\Services\FeeAdjustmentService::class)->voidFee($item->id, 'Void for test', $this->superAdmin->id);

        // Super admin updates fee to AED 320
        $payload = $this->editPayload($data['studentId'], [
            'monthly_fee' => '320.00',
            'currency' => 'AED',
        ]);
        $this->actingAs($this->superAdmin)->put("/manage-student/{$data['studentId']}", $payload);

        // Cashier attempting to recreate the voided fee via Create Fee with updated rate/currency is blocked
        $recreateResp = $this->actingAs($this->cashier)->post('/challan/create-fee', [
            'family_id' => $data['familyId'],
            'student_ids' => [$data['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
            'reissue_approved' => true,
        ]);
        $recreateResp->assertStatus(403);
    }

    /**
     * Requirement 6: Reissuing an older period before the new rate's effective date requires explicit reissue approval.
     */
    public function test_reissuing_older_period_before_new_rate_effective_date_requires_reissue_approval(): void
    {
        $data = $this->createStudentWithFee(28000, 'GR-QT-REISSUE');

        // October invoice at SAR 280 generated and voided
        $octInvId = $this->generateInvoiceForMonth($data, 2026, 10, 28000);
        $octItemId = DB::table('invoice_items')->where('invoice_id', $octInvId)->value('id');
        app(\App\Services\FeeAdjustmentService::class)->voidFee($octItemId, 'Voiding October', $this->superAdmin->id);

        // Insert new fee plan effective from December 2026 at AED 320
        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $data['enrollmentId'],
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee',
            'frequency' => 'monthly',
            'currency' => 'AED',
            'amount_minor' => 32000,
            'discount_kind' => null,
            'discount_value' => 0,
            'effective_from' => '2026-12-01',
            'effective_to' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempting to recreate October fee with new rate/currency without explicit reissue approval is rejected
        $respWithoutApproval = $this->actingAs($this->superAdmin)->post('/challan/create-fee', [
            'family_id' => $data['familyId'],
            'student_ids' => [$data['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
        ]);
        $respWithoutApproval->assertSessionHasErrors('fee_type_ids');

        // With reissue_approved flag, authorized admin can explicitly reissue using the newly approved rate and currency:
        $respWithApproval = $this->actingAs($this->superAdmin)->post('/challan/create-fee', [
            'family_id' => $data['familyId'],
            'student_ids' => [$data['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
            'reissue_approved' => true,
        ]);
        $respWithApproval->assertSessionHas('notice');

        $origVoidedOct = DB::table('invoice_items')->where('id', $octItemId)->first();
        $this->assertEquals('voided', $origVoidedOct->status);

        $recreated = DB::table('invoice_items')
            ->where('student_id', $data['studentId'])
            ->where('period_start', '2026-10-01')
            ->where('status', 'active')
            ->first();
        $this->assertNotNull($recreated);
        $this->assertEquals('active', $recreated->status);
        $this->assertEquals('AED', $recreated->currency);
        $this->assertEquals(32000, $recreated->amount_minor);
        $this->assertEquals($octItemId, $recreated->replaces_invoice_item_id);
    }
}
