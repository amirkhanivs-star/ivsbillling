<?php

namespace Tests\Feature;

use App\Exceptions\DatabaseSafetyException;
use App\Models\User;
use App\Services\Migration\StagingToPortalMigrationService;
use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase3cQaStudentMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function seedFictionalStagingData(): void
    {
        $this->seed();
        $now = now();

        // 1. Staging students
        // Stu 1: Fam A, School, SAR 500, Sibling
        // Stu 2: Fam A, School, SAR 500, Sibling of Stu 1
        // Stu 3: Fam B, School, AED 600, Sibling of Stu 4 (Multi-currency family)
        // Stu 4: Fam B, Tuition, SAR 400, Sibling of Stu 3 (Multi-currency family, Tuition dept)
        // Stu 5: Missing family code, School, Pending Currency (Held)
        DB::table('staging_students')->insert([
            [
                'source_row' => 2,
                'source_key' => 'STU:2',
                'raw_id' => '88001',
                'gr_number' => '88001',
                'family_code' => 'FAM-A',
                'student_name' => 'Alice Test',
                'father_name' => 'Tariq Test',
                'gender' => 'Female',
                'date_of_admission' => '01/08/2023',
                'class_name' => 'Grade 1 - A',
                'class_admitted' => 'Grade 1',
                'monthly_fee_raw' => '500.00',
                'currency_raw' => 'SAR',
                'status_raw' => 'present',
                'guardian_name' => 'Tariq Test',
                'contact_father' => '+966500000001',
                'contact_mother' => null,
                'address' => 'Riyadh KSA',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_row' => 3,
                'source_key' => 'STU:3',
                'raw_id' => '88002',
                'gr_number' => '88002',
                'family_code' => 'FAM-A',
                'student_name' => 'Bob Test',
                'father_name' => 'Tariq Test',
                'gender' => 'Male',
                'date_of_admission' => '01/08/2023',
                'class_name' => 'Grade 3 - A',
                'class_admitted' => 'Grade 1',
                'monthly_fee_raw' => '500.00',
                'currency_raw' => 'SAR',
                'status_raw' => 'present',
                'guardian_name' => 'Tariq Test',
                'contact_father' => '+966500000001',
                'contact_mother' => null,
                'address' => 'Riyadh KSA',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_row' => 4,
                'source_key' => 'STU:4',
                'raw_id' => '88003',
                'gr_number' => '88003',
                'family_code' => 'FAM-B',
                'student_name' => 'Charlie Test',
                'father_name' => 'Farhan Test',
                'gender' => 'Male',
                'date_of_admission' => '15/09/2023',
                'class_name' => 'Grade 5 - B',
                'class_admitted' => 'Grade 5',
                'monthly_fee_raw' => '600.00',
                'currency_raw' => 'AED',
                'status_raw' => 'present',
                'guardian_name' => 'Farhan Test',
                'contact_father' => '+971500000002',
                'contact_mother' => null,
                'address' => 'Dubai UAE',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_row' => 5,
                'source_key' => 'STU:5',
                'raw_id' => '88004',
                'gr_number' => '88004',
                'family_code' => 'FAM-B',
                'student_name' => 'Dana Test',
                'father_name' => 'Farhan Test',
                'gender' => 'Female',
                'date_of_admission' => '15/09/2023',
                'class_name' => 'Tuition Primary',
                'class_admitted' => 'Tuition',
                'monthly_fee_raw' => '400.00',
                'currency_raw' => 'SAR',
                'status_raw' => 'present',
                'guardian_name' => 'Farhan Test',
                'contact_father' => '+971500000002',
                'contact_mother' => null,
                'address' => 'Dubai UAE',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_row' => 6,
                'source_key' => 'STU:6',
                'raw_id' => '88005',
                'gr_number' => '88005',
                'family_code' => null, // Missing family code in source
                'student_name' => 'Evan Test',
                'father_name' => 'Kamran Test',
                'gender' => 'Male',
                'date_of_admission' => '01/10/2023',
                'class_name' => 'Grade 2 - A',
                'class_admitted' => 'Grade 2',
                'monthly_fee_raw' => '350.00',
                'currency_raw' => null,
                'status_raw' => 'present',
                'guardian_name' => 'Kamran Test',
                'contact_father' => '+923000000003',
                'contact_mother' => null,
                'address' => 'Karachi PK',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // 2. Identities
        DB::table('staging_student_identities')->insert([
            [
                'source_key' => 'STU:2',
                'gr_number' => '88001',
                'family_code' => 'FAM-A',
                'provisional_family_code' => null,
                'student_name' => 'Alice Test',
                'is_sibling' => true,
                'sibling_count' => 2,
                'has_multi_currency_siblings' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_key' => 'STU:3',
                'gr_number' => '88002',
                'family_code' => 'FAM-A',
                'provisional_family_code' => null,
                'student_name' => 'Bob Test',
                'is_sibling' => true,
                'sibling_count' => 2,
                'has_multi_currency_siblings' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_key' => 'STU:4',
                'gr_number' => '88003',
                'family_code' => 'FAM-B',
                'provisional_family_code' => null,
                'student_name' => 'Charlie Test',
                'is_sibling' => true,
                'sibling_count' => 2,
                'has_multi_currency_siblings' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_key' => 'STU:5',
                'gr_number' => '88004',
                'family_code' => 'FAM-B',
                'provisional_family_code' => null,
                'student_name' => 'Dana Test',
                'is_sibling' => true,
                'sibling_count' => 2,
                'has_multi_currency_siblings' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_key' => 'STU:6',
                'gr_number' => '88005',
                'family_code' => null,
                'provisional_family_code' => 'PROV-FAM-88005',
                'student_name' => 'Evan Test',
                'is_sibling' => false,
                'sibling_count' => 1,
                'has_multi_currency_siblings' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // 3. Campus assignments (all main)
        foreach (['88001', '88002', '88003', '88004', '88005'] as $gr) {
            DB::table('staging_campus_assignments')->insert([
                'source_key' => 'STU:' . substr($gr, 4),
                'gr_number' => $gr,
                'class_name' => 'General',
                'approved_campus' => 'main',
                'status' => 'APPROVED',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 4. Department assignments (88004 is tuition, others school)
        foreach (['88001', '88002', '88003', '88005'] as $gr) {
            DB::table('staging_department_assignments')->insert([
                'source_key' => 'STU:' . substr($gr, 4),
                'gr_number' => $gr,
                'department_code' => 'school',
                'evidence_source' => 'class_name',
                'status' => 'APPROVED',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('staging_department_assignments')->insert([
            'source_key' => 'STU:5',
            'gr_number' => '88004',
            'department_code' => 'tuition',
            'evidence_source' => 'class_name',
            'status' => 'APPROVED',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 5. Currency reviews
        // 88001: SAR approved
        // 88002: SAR approved
        // 88003: AED approved
        // 88004: SAR approved
        // 88005: PENDING (NO_FINANCIAL_EVIDENCE)
        DB::table('staging_currency_reviews')->insert([
            [
                'gr_number' => '88001',
                'currency_suggested' => 'SAR',
                'currency_approved_manual' => 'SAR',
                'match_status' => 'CONSISTENT_SAR',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'gr_number' => '88002',
                'currency_suggested' => 'SAR',
                'currency_approved_manual' => 'SAR',
                'match_status' => 'CONSISTENT_SAR',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'gr_number' => '88003',
                'currency_suggested' => 'AED',
                'currency_approved_manual' => 'AED',
                'match_status' => 'CONSISTENT_AED',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'gr_number' => '88004',
                'currency_suggested' => 'SAR',
                'currency_approved_manual' => 'SAR',
                'match_status' => 'CONSISTENT_SAR',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'gr_number' => '88005',
                'currency_suggested' => null,
                'currency_approved_manual' => null, // Pending hold
                'match_status' => 'NO_FINANCIAL_EVIDENCE',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function test_student_import_creates_students_and_families_accurately(): void
    {
        $this->seedFictionalStagingData();
        $service = app(StagingToPortalMigrationService::class);
        $conn = config('database.default');

        $stats = $service->migrate($conn, $conn);

        $this->assertEquals(5, $stats['total_students_processed']);
        $this->assertEquals(5, $stats['students_imported']);
        $this->assertEquals(3, $stats['families_count']); // FAM-A, FAM-B, PROV-FAM-88005
        $this->assertEquals(4, $stats['active_enrollments']);
        $this->assertEquals(1, $stats['held_enrollments']);
        $this->assertEquals(4, $stats['school_enrollments']);
        $this->assertEquals(1, $stats['tuition_enrollments']);
        $this->assertEquals(4, $stats['fee_plan_items_created']); // Stu 1, 2, 3, 4

        // Database assertions
        $this->assertDatabaseCount('students', 5);
        $this->assertDatabaseCount('families', 3);
        $this->assertDatabaseCount('enrollments', 5);
        $this->assertDatabaseCount('student_registration_details', 5);
        $this->assertDatabaseCount('fee_plan_items', 4);
    }

    public function test_student_import_is_strictly_idempotent_on_repeated_execution(): void
    {
        $this->seedFictionalStagingData();
        $service = app(StagingToPortalMigrationService::class);
        $conn = config('database.default');

        // First run
        $stats1 = $service->migrate($conn, $conn);
        $this->assertEquals(5, $stats1['students_imported']);
        $this->assertEquals(0, $stats1['students_updated']);

        // Second run
        $stats2 = $service->migrate($conn, $conn);
        $this->assertEquals(0, $stats2['students_imported']);
        $this->assertEquals(5, $stats2['students_updated']);

        // Counts MUST remain completely unchanged
        $this->assertDatabaseCount('students', 5);
        $this->assertDatabaseCount('families', 3);
        $this->assertDatabaseCount('enrollments', 5);
        $this->assertDatabaseCount('fee_plan_items', 4);
        $this->assertDatabaseCount('student_registration_details', 5);
    }

    public function test_missing_family_code_creates_provisional_family_without_overwriting(): void
    {
        $this->seedFictionalStagingData();
        $service = app(StagingToPortalMigrationService::class);
        $conn = config('database.default');

        $service->migrate($conn, $conn);

        $provFamily = DB::table('families')->where('family_code', 'PROV-FAM-88005')->first();
        $this->assertNotNull($provFamily);
        $this->assertTrue((bool) $provFamily->is_provisional);

        $student = DB::table('students')->where('gr_number', '88005')->first();
        $this->assertNotNull($student);
        $this->assertEquals($provFamily->id, $student->family_id);
    }

    public function test_sibling_group_maps_to_single_family(): void
    {
        $this->seedFictionalStagingData();
        $service = app(StagingToPortalMigrationService::class);
        $conn = config('database.default');

        $service->migrate($conn, $conn);

        $famA = DB::table('families')->where('family_code', 'FAM-A')->first();
        $this->assertNotNull($famA);
        $this->assertFalse((bool) $famA->is_provisional);

        $stu1 = DB::table('students')->where('gr_number', '88001')->first();
        $stu2 = DB::table('students')->where('gr_number', '88002')->first();

        $this->assertEquals($famA->id, $stu1->family_id);
        $this->assertEquals($famA->id, $stu2->family_id);
    }

    public function test_mixed_currency_family_maintains_currency_separation(): void
    {
        $this->seedFictionalStagingData();
        $service = app(StagingToPortalMigrationService::class);
        $conn = config('database.default');

        $service->migrate($conn, $conn);

        $famB = DB::table('families')->where('family_code', 'FAM-B')->first();
        $stu3 = DB::table('students')->where('gr_number', '88003')->first();
        $stu4 = DB::table('students')->where('gr_number', '88004')->first();

        // Both belong to FAM-B
        $this->assertEquals($famB->id, $stu3->family_id);
        $this->assertEquals($famB->id, $stu4->family_id);

        // Enrollments are strictly separated by currency
        $en3 = DB::table('enrollments')->where('student_id', $stu3->id)->first();
        $en4 = DB::table('enrollments')->where('student_id', $stu4->id)->first();

        $this->assertEquals('AED', $en3->currency);
        $this->assertEquals('SAR', $en4->currency);
    }

    public function test_school_vs_tuition_department_assignment(): void
    {
        $this->seedFictionalStagingData();
        $service = app(StagingToPortalMigrationService::class);
        $conn = config('database.default');

        $service->migrate($conn, $conn);

        $schoolDeptId = DB::table('departments')->where('code', 'school')->value('id');
        $tuitionDeptId = DB::table('departments')->where('code', 'tuition')->value('id');

        $stu1 = DB::table('students')->where('gr_number', '88001')->first();
        $stu4 = DB::table('students')->where('gr_number', '88004')->first();

        $enSchool = DB::table('enrollments')->where('student_id', $stu1->id)->first();
        $enTuition = DB::table('enrollments')->where('student_id', $stu4->id)->first();

        $this->assertEquals($schoolDeptId, $enSchool->department_id);
        $this->assertEquals($tuitionDeptId, $enTuition->department_id);
    }

    public function test_pending_currency_student_is_held_and_not_billable(): void
    {
        $this->seedFictionalStagingData();
        $service = app(StagingToPortalMigrationService::class);
        $conn = config('database.default');

        $service->migrate($conn, $conn);

        $stu5 = DB::table('students')->where('gr_number', '88005')->first();
        $this->assertEquals('review_hold', $stu5->status);

        $en5 = DB::table('enrollments')->where('student_id', $stu5->id)->first();
        $this->assertNotNull($en5);
        $this->assertEquals('review_hold', $en5->status);
        $this->assertEquals(0, $en5->monthly_fee_minor); // Non-billable: 0 minor units
        $this->assertEquals('HLD', $en5->currency);

        // Fee plan items MUST NOT exist for held student
        $feePlanCount = DB::table('fee_plan_items')->where('enrollment_id', $en5->id)->count();
        $this->assertEquals(0, $feePlanCount);
    }

    public function test_zero_financial_ledger_posting(): void
    {
        $this->seedFictionalStagingData();
        $service = app(StagingToPortalMigrationService::class);
        $conn = config('database.default');

        $service->migrate($conn, $conn);

        // Ledger tables MUST contain strictly 0 rows
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('billing_runs', 0);
    }

    public function test_forbidden_database_rejection_protects_quarantine_and_staging(): void
    {
        // When MySQL driver is targeted, DatabaseSafetyGuard must strictly block quarantine and cross-database pollution
        $this->assertThrows(
            fn() => DatabaseSafetyGuard::assertQaDatabase('quarantine'),
            DatabaseSafetyException::class
        );

        $this->assertThrows(
            fn() => DatabaseSafetyGuard::assertQaDatabase('staging'),
            DatabaseSafetyException::class
        );

        $this->assertThrows(
            fn() => DatabaseSafetyGuard::assertNotQuarantineDatabase('quarantine'),
            DatabaseSafetyException::class
        );

        $this->assertThrows(
            fn() => DatabaseSafetyGuard::assertNotStagingDatabase('staging'),
            DatabaseSafetyException::class
        );
    }

    public function test_staging_approvals_preserved_during_migration(): void
    {
        $this->seedFictionalStagingData();
        $service = app(StagingToPortalMigrationService::class);
        $conn = config('database.default');

        // Check staging counts before
        $stagedCountBefore = DB::table('staging_students')->count();
        $campusCountBefore = DB::table('staging_campus_assignments')->count();
        $deptCountBefore = DB::table('staging_department_assignments')->count();
        $currCountBefore = DB::table('staging_currency_reviews')->count();

        $service->migrate($conn, $conn);

        // Staging tables MUST NOT be modified or deleted
        $this->assertEquals($stagedCountBefore, DB::table('staging_students')->count());
        $this->assertEquals($campusCountBefore, DB::table('staging_campus_assignments')->count());
        $this->assertEquals($deptCountBefore, DB::table('staging_department_assignments')->count());
        $this->assertEquals($currCountBefore, DB::table('staging_currency_reviews')->count());
    }

    public function test_direct_import_myskool_command_is_blocked(): void
    {
        $this->seed();
        $this->artisan('iqra:import-myskool', [
            'file' => 'test.xlsx',
            '--department' => 'school',
            '--currency' => 'SAR',
        ])
        ->expectsOutputToContain('UNSAFE DIRECT IMPORT BLOCKED')
        ->assertExitCode(1);
    }
}
