<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FeeEligibilityService;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentReadmissionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'super_admin', ?int $campusId = null, ?int $departmentId = null): User
    {
        $this->seed();
        return User::create([
            'name' => 'Admin User',
            'email' => 'admin_' . uniqid() . '@example.test',
            'password' => bcrypt('secret123'),
            'role' => $role,
            'campus_id' => $campusId,
            'department_id' => $departmentId,
            'is_active' => true,
        ]);
    }

    private function createStudentSetup(array $overrides = []): array
    {
        $campusId = DB::table('campuses')->value('id');
        $deptId = DB::table('departments')->where('code', 'school')->value('id')
            ?: DB::table('departments')->value('id');

        $familyId = DB::table('families')->insertGetId([
            'family_code' => $overrides['family_code'] ?? 'FAM-READMIT-' . uniqid(),
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
            'date_of_leaving' => $overrides['date_of_leaving'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('student_registration_details')->insert([
            'student_id' => $studentId,
            'father_name' => 'Father Test',
            'registration_number' => $gr,
            'date_of_leaving' => $overrides['date_of_leaving'] ?? null,
            'leaving_reason' => $overrides['leaving_reason'] ?? null,
            'leaving_category' => $overrides['leaving_category'] ?? null,
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
            'ended_on' => $overrides['date_of_leaving'] ?? null,
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

    /** 1. Left student can be readmitted via endpoint and audit logged, and UI shows button */
    public function test_left_student_can_be_readmitted_and_audit_logged(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Ali Readmit', 'gr_number' => 'GR-READMIT-1']);

        // Mark student as left
        $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Relocation',
            'leaving_reason' => 'Family moved to another district.',
        ])->assertOk();

        // UI renders Readmit button for left student
        $uiResp = $this->get('/manage-student');
        $uiResp->assertOk();
        $uiResp->assertSee('title="Readmit"', false);
        $uiResp->assertSee('btn-readmit-student', false);
        $uiResp->assertSee('id="readmitConfirmModal"', false);

        // Perform readmission
        $resp = $this->postJson("/manage-student/{$setup['student_id']}/readmit", [
            'readmission_date' => '2027-02-10',
        ]);

        $resp->assertOk();
        $resp->assertJson([
            'success' => true,
            'student' => [
                'id' => $setup['student_id'],
                'name' => 'Ali Readmit',
                'gr_number' => 'GR-READMIT-1',
                'status' => 'present',
                'readmission_date' => '2027-02-10',
            ],
        ]);

        // Student status updated to present
        $stu = DB::table('students')->where('id', $setup['student_id'])->first();
        $this->assertSame('present', $stu->status);
        $this->assertSame('2027-02-10', $stu->date_of_readmission);
        $this->assertSame('2026-11-15', $stu->date_of_leaving); // preserved!

        // Registration details updated
        $reg = DB::table('student_registration_details')->where('student_id', $setup['student_id'])->first();
        $this->assertSame('2027-02-10', $reg->readmission_date);
        $this->assertSame('2026-11-15', $reg->date_of_leaving);
        $this->assertSame('Relocation', $reg->leaving_category);
        $this->assertSame('Family moved to another district.', $reg->leaving_reason);

        // Audit log created
        $audit = DB::table('audit_logs')
            ->where('action', 'student_readmitted')
            ->where('subject_id', $setup['student_id'])
            ->first();
        $this->assertNotNull($audit);
        $meta = json_decode($audit->metadata, true);
        $this->assertSame('left', $meta['from']);
        $this->assertSame('present', $meta['to']);
        $this->assertSame('2027-02-10', $meta['readmission_date']);
    }

    /** 2. Present student cannot be readmitted again */
    public function test_present_student_cannot_be_readmitted_again(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Active Student', 'status' => 'present']);

        $resp = $this->postJson("/manage-student/{$setup['student_id']}/readmit");
        $resp->assertStatus(422);
        $resp->assertJson([
            'success' => false,
        ]);
        $this->assertStringContainsString('not marked as Left', $resp->json('message'));
    }

    /** 3 & 4. Student ID, GR number, and Family association remain unchanged */
    public function test_student_id_gr_and_family_association_remain_unchanged(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Identity Preserved', 'gr_number' => 'GR-ID-001']);

        // Mark left
        $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Financial',
            'leaving_reason' => 'Fees issue.',
        ])->assertOk();

        // Readmit
        $this->postJson("/manage-student/{$setup['student_id']}/readmit", [
            'readmission_date' => '2027-02-10',
        ])->assertOk();

        $stu = DB::table('students')->where('id', $setup['student_id'])->first();
        $this->assertSame($setup['student_id'], $stu->id);
        $this->assertSame('GR-ID-001', $stu->gr_number);
        $this->assertSame($setup['family_id'], $stu->family_id);
    }

    /** 5. Appropriate enrollment becomes active without reviving older superseded enrollments */
    public function test_appropriate_enrollment_becomes_active_preserving_superseded(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Multi Enrollment Student', 'gr_number' => 'GR-MULTI-1']);

        // Create an older historical superseded enrollment (e.g., Grade 4 in 2025)
        $oldEnrollmentId = DB::table('enrollments')->insertGetId([
            'student_id' => $setup['student_id'],
            'campus_id' => $setup['campus_id'],
            'department_id' => $setup['department_id'],
            'currency' => 'SAR',
            'monthly_fee_minor' => 45000,
            'grade_label' => 'Grade 4',
            'status' => 'inactive',
            'started_on' => '2025-01-01',
            'ended_on' => '2025-12-31',
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);

        // Student leaves
        $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Other',
            'leaving_reason' => 'Temporary break.',
        ])->assertOk();

        // Both enrollments are now inactive
        $this->assertSame('inactive', DB::table('enrollments')->where('id', $oldEnrollmentId)->value('status'));
        $this->assertSame('inactive', DB::table('enrollments')->where('id', $setup['enrollment_id'])->value('status'));

        // Readmit
        $this->postJson("/manage-student/{$setup['student_id']}/readmit", [
            'readmission_date' => '2027-02-10',
        ])->assertOk();

        // The current appropriate enrollment is active with ended_on = null
        $currEn = DB::table('enrollments')->where('id', $setup['enrollment_id'])->first();
        $this->assertSame('active', $currEn->status);
        $this->assertNull($currEn->ended_on);

        // The older historical enrollment remains inactive!
        $oldEn = DB::table('enrollments')->where('id', $oldEnrollmentId)->first();
        $this->assertSame('inactive', $oldEn->status);
        $this->assertSame('2025-12-31', $oldEn->ended_on);
    }

    /** 6 & 7. Future monthly and bulk fees can be generated after readmission */
    public function test_future_monthly_and_bulk_fees_can_be_generated_after_readmission(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Billable Future', 'gr_number' => 'GR-FUT-1', 'monthly_fee_minor' => 40000]);

        // Mark left on 15 Nov 2026
        $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Medical',
            'leaving_reason' => 'Medical treatment.',
        ])->assertOk();

        // Readmit on 10 Feb 2027
        $this->postJson("/manage-student/{$setup['student_id']}/readmit", [
            'readmission_date' => '2027-02-10',
        ])->assertOk();

        // Run monthly billing for February 2027
        $runIdFeb = DB::table('billing_runs')->insertGetId([
            'campus_id' => $setup['campus_id'],
            'department_id' => $setup['department_id'],
            'frequency' => 'monthly',
            'period_key' => 'monthly:2027-02-01..2027-02-28',
            'period_start' => '2027-02-01',
            'period_end' => '2027-02-28',
            'due_date' => '2027-02-15',
            'fee_type_ids' => json_encode([$setup['fee_type_id']]),
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = app(InvoiceService::class);
        $resFeb = $svc->generate($runIdFeb);
        $this->assertSame(1, $resFeb['created'], 'February invoice must be generated for readmitted student');

        $febItem = DB::table('invoice_items')->where('student_id', $setup['student_id'])->where('period_start', '2027-02-01')->first();
        $this->assertNotNull($febItem);
        $this->assertSame(40000, (int)$febItem->net_minor);

        // Run bulk billing for March 2027
        $runIdMar = DB::table('billing_runs')->insertGetId([
            'campus_id' => $setup['campus_id'],
            'department_id' => $setup['department_id'],
            'frequency' => 'monthly',
            'period_key' => 'monthly:2027-03-01..2027-03-31',
            'period_start' => '2027-03-01',
            'period_end' => '2027-03-31',
            'due_date' => '2027-03-15',
            'fee_type_ids' => json_encode([$setup['fee_type_id']]),
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resMar = $svc->generate($runIdMar);
        $this->assertSame(1, $resMar['created'], 'March invoice must be generated for readmitted student');

        $marItem = DB::table('invoice_items')->where('student_id', $setup['student_id'])->where('period_start', '2027-03-01')->first();
        $this->assertNotNull($marItem);
    }

    /** 8, 9 & 10. Fees are blocked during Left period and missed months are NOT retroactively generated */
    public function test_fees_blocked_during_left_period_and_missed_months_not_generated(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        // Student leaves 15 Nov 2026, readmitted 10 Feb 2027
        $setup = $this->createStudentSetup(['name' => 'Leave Window Student', 'gr_number' => 'GR-WIN-1', 'monthly_fee_minor' => 42000]);

        $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Relocation',
            'leaving_reason' => 'Left temporarily.',
        ])->assertOk();

        // While Left: December 2026 cannot be billed
        $runDec = DB::table('billing_runs')->insertGetId([
            'campus_id' => $setup['campus_id'],
            'department_id' => $setup['department_id'],
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-12-01..2026-12-31',
            'period_start' => '2026-12-01',
            'period_end' => '2026-12-31',
            'due_date' => '2026-12-15',
            'fee_type_ids' => json_encode([$setup['fee_type_id']]),
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = app(InvoiceService::class);
        $svc->generate($runDec);
        $this->assertNull(DB::table('invoice_items')->where('student_id', $setup['student_id'])->where('period_start', '2026-12-01')->first());

        // Readmit on 10 Feb 2027
        $this->postJson("/manage-student/{$setup['student_id']}/readmit", [
            'readmission_date' => '2027-02-10',
        ])->assertOk();

        // Try generating fees for December 2026 and January 2027 AFTER readmission
        $runJan = DB::table('billing_runs')->insertGetId([
            'campus_id' => $setup['campus_id'],
            'department_id' => $setup['department_id'],
            'frequency' => 'monthly',
            'period_key' => 'monthly:2027-01-01..2027-01-31',
            'period_start' => '2027-01-01',
            'period_end' => '2027-01-31',
            'due_date' => '2027-01-15',
            'fee_type_ids' => json_encode([$setup['fee_type_id']]),
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc->generate($runJan);
        $this->assertNull(
            DB::table('invoice_items')->where('student_id', $setup['student_id'])->where('period_start', '2027-01-01')->first(),
            'January fee must NOT be generated retroactively for readmitted student'
        );

        // Check FeeEligibilityService evaluation for December 2026 and January 2027
        $decCheck = FeeEligibilityService::checkStudentBillable($setup['student_id'], '2026-12-01');
        $this->assertFalse($decCheck['eligible']);
        $this->assertStringContainsString('leave', strtolower($decCheck['reason']));

        $janCheck = FeeEligibilityService::checkStudentBillable($setup['student_id'], '2027-01-01');
        $this->assertFalse($janCheck['eligible']);
        $this->assertStringContainsString('leave', strtolower($decCheck['reason']));

        // February 2027 check is eligible
        $febCheck = FeeEligibilityService::checkStudentBillable($setup['student_id'], '2027-02-01');
        $this->assertTrue($febCheck['eligible']);

        // Manual fee creation for January 2027 is blocked
        $this->post('/challan/create-fee', [
            'family_id' => $setup['family_id'],
            'student_ids' => [$setup['student_id']],
            'fee_type_ids' => [$setup['fee_type_id']],
            'months' => [1],
            'year' => 2027,
        ])->assertSessionHasErrors('student_ids');

        // Manual fee creation for February 2027 succeeds
        $this->post('/challan/create-fee', [
            'family_id' => $setup['family_id'],
            'student_ids' => [$setup['student_id']],
            'fee_type_ids' => [$setup['fee_type_id']],
            'months' => [2],
            'year' => 2027,
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(DB::table('invoice_items')->where('student_id', $setup['student_id'])->where('period_start', '2027-02-01')->first());
    }

    /** 11 & 12. Existing invoices and payments remain unchanged and historical dues payable */
    public function test_existing_invoices_and_payments_remain_unchanged_and_dues_payable(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Historical Unpaid', 'monthly_fee_minor' => 30000]);

        // Invoice for October 2026 (before leaving)
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
            'invoice_number' => 'INV-OCT-001',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-15',
            'total_minor' => 30000,
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
            'amount_minor' => 30000,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => 30000,
            'paid_minor' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Student leaves and is then readmitted
        $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Other',
            'leaving_reason' => 'Left.',
        ])->assertOk();

        $this->postJson("/manage-student/{$setup['student_id']}/readmit", [
            'readmission_date' => '2027-02-10',
        ])->assertOk();

        // Dues are preserved: SAR 300.00
        $dues = FeeEligibilityService::getStudentRemainingDues($setup['student_id']);
        $this->assertSame(30000, $dues['total_minor']);

        // Historical invoice item is payable
        $this->post('/challan/receive', [
            'family_id' => $setup['family_id'],
            'campus_id' => $setup['campus_id'],
            'department_id' => $setup['department_id'],
            'currency' => 'SAR',
            'receiving_amount' => '300.00',
            'monthly_amount' => '300.00',
            'selected_item_ids' => [$itemId],
            'receiving_date' => '2027-02-12',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(30000, (int) DB::table('invoice_items')->where('id', $itemId)->value('paid_minor'));
        $this->assertSame(0, FeeEligibilityService::getStudentRemainingDues($setup['student_id'])['total_minor']);
    }

    /** 13 & 14. No duplicate student, enrollment, or fee plans are created */
    public function test_no_duplicate_student_enrollment_or_fee_plans_created(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Single Record Student']);

        $studentCountBefore = DB::table('students')->count();
        $enrollmentCountBefore = DB::table('enrollments')->count();
        $feePlanCountBefore = DB::table('fee_plan_items')->count();

        // Leave
        $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Transfer',
            'leaving_reason' => 'Transfer.',
        ])->assertOk();

        // Readmit
        $this->postJson("/manage-student/{$setup['student_id']}/readmit", [
            'readmission_date' => '2027-02-10',
        ])->assertOk();

        $this->assertSame($studentCountBefore, DB::table('students')->count(), 'No duplicate students');
        $this->assertSame($enrollmentCountBefore, DB::table('enrollments')->count(), 'No duplicate enrollments');
        $this->assertSame($feePlanCountBefore, DB::table('fee_plan_items')->count(), 'No duplicate fee plans');
    }

    /** 15. Repeated Readmit requests are handled safely */
    public function test_repeated_readmit_requests_handled_safely(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Repeated Readmit Student']);

        $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Other',
            'leaving_reason' => 'Reason.',
        ])->assertOk();

        // First readmit
        $this->postJson("/manage-student/{$setup['student_id']}/readmit")->assertOk();

        // Repeated readmit
        $resp = $this->postJson("/manage-student/{$setup['student_id']}/readmit");
        $resp->assertStatus(422);
        $resp->assertJson(['success' => false]);
    }

    /** 16. Unauthorized readmission requests are rejected */
    public function test_unauthorized_readmission_requests_are_rejected(): void
    {
        $superAdmin = $this->admin();
        $this->actingAs($superAdmin);

        $setup = $this->createStudentSetup(['name' => 'Security Student']);

        // Mark as left
        $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Other',
            'leaving_reason' => 'Reason.',
        ])->assertOk();

        // 1. Unauthenticated request
        auth()->logout();
        $this->postJson("/manage-student/{$setup['student_id']}/readmit")->assertStatus(401);

        // 2. Cashier role
        $cashier = $this->admin('cashier');
        $this->actingAs($cashier);
        $this->postJson("/manage-student/{$setup['student_id']}/readmit")->assertStatus(403);

        // 3. Department manager from a different campus/department
        $otherCampusId = DB::table('campuses')->insertGetId([
            'name' => 'Other Campus',
            'code' => 'OC_' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherDeptId = DB::table('departments')->insertGetId([
            'name' => 'Other Dept',
            'code' => 'OD_' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherDeptManager = $this->admin('department_manager', $otherCampusId, $otherDeptId);
        $this->actingAs($otherDeptManager);
        $this->postJson("/manage-student/{$setup['student_id']}/readmit")->assertStatus(403);
    }

    /** 17. Existing Leave functionality continues working after readmission */
    public function test_existing_leave_functionality_continues_working_after_readmission(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Cycle Student']);

        // Leave 1
        $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Other',
            'leaving_reason' => 'First leave.',
        ])->assertOk();

        // Readmit
        $this->postJson("/manage-student/{$setup['student_id']}/readmit", [
            'readmission_date' => '2027-02-10',
        ])->assertOk();

        $this->assertSame('present', DB::table('students')->where('id', $setup['student_id'])->value('status'));

        // Leave 2 (Student leaves again)
        $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2027-05-20',
            'leaving_category' => 'Graduated',
            'leaving_reason' => 'Completed program.',
        ])->assertOk();

        $this->assertSame('left', DB::table('students')->where('id', $setup['student_id'])->value('status'));
        $this->assertSame('2027-05-20', DB::table('students')->where('id', $setup['student_id'])->value('date_of_leaving'));

        // Future billing blocked again
        $check = FeeEligibilityService::checkStudentBillable($setup['student_id'], '2027-06-01');
        $this->assertFalse($check['eligible']);
    }
}
