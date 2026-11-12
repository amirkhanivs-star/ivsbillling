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

class StudentLifecycleHistoryAndMultiCycleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'super_admin', ?int $campusId = null, ?int $departmentId = null): User
    {
        $this->seed();
        return User::create([
            'name' => 'Phase 3 Admin',
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
            'family_code' => $overrides['family_code'] ?? 'FAM-P3-' . uniqid(),
            'guardian_name' => 'Guardian Phase 3',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $gr = $overrides['gr_number'] ?? 'GR-' . rand(1000, 9999);
        $studentId = DB::table('students')->insertGetId([
            'gr_number' => $gr,
            'name' => $overrides['name'] ?? 'Phase 3 Student',
            'family_id' => $familyId,
            'status' => $overrides['status'] ?? 'present',
            'date_of_admission' => '2026-01-01',
            'date_of_leaving' => $overrides['date_of_leaving'] ?? null,
            'date_of_readmission' => $overrides['date_of_readmission'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('student_registration_details')->insert([
            'student_id' => $studentId,
            'father_name' => 'Father Phase 3',
            'registration_number' => $gr,
            'date_of_leaving' => $overrides['date_of_leaving'] ?? null,
            'readmission_date' => $overrides['date_of_readmission'] ?? null,
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

    /**
     * 1. Multiple leave and readmission cycles preserve complete lifecycle history.
     * Transitions: Present -> Left -> Present -> Left -> Present
     */
    public function test_multiple_leave_and_readmission_cycles_preserves_complete_lifecycle_history(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Lifecycle Traveler', 'gr_number' => 'GR-LC-1']);
        $sid = $setup['student_id'];

        // Initial state: Present
        $this->assertSame('present', DB::table('students')->where('id', $sid)->value('status'));

        // Cycle 1: Leave on 15 Nov 2026
        $this->postJson("/manage-student/{$sid}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Medical',
            'leaving_reason' => 'Treatment abroad.',
        ])->assertOk();

        $this->assertSame('left', DB::table('students')->where('id', $sid)->value('status'));

        // Cycle 1: Readmit on 10 Feb 2027
        $this->postJson("/manage-student/{$sid}/readmit", [
            'readmission_date' => '2027-02-10',
        ])->assertOk();

        $this->assertSame('present', DB::table('students')->where('id', $sid)->value('status'));

        // Cycle 2: Leave on 20 April 2027
        $this->postJson("/manage-student/{$sid}/leave", [
            'date_of_leaving' => '2027-04-20',
            'leaving_category' => 'Relocation',
            'leaving_reason' => 'Temporary family relocation.',
        ])->assertOk();

        $this->assertSame('left', DB::table('students')->where('id', $sid)->value('status'));

        // Cycle 2: Readmit on 5 August 2027
        $this->postJson("/manage-student/{$sid}/readmit", [
            'readmission_date' => '2027-08-05',
        ])->assertOk();

        $this->assertSame('present', DB::table('students')->where('id', $sid)->value('status'));

        // Verify student_status_history has exactly 4 chronological entries
        $history = DB::table('student_status_history')
            ->where('student_id', $sid)
            ->orderBy('id', 'asc')
            ->get();

        $this->assertCount(4, $history);

        // Event 1: Left 2026-11-15
        $this->assertSame('left', $history[0]->event_type);
        $this->assertSame('2026-11-15', $history[0]->effective_date);
        $this->assertSame('Medical', $history[0]->leaving_category);
        $this->assertSame('Treatment abroad.', $history[0]->leaving_reason);
        $this->assertSame($user->id, (int)$history[0]->user_id);

        // Event 2: Readmitted 2027-02-10
        $this->assertSame('readmitted', $history[1]->event_type);
        $this->assertSame('2027-02-10', $history[1]->effective_date);
        $this->assertNull($history[1]->leaving_reason);
        $this->assertSame($user->id, (int)$history[1]->user_id);

        // Event 3: Left 2027-04-20
        $this->assertSame('left', $history[2]->event_type);
        $this->assertSame('2027-04-20', $history[2]->effective_date);
        $this->assertSame('Relocation', $history[2]->leaving_category);
        $this->assertSame('Temporary family relocation.', $history[2]->leaving_reason);
        $this->assertSame($user->id, (int)$history[2]->user_id);

        // Event 4: Readmitted 2027-08-05
        $this->assertSame('readmitted', $history[3]->event_type);
        $this->assertSame('2027-08-05', $history[3]->effective_date);
        $this->assertSame($user->id, (int)$history[3]->user_id);

        // Audit logs also preserved
        $logs = DB::table('audit_logs')->where('subject_id', $sid)->whereIn('action', ['student_marked_left', 'student_readmitted'])->get();
        $this->assertCount(4, $logs);
    }

    /**
     * 2. Billing eligibility across multiple cycles matches the exact Step 3 specification table.
     * Timeline:
     * - 1 October 2026: Present
     * - 15 November 2026: Left
     * - 10 February 2027: Readmitted
     * - 20 April 2027: Left again
     * - 5 August 2027: Readmitted again
     *
     * Expected behavior:
     * | Billing period | Expected behavior |
     * | October 2026   | Eligible          |
     * | December 2026  | Blocked           |
     * | January 2027   | Blocked           |
     * | March 2027     | Eligible          |
     * | May 2027       | Blocked           |
     * | June 2027      | Blocked           |
     * | September 2027 | Eligible          |
     */
    public function test_billing_eligibility_across_multiple_cycles_exact_specification_matrix(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Matrix Student', 'gr_number' => 'GR-MAT-1', 'monthly_fee_minor' => 45000]);
        $sid = $setup['student_id'];

        // Perform the timeline transitions
        $this->postJson("/manage-student/{$sid}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Medical',
            'leaving_reason' => 'First break.',
        ])->assertOk();

        $this->postJson("/manage-student/{$sid}/readmit", [
            'readmission_date' => '2027-02-10',
        ])->assertOk();

        $this->postJson("/manage-student/{$sid}/leave", [
            'date_of_leaving' => '2027-04-20',
            'leaving_category' => 'Travel',
            'leaving_reason' => 'Second break.',
        ])->assertOk();

        $this->postJson("/manage-student/{$sid}/readmit", [
            'readmission_date' => '2027-08-05',
        ])->assertOk();

        // 1. October 2026 -> Eligible
        $octCheck = FeeEligibilityService::checkStudentBillable($sid, '2026-10-01');
        $this->assertTrue($octCheck['eligible'], 'October 2026 must be eligible');

        // 2. December 2026 -> Blocked (Cycle 1 absence)
        $decCheck = FeeEligibilityService::checkStudentBillable($sid, '2026-12-01');
        $this->assertFalse($decCheck['eligible'], 'December 2026 must be blocked');
        $this->assertStringContainsString('leave', strtolower($decCheck['reason']));

        // 3. January 2027 -> Blocked (Cycle 1 absence)
        $janCheck = FeeEligibilityService::checkStudentBillable($sid, '2027-01-01');
        $this->assertFalse($janCheck['eligible'], 'January 2027 must be blocked');
        $this->assertStringContainsString('leave', strtolower($janCheck['reason']));

        // 4. March 2027 -> Eligible (Present between Readmit 1 and Leave 2)
        $marCheck = FeeEligibilityService::checkStudentBillable($sid, '2027-03-01');
        $this->assertTrue($marCheck['eligible'], 'March 2027 must be eligible');

        // 5. May 2027 -> Blocked (Cycle 2 absence)
        $mayCheck = FeeEligibilityService::checkStudentBillable($sid, '2027-05-01');
        $this->assertFalse($mayCheck['eligible'], 'May 2027 must be blocked');
        $this->assertStringContainsString('leave', strtolower($mayCheck['reason']));

        // 6. June 2027 -> Blocked (Cycle 2 absence)
        $junCheck = FeeEligibilityService::checkStudentBillable($sid, '2027-06-01');
        $this->assertFalse($junCheck['eligible'], 'June 2027 must be blocked');
        $this->assertStringContainsString('leave', strtolower($junCheck['reason']));

        // 7. September 2027 -> Eligible (Present after Readmit 2)
        $sepCheck = FeeEligibilityService::checkStudentBillable($sid, '2027-09-01');
        $this->assertTrue($sepCheck['eligible'], 'September 2027 must be eligible');

        // Now verify with actual InvoiceService billing runs
        $svc = app(InvoiceService::class);

        // Run December 2026: MUST be skipped
        $runDec = DB::table('billing_runs')->insertGetId([
            'campus_id' => $setup['campus_id'], 'department_id' => $setup['department_id'],
            'frequency' => 'monthly', 'period_key' => 'monthly:2026-12-01..2026-12-31',
            'period_start' => '2026-12-01', 'period_end' => '2026-12-31',
            'due_date' => '2026-12-15', 'fee_type_ids' => json_encode([$setup['fee_type_id']]),
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $resDec = $svc->generate($runDec);
        $this->assertSame(0, $resDec['created']);
        $this->assertNull(DB::table('invoice_items')->where('student_id', $sid)->where('period_start', '2026-12-01')->first());

        // Run January 2027: MUST be skipped
        $runJan = DB::table('billing_runs')->insertGetId([
            'campus_id' => $setup['campus_id'], 'department_id' => $setup['department_id'],
            'frequency' => 'monthly', 'period_key' => 'monthly:2027-01-01..2027-01-31',
            'period_start' => '2027-01-01', 'period_end' => '2027-01-31',
            'due_date' => '2027-01-15', 'fee_type_ids' => json_encode([$setup['fee_type_id']]),
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $resJan = $svc->generate($runJan);
        $this->assertSame(0, $resJan['created']);
        $this->assertNull(DB::table('invoice_items')->where('student_id', $sid)->where('period_start', '2027-01-01')->first());

        // Run March 2027: MUST be generated!
        $runMar = DB::table('billing_runs')->insertGetId([
            'campus_id' => $setup['campus_id'], 'department_id' => $setup['department_id'],
            'frequency' => 'monthly', 'period_key' => 'monthly:2027-03-01..2027-03-31',
            'period_start' => '2027-03-01', 'period_end' => '2027-03-31',
            'due_date' => '2027-03-15', 'fee_type_ids' => json_encode([$setup['fee_type_id']]),
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $resMar = $svc->generate($runMar);
        $this->assertSame(1, $resMar['created']);
        $this->assertNotNull(DB::table('invoice_items')->where('student_id', $sid)->where('period_start', '2027-03-01')->first());

        // Run May 2027: MUST be skipped
        $runMay = DB::table('billing_runs')->insertGetId([
            'campus_id' => $setup['campus_id'], 'department_id' => $setup['department_id'],
            'frequency' => 'monthly', 'period_key' => 'monthly:2027-05-01..2027-05-31',
            'period_start' => '2027-05-01', 'period_end' => '2027-05-31',
            'due_date' => '2027-05-15', 'fee_type_ids' => json_encode([$setup['fee_type_id']]),
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $resMay = $svc->generate($runMay);
        $this->assertSame(0, $resMay['created']);
        $this->assertNull(DB::table('invoice_items')->where('student_id', $sid)->where('period_start', '2027-05-01')->first());

        // Run June 2027: MUST be skipped
        $runJun = DB::table('billing_runs')->insertGetId([
            'campus_id' => $setup['campus_id'], 'department_id' => $setup['department_id'],
            'frequency' => 'monthly', 'period_key' => 'monthly:2027-06-01..2027-06-30',
            'period_start' => '2027-06-01', 'period_end' => '2027-06-30',
            'due_date' => '2027-06-15', 'fee_type_ids' => json_encode([$setup['fee_type_id']]),
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $resJun = $svc->generate($runJun);
        $this->assertSame(0, $resJun['created']);
        $this->assertNull(DB::table('invoice_items')->where('student_id', $sid)->where('period_start', '2027-06-01')->first());

        // Run September 2027: MUST be generated!
        $runSep = DB::table('billing_runs')->insertGetId([
            'campus_id' => $setup['campus_id'], 'department_id' => $setup['department_id'],
            'frequency' => 'monthly', 'period_key' => 'monthly:2027-09-01..2027-09-30',
            'period_start' => '2027-09-01', 'period_end' => '2027-09-30',
            'due_date' => '2027-09-15', 'fee_type_ids' => json_encode([$setup['fee_type_id']]),
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $resSep = $svc->generate($runSep);
        $this->assertSame(1, $resSep['created']);
        $this->assertNotNull(DB::table('invoice_items')->where('student_id', $sid)->where('period_start', '2027-09-01')->first());
    }

    /**
     * 3. Current Left students are always blocked from new fee creation.
     */
    public function test_current_left_student_is_always_blocked_from_new_fee_creation(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Active Leftie', 'gr_number' => 'GR-AL-1']);
        $sid = $setup['student_id'];

        // Mark student left
        $this->postJson("/manage-student/{$sid}/leave", [
            'date_of_leaving' => '2026-11-20',
            'leaving_category' => 'Financial Reasons',
            'leaving_reason' => 'Cannot afford tuition.',
        ])->assertOk();

        // 1. Centralized billable check is false
        $chk = FeeEligibilityService::checkStudentBillable($sid, '2026-12-01');
        $this->assertFalse($chk['eligible']);
        $this->assertSame('left', $chk['status']);

        // 2. Manual fee creation rejected
        $this->post('/challan/create-fee', [
            'family_id' => $setup['family_id'],
            'student_ids' => [$sid],
            'fee_type_ids' => [$setup['fee_type_id']],
            'months' => [12],
            'year' => 2026,
        ])->assertSessionHasErrors('student_ids');

        // 3. Adding fee plan rejected
        $this->post('/manage-fee', [
            'enrollment_id' => $setup['enrollment_id'],
            'fee_type_id' => $setup['fee_type_id'],
            'description' => 'Unintended Plan',
            'amount' => '100.00',
            'frequency' => 'monthly',
        ])->assertSessionHasErrors('enrollment_id');

        // 4. Preview challan fee reports blocked
        $previewResp = $this->postJson('/challan/preview-fee', [
            'family_id' => $setup['family_id'],
            'student_ids' => [$sid],
            'fee_type_ids' => [$setup['fee_type_id']],
            'months' => [12],
            'year' => 2026,
        ]);
        $previewResp->assertOk();
        $items = $previewResp->json('items');
        $this->assertNotEmpty($items);
        $this->assertFalse($items[0]['is_eligible']);
        $this->assertSame('student_left', $items[0]['status_code']);
    }

    /**
     * 4. Enrollment history and superseded enrollments remain intact across multiple cycles.
     */
    public function test_enrollment_history_and_superseded_enrollments_preserved_across_multiple_cycles(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Enrollment Guard', 'gr_number' => 'GR-EG-1']);
        $sid = $setup['student_id'];

        // Create an older superseded enrollment (Grade 4 in 2025)
        $oldEnId = DB::table('enrollments')->insertGetId([
            'student_id' => $sid,
            'campus_id' => $setup['campus_id'],
            'department_id' => $setup['department_id'],
            'currency' => 'SAR',
            'monthly_fee_minor' => 35000,
            'grade_label' => 'Grade 4',
            'status' => 'inactive',
            'started_on' => '2025-01-01',
            'ended_on' => '2025-12-31',
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);

        // Cycle 1: Leave & Readmit
        $this->postJson("/manage-student/{$sid}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Other',
            'leaving_reason' => 'Cycle 1 leave',
        ])->assertOk();

        $this->postJson("/manage-student/{$sid}/readmit", [
            'readmission_date' => '2027-02-10',
        ])->assertOk();

        // Cycle 2: Leave & Readmit
        $this->postJson("/manage-student/{$sid}/leave", [
            'date_of_leaving' => '2027-04-20',
            'leaving_category' => 'Other',
            'leaving_reason' => 'Cycle 2 leave',
        ])->assertOk();

        $this->postJson("/manage-student/{$sid}/readmit", [
            'readmission_date' => '2027-08-05',
        ])->assertOk();

        // Total enrollments must still be exactly 2
        $this->assertSame(2, DB::table('enrollments')->where('student_id', $sid)->count());

        // Older historical enrollment was NOT reactivated and retains its historical dates
        $oldEn = DB::table('enrollments')->where('id', $oldEnId)->first();
        $this->assertSame('inactive', $oldEn->status);
        $this->assertSame('2025-12-31', $oldEn->ended_on);
        $this->assertSame('Grade 4', $oldEn->grade_label);

        // Current enrollment is active with ended_on = null
        $currEn = DB::table('enrollments')->where('id', $setup['enrollment_id'])->first();
        $this->assertSame('active', $currEn->status);
        $this->assertNull($currEn->ended_on);
        $this->assertSame('Grade 5', $currEn->grade_label);
    }

    /**
     * 5. Financial integrity and outstanding dues are preserved throughout all cycles.
     */
    public function test_financial_integrity_and_outstanding_dues_preserved_throughout_all_cycles(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Ledger Integrity', 'gr_number' => 'GR-LI-1']);
        $sid = $setup['student_id'];

        // Create an existing unpaid invoice for October 2026
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

        $invId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $setup['family_id'],
            'campus_id' => $setup['campus_id'],
            'department_id' => $setup['department_id'],
            'invoice_number' => 'INV-2026-TEST1',
            'currency' => 'SAR',
            'status' => 'issued',
            'total_minor' => 50000,
            'paid_minor' => 20000, // partially paid SAR 200, SAR 300 due
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $sid,
            'enrollment_id' => $setup['enrollment_id'],
            'fee_type_id' => $setup['fee_type_id'],
            'currency' => 'SAR',
            'description' => 'October Tuition',
            'amount_minor' => 50000,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => 50000,
            'paid_minor' => 20000,
            'status' => 'partial',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Dues prior to leave: SAR 300.00
        $initialDues = FeeEligibilityService::getStudentRemainingDues($sid);
        $this->assertSame(30000, $initialDues['total_minor']);

        // Cycle 1: Leave & Readmit
        $this->postJson("/manage-student/{$sid}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Other',
            'leaving_reason' => 'Cycle 1',
        ])->assertOk();

        // While Left: Dues remain SAR 300.00
        $duringLeave1Dues = FeeEligibilityService::getStudentRemainingDues($sid);
        $this->assertSame(30000, $duringLeave1Dues['total_minor']);

        $this->postJson("/manage-student/{$sid}/readmit", [
            'readmission_date' => '2027-02-10',
        ])->assertOk();

        // After Readmit 1: Dues remain SAR 300.00
        $afterReadmit1Dues = FeeEligibilityService::getStudentRemainingDues($sid);
        $this->assertSame(30000, $afterReadmit1Dues['total_minor']);

        // Cycle 2: Leave & Readmit
        $this->postJson("/manage-student/{$sid}/leave", [
            'date_of_leaving' => '2027-04-20',
            'leaving_category' => 'Other',
            'leaving_reason' => 'Cycle 2',
        ])->assertOk();

        // While Left 2: Dues remain SAR 300.00
        $duringLeave2Dues = FeeEligibilityService::getStudentRemainingDues($sid);
        $this->assertSame(30000, $duringLeave2Dues['total_minor']);

        $this->postJson("/manage-student/{$sid}/readmit", [
            'readmission_date' => '2027-08-05',
        ])->assertOk();

        // After Readmit 2: Dues remain SAR 300.00
        $afterReadmit2Dues = FeeEligibilityService::getStudentRemainingDues($sid);
        $this->assertSame(30000, $afterReadmit2Dues['total_minor']);

        // Verify invoice and item amounts were completely untouched
        $inv = DB::table('invoices')->where('id', $invId)->first();
        $this->assertSame(50000, (int)$inv->total_minor);
        $this->assertSame(20000, (int)$inv->paid_minor);

        $item = DB::table('invoice_items')->where('id', $itemId)->first();
        $this->assertSame(50000, (int)$item->net_minor);
        $this->assertSame(20000, (int)$item->paid_minor);
    }

    /**
     * 6. Duplicate leave submissions and idempotency across lifecycle history.
     */
    public function test_duplicate_submissions_and_idempotency_across_history(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Idempotent Multi', 'gr_number' => 'GR-IDM-1']);
        $sid = $setup['student_id'];

        $payload = [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Medical',
            'leaving_reason' => 'Initial medical break.',
        ];

        // First leave submission
        $this->postJson("/manage-student/{$sid}/leave", $payload)->assertOk();

        // Duplicate submission with updated reason
        $updatedPayload = [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Medical',
            'leaving_reason' => 'Updated medical break description.',
        ];
        $this->postJson("/manage-student/{$sid}/leave", $updatedPayload)->assertOk();

        // Verify history has only 1 record for this leave interval, with updated reason
        $history = DB::table('student_status_history')->where('student_id', $sid)->get();
        $this->assertCount(1, $history);
        $this->assertSame('Updated medical break description.', $history->first()->leaving_reason);

        // Readmit
        $this->postJson("/manage-student/{$sid}/readmit", [
            'readmission_date' => '2027-02-10',
        ])->assertOk();

        // Repeated readmit on already present student is rejected
        $this->postJson("/manage-student/{$sid}/readmit", [
            'readmission_date' => '2027-02-15',
        ])->assertStatus(422);

        // History count remains 2 (1 left, 1 readmitted)
        $this->assertSame(2, DB::table('student_status_history')->where('student_id', $sid)->count());
    }

    /**
     * 7. Unauthorized leave and readmission attempts are rejected.
     */
    public function test_unauthorized_leave_and_readmission_attempts_are_rejected(): void
    {
        $superAdmin = $this->admin('super_admin');
        $this->actingAs($superAdmin);

        $campuses = DB::table('campuses')->pluck('id');
        $campusA = $campuses[0];
        $campusB = $campuses[1] ?? DB::table('campuses')->insertGetId(['name' => 'Campus B', 'code' => 'CB', 'created_at' => now(), 'updated_at' => now()]);

        $setup = $this->createStudentSetup(['name' => 'Campus A Student', 'gr_number' => 'GR-CA-1']);
        DB::table('enrollments')->where('id', $setup['enrollment_id'])->update(['campus_id' => $campusA]);

        // 1. Auditor cannot mark left or readmit
        $auditor = $this->admin('auditor');
        $this->actingAs($auditor);

        $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Other',
            'leaving_reason' => 'Auditor attempt',
        ])->assertStatus(403);

        $this->postJson("/manage-student/{$setup['student_id']}/readmit", [
            'readmission_date' => '2027-02-10',
        ])->assertStatus(403);

        // 2. Admin restricted to Campus B cannot mark Campus A student left
        $deptAdminCampusB = $this->admin('school_admin', $campusB);
        $this->actingAs($deptAdminCampusB);

        $this->postJson("/manage-student/{$setup['student_id']}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Other',
            'leaving_reason' => 'Wrong campus attempt',
        ])->assertStatus(403);
    }

    /**
     * 8. Other students in the family/campus remain completely unaffected.
     */
    public function test_other_students_remain_unaffected_when_student_leaves_and_is_readmitted(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        // Student A leaves and re-enters
        $studentA = $this->createStudentSetup(['name' => 'Student Alpha', 'gr_number' => 'GR-ALPH-1', 'monthly_fee_minor' => 40000]);

        // Student B is a sibling in the same family and remains present throughout
        $studentBId = DB::table('students')->insertGetId([
            'gr_number' => 'GR-BETA-2',
            'name' => 'Student Beta',
            'family_id' => $studentA['family_id'],
            'status' => 'present',
            'date_of_admission' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $studentBEnrollmentId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentBId,
            'campus_id' => $studentA['campus_id'],
            'department_id' => $studentA['department_id'],
            'currency' => 'SAR',
            'monthly_fee_minor' => 30000,
            'grade_label' => 'Grade 3',
            'status' => 'active',
            'started_on' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('fee_plan_items')->insertGetId([
            'enrollment_id' => $studentBEnrollmentId,
            'fee_type_id' => $studentA['fee_type_id'],
            'description' => 'Monthly Fee',
            'amount_minor' => 30000,
            'currency' => 'SAR',
            'frequency' => 'monthly',
            'is_active' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Student A leaves on 15 Nov 2026
        $this->postJson("/manage-student/{$studentA['student_id']}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Medical',
            'leaving_reason' => 'Alpha leaves.',
        ])->assertOk();

        // Student B is still present and active
        $this->assertSame('present', DB::table('students')->where('id', $studentBId)->value('status'));
        $this->assertSame('active', DB::table('enrollments')->where('id', $studentBEnrollmentId)->value('status'));

        // Run monthly billing for December 2026
        $runDec = DB::table('billing_runs')->insertGetId([
            'campus_id' => $studentA['campus_id'], 'department_id' => $studentA['department_id'],
            'frequency' => 'monthly', 'period_key' => 'monthly:2026-12-01..2026-12-31',
            'period_start' => '2026-12-01', 'period_end' => '2026-12-31',
            'due_date' => '2026-12-15', 'fee_type_ids' => json_encode([$studentA['fee_type_id']]),
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $svc = app(InvoiceService::class);
        $resDec = $svc->generate($runDec);
        $this->assertSame(1, $resDec['created'], 'Student B must receive invoice');

        // Assert Student B has an item, Student A does NOT
        $this->assertNull(DB::table('invoice_items')->where('student_id', $studentA['student_id'])->where('period_start', '2026-12-01')->first());
        $this->assertNotNull(DB::table('invoice_items')->where('student_id', $studentBId)->where('period_start', '2026-12-01')->first());
    }

    /**
     * 9. Leave drawer info endpoint returns rich lifecycle history and correct dues.
     */
    public function test_leave_drawer_info_endpoint_returns_complete_history_and_correct_dues(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Drawer History Student', 'gr_number' => 'GR-DHS-1']);
        $sid = $setup['student_id'];

        // Perform Leave and Readmission
        $this->postJson("/manage-student/{$sid}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Medical',
            'leaving_reason' => 'First break.',
        ])->assertOk();

        $this->postJson("/manage-student/{$sid}/readmit", [
            'readmission_date' => '2027-02-10',
        ])->assertOk();

        // Fetch leave info
        $resp = $this->getJson("/manage-student/{$sid}/leave-info");
        $resp->assertOk();
        $data = $resp->json();

        $this->assertTrue($data['success']);
        $this->assertSame($sid, $data['student_id']);
        $this->assertSame('present', $data['status']);
        $this->assertArrayHasKey('history', $data);
        $this->assertCount(2, $data['history']);

        // Most recent event first: Readmitted
        $this->assertSame('readmitted', $data['history'][0]['event_type']);
        $this->assertSame('2027-02-10', $data['history'][0]['effective_date']);

        // Prior event: Left
        $this->assertSame('left', $data['history'][1]['event_type']);
        $this->assertSame('2026-11-15', $data['history'][1]['effective_date']);
        $this->assertSame('Medical', $data['history'][1]['leaving_category']);
    }

    /**
     * 10. Student search and filters work seamlessly across all lifecycle states.
     */
    public function test_student_search_and_filters_work_seamlessly_across_status_changes(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $setup = $this->createStudentSetup(['name' => 'Filterable Student', 'gr_number' => 'GR-FIL-1']);
        $sid = $setup['student_id'];

        // 1. Present: appears in present filter
        $this->get('/manage-student?status=present')
            ->assertOk()
            ->assertSee('Filterable Student');

        // 2. Mark Left
        $this->postJson("/manage-student/{$sid}/leave", [
            'date_of_leaving' => '2026-11-15',
            'leaving_category' => 'Other',
            'leaving_reason' => 'Testing filter',
        ])->assertOk();

        // Appears in left filter
        $this->get('/manage-student?status=left')
            ->assertOk()
            ->assertSee('Filterable Student');

        // Search by GR number works
        $this->get('/manage-student?q=GR-FIL-1')
            ->assertOk()
            ->assertSee('Filterable Student');

        // 3. Readmit: reappears in present filter
        $this->postJson("/manage-student/{$sid}/readmit", [
            'readmission_date' => '2027-02-10',
        ])->assertOk();

        $this->get('/manage-student?status=present')
            ->assertOk()
            ->assertSee('Filterable Student');
    }

    /**
     * 11. Migration 000028 accurately reconstructs effective dates from audit_logs without overwriting them.
     */
    public function test_status_history_migration_reconstructs_audit_log_effective_dates_accurately(): void
    {
        $user = $this->admin();
        $setup = $this->createStudentSetup([
            'name' => 'Legacy Reconstruct Student',
            'gr_number' => 'GR-LEG-1',
            'status' => 'present',
            'date_of_leaving' => '2026-09-10',
            'date_of_readmission' => '2026-11-01',
        ]);
        $sid = $setup['student_id'];

        DB::table('audit_logs')->insert([
            [
                'actor_id' => $user->id,
                'action' => 'student_marked_left',
                'subject_type' => 'student',
                'subject_id' => $sid,
                'metadata' => json_encode([
                    'date_of_leaving' => '2026-09-10',
                    'leaving_category' => 'Relocation',
                    'leaving_reason' => 'Moved temporarily.',
                ]),
                'created_at' => '2026-11-12 08:00:00',
                'updated_at' => '2026-11-12 08:00:00',
            ],
            [
                'actor_id' => null, // deleted user (nullOnDelete)
                'action' => 'student_readmitted',
                'subject_type' => 'student',
                'subject_id' => $sid,
                'metadata' => json_encode([
                    'readmission_date' => '2026-11-01',
                ]),
                'created_at' => '2026-11-12 09:00:00',
                'updated_at' => '2026-11-12 09:00:00',
            ],
        ]);

        // Re-run migration 000028 down() + up() on isolated test DB
        $migration = include base_path('database/migrations/2026_11_12_000028_create_student_status_history_table.php');
        $migration->down();
        $migration->up();

        $events = DB::table('student_status_history')
            ->where('student_id', $sid)
            ->orderBy('id', 'asc')
            ->get();

        $this->assertCount(2, $events);
        $this->assertSame('left', $events[0]->event_type);
        $this->assertSame('2026-09-10', $events[0]->effective_date);
        $this->assertSame('Relocation', $events[0]->leaving_category);
        $this->assertSame($user->id, (int) $events[0]->actor_id);

        $this->assertSame('readmitted', $events[1]->event_type);
        $this->assertSame('2026-11-01', $events[1]->effective_date);
        $this->assertNull($events[1]->actor_id);
    }
}
