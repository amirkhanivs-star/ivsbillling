<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FeeImportTest extends TestCase
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

    public function test_fee_collection_shows_import_button_and_dialog(): void
    {
        $admin = $this->setupAdmin();

        $res = $this->actingAs($admin)->get('/challan');
        $res->assertStatus(200);
        $res->assertSee('Import Fees');
        $res->assertSee('fee-import-dialog');
        $res->assertSee('Outstanding Fees Report');
        $res->assertSee('Fee Collection (Paid Receipts) Report');
        $res->assertSee('Clear Fee Dues', false);
    }

    public function test_outstanding_and_collection_imports_and_clear(): void
    {
        $admin = $this->setupAdmin();

        // Create student and family
        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-TEST-99',
            'guardian_name' => 'Test Guardian',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'gr_number' => '5525',
            'name' => 'Muhammad Abdullah',
            'family_id' => $familyId,
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deptId = DB::table('departments')->first()->id;
        $campusId = DB::table('campuses')->first()->id;

        DB::table('enrollments')->insert([
            'student_id' => $studentId,
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'grade_label' => 'FS-1 - A',
            'currency' => 'SAR',
            'monthly_fee_minor' => 20000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. Upload Outstanding CSV
        $outstandingCsv = <<<CSV
IQRA VIRTUAL SCHOOL,Agha Meer Jani Road Wazir Bagh Peshawar,Outstanding Report,,,,
,,,,,,
,GRN,Student Name,Contact #,Particular,Balance,
,FS-1 - A,,,,,
,5525,Muhammad Abdullah,4213123412312,Monthly Fee - Jul-2025,200,
,,,,Monthly Fee - Nov-2025,200,
CSV;

        $fileOut = UploadedFile::fake()->createWithContent('outstanding.csv', $outstandingCsv);
        $res = $this->actingAs($admin)->post('/challan/import-outstanding', [
            'csv_file' => $fileOut,
        ]);
        $res->assertRedirect('/challan');
        $res->assertSessionHas('notice');

        // Check invoice items created
        $this->assertDatabaseHas('invoice_items', [
            'student_id' => $studentId,
            'description' => 'Monthly Fee',
            'amount_minor' => 20000,
            'status' => 'active',
        ]);

        // 2. Upload Collection CSV
        $collectionCsv = <<<CSV
Cash,Slip #,GRN,Student Name,Particular,Date,Amount
,24064,5525,Muhammad Abdullah,Monthly Fee - Jul-2025, 3 - Aug,200
CSV;

        $fileCol = UploadedFile::fake()->createWithContent('collection.csv', $collectionCsv);
        $res2 = $this->actingAs($admin)->post('/challan/import-collection', [
            'csv_file' => $fileCol,
        ]);
        $res2->assertRedirect('/challan');
        $res2->assertSessionHas('notice');

        // Check payment recorded and item paid
        $this->assertDatabaseHas('payments', [
            'receipt_number' => 'REC-24064',
            'amount_minor' => 20000,
        ]);
        $this->assertDatabaseHas('invoice_items', [
            'student_id' => $studentId,
            'period_start' => '2025-07-01',
            'status' => 'paid',
        ]);

        // 3. Test Clear Fee Data
        $res3 = $this->actingAs($admin)->post('/challan/clear-fee-data');
        $res3->assertRedirect('/challan');

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        // Student and family are still preserved
        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('families', 1);
    }

    public function test_import_outstanding_handles_summation_rows_and_currency_columns(): void
    {
        $admin = $this->setupAdmin();

        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-888',
            'guardian_name' => 'Guardian 888',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'gr_number' => '9834',
            'name' => 'Saleh Ahmed',
            'family_id' => $familyId,
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deptId = DB::table('departments')->first()->id;
        $campusId = DB::table('campuses')->first()->id;

        DB::table('enrollments')->insert([
            'student_id' => $studentId,
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'grade_label' => 'FS-1 - A',
            'currency' => 'SAR',
            'monthly_fee_minor' => 22000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // CSV with class header, student lines with blank GRN, summation line, and Currency column
        $duesCsv = <<<CSV
IQRA VIRTUAL SCHOOL,Agha Meer Jani Road Wazir Bagh Peshawar,Outstanding Report,,,,,
,,,,,,,
FS-1 - A,GRN,Student Name,Contact #,Particular,Currency,Balance,
,,,,,,,
,9834,Saleh Ahmed,,Monthly Fee - Sep-2026,SAR,220,
,,,,Monthly Fee - Oct-2026,SAR,220,
,440,,,,,,
CSV;

        $fileOut = UploadedFile::fake()->createWithContent('dues_with_summation.csv', $duesCsv);
        $res = $this->actingAs($admin)->post('/challan/import-outstanding', [
            'csv_file' => $fileOut,
        ]);
        $res->assertRedirect('/challan');
        $res->assertSessionHas('notice');

        // Verify only 2 invoice items were created, NO phantom item or student for "440"
        $this->assertDatabaseCount('invoice_items', 2);
        $this->assertDatabaseHas('invoice_items', [
            'student_id' => $studentId,
            'period_start' => '2026-09-01',
            'amount_minor' => 22000,
            'currency' => 'SAR',
        ]);
        $this->assertDatabaseHas('invoice_items', [
            'student_id' => $studentId,
            'period_start' => '2026-10-01',
            'amount_minor' => 22000,
            'currency' => 'SAR',
        ]);
        $this->assertDatabaseMissing('students', [
            'gr_number' => '440',
        ]);

        // Now test 8-column Paid Details with Currency column and date like "1-Mar-25"
        $paidCsv = <<<CSV
IQRA VIRTUAL SCHOOL,Agha Meer Jani Road Wazir Bagh Peshawar,Collection Report (01/Mar/2025 - 17/Oct/2026),,,,,,
,,,,,,,,
Cash,Slip #,GRN,Student Name,Particular,Date,Currency,Amount,
,,,,,,,,
,8888,9834,Saleh Ahmed,Monthly Fee - Sep-2026, 1-Mar-25,SAR,220,
CSV;

        $filePaid = UploadedFile::fake()->createWithContent('paid_with_currency.csv', $paidCsv);
        $resPaid = $this->actingAs($admin)->post('/challan/import-collection', [
            'csv_file' => $filePaid,
        ]);
        $resPaid->assertRedirect('/challan');

        // Check paid item settled
        $this->assertDatabaseHas('invoice_items', [
            'student_id' => $studentId,
            'period_start' => '2026-09-01',
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('payments', [
            'receipt_number' => 'REC-8888',
            'received_on' => '2025-03-01',
            'currency' => 'SAR',
            'amount_minor' => 22000,
        ]);
    }
}
