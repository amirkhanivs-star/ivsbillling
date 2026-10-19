<?php

namespace Tests\Feature;

use App\Exceptions\DatabaseSafetyException;
use App\Services\Staging\DatabaseSafetyGuard;
use App\Services\Staging\StagingImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

class StagingImportPipelineTest extends TestCase
{
    use RefreshDatabase;

    private string $tempZipPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempZipPath = storage_path('framework/testing/test_staging_' . uniqid() . '.zip');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempZipPath)) {
            @unlink($this->tempZipPath);
        }
        parent::tearDown();
    }

    /**
     * Helper to generate a valid, realistic mock staging ZIP archive with fictional data.
     */
    private function createMockStagingZip(array $options = []): string
    {
        $dirPath = dirname($this->tempZipPath);
        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0777, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($this->tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create test zip: {$this->tempZipPath}");
        }

        $dir = 'TEST_STAGING_BATCH/';

        // 1. STAGING_summary.csv
        $summary = "Metric,Value\n";
        $summary .= "Staging phase,complete\n";
        $summary .= "All source batches verified,true\n";
        $summary .= "students expected count,3\n";
        $summary .= "dues expected count,3\n";
        $summary .= "paid expected count,3\n";
        $zip->addFromString($dir . 'STAGING_summary.csv', $summary);

        // 2. STAGING_batches.csv
        $batches = "category,source_sheet,start_row,end_row,source_batch_sha256,detail_rows_staged,issue_findings,non_detail_rows,verification_status,verified_at\n";
        $batches .= "students,Student Information,1,100,hash-students-01,3,0,1,MATCH,2026-10-19 10:00:00\n";
        $batches .= "dues,Dues Details,1,100,hash-dues-01,3,0,1,MATCH,2026-10-19 10:00:00\n";
        $zip->addFromString($dir . 'STAGING_batches.csv', $batches);

        // 3. STAGING_currency_review.csv
        $curr = "GR No,Student Name,Family Code,currency_suggested,currency_approved_MANUAL,match_status,paid_detail_lines,dues_detail_lines,recognized_currency_counts,unrecognized_source_currency_labels,action_needed\n";
        $curr .= "90001,Fictional Student 1,FAM-901,SAR,SAR,UNIQUE_CURRENCY,2,1,SAR: 3,,Approved\n";
        $curr .= "90002,Fictional Student 2,FAM-902,AED,AED,UNIQUE_CURRENCY,1,1,AED: 2,,Approved\n";
        $curr .= "90003,Fictional Student 3,FAM-903,PKR,,NO_FINANCIAL_EVIDENCE,0,0,,,Requires review\n";
        $zip->addFromString($dir . 'STAGING_currency_review.csv', $curr);

        // 4. STAGING_students.csv
        $students = "ID,GR No,Family Code,Student Name,Father Name,Gender,Date of Birth,Religion,Contact (Father),Contact (Mother),Address,Date of Admission,Class,Class Admitted,Monthly Fee,Currency,Status,Guardian Name,RFID,Date of Leaving,Leaving Reason,Siblings,Siblings Class,source_row,source_key,department_hint,department_approved,campus_approved,currency_approved,review_issues,staging_status,raw_row_json,currency_suggested\n";
        $students .= "1,90001,FAM-901,Fictional Student 1,Father One,Male,2015-01-01,ISLAM,+966500000001,,Riyadh,2023-08-10,Grade 1,Grade 1,200,SAR,present,Guardian One,,,,,,2,STU:2,school,,,SAR,,REVIEW,\"{\"\"id\"\":1}\",SAR\n";
        $students .= "2,90002,FAM-902,Fictional Student 2,Father Two,Female,2016-02-02,ISLAM,+971500000002,,Dubai,2023-08-10,Grade 2,Grade 2,250,AED,present,Guardian Two,,,,,,3,STU:3,school,,,AED,,REVIEW,\"{\"\"id\"\":2}\",AED\n";
        $students .= "3,90003,FAM-903,Fictional Student 3,Father Three,Male,2014-03-03,ISLAM,+923000000003,,Karachi,2023-08-10,Grade 3,Grade 3,300,,present,Guardian Three,,,,,,4,STU:4,tuition,,,,,REVIEW,\"{\"\"id\"\":3}\",PKR\n";
        $zip->addFromString($dir . 'STAGING_students.csv', $students);

        // 5. STAGING_dues.csv
        $dues = "source_row,source_key,report_class,gr_number,student_name,contact_raw,particular_raw,currency_raw,currency_iso,outstanding_amount,period_key,fee_description,department_hint,department_approved,campus_approved,family_code_approved,review_issues,staging_status,raw_row_json\n";
        $dues .= "2,DUE:2,Grade 1,90001,Fictional Student 1,+966500000001,Monthly Fee - Oct 2026,SAR,SAR,200.00,2026-10,Monthly Fee,school,,,,STAGED,\"{\"\"row\"\":2}\"\n";
        $dues .= "3,DUE:3,Grade 2,90002,Fictional Student 2,+971500000002,Monthly Fee - Oct 2026,AED,AED,250.00,2026-10,Monthly Fee,school,,,,STAGED,\"{\"\"row\"\":3}\"\n";
        $dues .= "4,DUE:4,Grade 3,90003,Fictional Student 3,+923000000003,Tuition Fee,INVALID_CURR,,150.00,,Tuition Fee,tuition,,,,STAGED,\"{\"\"row\"\":4}\"\n";
        $zip->addFromString($dir . 'STAGING_dues.csv', $dues);

        // 6. STAGING_paid.csv
        $paid = "source_row,source_key,payment_method,slip_number,gr_number,student_name,particular_raw,payment_date_raw,payment_date_iso,currency_raw,currency_iso,paid_amount,period_key,fee_description,department_hint,department_approved,campus_approved,family_code_approved,review_issues,staging_status,raw_row_json\n";
        $paid .= "2,PAY:2,Cash,SLIP-001,90001,Fictional Student 1,Monthly Fee,10-Oct-2026,2026-10-10,SAR,SAR,200.00,2026-10,Monthly Fee,school,,,,STAGED,\"{\"\"row\"\":2}\"\n";
        $paid .= "3,PAY:3,Online,SLIP-002,90002,Fictional Student 2,Monthly Fee,invalid-date,,AED,AED,250.00,2026-10,Monthly Fee,school,,,,STAGED,\"{\"\"row\"\":3}\"\n";
        $paid .= "4,PAY:4,Cash,SLIP-003,90003,Fictional Student 3,Tuition Fee,12-Oct-2026,2026-10-12,PKR,PKR,300.00,2026-10,Tuition Fee,tuition,,,,STAGED,\"{\"\"row\"\":4}\"\n";
        $zip->addFromString($dir . 'STAGING_paid.csv', $paid);

        // 7. STAGING_exceptions.csv
        $exceptions = "source_sheet,source_row,source_key,gr_number,issue_code,raw_row_json\n";
        $exceptions .= "Student Information,4,STU:4,90003,MISSING_CURRENCY_APPROVAL,\"{\"\"gr\"\":90003}\"\n";
        $exceptions .= "Paid Details,3,PAY:3,90002,MALFORMED_PAYMENT_DATE,\"{\"\"gr\"\":90002}\"\n";
        $zip->addFromString($dir . 'STAGING_exceptions.csv', $exceptions);

        $zip->close();
        return $this->tempZipPath;
    }

    public function test_database_isolation_rejects_quarantine_database_connection(): void
    {
        // When MySQL driver is active with iqra_billing, DatabaseSafetyGuard MUST throw
        $this->assertThrows(
            fn() => DatabaseSafetyGuard::assertStagingDatabase('mysql'),
            DatabaseSafetyException::class
        );

        $this->assertThrows(
            fn() => DatabaseSafetyGuard::assertAllowedMigration('mysql'),
            DatabaseSafetyException::class
        );
    }

    public function test_staging_import_with_fictional_data_is_lossless_and_accurate(): void
    {
        $zipPath = $this->createMockStagingZip();
        $service = app(StagingImportService::class);
        $zip = $service->openZip($zipPath);
        $fileMap = $service->getZipFileMap($zip);

        $conn = config('database.default'); // sqlite in-memory for testing

        // 1. Ingest Batches
        $bRes = $service->importBatches($zip, $fileMap['batches'], $conn);
        $this->assertEquals(2, $bRes['staged']);
        $this->assertDatabaseCount('staging_batches', 2);

        // 2. Ingest Currency Review
        $cRes = $service->importCurrencyReviews($zip, $fileMap['currency_review'], $conn);
        $this->assertEquals(3, $cRes['staged']);
        $this->assertEquals(2, $cRes['approved']);
        $this->assertEquals(1, $cRes['unresolved']);
        $this->assertDatabaseCount('staging_currency_reviews', 3);

        // 3. Ingest Students
        $sRes = $service->importStudents($zip, $fileMap['students'], $conn);
        $this->assertEquals(3, $sRes['staged']);
        $this->assertDatabaseCount('staging_students', 3);

        // 4. Ingest Dues
        $dRes = $service->importDues($zip, $fileMap['dues'], $conn);
        $this->assertEquals(3, $dRes['staged']);
        $this->assertDatabaseCount('staging_dues', 3);

        // 5. Ingest Payments
        $pRes = $service->importPayments($zip, $fileMap['paid'], $conn);
        $this->assertEquals(3, $pRes['staged']);
        $this->assertDatabaseCount('staging_payments', 3);

        // 6. Ingest Exceptions
        $eRes = $service->importExceptions($zip, $fileMap['exceptions'], $conn);
        $this->assertEquals(2, $eRes['staged']);
        $this->assertDatabaseCount('staging_exceptions', 2);

        $zip->close();

        // 7. Verify zero production ledger records created
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_repeated_import_is_strictly_idempotent_and_creates_zero_duplicates(): void
    {
        $zipPath = $this->createMockStagingZip();
        $service = app(StagingImportService::class);
        $conn = config('database.default');

        // First Run
        $zip1 = $service->openZip($zipPath);
        $map1 = $service->getZipFileMap($zip1);
        $service->importBatches($zip1, $map1['batches'], $conn);
        $service->importCurrencyReviews($zip1, $map1['currency_review'], $conn);
        $service->importStudents($zip1, $map1['students'], $conn);
        $service->importDues($zip1, $map1['dues'], $conn);
        $service->importPayments($zip1, $map1['paid'], $conn);
        $service->importExceptions($zip1, $map1['exceptions'], $conn);
        $zip1->close();

        $batchesCount = DB::table('staging_batches')->count();
        $currCount = DB::table('staging_currency_reviews')->count();
        $stuCount = DB::table('staging_students')->count();
        $duesCount = DB::table('staging_dues')->count();
        $payCount = DB::table('staging_payments')->count();
        $excCount = DB::table('staging_exceptions')->count();

        $this->assertEquals(2, $batchesCount);
        $this->assertEquals(3, $currCount);
        $this->assertEquals(3, $stuCount);
        $this->assertEquals(3, $duesCount);
        $this->assertEquals(3, $payCount);
        $this->assertEquals(2, $excCount);

        // Second Run (Repeated Ingestion of same archive)
        $zip2 = $service->openZip($zipPath);
        $map2 = $service->getZipFileMap($zip2);
        $service->importBatches($zip2, $map2['batches'], $conn);
        $service->importCurrencyReviews($zip2, $map2['currency_review'], $conn);
        $service->importStudents($zip2, $map2['students'], $conn);
        $service->importDues($zip2, $map2['dues'], $conn);
        $service->importPayments($zip2, $map2['paid'], $conn);
        $service->importExceptions($zip2, $map2['exceptions'], $conn);
        $zip2->close();

        // Verify zero record count increase
        $this->assertEquals($batchesCount, DB::table('staging_batches')->count());
        $this->assertEquals($currCount, DB::table('staging_currency_reviews')->count());
        $this->assertEquals($stuCount, DB::table('staging_students')->count());
        $this->assertEquals($duesCount, DB::table('staging_dues')->count());
        $this->assertEquals($payCount, DB::table('staging_payments')->count());
        $this->assertEquals($excCount, DB::table('staging_exceptions')->count());
    }

    public function test_malformed_dates_and_invalid_currencies_are_preserved_without_fatal_crash(): void
    {
        $zipPath = $this->createMockStagingZip();
        $service = app(StagingImportService::class);
        $conn = config('database.default');

        $zip = $service->openZip($zipPath);
        $map = $service->getZipFileMap($zip);
        $service->importPayments($zip, $map['paid'], $conn);
        $service->importDues($zip, $map['dues'], $conn);
        $zip->close();

        // 1. Payment with malformed date preserves raw date string and sets ISO date to null
        $malformedPayment = DB::table('staging_payments')->where('source_key', 'PAY:3')->first();
        $this->assertNotNull($malformedPayment);
        $this->assertEquals('invalid-date', $malformedPayment->payment_date_raw);
        $this->assertNull($malformedPayment->payment_date_iso);

        // 2. Dues with unparsed currency preserves raw string and does not fail
        $malformedDue = DB::table('staging_dues')->where('source_key', 'DUE:4')->first();
        $this->assertNotNull($malformedDue);
        $this->assertEquals('INVALID_CURR', $malformedDue->currency_raw);
        $this->assertNull($malformedDue->currency_iso);
        $this->assertEquals(150.00, (float)$malformedDue->outstanding_amount);
    }

    public function test_unresolved_currencies_and_departments_remain_flagged_for_review(): void
    {
        $zipPath = $this->createMockStagingZip();
        $service = app(StagingImportService::class);
        $conn = config('database.default');

        $zip = $service->openZip($zipPath);
        $map = $service->getZipFileMap($zip);
        $service->importCurrencyReviews($zip, $map['currency_review'], $conn);
        $service->importStudents($zip, $map['students'], $conn);
        $zip->close();

        // 1. Student 90003 has unresolved currency -> marked PENDING_REVIEW
        $currReview = DB::table('staging_currency_reviews')->where('gr_number', '90003')->first();
        $this->assertEquals('PENDING_REVIEW', $currReview->review_status);
        $this->assertNull($currReview->currency_approved_manual);

        // 2. Student 90001 has approved SAR currency -> marked APPROVED
        $approvedReview = DB::table('staging_currency_reviews')->where('gr_number', '90001')->first();
        $this->assertEquals('APPROVED', $approvedReview->review_status);
        $this->assertEquals('SAR', $approvedReview->currency_approved_manual);

        // 3. Departments/campuses not approved must NOT be inferred automatically
        $student3 = DB::table('staging_students')->where('gr_number', '90003')->first();
        $this->assertNull($student3->department_approved);
        $this->assertNull($student3->campus_approved);
    }

    public function test_dry_run_mode_does_not_mutate_database(): void
    {
        $zipPath = $this->createMockStagingZip();
        $service = app(StagingImportService::class);
        $conn = config('database.default');

        $zip = $service->openZip($zipPath);
        $map = $service->getZipFileMap($zip);

        // Run all imports with dryRun = true
        $service->importBatches($zip, $map['batches'], $conn, true);
        $service->importCurrencyReviews($zip, $map['currency_review'], $conn, true);
        $service->importStudents($zip, $map['students'], $conn, true);
        $service->importDues($zip, $map['dues'], $conn, true);
        $service->importPayments($zip, $map['paid'], $conn, true);
        $service->importExceptions($zip, $map['exceptions'], $conn, true);
        $zip->close();

        // Assert 0 rows in all staging tables
        $this->assertDatabaseCount('staging_batches', 0);
        $this->assertDatabaseCount('staging_currency_reviews', 0);
        $this->assertDatabaseCount('staging_students', 0);
        $this->assertDatabaseCount('staging_dues', 0);
        $this->assertDatabaseCount('staging_payments', 0);
        $this->assertDatabaseCount('staging_exceptions', 0);
    }
}
