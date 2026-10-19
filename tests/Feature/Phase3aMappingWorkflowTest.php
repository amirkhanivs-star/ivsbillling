<?php

namespace Tests\Feature;

use App\Exceptions\DatabaseSafetyException;
use App\Models\User;
use App\Services\Staging\DatabaseSafetyGuard;
use App\Services\Staging\StudentIdentityMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase3aMappingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function setupAdmin(): User
    {
        $this->seed();
        $id = DB::table('users')->insertGetId([
            'name' => 'Admin User',
            'email' => 'admin_' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'role' => 'super_admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return User::find($id);
    }

    private function setupCashier(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Cashier User',
            'email' => 'cashier_' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'role' => 'cashier',
            'campus_id' => DB::table('campuses')->first()->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return User::find($id);
    }

    private function seedFictionalStagingData(): void
    {
        $this->seed();
        $now = now();

        // 1. Students:
        // - 2 siblings with Family FAM-101 (both SAR)
        // - 2 siblings with Family FAM-102 (one SAR, one AED -> multi-currency family)
        // - 1 student with missing family code
        DB::table('staging_students')->insert([
            [
                'source_row' => 2,
                'source_key' => 'STU:2',
                'raw_id' => '101',
                'gr_number' => '80001',
                'family_code' => 'FAM-101',
                'student_name' => 'Zayd Ahmad',
                'father_name' => 'Ahmad Khan',
                'status_raw' => 'present',
                'class_name' => 'Grade 1 - A',
                'department_hint' => 'school',
                'staging_status' => 'REVIEW',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_row' => 3,
                'source_key' => 'STU:3',
                'raw_id' => '102',
                'gr_number' => '80002',
                'family_code' => 'FAM-101',
                'student_name' => 'Bilal Ahmad',
                'father_name' => 'Ahmad Khan',
                'status_raw' => 'present',
                'class_name' => 'Grade 3 - A',
                'department_hint' => 'school',
                'staging_status' => 'REVIEW',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_row' => 4,
                'source_key' => 'STU:4',
                'raw_id' => '103',
                'gr_number' => '80003',
                'family_code' => 'FAM-102',
                'student_name' => 'Tariq Mehmood',
                'father_name' => 'Mehmood Ali',
                'status_raw' => 'present',
                'class_name' => 'Grade 5 - A',
                'department_hint' => 'school',
                'staging_status' => 'REVIEW',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_row' => 5,
                'source_key' => 'STU:5',
                'raw_id' => '104',
                'gr_number' => '80004',
                'family_code' => 'FAM-102',
                'student_name' => 'Sara Mehmood',
                'father_name' => 'Mehmood Ali',
                'status_raw' => 'present',
                'class_name' => 'Tuition - A',
                'department_hint' => 'tuition',
                'staging_status' => 'REVIEW',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_row' => 6,
                'source_key' => 'STU:6',
                'raw_id' => '105',
                'gr_number' => '80005',
                'family_code' => null, // Missing family code
                'student_name' => 'Orphan Student',
                'father_name' => null,
                'status_raw' => 'present',
                'class_name' => 'Grade 2 - A',
                'department_hint' => 'school',
                'staging_status' => 'REVIEW',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Currency reviews
        DB::table('staging_currency_reviews')->insert([
            ['gr_number' => '80001', 'student_name' => 'Zayd Ahmad', 'family_code' => 'FAM-101', 'currency_approved_manual' => 'SAR', 'review_status' => 'APPROVED', 'match_status' => 'UNIQUE_CURRENCY', 'created_at' => $now, 'updated_at' => $now],
            ['gr_number' => '80002', 'student_name' => 'Bilal Ahmad', 'family_code' => 'FAM-101', 'currency_approved_manual' => 'SAR', 'review_status' => 'APPROVED', 'match_status' => 'UNIQUE_CURRENCY', 'created_at' => $now, 'updated_at' => $now],
            ['gr_number' => '80003', 'student_name' => 'Tariq Mehmood', 'family_code' => 'FAM-102', 'currency_approved_manual' => 'SAR', 'review_status' => 'APPROVED', 'match_status' => 'UNIQUE_CURRENCY', 'created_at' => $now, 'updated_at' => $now],
            ['gr_number' => '80004', 'student_name' => 'Sara Mehmood', 'family_code' => 'FAM-102', 'currency_approved_manual' => 'AED', 'review_status' => 'APPROVED', 'match_status' => 'UNIQUE_CURRENCY', 'created_at' => $now, 'updated_at' => $now], // Different currency in same family!
            ['gr_number' => '80005', 'student_name' => 'Orphan Student', 'family_code' => null, 'currency_approved_manual' => null, 'review_status' => 'PENDING_REVIEW', 'match_status' => 'NO_FINANCIAL_EVIDENCE', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Dues details (including unknown GRs)
        DB::table('staging_dues')->insert([
            ['source_row' => 2, 'source_key' => 'DUE:2', 'gr_number' => '80001', 'student_name' => 'Zayd Ahmad', 'outstanding_amount' => 200.00, 'currency_iso' => 'SAR', 'period_key' => '2026-10', 'staging_status' => 'STAGED', 'created_at' => $now, 'updated_at' => $now],
            ['source_row' => 3, 'source_key' => 'DUE:3', 'gr_number' => '99991', 'student_name' => 'Zayd Ahmad', 'outstanding_amount' => 150.00, 'currency_iso' => 'SAR', 'period_key' => '2026-09', 'staging_status' => 'STAGED', 'created_at' => $now, 'updated_at' => $now], // Unknown GR, but exact name matches 80001!
            ['source_row' => 4, 'source_key' => 'DUE:4', 'gr_number' => '99992', 'student_name' => 'Completely Unknown Person', 'outstanding_amount' => 300.00, 'currency_iso' => 'AED', 'period_key' => '2026-08', 'staging_status' => 'STAGED', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Payment details (including unknown & unlinked)
        DB::table('staging_payments')->insert([
            ['source_row' => 2, 'source_key' => 'PAY:2', 'gr_number' => '80001', 'student_name' => 'Zayd Ahmad', 'paid_amount' => 200.00, 'currency_iso' => 'SAR', 'slip_number' => 'SLIP-01', 'staging_status' => 'STAGED', 'created_at' => $now, 'updated_at' => $now],
            ['source_row' => 3, 'source_key' => 'PAY:3', 'gr_number' => '99991', 'student_name' => 'Zayd Ahmad', 'paid_amount' => 150.00, 'currency_iso' => 'SAR', 'slip_number' => 'SLIP-02', 'staging_status' => 'STAGED', 'created_at' => $now, 'updated_at' => $now], // Unknown GR, exact name match!
            ['source_row' => 4, 'source_key' => 'PAY:4', 'gr_number' => '', 'student_name' => 'Unlinked Cash Deposit', 'paid_amount' => 500.00, 'currency_iso' => 'SAR', 'slip_number' => 'SLIP-03', 'staging_status' => 'STAGED', 'created_at' => $now, 'updated_at' => $now], // Unlinked (blank GR)
        ]);
    }

    public function test_database_isolation_rejects_quarantine_database_on_phase3a(): void
    {
        $this->assertThrows(
            fn() => DatabaseSafetyGuard::assertStagingDatabase('mysql'),
            DatabaseSafetyException::class
        );

        $this->assertThrows(
            fn() => DatabaseSafetyGuard::assertAllowedMigration('mysql'),
            DatabaseSafetyException::class
        );
    }

    public function test_student_identity_mapping_groups_siblings_and_identifies_missing_family_codes(): void
    {
        $this->seedFictionalStagingData();
        $service = app(StudentIdentityMappingService::class);
        $conn = config('database.default');

        $stats = $service->processStudentIdentities($conn);

        $this->assertEquals(5, $stats['total_students']);
        $this->assertEquals(4, $stats['verified_family_mappings']);
        $this->assertEquals(1, $stats['provisional_family_mappings']);
        $this->assertEquals(2, $stats['sibling_families_count']); // FAM-101 and FAM-102
        $this->assertEquals(4, $stats['sibling_students_count']);
        $this->assertEquals(1, $stats['multi_currency_families_count']); // FAM-102 has SAR and AED

        // Assert student with missing family code
        $orphan = DB::table('staging_student_identities')->where('gr_number', '80005')->first();
        $this->assertNotNull($orphan);
        $this->assertNull($orphan->family_code); // Original family_code NOT overwritten
        $this->assertEquals('PROV-FAM-80005', $orphan->provisional_family_code);
        $this->assertEquals('PROVISIONAL_FAMILY', $orphan->mapping_status);

        // Assert multi-currency flag on FAM-102
        $tariq = DB::table('staging_student_identities')->where('gr_number', '80003')->first();
        $this->assertTrue((bool)$tariq->has_multi_currency_siblings);
        $this->assertTrue((bool)$tariq->is_sibling);
        $this->assertEquals(2, $tariq->sibling_count);

        // Assert FAM-101 has single currency
        $zayd = DB::table('staging_student_identities')->where('gr_number', '80001')->first();
        $this->assertFalse((bool)$zayd->has_multi_currency_siblings);
        $this->assertTrue((bool)$zayd->is_sibling);

        // Assert ZERO records in production tables
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('families', 0);
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_campus_and_department_proposals_never_auto_approve(): void
    {
        $this->seedFictionalStagingData();
        $service = app(StudentIdentityMappingService::class);
        $conn = config('database.default');

        $campusStats = $service->processCampusProposals($conn);
        $this->assertEquals(5, $campusStats['total_proposals']);
        $this->assertEquals(5, $campusStats['pending_approval']);
        $this->assertEquals(0, $campusStats['approved']);

        // Assert all 5 have status PENDING and null approved_campus
        $pendingCampuses = DB::table('staging_campus_assignments')->where('status', 'PENDING')->count();
        $this->assertEquals(5, $pendingCampuses);
        $approvedCampuses = DB::table('staging_campus_assignments')->whereNotNull('approved_campus')->count();
        $this->assertEquals(0, $approvedCampuses);

        // Department proposals
        $deptStats = $service->processDepartmentProposals($conn);
        $this->assertEquals(5, $deptStats['total_proposals']);
        $this->assertEquals(4, $deptStats['school_proposed']);
        $this->assertEquals(1, $deptStats['tuition_proposed']); // STU:5 Tuition - A
        $this->assertEquals(0, $deptStats['approved']);

        // Assert all 5 are PROPOSED and none approved
        $proposedDepts = DB::table('staging_department_assignments')->where('status', 'PROPOSED')->count();
        $this->assertEquals(5, $proposedDepts);
        $approvedDepts = DB::table('staging_department_assignments')->where('status', 'APPROVED')->count();
        $this->assertEquals(0, $approvedDepts);
    }

    public function test_historical_gr_reconciliation_detects_unknown_and_exact_name_matches(): void
    {
        $this->seedFictionalStagingData();
        $service = app(StudentIdentityMappingService::class);
        $conn = config('database.default');

        $reconStats = $service->processHistoricalGrReconciliations($conn);

        // 2 unknown dues (99991 and 99992)
        $this->assertEquals(2, $reconStats['unknown_dues_entries']);
        $this->assertEquals(1, $reconStats['dues_suggested_matches']); // 99991 matches Zayd Ahmad (80001)

        // 1 unknown pay (99991) and 1 unlinked pay (blank GR)
        $this->assertEquals(1, $reconStats['unknown_payment_entries']);
        $this->assertEquals(1, $reconStats['unlinked_payment_entries']);
        $this->assertEquals(1, $reconStats['payment_suggested_matches']);

        // Assert exact name match details
        $dueRecon = DB::table('staging_gr_reconciliations')->where('source_key', 'DUE:3')->first();
        $this->assertNotNull($dueRecon);
        $this->assertEquals('EXACT_NAME', $dueRecon->match_confidence);
        $this->assertEquals('80001', $dueRecon->suggested_match_gr);
        $this->assertEquals('Zayd Ahmad', $dueRecon->suggested_match_name);
        $this->assertEquals('UNRESOLVED', $dueRecon->reconciliation_status); // Remains unresolved!

        // Assert unlinked pay details
        $unlinkedPay = DB::table('staging_gr_reconciliations')->where('source_key', 'PAY:4')->first();
        $this->assertNotNull($unlinkedPay);
        $this->assertEquals('UNLINKED', $unlinkedPay->match_confidence);
        $this->assertNull($unlinkedPay->suggested_match_gr);

        // Assert original source records remain unchanged
        $this->assertDatabaseHas('staging_dues', ['source_key' => 'DUE:3', 'gr_number' => '99991']);
        $this->assertDatabaseHas('staging_payments', ['source_key' => 'PAY:4', 'gr_number' => '']);
    }

    public function test_bulk_campus_and_department_approval_with_audit_logs(): void
    {
        $this->seedFictionalStagingData();
        $service = app(StudentIdentityMappingService::class);
        $conn = config('database.default');

        $service->processCampusProposals($conn);
        $service->processDepartmentProposals($conn);

        // 1. Bulk approve Campus for Grade 1 - A
        $countCampus = $service->approveCampusBulk($conn, ['class_name' => 'Grade 1 - A'], 'main', 'Admin Tester', 'Approved for Main Campus');
        $this->assertEquals(1, $countCampus);

        $approvedRecord = DB::table('staging_campus_assignments')->where('class_name', 'Grade 1 - A')->first();
        $this->assertEquals('APPROVED', $approvedRecord->status);
        $this->assertEquals('main', $approvedRecord->approved_campus);
        $this->assertEquals('Admin Tester', $approvedRecord->approved_by);

        // Assert audit log was recorded
        $this->assertDatabaseHas('staging_approval_logs', [
            'entity_type' => 'campus',
            'action' => 'CAMPUS_BULK_APPROVAL',
            'performed_by' => 'Admin Tester',
        ]);

        // 2. Bulk approve Department for school
        $countDept = $service->approveDepartmentBulk($conn, ['department_code' => 'school'], 'school', 'Admin Tester', 'Approved regular school');
        $this->assertEquals(4, $countDept);

        $this->assertDatabaseHas('staging_approval_logs', [
            'entity_type' => 'department',
            'action' => 'DEPARTMENT_BULK_APPROVAL',
            'performed_by' => 'Admin Tester',
        ]);
    }

    public function test_currency_manual_approval_for_unresolved_student(): void
    {
        $this->seedFictionalStagingData();
        $service = app(StudentIdentityMappingService::class);
        $conn = config('database.default');

        // Student 80005 is pending review
        $pending = DB::table('staging_currency_reviews')->where('gr_number', '80005')->first();
        $this->assertEquals('PENDING_REVIEW', $pending->review_status);
        $this->assertNull($pending->currency_approved_manual);

        // Admin approves PKR
        $ok = $service->approveCurrency($conn, '80005', 'PKR', 'Admin Tester', 'Approved based on parent location');
        $this->assertTrue($ok);

        $approved = DB::table('staging_currency_reviews')->where('gr_number', '80005')->first();
        $this->assertEquals('APPROVED', $approved->review_status);
        $this->assertEquals('PKR', $approved->currency_approved_manual);

        // Assert audit log
        $this->assertDatabaseHas('staging_approval_logs', [
            'entity_type' => 'currency',
            'gr_number' => '80005',
            'action' => 'CURRENCY_MANUAL_APPROVAL',
            'performed_by' => 'Admin Tester',
        ]);
    }

    public function test_repeated_execution_is_strictly_idempotent(): void
    {
        $this->seedFictionalStagingData();
        $service = app(StudentIdentityMappingService::class);
        $conn = config('database.default');

        // First pass
        $service->processStudentIdentities($conn);
        $service->processCampusProposals($conn);
        $service->processDepartmentProposals($conn);
        $service->processHistoricalGrReconciliations($conn);

        $c1 = DB::table('staging_student_identities')->count();
        $c2 = DB::table('staging_campus_assignments')->count();
        $c3 = DB::table('staging_department_assignments')->count();
        $c4 = DB::table('staging_gr_reconciliations')->count();

        $this->assertEquals(5, $c1);
        $this->assertEquals(5, $c2);
        $this->assertEquals(5, $c3);
        $this->assertEquals(4, $c4); // 2 dues + 2 payments

        // Second pass
        $service->processStudentIdentities($conn);
        $service->processCampusProposals($conn);
        $service->processDepartmentProposals($conn);
        $service->processHistoricalGrReconciliations($conn);

        // Assert counts remain identical (0 duplicates)
        $this->assertEquals($c1, DB::table('staging_student_identities')->count());
        $this->assertEquals($c2, DB::table('staging_campus_assignments')->count());
        $this->assertEquals($c3, DB::table('staging_department_assignments')->count());
        $this->assertEquals($c4, DB::table('staging_gr_reconciliations')->count());
    }

    public function test_unauthorized_users_cannot_access_or_approve_mappings(): void
    {
        $admin = $this->setupAdmin();
        $cashier = $this->setupCashier();

        // 1. Unauthenticated requests redirect to login
        $this->get('/migration')->assertRedirect('/login');
        $this->get('/migration/campus')->assertRedirect('/login');
        $this->get('/migration/department')->assertRedirect('/login');
        $this->get('/migration/currency')->assertRedirect('/login');
        $this->get('/migration/gr-reconciliation')->assertRedirect('/login');
        $this->get('/migration/exceptions')->assertRedirect('/login');
        $this->post('/migration/campus/approve')->assertRedirect('/login');

        // 2. Cashier unauthorized requests return 403 Forbidden
        $this->actingAs($cashier)->get('/migration')->assertStatus(403);
        $this->actingAs($cashier)->get('/migration/campus')->assertStatus(403);
        $this->actingAs($cashier)->get('/migration/department')->assertStatus(403);
        $this->actingAs($cashier)->get('/migration/currency')->assertStatus(403);
        $this->actingAs($cashier)->get('/migration/gr-reconciliation')->assertStatus(403);
        $this->actingAs($cashier)->get('/migration/exceptions')->assertStatus(403);
        $this->actingAs($cashier)->post('/migration/campus/approve', [
            'target_campus' => 'main',
            'confirm_approval' => 1,
        ])->assertStatus(403);

        // 3. Super Admin requests succeed
        $this->actingAs($admin)->get('/migration')->assertStatus(200);
        $this->actingAs($admin)->get('/migration/campus')->assertStatus(200);
        $this->actingAs($admin)->get('/migration/department')->assertStatus(200);
        $this->actingAs($admin)->get('/migration/currency')->assertStatus(200);
        $this->actingAs($admin)->get('/migration/gr-reconciliation')->assertStatus(200);
        $this->actingAs($admin)->get('/migration/exceptions')->assertStatus(200);
    }

    public function test_currency_distribution_and_student_level_integrity(): void
    {
        $this->seedFictionalStagingData();

        // Approved students have currency, pending student has null
        $approvedCount = DB::table('staging_currency_reviews')->where('review_status', 'APPROVED')->count();
        $pendingCount = DB::table('staging_currency_reviews')->where('review_status', 'PENDING_REVIEW')->count();

        $this->assertEquals(4, $approvedCount);
        $this->assertEquals(1, $pendingCount);

        // Test distribution
        $currencies = DB::table('staging_currency_reviews')
            ->where('review_status', 'APPROVED')
            ->pluck('currency_approved_manual')
            ->toArray();

        $counts = array_count_values($currencies);
        $this->assertEquals(3, $counts['SAR'] ?? 0);
        $this->assertEquals(1, $counts['AED'] ?? 0);

        // Assert pending student currency cannot change without manual approval
        $pendingStudent = DB::table('staging_currency_reviews')->where('gr_number', '80005')->first();
        $this->assertNull($pendingStudent->currency_approved_manual);
        $this->assertEquals('PENDING_REVIEW', $pendingStudent->review_status);
    }

    public function test_main_campus_bulk_approval_idempotency_and_audit_history(): void
    {
        $this->seedFictionalStagingData();
        $service = app(StudentIdentityMappingService::class);
        $conn = config('database.default');

        // Generate proposals
        $service->processCampusProposals($conn);
        $this->assertEquals(5, DB::table('staging_campus_assignments')->where('status', 'PENDING')->count());

        // First pass approval
        $approved = $service->approveCampusBulk($conn, [], 'main', 'Senior Architect', 'Authorized bulk approval');
        $this->assertEquals(5, $approved);
        $this->assertEquals(0, DB::table('staging_campus_assignments')->where('status', 'PENDING')->count());
        $this->assertEquals(5, DB::table('staging_campus_assignments')->where('status', 'APPROVED')->count());
        $this->assertEquals(5, DB::table('staging_students')->where('campus_approved', 'main')->count());

        // Audit log exists
        $this->assertDatabaseHas('staging_approval_logs', [
            'entity_type' => 'campus',
            'action' => 'CAMPUS_BULK_APPROVAL',
            'performed_by' => 'Senior Architect',
        ]);

        // Second pass approval (strict idempotency)
        $reApproved = $service->approveCampusBulk($conn, [], 'main', 'Senior Architect', 'Repeated run');
        $this->assertEquals(0, $reApproved);
        $this->assertEquals(5, DB::table('staging_campus_assignments')->where('status', 'APPROVED')->count());

        // Campus correction
        $corrected = $service->correctCampusAssignment($conn, '80001', 'main', 'Supervisor', 'Verified assignment');
        $this->assertTrue($corrected);
        $this->assertDatabaseHas('staging_approval_logs', [
            'entity_type' => 'campus',
            'action' => 'CAMPUS_ASSIGNMENT_CORRECTION',
            'gr_number' => '80001',
            'performed_by' => 'Supervisor',
        ]);
    }

    public function test_financial_ledger_isolation_and_source_immutability(): void
    {
        $this->seedFictionalStagingData();

        // 1. Production ledger tables must be completely empty
        $this->assertEquals(0, DB::table('students')->count());
        $this->assertEquals(0, DB::table('invoices')->count());
        $this->assertEquals(0, DB::table('payments')->count());
        $this->assertEquals(0, DB::table('payment_allocations')->count());

        // 2. Historical dues and payments must have valid amounts and currencies
        $duesTotal = DB::table('staging_dues')->sum('outstanding_amount');
        $payTotal = DB::table('staging_payments')->sum('paid_amount');
        $this->assertEquals(650.00, $duesTotal);
        $this->assertEquals(850.00, $payTotal);

        // 3. Database isolation guard blocks quarantine database
        $this->assertThrows(
            fn() => DatabaseSafetyGuard::assertStagingDatabase('mysql'),
            DatabaseSafetyException::class
        );
    }
}
