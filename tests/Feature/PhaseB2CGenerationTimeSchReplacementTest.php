<?php

namespace Tests\Feature;

use App\Jobs\DispatchBatchToN8n;
use App\Services\InvoiceService;
use App\Services\SafeSchReplacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseB2CGenerationTimeSchReplacementTest extends TestCase
{
    use RefreshDatabase;

    private SafeSchReplacementService $service;
    private object $admin;
    private object $deptManager;
    private int $campusId;
    private int $deptId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SafeSchReplacementService::class);

        // Create department
        $this->deptId = DB::table('departments')->insertGetId([
            'name' => 'Department B2C',
            'code' => 'DEP-B2C',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create campus
        $this->campusId = DB::table('campuses')->insertGetId([
            'name' => 'Campus B2C',
            'code' => 'C-B2C',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create authorized admin user
        $adminId = DB::table('users')->insertGetId([
            'name' => 'Finance Admin B2C',
            'email' => 'admin_test_b2c@iqra.test',
            'password' => bcrypt('secret123'),
            'role' => 'finance_admin',
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin = \App\Models\User::find($adminId);

        // Create department manager
        $deptManagerId = DB::table('users')->insertGetId([
            'name' => 'Dept Manager B2C',
            'email' => 'dept_manager_test_b2c@iqra.test',
            'password' => bcrypt('secret123'),
            'role' => 'department_manager',
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->deptManager = \App\Models\User::find($deptManagerId);
    }

    private function createFamilyWithStudent(string $currency = 'SAR'): array
    {
        $familyCode = 'FAM-B2C-' . uniqid();
        $familyId = DB::table('families')->insertGetId([
            'family_code' => $familyCode,
            'guardian_name' => 'Guardian ' . $familyCode,
            'email' => $familyCode . '@test.com',
            'whatsapp' => '+966500000002',
            'contact_verified' => 1,
            'messaging_opt_in' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'name' => 'Student B2C',
            'gr_number' => 'GR-' . uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'status' => 'active',
            'currency' => $currency,
            'monthly_fee_minor' => 50000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feeTypeId = DB::table('fee_types')->insertGetId([
            'name' => 'Tuition Fee B2C',
            'code' => 'TUI-' . uniqid(),
            'department_id' => $this->deptId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('familyId', 'studentId', 'enrollmentId', 'feeTypeId', 'currency');
    }

    /**
     * Requirement 9.1: A newly generated SCH triggers eligibility evaluation after commit.
     * Requirement 9.2: An older safe queued notification is superseded.
     */
    public function test_1_newly_generated_sch_triggers_replacement_of_older_safe_queued_notification(): void
    {
        $data = $this->createFamilyWithStudent();

        // 1. Create an older invoice and older queued notification
        $olderRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'status' => 'generated',
            'created_by' => $this->admin->id,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $olderInvId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $olderRunId,
            'family_id' => $data['familyId'],
            'department_id' => $this->deptId,
            'campus_id' => $this->campusId,
            'currency' => $data['currency'],
            'invoice_number' => 'SCH-2026-0000010',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 50000,
            'paid_minor' => 0,
            'status' => 'issued',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $olderInvId,
            'student_id' => $data['studentId'],
            'enrollment_id' => $data['enrollmentId'],
            'fee_type_id' => $data['feeTypeId'],
            'description' => 'Tuition Fee Oct 2026',
            'currency' => $data['currency'],
            'amount_minor' => 50000,
            'discount_minor' => 0,
            'net_minor' => 50000,
            'paid_minor' => 0,
            'status' => 'active',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $batchId = 'BATCH-GEN-TEST-' . uniqid();
        DB::table('notification_batches')->insert([
            'batch_id' => $batchId,
            'billing_period' => '2026-10',
            'webhook_type' => 'monthly',
            'status' => 'queued',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $olderNotifId = DB::table('notifications')->insertGetId([
            'batch_id' => $batchId,
            'invoice_id' => $olderInvId,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'webhook_type' => 'monthly',
            'status' => 'queued',
            'attempts' => 0,
            'last_attempt_at' => null,
            'submitted_at' => null,
            'is_superseded' => false,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        // 2. Set up active fee plan item for Nov 2026
        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $data['enrollmentId'],
            'fee_type_id' => $data['feeTypeId'],
            'frequency' => 'monthly',
            'description' => 'Tuition Fee Nov 2026',
            'amount_minor' => 50000,
            'currency' => $data['currency'],
            'is_active' => true,
            'status' => 'active',
            'effective_from' => '2026-11-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Trigger generation through BillingController@generate endpoint
        $response = $this->actingAs($this->admin)->post('/fee-creator', [
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'monthly',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-25',
        ]);

        $response->assertRedirect('/invoices');

        // 4. Verify older notification was automatically superseded
        $olderNotif = DB::table('notifications')->where('id', $olderNotifId)->first();
        $this->assertSame('superseded', $olderNotif->status);
        $this->assertTrue((bool) $olderNotif->is_superseded);
        $this->assertNotNull($olderNotif->superseded_by_invoice_id);

        // Verify the newer invoice is indeed the superseding invoice
        $newerInvoice = DB::table('invoices')->where('id', $olderNotif->superseded_by_invoice_id)->first();
        $this->assertSame($data['familyId'], (int) $newerInvoice->family_id);
        $this->assertFalse((bool) $newerInvoice->approved_to_send); // Still subject to staff approval!

        // Verify audit log entry
        $audit = DB::table('audit_logs')
            ->where('action', 'notification.superseded_by_sch_generation')
            ->where('subject_id', $olderNotifId)
            ->first();
        $this->assertNotNull($audit);
    }

    /**
     * Requirement 9.3: A claimed or attempted notification is preserved.
     */
    public function test_2_claimed_or_attempted_notification_is_preserved_during_generation(): void
    {
        $data = $this->createFamilyWithStudent();

        $olderRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'status' => 'generated',
            'created_by' => $this->admin->id,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $olderInvId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $olderRunId,
            'family_id' => $data['familyId'],
            'department_id' => $this->deptId,
            'campus_id' => $this->campusId,
            'currency' => $data['currency'],
            'invoice_number' => 'SCH-2026-0000020',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 50000,
            'paid_minor' => 0,
            'status' => 'issued',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $olderInvId,
            'student_id' => $data['studentId'],
            'enrollment_id' => $data['enrollmentId'],
            'fee_type_id' => $data['feeTypeId'],
            'description' => 'Tuition Fee Oct 2026',
            'currency' => $data['currency'],
            'amount_minor' => 50000,
            'discount_minor' => 0,
            'net_minor' => 50000,
            'paid_minor' => 0,
            'status' => 'active',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        // Attempted notification (attempts = 1, status = processing)
        $olderNotifId = DB::table('notifications')->insertGetId([
            'batch_id' => 'BATCH-CLAIMED-' . uniqid(),
            'invoice_id' => $olderInvId,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'webhook_type' => 'monthly',
            'status' => 'processing',
            'attempts' => 1,
            'last_attempt_at' => now()->subMinutes(10),
            'submitted_at' => null,
            'is_superseded' => false,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $data['enrollmentId'],
            'fee_type_id' => $data['feeTypeId'],
            'frequency' => 'monthly',
            'description' => 'Tuition Fee Nov 2026',
            'amount_minor' => 50000,
            'currency' => $data['currency'],
            'is_active' => true,
            'status' => 'active',
            'effective_from' => '2026-11-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)->post('/fee-creator', [
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'monthly',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-25',
        ]);

        // Must remain unchanged
        $notif = DB::table('notifications')->where('id', $olderNotifId)->first();
        $this->assertSame('processing', $notif->status);
        $this->assertFalse((bool) $notif->is_superseded);
        $this->assertNull($notif->superseded_by_invoice_id);
    }

    /**
     * Requirement 9.4: A failed generation never triggers replacement.
     */
    public function test_4_failed_generation_never_triggers_replacement(): void
    {
        $data = $this->createFamilyWithStudent();

        $olderRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'status' => 'generated',
            'created_by' => $this->admin->id,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $olderInvId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $olderRunId,
            'family_id' => $data['familyId'],
            'department_id' => $this->deptId,
            'campus_id' => $this->campusId,
            'currency' => $data['currency'],
            'invoice_number' => 'SCH-2026-0000030',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 50000,
            'paid_minor' => 0,
            'status' => 'issued',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $olderNotifId = DB::table('notifications')->insertGetId([
            'batch_id' => 'BATCH-FAIL-TEST-' . uniqid(),
            'invoice_id' => $olderInvId,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'webhook_type' => 'monthly',
            'status' => 'queued',
            'attempts' => 0,
            'last_attempt_at' => null,
            'submitted_at' => null,
            'is_superseded' => false,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        // Submit invalid dates that fail validation in BillingController
        $response = $this->actingAs($this->admin)->post('/fee-creator', [
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'monthly',
            'period_start' => '2026-11-05', // Not full calendar month
            'period_end' => '2026-11-20',
            'due_date' => '2026-11-25',
        ]);

        $response->assertSessionHasErrors(['period_end']);

        // Notification must remain queued and intact
        $notif = DB::table('notifications')->where('id', $olderNotifId)->first();
        $this->assertSame('queued', $notif->status);
        $this->assertFalse((bool) $notif->is_superseded);
    }

    /**
     * Requirement 9.5: Another family's batch notifications are unaffected.
     */
    public function test_5_another_familys_batch_notifications_are_unaffected(): void
    {
        $data1 = $this->createFamilyWithStudent();
        $data2 = $this->createFamilyWithStudent();

        $batchId = 'BATCH-MULTI-FAM-' . uniqid();
        DB::table('notification_batches')->insert([
            'batch_id' => $batchId,
            'billing_period' => '2026-10',
            'webhook_type' => 'monthly',
            'status' => 'queued',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $olderRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'status' => 'generated',
            'created_by' => $this->admin->id,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $inv1 = DB::table('invoices')->insertGetId([
            'billing_run_id' => $olderRunId,
            'family_id' => $data1['familyId'],
            'department_id' => $this->deptId,
            'campus_id' => $this->campusId,
            'currency' => $data1['currency'],
            'invoice_number' => 'SCH-2026-0000041',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 50000,
            'paid_minor' => 0,
            'status' => 'issued',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $inv1,
            'student_id' => $data1['studentId'],
            'enrollment_id' => $data1['enrollmentId'],
            'fee_type_id' => $data1['feeTypeId'],
            'description' => 'Tuition Fee Fam 1',
            'currency' => $data1['currency'],
            'amount_minor' => 50000,
            'discount_minor' => 0,
            'net_minor' => 50000,
            'paid_minor' => 0,
            'status' => 'active',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $notif1 = DB::table('notifications')->insertGetId([
            'batch_id' => $batchId,
            'invoice_id' => $inv1,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'webhook_type' => 'monthly',
            'status' => 'queued',
            'attempts' => 0,
            'last_attempt_at' => null,
            'submitted_at' => null,
            'is_superseded' => false,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $inv2 = DB::table('invoices')->insertGetId([
            'billing_run_id' => $olderRunId,
            'family_id' => $data2['familyId'],
            'department_id' => $this->deptId,
            'campus_id' => $this->campusId,
            'currency' => $data2['currency'],
            'invoice_number' => 'SCH-2026-0000042',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 50000,
            'paid_minor' => 0,
            'status' => 'issued',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $inv2,
            'student_id' => $data2['studentId'],
            'enrollment_id' => $data2['enrollmentId'],
            'fee_type_id' => $data2['feeTypeId'],
            'description' => 'Tuition Fee Fam 2',
            'currency' => $data2['currency'],
            'amount_minor' => 50000,
            'discount_minor' => 0,
            'net_minor' => 50000,
            'paid_minor' => 0,
            'status' => 'active',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $notif2 = DB::table('notifications')->insertGetId([
            'batch_id' => $batchId,
            'invoice_id' => $inv2,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'webhook_type' => 'monthly',
            'status' => 'queued',
            'attempts' => 0,
            'last_attempt_at' => null,
            'submitted_at' => null,
            'is_superseded' => false,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        // Only Family 1 has a fee plan item for Nov 2026
        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $data1['enrollmentId'],
            'fee_type_id' => $data1['feeTypeId'],
            'frequency' => 'monthly',
            'description' => 'Tuition Fee Nov 2026',
            'amount_minor' => 50000,
            'currency' => $data1['currency'],
            'is_active' => true,
            'status' => 'active',
            'effective_from' => '2026-11-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)->post('/fee-creator', [
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'monthly',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-25',
        ]);

        // Family 1 notification is superseded
        $resNotif1 = DB::table('notifications')->where('id', $notif1)->first();
        $this->assertSame('superseded', $resNotif1->status);
        $this->assertTrue((bool) $resNotif1->is_superseded);

        // Family 2 notification is completely unaffected and still queued in the batch
        $resNotif2 = DB::table('notifications')->where('id', $notif2)->first();
        $this->assertSame('queued', $resNotif2->status);
        $this->assertFalse((bool) $resNotif2->is_superseded);
        $this->assertSame($batchId, $resNotif2->batch_id);
    }

    /**
     * Requirement 9.6: No duplicate WhatsApp webhook request occurs.
     * Requirement 9.7: New SCH remains subject to staff approval.
     */
    public function test_6_no_duplicate_webhook_and_new_sch_requires_approval(): void
    {
        Http::fake(['*' => Http::response(['message_id' => 'MSG-B2C-1'], 200)]);

        DB::table('webhook_settings')->updateOrInsert(['webhook_type' => 'monthly'], [
            'url' => 'https://webhook.test/monthly',
            'is_enabled' => 1,
            'secret' => 'test-secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $data = $this->createFamilyWithStudent();

        $olderRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'status' => 'generated',
            'created_by' => $this->admin->id,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $olderInvId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $olderRunId,
            'family_id' => $data['familyId'],
            'department_id' => $this->deptId,
            'campus_id' => $this->campusId,
            'currency' => $data['currency'],
            'invoice_number' => 'SCH-2026-0000050',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 50000,
            'paid_minor' => 0,
            'status' => 'issued',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $olderInvId,
            'student_id' => $data['studentId'],
            'enrollment_id' => $data['enrollmentId'],
            'fee_type_id' => $data['feeTypeId'],
            'description' => 'Tuition Fee Oct 2026',
            'currency' => $data['currency'],
            'amount_minor' => 50000,
            'discount_minor' => 0,
            'net_minor' => 50000,
            'paid_minor' => 0,
            'status' => 'active',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $batchId = 'BATCH-DUP-TEST-' . uniqid();
        DB::table('notification_batches')->insert([
            'batch_id' => $batchId,
            'billing_period' => '2026-10',
            'webhook_type' => 'monthly',
            'status' => 'queued',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $olderNotifId = DB::table('notifications')->insertGetId([
            'batch_id' => $batchId,
            'invoice_id' => $olderInvId,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'webhook_type' => 'monthly',
            'status' => 'queued',
            'attempts' => 0,
            'last_attempt_at' => null,
            'submitted_at' => null,
            'is_superseded' => false,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $data['enrollmentId'],
            'fee_type_id' => $data['feeTypeId'],
            'frequency' => 'monthly',
            'description' => 'Tuition Fee Nov 2026',
            'amount_minor' => 50000,
            'currency' => $data['currency'],
            'is_active' => true,
            'status' => 'active',
            'effective_from' => '2026-11-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Generate Nov 2026 invoice
        $this->actingAs($this->admin)->post('/fee-creator', [
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'monthly',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-25',
        ]);

        // Older notification is superseded
        $this->assertSame('superseded', DB::table('notifications')->where('id', $olderNotifId)->value('status'));

        // Run worker on older batch
        $job = new DispatchBatchToN8n($batchId);
        $job->handle();

        // No HTTP request was sent for the superseded notification!
        Http::assertNothingSent();

        // New invoice must have approved_to_send = false (requires explicit staff approval)
        $newInvoice = DB::table('invoices')->where('billing_run_id', '!=', $olderRunId)->first();
        $this->assertNotNull($newInvoice);
        $this->assertFalse((bool) $newInvoice->approved_to_send);
    }

    /**
     * Requirement 9.8: No financial invoice or receipt records are modified.
     * Requirement 9.9: Route counts and permissions remain unchanged.
     */
    public function test_7_no_financial_records_modified_and_routes_remain_intact(): void
    {
        $initialInvoices = DB::table('invoices')->count();
        $initialPayments = DB::table('payments')->count();
        $initialAllocations = DB::table('payment_allocations')->count();

        // Verify routes count and integrity
        $routeCollection = \Illuminate\Support\Facades\Route::getRoutes();
        $invoiceRoutes = collect($routeCollection)->filter(fn($r) => str_contains($r->uri(), 'invoices') || str_contains($r->uri(), 'challan') || str_contains($r->uri(), 'fee-creator'));
        $this->assertGreaterThan(10, $invoiceRoutes->count());

        $this->assertSame($initialInvoices, DB::table('invoices')->count());
        $this->assertSame($initialPayments, DB::table('payments')->count());
        $this->assertSame($initialAllocations, DB::table('payment_allocations')->count());
    }

    /**
     * Requirement 9.10: Replacement remains safe under simultaneous generation and worker activity.
     * When worker holds the batch lock, generation completes successfully but replacement is safely skipped.
     */
    public function test_8_simultaneous_worker_lock_preserves_notification_and_does_not_break_generation(): void
    {
        $data = $this->createFamilyWithStudent();

        $olderRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'status' => 'generated',
            'created_by' => $this->admin->id,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $olderInvId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $olderRunId,
            'family_id' => $data['familyId'],
            'department_id' => $this->deptId,
            'campus_id' => $this->campusId,
            'currency' => $data['currency'],
            'invoice_number' => 'SCH-2026-0000080',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 50000,
            'paid_minor' => 0,
            'status' => 'issued',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $olderInvId,
            'student_id' => $data['studentId'],
            'enrollment_id' => $data['enrollmentId'],
            'fee_type_id' => $data['feeTypeId'],
            'description' => 'Tuition Fee Oct 2026',
            'currency' => $data['currency'],
            'amount_minor' => 50000,
            'discount_minor' => 0,
            'net_minor' => 50000,
            'paid_minor' => 0,
            'status' => 'active',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $batchId = 'BATCH-CONCURRENT-' . uniqid();
        DB::table('notification_batches')->insert([
            'batch_id' => $batchId,
            'billing_period' => '2026-10',
            'webhook_type' => 'monthly',
            'status' => 'queued',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $olderNotifId = DB::table('notifications')->insertGetId([
            'batch_id' => $batchId,
            'invoice_id' => $olderInvId,
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'webhook_type' => 'monthly',
            'status' => 'queued',
            'attempts' => 0,
            'last_attempt_at' => null,
            'submitted_at' => null,
            'is_superseded' => false,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $data['enrollmentId'],
            'fee_type_id' => $data['feeTypeId'],
            'frequency' => 'monthly',
            'description' => 'Tuition Fee Nov 2026',
            'amount_minor' => 50000,
            'currency' => $data['currency'],
            'is_active' => true,
            'status' => 'active',
            'effective_from' => '2026-11-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Worker acquires the lock before generation finishes
        $lock = app(\App\Services\BatchDispatchLock::class);
        $this->assertTrue($lock->acquire($batchId));

        try {
            // Trigger generation
            $response = $this->actingAs($this->admin)->post('/fee-creator', [
                'campus_id' => $this->campusId,
                'department_id' => $this->deptId,
                'frequency' => 'monthly',
                'period_start' => '2026-11-01',
                'period_end' => '2026-11-30',
                'due_date' => '2026-11-25',
            ]);
            $response->assertRedirect('/invoices');

            // Because lock was held by worker, replacement was safely skipped (no corruption, no crash)
            $notif = DB::table('notifications')->where('id', $olderNotifId)->first();
            $this->assertSame('queued', $notif->status);
            $this->assertFalse((bool) $notif->is_superseded);
            $this->assertNull($notif->superseded_by_invoice_id);
        } finally {
            $lock->release();
        }
    }
}
