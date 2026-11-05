<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InvoiceService;
use App\Services\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentDepartmentChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $schoolDeptId;
    private int $quranDeptId;
    private int $tuitionDeptId;
    private int $campusId;
    private int $monthlyFeeTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'password' => bcrypt('secret'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->schoolDeptId = (int) DB::table('departments')->where('code', 'school')->value('id');
        $this->quranDeptId = (int) DB::table('departments')->where('code', 'quran')->value('id');
        $this->tuitionDeptId = (int) DB::table('departments')->where('code', 'tuition')->value('id');
        $this->campusId = (int) DB::table('campuses')->value('id');
        $this->monthlyFeeTypeId = (int) DB::table('fee_types')->where('code', 'monthly')->value('id');
    }

    private function createStudentWithHistory(): array
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-TEST-DEPT',
            'guardian_name' => 'Test Guardian',
            'email' => 'guardian@example.test',
            'whatsapp' => '+923001112233',
            'contact_verified' => 1,
            'messaging_opt_in' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'GR-TEST-001',
            'name' => 'Zayd Khan',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('student_registration_details')->insert([
            'student_id' => $studentId,
            'father_name' => 'Khan',
            'registration_number' => 'GR-TEST-001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $programId = DB::table('programs')->insertGetId([
            'department_id' => $this->schoolDeptId,
            'name' => 'Grade 4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'program_id' => $programId,
            'grade_label' => 'Grade 4',
            'currency' => 'SAR',
            'monthly_fee_minor' => 30000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $planId = DB::table('fee_plan_items')->insertGetId([
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee',
            'frequency' => 'monthly',
            'currency' => 'SAR',
            'amount_minor' => 30000,
            'effective_from' => '2026-08-01',
            'effective_to' => null,
            'is_active' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Historical billing run & invoice for August 2026 (School)
        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-08-01..2026-08-31',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'due_date' => '2026-08-15',
            'status' => 'generated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoiceId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'invoice_number' => sprintf('SCH-2026-%07d', 101),
            'issued_on' => '2026-08-01',
            'due_date' => '2026-08-15',
            'total_minor' => 30000,
            'paid_minor' => 30000,
            'status' => 'paid',
            'approved_to_send' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId,
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'fee_plan_item_id' => $planId,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee - Aug-2026',
            'period_key' => 'monthly:2026-08-01..2026-08-31',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'currency' => 'SAR',
            'amount_minor' => 30000,
            'net_minor' => 30000,
            'paid_minor' => 30000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Historical payment receipt for August
        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'receipt_number' => 'RCP-SCH-2026-00001',
            'amount_minor' => 30000,
            'unallocated_minor' => 0,
            'received_on' => '2026-08-05',
            'idempotency_key' => 'idemp-' . uniqid(),
            'request_hash' => md5(uniqid()),
            'received_by' => $this->admin->id,
            'status' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_allocations')->insertGetId([
            'payment_id' => $paymentId,
            'invoice_item_id' => $itemId,
            'amount_minor' => 30000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('familyId', 'studentId', 'enrollmentId', 'planId', 'invoiceId', 'itemId', 'paymentId');
    }

    public function test_school_to_quran_department_change_and_future_billing(): void
    {
        $fixture = $this->createStudentWithHistory();
        $studentId = $fixture['studentId'];

        // 1. Change department from School to Quran via PUT /manage-student/{id}
        $response = $this->actingAs($this->admin)->put("/manage-student/{$studentId}", [
            'name' => 'Zayd Khan',
            'father_name' => 'Khan',
            'registration_number' => 'GR-TEST-001',
            'family_code' => 'FAM-TEST-DEPT',
            'family_email' => 'guardian@example.test',
            'family_whatsapp' => '+923001112233',
            'campus_id' => $this->campusId,
            'department_id' => $this->quranDeptId,
            'grade_label' => 'Hifz Class',
            'class_days_per_week' => 5,
            'currency' => 'SAR',
            'monthly_fee' => '350.00',
        ]);

        $response->assertRedirect("/manage-student/{$studentId}");
        $response->assertSessionHas('notice');

        // 2. Department change saved and retained after page refresh
        $editPage = $this->actingAs($this->admin)->get("/manage-student/{$studentId}/edit");
        $editPage->assertOk();
        $editData = $editPage->viewData('initial');
        $this->assertEquals($this->quranDeptId, $editData['department_id'], 'Edit form must select the new department');
        $this->assertEquals('Hifz Class', $editData['grade_label']);
        $this->assertEquals(5, $editData['class_days_per_week']);
        $this->assertEquals('350.00', $editData['monthly_fee']);

        // Check enrollment record in DB
        $enrollment = DB::table('enrollments')->where('student_id', $studentId)->first();
        $this->assertEquals($this->quranDeptId, (int) $enrollment->department_id);
        $this->assertEquals('Hifz Class', $enrollment->grade_label);
        $this->assertEquals(35000, (int) $enrollment->monthly_fee_minor);
        $this->assertEquals(5, (int) $enrollment->class_days_per_week);

        // 3. Cutoff: prior plan closed at August 31, new plan active from September 1
        $oldPlan = DB::table('fee_plan_items')->where('id', $fixture['planId'])->first();
        $this->assertFalse((bool) $oldPlan->is_active);
        $this->assertEquals('superseded', $oldPlan->status);
        $this->assertEquals('2026-08-31', $oldPlan->effective_to);

        $newPlan = DB::table('fee_plan_items')
            ->where('enrollment_id', $enrollment->id)
            ->where('is_active', true)
            ->first();
        $this->assertNotNull($newPlan);
        $this->assertEquals(35000, (int) $newPlan->amount_minor);
        $this->assertEquals('2026-09-01', $newPlan->effective_from);
        $this->assertEquals(5, (int) $newPlan->class_days_per_week);

        // 4. Verify historical invoice & receipt remain UNCHANGED under School
        $histInvoice = DB::table('invoices')->where('id', $fixture['invoiceId'])->first();
        $this->assertEquals($this->schoolDeptId, (int) $histInvoice->department_id);
        $this->assertStringStartsWith('SCH-', $histInvoice->invoice_number);
        $this->assertEquals(30000, (int) $histInvoice->total_minor);

        $histPayment = DB::table('payments')->where('id', $fixture['paymentId'])->first();
        $this->assertEquals($this->schoolDeptId, (int) $histPayment->department_id);
        $this->assertEquals('RCP-SCH-2026-00001', $histPayment->receipt_number);

        $histAllocation = DB::table('payment_allocations')->where('payment_id', $fixture['paymentId'])->first();
        $this->assertEquals($fixture['itemId'], (int) $histAllocation->invoice_item_id);

        // 5. Generate September fee for School: student must NOT be billed in School
        $schoolRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-09-01..2026-09-30',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'due_date' => '2026-09-15',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $invoiceSvc = app(InvoiceService::class);
        $schoolResult = $invoiceSvc->generate($schoolRunId, '2026-09-01');
        $this->assertEquals(0, $schoolResult['created'], 'School run must not bill student in Quran department');

        // 6. Generate September fee for Quran: student MUST be billed under Quran
        $quranRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->quranDeptId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-09-01..2026-09-30',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'due_date' => '2026-09-15',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $quranResult = $invoiceSvc->generate($quranRunId, '2026-09-01');
        $this->assertEquals(1, $quranResult['created'], 'Quran run must bill student under Quran');

        $newInvoice = DB::table('invoices')->where('billing_run_id', $quranRunId)->first();
        $this->assertNotNull($newInvoice);
        $this->assertEquals($this->quranDeptId, (int) $newInvoice->department_id);
        $this->assertStringStartsWith('QUR-', $newInvoice->invoice_number);
        $this->assertEquals(35000, (int) $newInvoice->total_minor);

        $newItem = DB::table('invoice_items')->where('invoice_id', $newInvoice->id)->first();
        $this->assertEquals($studentId, (int) $newItem->student_id);
        $this->assertEquals(35000, (int) $newItem->net_minor);
        $this->assertEquals('monthly:2026-09-01..2026-09-30', $newItem->period_key);

        // 7. Test Quran to School change back
        $response2 = $this->actingAs($this->admin)->put("/manage-student/{$studentId}", [
            'name' => 'Zayd Khan',
            'father_name' => 'Khan',
            'registration_number' => 'GR-TEST-001',
            'family_code' => 'FAM-TEST-DEPT',
            'family_email' => 'guardian@example.test',
            'family_whatsapp' => '+923001112233',
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'grade_label' => 'Grade 5',
            'currency' => 'SAR',
            'monthly_fee' => '320.00',
        ]);
        $response2->assertRedirect("/manage-student/{$studentId}");

        $enrollmentAfterReturn = DB::table('enrollments')->where('student_id', $studentId)->first();
        $this->assertEquals($this->schoolDeptId, (int) $enrollmentAfterReturn->department_id);
        $this->assertEquals('Grade 5', $enrollmentAfterReturn->grade_label);
        $this->assertEquals(32000, (int) $enrollmentAfterReturn->monthly_fee_minor);
        $this->assertNull($enrollmentAfterReturn->class_days_per_week, 'Quran days must be cleared when returning to School');

        // Check fee plan cutoff for October
        $octPlan = DB::table('fee_plan_items')
            ->where('enrollment_id', $enrollment->id)
            ->where('is_active', true)
            ->first();
        $this->assertEquals('2026-10-01', $octPlan->effective_from);
        $this->assertEquals(32000, (int) $octPlan->amount_minor);

        // 8. Generate October fee for School: billed under School with SCH-
        $schoolRunOctId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-15',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $schoolOctResult = $invoiceSvc->generate($schoolRunOctId, '2026-10-01');
        $this->assertEquals(1, $schoolOctResult['created']);

        $octInvoice = DB::table('invoices')->where('billing_run_id', $schoolRunOctId)->first();
        $this->assertEquals($this->schoolDeptId, (int) $octInvoice->department_id);
        $this->assertStringStartsWith('SCH-', $octInvoice->invoice_number);
        $this->assertEquals(32000, (int) $octInvoice->total_minor);

        // 9. All 3 invoices retain their exact original department
        // August: School (SCH-)
        $this->assertEquals($this->schoolDeptId, (int) DB::table('invoices')->where('id', $fixture['invoiceId'])->value('department_id'));
        $this->assertStringStartsWith('SCH-', DB::table('invoices')->where('id', $fixture['invoiceId'])->value('invoice_number'));

        // September: Quran (QUR-)
        $this->assertEquals($this->quranDeptId, (int) DB::table('invoices')->where('id', $newInvoice->id)->value('department_id'));
        $this->assertStringStartsWith('QUR-', DB::table('invoices')->where('id', $newInvoice->id)->value('invoice_number'));

        // October: School (SCH-)
        $this->assertEquals($this->schoolDeptId, (int) DB::table('invoices')->where('id', $octInvoice->id)->value('department_id'));
        $this->assertStringStartsWith('SCH-', DB::table('invoices')->where('id', $octInvoice->id)->value('invoice_number'));
    }

    public function test_department_change_to_tuition_handles_subject_name(): void
    {
        $fixture = $this->createStudentWithHistory();
        $studentId = $fixture['studentId'];

        // Change department to Tuition with subject 'Mathematics'
        $response = $this->actingAs($this->admin)->put("/manage-student/{$studentId}", [
            'name' => 'Zayd Khan',
            'father_name' => 'Khan',
            'registration_number' => 'GR-TEST-001',
            'family_code' => 'FAM-TEST-DEPT',
            'family_email' => 'guardian@example.test',
            'family_whatsapp' => '+923001112233',
            'campus_id' => $this->campusId,
            'department_id' => $this->tuitionDeptId,
            'grade_label' => 'Tuition Grade 8',
            'subject_name' => 'Mathematics',
            'currency' => 'SAR',
            'monthly_fee' => '250.00',
        ]);

        $response->assertRedirect("/manage-student/{$studentId}");

        $enrollment = DB::table('enrollments')->where('student_id', $studentId)->first();
        $this->assertEquals($this->tuitionDeptId, (int) $enrollment->department_id);
        $this->assertEquals('Mathematics', $enrollment->subject_name);

        $plan = DB::table('fee_plan_items')
            ->where('enrollment_id', $enrollment->id)
            ->where('is_active', true)
            ->first();
        $this->assertEquals('Mathematics', $plan->subject_name);
        $this->assertEquals('Tuition Fee - Mathematics', $plan->description);
        $this->assertEquals(25000, (int) $plan->amount_minor);

        // Future bill uses TUI prefix
        $tuitionRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->tuitionDeptId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-09-01..2026-09-30',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'due_date' => '2026-09-15',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $invoiceSvc = app(InvoiceService::class);
        $tuitionResult = $invoiceSvc->generate($tuitionRunId, '2026-09-01');
        $this->assertEquals(1, $tuitionResult['created']);

        $tuitionInvoice = DB::table('invoices')->where('billing_run_id', $tuitionRunId)->first();
        $this->assertEquals($this->tuitionDeptId, (int) $tuitionInvoice->department_id);
        $this->assertStringStartsWith('TUI-', $tuitionInvoice->invoice_number);
    }

    public function test_voided_fee_recreation_respects_original_department(): void
    {
        $fixture = $this->createStudentWithHistory();
        $studentId = $fixture['studentId'];
        $familyId = $fixture['familyId'];

        // Change department to Quran
        $this->actingAs($this->admin)->put("/manage-student/{$studentId}", [
            'name' => 'Zayd Khan',
            'father_name' => 'Khan',
            'registration_number' => 'GR-TEST-001',
            'family_code' => 'FAM-TEST-DEPT',
            'family_email' => 'guardian@example.test',
            'family_whatsapp' => '+923001112233',
            'campus_id' => $this->campusId,
            'department_id' => $this->quranDeptId,
            'grade_label' => 'Hifz Class',
            'class_days_per_week' => 5,
            'currency' => 'SAR',
            'monthly_fee' => '350.00',
        ])->assertRedirect("/manage-student/{$studentId}");

        // Reverse receipt first so item can be voided
        $this->actingAs($this->admin)->post('/challan/reverse-receipt', [
            'payment_id' => $fixture['paymentId'],
            'reason' => 'Reversing receipt to allow voiding test',
        ])->assertSessionHas('notice');

        // Now void August School invoice item
        $this->actingAs($this->admin)->post('/challan/void', [
            'invoice_item_id' => $fixture['itemId'],
            'reason' => 'Voiding August School fee for test',
        ])->assertSessionHas('notice');

        // Check preview via /challan/preview-fee
        $previewResp = $this->actingAs($this->admin)->postJson('/challan/preview-fee', [
            'family_id' => $familyId,
            'student_ids' => [$studentId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [8],
            'year' => 2026,
        ]);
        $previewResp->assertOk();

        // Recreate August voided fee
        $recreateResp = $this->actingAs($this->admin)->postJson('/challan/recreate-voided-fee', [
            'invoice_item_id' => $fixture['itemId'],
            'reason' => 'Recreating August voided fee',
        ]);
        $recreateResp->assertOk();
        $recreateData = $recreateResp->json();

        // Replacement invoice must retain original School department
        $replacementItem = DB::table('invoice_items')->where('id', $recreateData['replacement_invoice_item_id'])->first();
        $this->assertNotNull($replacementItem);
        $this->assertEquals('active', $replacementItem->status);

        $replacementInvoice = DB::table('invoices')->where('id', $replacementItem->invoice_id)->first();
        $this->assertEquals($this->schoolDeptId, (int) $replacementInvoice->department_id, 'Recreated fee must stay in original School department');
        $this->assertStringStartsWith('SCH-', $replacementInvoice->invoice_number);
    }

    public function test_fee_collection_and_student_profile_render_correct_departments(): void
    {
        $fixture = $this->createStudentWithHistory();
        $studentId = $fixture['studentId'];
        $familyId = $fixture['familyId'];

        // Change department to Quran
        $this->actingAs($this->admin)->put("/manage-student/{$studentId}", [
            'name' => 'Zayd Khan',
            'father_name' => 'Khan',
            'registration_number' => 'GR-TEST-001',
            'family_code' => 'FAM-TEST-DEPT',
            'family_email' => 'guardian@example.test',
            'family_whatsapp' => '+923001112233',
            'campus_id' => $this->campusId,
            'department_id' => $this->quranDeptId,
            'grade_label' => 'Hifz Class',
            'class_days_per_week' => 5,
            'currency' => 'SAR',
            'monthly_fee' => '350.00',
        ])->assertRedirect("/manage-student/{$studentId}");

        // View student profile (/manage-student/{id})
        $profile = $this->actingAs($this->admin)->get("/manage-student/{$studentId}");
        $profile->assertOk();
        $enrollments = $profile->viewData('enrollments');
        $this->assertNotEmpty($enrollments);
        $this->assertEquals('Quran', $enrollments->first()->department_name);

        // View Fee Collection (/challan?family=FAM-TEST-DEPT)
        $challan = $this->actingAs($this->admin)->get("/challan?family=FAM-TEST-DEPT");
        $challan->assertOk();
        $scopes = $challan->viewData('scopes');
        // Scopes include Quran (from active enrollment) and School (from historical billed invoice)
        $deptIds = $scopes->pluck('department_id')->map(fn($id) => (int)$id)->all();
        $this->assertContains($this->quranDeptId, $deptIds, 'Quran department must be in Challan scopes');
        $this->assertContains($this->schoolDeptId, $deptIds, 'School department must remain in Challan scopes for historical invoices');
    }
}
