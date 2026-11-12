<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FeeEligibilityService;
use App\Services\InvoiceService;
use App\Services\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentLeaveAndFeeExclusionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'super_admin'): User
    {
        $this->seed();
        return User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => bcrypt('secret123'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function createStudentSetup(array $overrides = []): array
    {
        $campusId = DB::table('campuses')->value('id');
        $deptId = DB::table('departments')->where('code', 'school')->value('id')
            ?: DB::table('departments')->value('id');

        $familyId = DB::table('families')->insertGetId([
            'family_code' => $overrides['family_code'] ?? 'FAM-LEAVE-' . uniqid(),
            'guardian_name' => 'Guardian Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $gr = $overrides['gr_number'] ?? 'GR-' . rand(1000, 9999);
        $studentId = DB::table('students')->insertGetId([
            'gr_number' => $gr,
            'name' => $overrides['name'] ?? 'Test Student',
            'family_id' => $familyId,
            'status' => $overrides['status'] ?? 'present',
            'date_of_admission' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('student_registration_details')->insert([
            'student_id' => $studentId,
            'father_name' => 'Father Test',
            'registration_number' => $gr,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'currency' => 'SAR',
            'monthly_fee_minor' => $overrides['monthly_fee_minor'] ?? 50000,
            'grade_label' => 'Grade 5',
            'status' => ($overrides['status'] ?? 'present') === 'present' ? 'active' : 'inactive',
            'started_on' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feeTypeId = DB::table('fee_types')->where('code', 'monthly')->value('id')
            ?: DB::table('fee_types')->insertGetId(['name' => 'Monthly Tuition Fee', 'code' => 'monthly', 'created_at' => now(), 'updated_at' => now()]);

        $feePlanId = DB::table('fee_plan_items')->insertGetId([
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $feeTypeId,
            'description' => 'Monthly Fee',
            'amount_minor' => $overrides['monthly_fee_minor'] ?? 50000,
            'currency' => 'SAR',
            'frequency' => 'monthly',
            'is_active' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'family_id' => $familyId,
            'student_id' => $studentId,
            'gr_number' => $gr,
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $feeTypeId,
            'fee_plan_id' => $feePlanId,
        ];
    }

    /** 1. Leave drawer UI elements and info endpoint return correct student info and read-only dues */
    public function test_leave_drawer_info_endpoint_returns_correct_student_and_ledger_dues(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Zayd Ali', 'gr_number' => 'GR-9901']);

        // Check students page renders Leave drawer markup and trigger button
        $resp = $this->get('/manage-student');
        $resp->assertOk();
        $resp->assertSee('id="myskoolLeaveDrawer"', false);
        $resp->assertSee('id="leaveDrawerStudentName"', false);
        $resp->assertSee('id="leave_remaining_dues"', false);
        $resp->assertSee('id="leave_category"', false);
        $resp->assertSee('id="leave_reason"', false);
        $resp->assertSee('id="leaveSaveBtn"', false);

        // Call leave-info endpoint
        $infoResp = $this->getJson("/manage-student/{$setup['student_id']}/leave-info");
        $infoResp->assertOk();
        $infoResp->assertJson([
            'success' => true,
            'student_id' => $setup['student_id'],
            'student_name' => 'Zayd Ali',
            'gr_number' => 'GR-9901',
            'status' => 'present',
            'remaining_dues' => 'SAR 0.00',
            'remaining_dues_minor' => 0,
        ]);
    }

    /** 2 & 3. Save leave info validates and records reason, date, category, status=left, preserves identity */
    public function test_save_leave_info_saves_data_and_marks_student_left(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Hamza Khan', 'gr_number' => 'GR-9902']);

        // Validation failure on empty fields
        $badResp = $this->postJson("/manage-student/{$setup['student_id']}/leave", []);
        $badResp->assertStatus(422);
        $badResp->assertJsonValidationErrors(['date_of_leaving', 'leaving_category', 'leaving_reason']);

        // Successful leave save
        $saveResp = $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2026-11-10',
            'leaving_category' => 'Transfer / Relocation',
            'leaving_reason' => 'Family relocated to another city.',
        ]);
        $saveResp->assertOk();
        $saveResp->assertJson([
            'success' => true,
            'student' => [
                'id' => $setup['student_id'],
                'name' => 'Hamza Khan',
                'gr_number' => 'GR-9902',
                'status' => 'left',
                'date_of_leaving' => '2026-11-10',
                'leaving_category' => 'Transfer / Relocation',
                'leaving_reason' => 'Family relocated to another city.',
            ],
        ]);

        // Verify database state: status=left, identity preserved
        $student = DB::table('students')->where('id', $setup['student_id'])->first();
        $this->assertSame('left', $student->status);
        $this->assertSame('2026-11-10', $student->date_of_leaving);
        $this->assertSame('GR-9902', $student->gr_number);
        $this->assertSame($setup['family_id'], $student->family_id);

        $reg = DB::table('student_registration_details')->where('student_id', $setup['student_id'])->first();
        $this->assertSame('2026-11-10', $reg->date_of_leaving);
        $this->assertSame('Transfer / Relocation', $reg->leaving_category);
        $this->assertSame('Family relocated to another city.', $reg->leaving_reason);

        $enrollment = DB::table('enrollments')->where('id', $setup['enrollment_id'])->first();
        $this->assertSame('inactive', $enrollment->status);
        $this->assertSame('2026-11-10', $enrollment->ended_on);

        // Audit log created
        $audit = DB::table('audit_logs')->where('action', 'student_marked_left')->where('subject_id', $setup['student_id'])->first();
        $this->assertNotNull($audit);
    }

    /** 4 & 5. Left student is strictly excluded from future monthly fee generation and bulk generation */
    public function test_left_student_is_excluded_from_monthly_and_bulk_fee_generation(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        // Create 2 students in same department/campus: Student A (Present), Student B (Left)
        $studentA = $this->createStudentSetup(['name' => 'Active Student', 'gr_number' => 'GR-ACTIVE-1', 'monthly_fee_minor' => 40000]);
        $studentB = $this->createStudentSetup(['name' => 'Left Student', 'gr_number' => 'GR-LEFT-1', 'monthly_fee_minor' => 40000]);

        // Mark Student B as Left
        $this->postJson("/manage-student/{$studentB['student_id']}/leave", [
            'date_of_leaving' => '2026-11-01',
            'leaving_category' => 'Switched to Another School',
            'leaving_reason' => 'Transferred out.',
        ])->assertOk();

        // Run monthly billing for November 2026
        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $studentA['campus_id'],
            'department_id' => $studentA['department_id'],
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-15',
            'fee_type_ids' => json_encode([$studentA['fee_type_id']]),
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = app(InvoiceService::class);
        $res = $svc->generate($runId);

        // Assert fee was generated for Student A, NOT for Student B
        $itemsStudentA = DB::table('invoice_items')->where('student_id', $studentA['student_id'])->get();
        $itemsStudentB = DB::table('invoice_items')->where('student_id', $studentB['student_id'])->get();

        $this->assertCount(1, $itemsStudentA, 'Active student must receive fee invoice');
        $this->assertCount(0, $itemsStudentB, 'Left student must NEVER receive fee invoice');
    }

    /** 6. Manual fee generation is rejected for Left students with a clear validation message */
    public function test_manual_fee_generation_is_blocked_for_left_students(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Tariq Left', 'gr_number' => 'GR-9903']);

        // Mark as left
        $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2026-11-05',
            'leaving_category' => 'Financial Difficulties',
            'leaving_reason' => 'Unable to continue.',
        ])->assertOk();

        // Attempt manual fee generation via createChallanFee
        $resp = $this->post('/challan/create-fee', [
            'family_id' => $setup['family_id'],
            'student_ids' => [$setup['student_id']],
            'fee_type_ids' => [$setup['fee_type_id']],
            'months' => [11],
            'year' => 2026,
        ]);

        $resp->assertSessionHasErrors('student_ids');
        $errorMsg = session('errors')->first('student_ids');
        $this->assertStringContainsString('Tariq Left', $errorMsg);
        $this->assertStringContainsString('marked as Left', $errorMsg);

        // Centralized check also returns false
        $check = FeeEligibilityService::checkStudentBillable($setup['student_id']);
        $this->assertFalse($check['eligible']);
        $this->assertSame('left', $check['status']);
    }

    /** 7. Preview fee generation reports Left students are blocked */
    public function test_preview_challan_fee_reports_left_students_as_blocked(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Bilal Left', 'gr_number' => 'GR-9904']);

        $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2026-11-05',
            'leaving_category' => 'Graduated / Completed Studies',
            'leaving_reason' => 'Completed graduation.',
        ])->assertOk();

        $previewResp = $this->postJson('/challan/preview-fee', [
            'family_id' => $setup['family_id'],
            'student_ids' => [$setup['student_id']],
            'fee_type_ids' => [$setup['fee_type_id']],
            'months' => [11],
            'year' => 2026,
        ]);

        $previewResp->assertOk();
        $json = $previewResp->json();
        $this->assertSame(0, $json['summary']['total_eligible']);
        $this->assertGreaterThan(0, $json['summary']['total_blocked']);
        $this->assertFalse($json['items'][0]['is_eligible']);
        $this->assertStringContainsString('marked as Left', $json['items'][0]['reason']);
    }

    /** 8. Blocking fee plan creation on Left students */
    public function test_adding_fee_plan_for_left_student_is_rejected(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Maryam Left', 'gr_number' => 'GR-9905']);

        $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2026-11-05',
            'leaving_category' => 'Other',
            'leaving_reason' => 'Left school.',
        ])->assertOk();

        $resp = $this->post('/manage-fee', [
            'enrollment_id' => $setup['enrollment_id'],
            'fee_type_id' => $setup['fee_type_id'],
            'description' => 'Annual Fee',
            'amount' => '150.00',
            'frequency' => 'annual',
        ]);

        $resp->assertSessionHasErrors('enrollment_id');
        $this->assertStringContainsString('marked as Left', session('errors')->first('enrollment_id'));
    }

    /** 9 & 10. Existing dues and invoices for Left student remain payable and unchanged */
    public function test_existing_invoices_and_dues_for_left_student_remain_payable(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Historical Debtor', 'gr_number' => 'GR-9906', 'monthly_fee_minor' => 35000]);

        // Create an existing unpaid invoice before leaving
        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $setup['campus_id'],
            'department_id' => $setup['department_id'],
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-15',
            'status' => 'completed',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoiceId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $setup['family_id'],
            'campus_id' => $setup['campus_id'],
            'department_id' => $setup['department_id'],
            'currency' => 'SAR',
            'invoice_number' => 'INV-TEST-001',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-15',
            'total_minor' => 35000,
            'paid_minor' => 0,
            'previous_dues_snapshot_minor' => 0,
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId,
            'student_id' => $setup['student_id'],
            'enrollment_id' => $setup['enrollment_id'],
            'fee_type_id' => $setup['fee_type_id'],
            'currency' => 'SAR',
            'description' => 'October Monthly Fee',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'amount_minor' => 35000,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => 35000,
            'paid_minor' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Dues before leaving: SAR 350.00
        $duesBefore = FeeEligibilityService::getStudentRemainingDues($setup['student_id']);
        $this->assertSame(35000, $duesBefore['total_minor']);
        $this->assertSame('SAR 350.00', $duesBefore['formatted']);

        // Mark student as Left
        $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2026-11-01',
            'leaving_category' => 'Personal / Family Reasons',
            'leaving_reason' => 'Family moved.',
        ])->assertOk();

        // Dues after leaving remain visible and identical: SAR 350.00
        $duesAfter = FeeEligibilityService::getStudentRemainingDues($setup['student_id']);
        $this->assertSame(35000, $duesAfter['total_minor']);
        $this->assertSame('SAR 350.00', $duesAfter['formatted']);

        // Receive payment for historical dues
        $payResp = $this->post('/challan/receive', [
            'family_id' => $setup['family_id'],
            'campus_id' => $setup['campus_id'],
            'department_id' => $setup['department_id'],
            'currency' => 'SAR',
            'receiving_amount' => '350.00',
            'monthly_amount' => '350.00',
            'selected_item_ids' => [$itemId],
            'receiving_date' => '2026-11-12',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $payResp->assertSessionHasNoErrors();

        // Verify invoice item was fully settled
        $refreshedItem = DB::table('invoice_items')->where('id', $itemId)->first();
        $this->assertSame(35000, (int) $refreshedItem->paid_minor);
        $this->assertSame((int) $refreshedItem->net_minor, (int) $refreshedItem->paid_minor);

        $duesSettled = FeeEligibilityService::getStudentRemainingDues($setup['student_id']);
        $this->assertSame(0, $duesSettled['total_minor']);
    }

    /** 11. Student search and status filter works with left students */
    public function test_student_search_and_filter_works_for_left_students(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Searchable Leftie', 'gr_number' => 'GR-9907']);

        $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2026-11-01',
            'leaving_category' => 'Medical / Health Reasons',
            'leaving_reason' => 'Health reasons.',
        ])->assertOk();

        // Filter by status=left
        $this->get('/manage-student?status=left')
            ->assertOk()
            ->assertSee('Searchable Leftie')
            ->assertSee('GR: GR-9907');

        // Search by name
        $this->get('/manage-student?q=Searchable')
            ->assertOk()
            ->assertSee('Searchable Leftie');
    }

    /** 12. Duplicate submissions do not create inconsistent status records */
    public function test_duplicate_leave_submissions_remain_idempotent_and_consistent(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Idempotent Student', 'gr_number' => 'GR-9908']);

        $payload = [
            'date_of_leaving' => '2026-11-01',
            'leaving_category' => 'Other',
            'leaving_reason' => 'Left school.',
        ];

        // First submission
        $this->postJson("/manage-student/{$setup['student_id']}/leave", $payload)->assertOk();
        $this->assertSame('left', DB::table('students')->where('id', $setup['student_id'])->value('status'));

        // Second duplicate submission
        $this->postJson("/manage-student/{$setup['student_id']}/leave", $payload)->assertOk();
        $this->assertSame('left', DB::table('students')->where('id', $setup['student_id'])->value('status'));

        // Registration details row count remains 1
        $this->assertSame(1, DB::table('student_registration_details')->where('student_id', $setup['student_id'])->count());
    }
}
