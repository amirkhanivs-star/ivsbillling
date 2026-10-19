<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FeeImportService;
use App\Services\InvoiceService;
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

    public function test_fee_collection_shows_import_button_and_dialog_with_safety_locks(): void
    {
        $admin = $this->setupAdmin();

        $res = $this->actingAs($admin)->get('/challan');
        $res->assertStatus(200);
        $res->assertSee('Import Fees');
        $res->assertSee('fee-import-dialog');
        $res->assertSee('Outstanding Fees Report');
        $res->assertSee('Fee Collection (Paid Receipts) Report');
        // Verify safety lock notice is visible
        $res->assertSee('Direct-to-ledger import disabled for Phase 1 Recovery Protection');
        // Verify dangerous clear button and reset actions are completely removed
        $res->assertDontSee('Clear Fee Dues');
        $res->assertDontSee('Reset / Clear Fee Records');
    }

    public function test_dangerous_deletion_actions_are_rejected_and_ledger_preserved(): void
    {
        $admin = $this->setupAdmin();
        $deptId = DB::table('departments')->first()->id;
        $campusId = DB::table('campuses')->first()->id;
        $feeTypeId = DB::table('fee_types')->where('code', 'monthly')->value('id');

        // Seed sample financial ledger records
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

        $enrollId = DB::table('enrollments')->insertGetId([
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

        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $enrollId,
            'fee_type_id' => $feeTypeId,
            'description' => 'Monthly Fee',
            'frequency' => 'monthly',
            'currency' => 'SAR',
            'amount_minor' => 20000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(InvoiceService::class)->generate($runId);

        DB::table('payments')->insert([
            'family_id' => $familyId,
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'currency' => 'SAR',
            'amount_minor' => 20000,
            'unallocated_minor' => 0,
            'received_on' => '2026-10-19',
            'receipt_number' => 'REC-TEST-001',
            'idempotency_key' => 'idemp-001',
            'request_hash' => 'hash-001',
            'received_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. Web route POST /challan/clear-fee-data MUST return 403 Forbidden
        $res = $this->actingAs($admin)->post('/challan/clear-fee-data');
        $res->assertStatus(403);

        // 2. Direct service calls to clearFeeData and clearAllData MUST throw RuntimeException
        $svc = new FeeImportService();
        $this->assertThrows(fn() => $svc->clearFeeData(), \RuntimeException::class);
        $this->assertThrows(fn() => $svc->clearAllData(), \RuntimeException::class);

        // 3. Verify ledger records remain completely intact and untouched
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('families', 1);
    }

    public function test_direct_historical_imports_are_rejected(): void
    {
        $admin = $this->setupAdmin();

        $fileOut = UploadedFile::fake()->createWithContent('outstanding.csv', "GRN,Student Name,Particular,Balance\n5525,Abdullah,Monthly Fee,200\n");
        $fileCol = UploadedFile::fake()->createWithContent('collection.csv', "Slip #,GRN,Student Name,Particular,Date,Amount\n24064,5525,Abdullah,Monthly Fee,03-Aug,200\n");

        // 1. Direct outstanding upload rejected
        $resOut = $this->actingAs($admin)->post('/challan/import-outstanding', ['csv_file' => $fileOut]);
        $resOut->assertRedirect('/challan');
        $resOut->assertSessionHasErrors(['error']);

        // 2. Preloaded outstanding rejected
        $resOutPre = $this->actingAs($admin)->post('/challan/import-outstanding-preloaded');
        $resOutPre->assertRedirect('/challan');
        $resOutPre->assertSessionHasErrors(['error']);

        // 3. Direct collection upload rejected
        $resCol = $this->actingAs($admin)->post('/challan/import-collection', ['csv_file' => $fileCol]);
        $resCol->assertRedirect('/challan');
        $resCol->assertSessionHasErrors(['error']);

        // 4. Preloaded collection rejected
        $resColPre = $this->actingAs($admin)->post('/challan/import-collection-preloaded');
        $resColPre->assertRedirect('/challan');
        $resColPre->assertSessionHasErrors(['error']);

        // 5. Direct service method execution throws RuntimeException
        $svc = new FeeImportService();
        $this->assertThrows(fn() => $svc->importOutstanding('dummy.csv'), \RuntimeException::class);
        $this->assertThrows(fn() => $svc->importCollection('dummy.csv'), \RuntimeException::class);

        // 6. Verify zero phantom financial records created
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_normal_cashier_fee_collection_remains_functional(): void
    {
        $admin = $this->setupAdmin();
        $deptId = DB::table('departments')->first()->id;
        $campusId = DB::table('campuses')->first()->id;
        $feeTypeId = DB::table('fee_types')->where('code', 'monthly')->value('id');

        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-REGULAR-1',
            'guardian_name' => 'Regular Guardian',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'gr_number' => '7701',
            'name' => 'Zayd Khan',
            'family_id' => $familyId,
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'grade_label' => 'Grade 1',
            'currency' => 'SAR',
            'monthly_fee_minor' => 30000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $enrollId,
            'fee_type_id' => $feeTypeId,
            'description' => 'Monthly Fee',
            'frequency' => 'monthly',
            'currency' => 'SAR',
            'amount_minor' => 30000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(InvoiceService::class)->generate($runId);

        // Cashier collects fee payment normally
        $res = $this->actingAs($admin)->post('/challan/receive', [
            'family_id' => $familyId,
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'currency' => 'SAR',
            'receiving_amount' => '300.00',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $res->assertRedirect();
        $this->assertDatabaseHas('payments', [
            'family_id' => $familyId,
            'amount_minor' => 30000,
            'currency' => 'SAR',
        ]);

        // Invoice item marked paid
        $this->assertDatabaseHas('invoice_items', [
            'student_id' => $studentId,
            'paid_minor' => 30000,
        ]);
    }
}
