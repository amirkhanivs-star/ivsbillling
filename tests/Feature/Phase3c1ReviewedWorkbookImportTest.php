<?php

namespace Tests\Feature;

use App\Services\Migration\ReviewedWorkbookImportService;
use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class Phase3c1ReviewedWorkbookImportTest extends TestCase
{
    use RefreshDatabase;

    private ?string $tempWorkbookPath = null;

    protected function tearDown(): void
    {
        if ($this->tempWorkbookPath && file_exists($this->tempWorkbookPath)) {
            @unlink($this->tempWorkbookPath);
        }
        parent::tearDown();
    }

    /**
     * Create a fictional test XLSX workbook with the exact 3 sheets and required columns.
     */
    private function createMockWorkbook(): string
    {
        $spreadsheet = new Spreadsheet();

        // 1. Sheet 1: Registration
        $sheetReg = $spreadsheet->getActiveSheet();
        $sheetReg->setTitle(ReviewedWorkbookImportService::SHEET_REGISTRATION);
        $regHeaders = [
            'ID', 'GR No', 'Family Code', 'Student Name', 'Father Name', 'Gender',
            'Date of Birth', 'Religion', 'Contact (Father)', 'Contact (Mother)',
            'Date of Admission', 'Class', 'Class Admitted', 'Monthly Fee', 'Status',
            'Guardian Name', 'RFID', 'Date of Leaving', 'Leaving Reason', 'Siblings',
            'Siblings Class', 'currency', 'currency_evidence', 'currency_provisional',
            'paid_currencies', 'outstanding_currencies', 'currency_conflict_flags',
            'original_currency', 'proposed_currency', 'currency_audit_notes',
            'currency_approved', 'identity_approved', 'enrollment_status', 'original_row'
        ];
        $sheetReg->fromArray([$regHeaders], null, 'A1');

        // Rows:
        // - Row 2: GR 99001 (Shared, Family FAM-M1, School, SAR 500, Prov: NO)
        // - Row 3: GR 99002 (Shared, Family FAM-M1, School, SAR 500, Prov: NO, Sibling of 99001)
        // - Row 4: GR 99003 (Shared, Family FAM-M2, Tuition, AED 400, Prov: NO)
        // - Row 5: GR 99004 (New, Family FAM-M2, School, SAR 350, Prov: NO, Sibling of 99003 - Mixed Currency Family!)
        // - Row 6: GR 99005 (New, Family FAM-M3, School, SAR 450, Prov: REVIEW -> Hold!)
        // - Row 7: GR 99006 (New, Family '', School, '', Prov: YES -> Blank Currency Hold!)
        $regRows = [
            ['1', '99001', 'FAM-M1', 'Student One', 'Father One', 'Male', '40000', 'ISLAM', '+966500000001', '', '45000', 'Grade 1 - A', 'Grade 1', '500', 'present', 'Father One', '', '', '', '', '', 'SAR', 'Slip SAR', 'NO', 'SAR', 'SAR', '', 'SAR', 'NO', 'manual', '1', '1', 'ACTIVE', '2'],
            ['2', '99002', 'FAM-M1', 'Student Two', 'Father One', 'Female', '40500', 'ISLAM', '+966500000001', '', '45000', 'Grade 2 - A', 'Grade 1', '500', 'present', 'Father One', '', '', '', '', '', 'SAR', 'Slip SAR', 'NO', 'SAR', 'SAR', '', 'SAR', 'NO', 'manual', '1', '1', 'ACTIVE', '3'],
            ['3', '99003', 'FAM-M2', 'Student Three', 'Father Two', 'Male', '41000', 'ISLAM', '+971500000002', '', '45000', 'Tuition Primary', 'Tuition', '400', 'present', 'Father Two', '', '', '', '', '', 'AED', 'Slip AED', 'NO', 'AED', 'AED', '', 'AED', 'NO', 'manual', '1', '1', 'ACTIVE', '4'],
            ['4', '99004', 'FAM-M2', 'Student Four', 'Father Two', 'Female', '41500', 'ISLAM', '+971500000002', '', '45100', 'Grade 4 - A', 'Grade 4', '350', 'present', 'Father Two', '', '', '', '', '', 'SAR', 'Slip SAR', 'NO', 'SAR', 'SAR', '', '', '', '', '1', '1', 'ACTIVE', '5'],
            ['5', '99005', 'FAM-M3', 'Student Five', 'Father Three', 'Male', '42000', 'ISLAM', '+923000000003', '', '45200', 'Grade 5 - A', 'Grade 5', '450', 'present', 'Father Three', '', '', '', '', '', 'SAR', 'No slip', 'REVIEW', '', '', 'CONFLICT', '', '', '', '0', '1', 'HOLD', '6'],
            ['6', '99006', '', 'Student Six', 'Father Four', 'Female', '42500', 'ISLAM', '+923000000004', '', '45300', 'Grade 6 - A', 'Grade 6', '300', 'present', 'Father Four', '', '', '', '', '', '', '', 'YES', '', '', '', '', '', '', '0', '0', 'HOLD', '7'],
        ];
        $sheetReg->fromArray($regRows, null, 'A2');

        // 2. Sheet 2: Collection
        $sheetCol = $spreadsheet->createSheet();
        $sheetCol->setTitle(ReviewedWorkbookImportService::SHEET_COLLECTION);
        $colHeaders = [
            'slip_number', 'GRN', 'student_name', 'registration_name', 'family_code',
            'present_at_22oct', 'fee_particular', 'payment_date', 'payment_date_original',
            'payment_method', 'currency', 'currency_original', 'amount',
            'registration_currency', 'currency_compare', 'source_pdf_page',
            'source_pdf_y', 'source_grn_original', 'source_name_original', 'review_flags'
        ];
        $sheetCol->fromArray([$colHeaders], null, 'A1');

        $colRows = [
            ['SLIP-001', '99001', 'Student One', 'Student One', 'FAM-M1', 'YES', 'Monthly Fee - Sep-2023', '45173', '45173', 'Cash', 'SAR', 'SAR', '500.00', 'SAR', 'MATCH', '1', '100', '99001', 'Student One', ''],
            ['SLIP-002', '99003', 'Student Three', 'Student Three', 'FAM-M2', 'YES', 'Monthly Fee - Sep-2023', '45173', '45173', 'Online', 'AED', 'AED', '400.00', 'AED', 'MATCH', '2', '200', '99003', 'Student Three', ''],
            ['SLIP-003', '99005', 'Student Five', 'Student Five', 'FAM-M3', 'YES', 'Monthly Fee - Sep-2023', '45173', '45173', 'Cash', '', '', '150.00', 'SAR', 'UNRESOLVED', '3', '300', '99005', 'Student Five', 'FLAG_UNRESOLVED_CURRENCY'],
        ];
        $sheetCol->fromArray($colRows, null, 'A2');

        // 3. Sheet 3: Outstanding
        $sheetDue = $spreadsheet->createSheet();
        $sheetDue->setTitle(ReviewedWorkbookImportService::SHEET_OUTSTANDING);
        $dueHeaders = [
            'GRN', 'student_name', 'registration_name', 'family_code_final',
            'family_code_pdf', 'family_code_registration', 'present_at_22oct',
            'class_in_dues_report', 'fee_particular', 'currency', 'currency_original',
            'outstanding_amount', 'registration_currency', 'source_pdf_page',
            'source_pdf_y', 'source_grn_original', 'source_name_original', 'review_flags'
        ];
        $sheetDue->fromArray([$dueHeaders], null, 'A1');

        $dueRows = [
            ['99001', 'Student One', 'Student One', 'FAM-M1', 'FAM-M1', 'FAM-M1', 'YES', 'Grade 1 - A', 'Monthly Fee - Aug-2026', 'SAR', 'SAR', '500.00', 'SAR', '1', '50', '99001', 'Student One', ''],
            ['99004', 'Student Four', 'Student Four', 'FAM-M2', 'FAM-M2', 'FAM-M2', 'YES', 'Grade 4 - A', 'Monthly Fee - Aug-2026', 'SAR', 'SAR', '350.00', 'SAR', '1', '150', '99004', 'Student Four', ''],
            ['99005', 'Student Five', 'Student Five', 'FAM-M3', 'FAM-M3', 'FAM-M3', 'YES', 'Grade 5 - A', 'Monthly Fee - Aug-2026', '', '', '200.00', 'SAR', '2', '80', '99005', 'Student Five', 'FLAG_UNRESOLVED_CURRENCY'],
        ];
        $sheetDue->fromArray($dueRows, null, 'A2');

        $writer = new Xlsx($spreadsheet);
        $tempDir = storage_path('framework/testing');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0777, true);
        }
        $tempPath = $tempDir . DIRECTORY_SEPARATOR . 'test_workbook_' . uniqid() . '.xlsx';
        $writer->save($tempPath);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $this->tempWorkbookPath = $tempPath;
        return $tempPath;
    }

    /**
     * Pre-seed QA baseline with 4 students:
     * - 99001 (shared)
     * - 99002 (shared)
     * - 99003 (shared)
     * - 99099 (ABSENT from new workbook: must be held on historical review!)
     */
    private function seedQaBaseline(): void
    {
        $this->seed();
        $now = now();

        $mainCampusId = DB::table('campuses')->where('code', 'main')->value('id');
        $schoolDeptId = DB::table('departments')->where('code', 'school')->value('id');
        $monthlyFeeId = DB::table('fee_types')->where('code', 'monthly')->value('id');

        // Families
        $fam1Id = DB::table('families')->insertGetId([
            'family_code' => 'FAM-M1',
            'guardian_name' => 'Father One',
            'is_provisional' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $fam2Id = DB::table('families')->insertGetId([
            'family_code' => 'FAM-M2',
            'guardian_name' => 'Father Two',
            'is_provisional' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $famOldId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-OLD',
            'guardian_name' => 'Father Old',
            'is_provisional' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Students
        $stu1Id = DB::table('students')->insertGetId(['gr_number' => '99001', 'name' => 'Student One', 'family_id' => $fam1Id, 'status' => 'present', 'created_at' => $now, 'updated_at' => $now]);
        $stu2Id = DB::table('students')->insertGetId(['gr_number' => '99002', 'name' => 'Student Two', 'family_id' => $fam1Id, 'status' => 'present', 'created_at' => $now, 'updated_at' => $now]);
        $stu3Id = DB::table('students')->insertGetId(['gr_number' => '99003', 'name' => 'Student Three', 'family_id' => $fam2Id, 'status' => 'present', 'created_at' => $now, 'updated_at' => $now]);
        $stuOldId = DB::table('students')->insertGetId(['gr_number' => '99099', 'name' => 'Old Absent Student', 'family_id' => $famOldId, 'status' => 'present', 'created_at' => $now, 'updated_at' => $now]);

        // Enrollments
        $en1 = DB::table('enrollments')->insertGetId(['student_id' => $stu1Id, 'campus_id' => $mainCampusId, 'department_id' => $schoolDeptId, 'grade_label' => 'Grade 1 - A', 'currency' => 'SAR', 'monthly_fee_minor' => 50000, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $en2 = DB::table('enrollments')->insertGetId(['student_id' => $stu2Id, 'campus_id' => $mainCampusId, 'department_id' => $schoolDeptId, 'grade_label' => 'Grade 2 - A', 'currency' => 'SAR', 'monthly_fee_minor' => 50000, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $en3 = DB::table('enrollments')->insertGetId(['student_id' => $stu3Id, 'campus_id' => $mainCampusId, 'department_id' => $schoolDeptId, 'grade_label' => 'Tuition Primary', 'currency' => 'AED', 'monthly_fee_minor' => 40000, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $enOld = DB::table('enrollments')->insertGetId(['student_id' => $stuOldId, 'campus_id' => $mainCampusId, 'department_id' => $schoolDeptId, 'grade_label' => 'Old Class', 'currency' => 'SAR', 'monthly_fee_minor' => 30000, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);

        // Fee plans
        DB::table('fee_plan_items')->insert([
            ['enrollment_id' => $en1, 'fee_type_id' => $monthlyFeeId, 'description' => 'Monthly Fee', 'frequency' => 'monthly', 'currency' => 'SAR', 'amount_minor' => 50000, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['enrollment_id' => $en2, 'fee_type_id' => $monthlyFeeId, 'description' => 'Monthly Fee', 'frequency' => 'monthly', 'currency' => 'SAR', 'amount_minor' => 50000, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['enrollment_id' => $en3, 'fee_type_id' => $monthlyFeeId, 'description' => 'Monthly Fee', 'frequency' => 'monthly', 'currency' => 'AED', 'amount_minor' => 40000, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['enrollment_id' => $enOld, 'fee_type_id' => $monthlyFeeId, 'description' => 'Monthly Fee', 'frequency' => 'monthly', 'currency' => 'SAR', 'amount_minor' => 30000, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function test_workbook_sha256_verification_and_rejection(): void
    {
        $path = $this->createMockWorkbook();
        $service = app(ReviewedWorkbookImportService::class);

        // Expect exception when hash does not match
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SHA-256 mismatch');

        $service->preview($path, 'invalid_expected_hash_00000000000000000000000000000000000000000');
    }

    public function test_reconciliation_logic_with_fictional_records(): void
    {
        $this->seedQaBaseline();
        $path = $this->createMockWorkbook();
        $service = app(ReviewedWorkbookImportService::class);
        $conn = config('database.default');

        // Preview checks
        $preview = $service->preview($path, null, $conn);
        $this->assertEquals(6, $preview['registration']['total_rows']);
        $this->assertEquals(3, $preview['registration']['shared_grs_count']);
        $this->assertEquals(3, $preview['registration']['new_grs_count']);
        $this->assertEquals(1, $preview['registration']['old_only_grs_count']); // 99099
        $this->assertEquals(4, $preview['registration']['approved_count']); // 99001, 99002, 99003, 99004
        $this->assertEquals(2, $preview['registration']['held_count']); // 99005 (REVIEW) + 99006 (Blank)

        // Execute Import
        $stats = $service->import($path, null, $conn, 'TEST-BATCH-001');

        $this->assertEquals(3, $stats['shared_updated']);
        $this->assertEquals(3, $stats['new_created']);
        $this->assertEquals(1, $stats['historical_held']); // 99099
        $this->assertEquals(4, $stats['active_billable']);
        $this->assertEquals(2, $stats['review_holds']);

        // Check Absent Student 99099 is PRESERVED, NOT DELETED, and set to historical_hold
        $absentStu = DB::table('students')->where('gr_number', '99099')->first();
        $this->assertNotNull($absentStu);
        $this->assertEquals('historical_hold', $absentStu->status);

        $absentEn = DB::table('enrollments')->where('student_id', $absentStu->id)->first();
        $this->assertEquals('historical_review', $absentEn->status);

        $absentPlan = DB::table('fee_plan_items')->where('enrollment_id', $absentEn->id)->first();
        $this->assertFalse((bool) $absentPlan->is_active);

        // Check 72/Held student 99005: placed on review_hold with 0 monthly fee
        $heldStu = DB::table('students')->where('gr_number', '99005')->first();
        $this->assertEquals('review_hold', $heldStu->status);
        $heldEn = DB::table('enrollments')->where('student_id', $heldStu->id)->first();
        $this->assertEquals('review_hold', $heldEn->status);
        $this->assertEquals(0, $heldEn->monthly_fee_minor);
        $this->assertEquals(0, DB::table('fee_plan_items')->where('enrollment_id', $heldEn->id)->where('is_active', true)->count());

        // Check total students in table: 3 shared + 3 new + 1 absent = 7 students
        $this->assertEquals(7, DB::table('students')->count());
    }

    public function test_mixed_currency_families_preserved_under_new_workbook(): void
    {
        $this->seedQaBaseline();
        $path = $this->createMockWorkbook();
        $service = app(ReviewedWorkbookImportService::class);
        $conn = config('database.default');

        $service->import($path, null, $conn, 'TEST-BATCH-002');

        // FAM-M2 has 99003 (AED) and 99004 (SAR)
        $fam2 = DB::table('families')->where('family_code', 'FAM-M2')->first();
        $this->assertNotNull($fam2);

        $stu3 = DB::table('students')->where('gr_number', '99003')->first();
        $stu4 = DB::table('students')->where('gr_number', '99004')->first();
        $this->assertEquals($fam2->id, $stu3->family_id);
        $this->assertEquals($fam2->id, $stu4->family_id);

        $en3 = DB::table('enrollments')->where('student_id', $stu3->id)->first();
        $en4 = DB::table('enrollments')->where('student_id', $stu4->id)->first();
        $this->assertEquals('AED', $en3->currency);
        $this->assertEquals('SAR', $en4->currency);
    }

    public function test_idempotency_on_repeated_workbook_import(): void
    {
        $this->seedQaBaseline();
        $path = $this->createMockWorkbook();
        $service = app(ReviewedWorkbookImportService::class);
        $conn = config('database.default');

        // Run 1
        $service->import($path, null, $conn, 'TEST-BATCH-003');
        $studentsAfterRun1 = DB::table('students')->count();
        $collectionsAfterRun1 = DB::table('qa_staged_collections')->count();
        $duesAfterRun1 = DB::table('qa_staged_dues')->count();

        // Run 2
        $service->import($path, null, $conn, 'TEST-BATCH-003');
        $studentsAfterRun2 = DB::table('students')->count();
        $collectionsAfterRun2 = DB::table('qa_staged_collections')->count();
        $duesAfterRun2 = DB::table('qa_staged_dues')->count();

        $this->assertEquals($studentsAfterRun1, $studentsAfterRun2);
        $this->assertEquals($collectionsAfterRun1, $collectionsAfterRun2);
        $this->assertEquals($duesAfterRun1, $duesAfterRun2);
    }

    public function test_staged_financial_worksheets_imported_without_ledger_posting(): void
    {
        $this->seedQaBaseline();
        $path = $this->createMockWorkbook();
        $service = app(ReviewedWorkbookImportService::class);
        $conn = config('database.default');

        $service->import($path, null, $conn, 'TEST-BATCH-004');

        // Staged tables have rows
        $this->assertEquals(3, DB::table('qa_staged_collections')->count());
        $this->assertEquals(3, DB::table('qa_staged_dues')->count());

        // STRICT ZERO LEDGER POSTING ASSERTIONS
        $this->assertEquals(0, DB::table('invoices')->count());
        $this->assertEquals(0, DB::table('invoice_items')->count());
        $this->assertEquals(0, DB::table('payments')->count());
        $this->assertEquals(0, DB::table('payment_allocations')->count());
        $this->assertEquals(0, DB::table('billing_runs')->count());
    }

    public function test_unresolved_currency_rows_are_flagged_and_logged_to_exceptions(): void
    {
        $this->seedQaBaseline();
        $path = $this->createMockWorkbook();
        $service = app(ReviewedWorkbookImportService::class);
        $conn = config('database.default');

        $service->import($path, null, $conn, 'TEST-BATCH-005');

        // Check SLIP-003 was flagged as unresolved
        $colUnres = DB::table('qa_staged_collections')->where('slip_number', 'SLIP-003')->first();
        $this->assertTrue((bool) $colUnres->is_currency_unresolved);

        // Check Outstanding row for 99005 was flagged as unresolved
        $dueUnres = DB::table('qa_staged_dues')->where('gr_number', '99005')->first();
        $this->assertTrue((bool) $dueUnres->is_currency_unresolved);

        // Check exceptions table has logged both
        $exceptions = DB::table('qa_staged_exceptions')->get();
        $this->assertCount(2, $exceptions);
        $this->assertTrue($exceptions->contains('issue_code', 'UNRESOLVED_COLLECTION_CURRENCY'));
        $this->assertTrue($exceptions->contains('issue_code', 'UNRESOLVED_OUTSTANDING_CURRENCY'));
    }

    public function test_quarantine_and_staging_databases_are_strictly_rejected(): void
    {
        $path = $this->createMockWorkbook();
        $service = app(ReviewedWorkbookImportService::class);

        $this->expectException(\App\Exceptions\DatabaseSafetyException::class);
        $this->expectExceptionMessage('CRITICAL DATABASE ISOLATION BREACH');

        $service->import($path, null, 'quarantine');
    }

    public function test_disallowed_legacy_csv_sources_are_strictly_rejected(): void
    {
        $service = app(ReviewedWorkbookImportService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DISALLOWED SOURCE');

        $service->preview('storage/app/imports/students.csv');
    }
}
