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

    public function test_fee_collection_obsolete_import_dialog_and_triggers_are_absent(): void
    {
        $admin = $this->setupAdmin();

        $res = $this->actingAs($admin)->get('/challan');
        $res->assertStatus(200);

        // Verify obsolete legacy import triggers and dialog are completely removed
        $res->assertDontSee('Import Fees');
        $res->assertDontSee('fee-import-dialog');
        $res->assertDontSee('Outstanding Fees Report');
        $res->assertDontSee('Fee Collection (Paid Receipts) Report');
        $res->assertDontSee('Direct-to-ledger import disabled for Phase 1 Recovery Protection');

        // Verify dangerous clear button and reset actions remain completely removed
        $res->assertDontSee('Clear Fee Dues');
        $res->assertDontSee('Reset / Clear Fee Records');

        // Verify active receipt details dialog remains intact
        $res->assertSee('receipt-details-dialog');
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

    public function test_dangerous_deletion_actions_are_rejected_and_ledger_preserved(): void
    {
        $admin = $this->setupAdmin();
        $cashier = $this->setupCashier();
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

        // 1. Unauthenticated request to /challan/clear-fee-data redirects to login
        $this->post('/challan/clear-fee-data')->assertRedirect('/login');

        // 2. Cashier request to /challan/clear-fee-data returns 403 Forbidden
        $this->actingAs($cashier)->post('/challan/clear-fee-data')->assertStatus(403);

        // 3. Super admin request to /challan/clear-fee-data returns 403 Forbidden
        $res = $this->actingAs($admin)->post('/challan/clear-fee-data');
        $res->assertStatus(403);

        // 4. Direct service calls to clearFeeData and clearAllData MUST throw RuntimeException
        $svc = new FeeImportService();
        $this->assertThrows(fn() => $svc->clearFeeData(), \RuntimeException::class);
        $this->assertThrows(fn() => $svc->clearAllData(), \RuntimeException::class);

        // 5. Verify ledger records remain completely intact and untouched
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('families', 1);
    }

    public function test_direct_historical_imports_are_rejected(): void
    {
        $admin = $this->setupAdmin();
        $cashier = $this->setupCashier();

        $fileOut = UploadedFile::fake()->createWithContent('outstanding.csv', "GRN,Student Name,Particular,Balance\n5525,Abdullah,Monthly Fee,200\n");
        $fileCol = UploadedFile::fake()->createWithContent('collection.csv', "Slip #,GRN,Student Name,Particular,Date,Amount\n24064,5525,Abdullah,Monthly Fee,03-Aug,200\n");

        // 1. Unauthenticated requests redirect to login
        $this->post('/challan/import-outstanding', ['csv_file' => $fileOut])->assertRedirect('/login');
        $this->post('/challan/import-collection', ['csv_file' => $fileCol])->assertRedirect('/login');

        // 2. Cashier unauthorized requests return 403 Forbidden
        $this->actingAs($cashier)->post('/challan/import-outstanding', ['csv_file' => $fileOut])->assertStatus(403);
        $this->actingAs($cashier)->post('/challan/import-outstanding-preloaded')->assertStatus(403);
        $this->actingAs($cashier)->post('/challan/import-collection', ['csv_file' => $fileCol])->assertStatus(403);
        $this->actingAs($cashier)->post('/challan/import-collection-preloaded')->assertStatus(403);

        // 3. Authenticated Super Admin requests are rejected with safety lock notice
        $resOut = $this->actingAs($admin)->post('/challan/import-outstanding', ['csv_file' => $fileOut]);
        $resOut->assertRedirect('/challan');
        $resOut->assertSessionHasErrors(['error']);

        $resOutPre = $this->actingAs($admin)->post('/challan/import-outstanding-preloaded');
        $resOutPre->assertRedirect('/challan');
        $resOutPre->assertSessionHasErrors(['error']);

        $resCol = $this->actingAs($admin)->post('/challan/import-collection', ['csv_file' => $fileCol]);
        $resCol->assertRedirect('/challan');
        $resCol->assertSessionHasErrors(['error']);

        $resColPre = $this->actingAs($admin)->post('/challan/import-collection-preloaded');
        $resColPre->assertRedirect('/challan');
        $resColPre->assertSessionHasErrors(['error']);

        // 4. Direct service method execution throws RuntimeException
        $svc = new FeeImportService();
        $this->assertThrows(fn() => $svc->importOutstanding('dummy.csv'), \RuntimeException::class);
        $this->assertThrows(fn() => $svc->importCollection('dummy.csv'), \RuntimeException::class);

        // 5. Verify zero phantom financial records created
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

    public function test_normalize_currency_preserves_valid_currencies(): void
    {
        $this->seed();
        $svc = new FeeImportService();

        // Valid SAR aliases
        $this->assertSame('SAR', $svc->normalizeCurrency('SAR'));
        $this->assertSame('SAR', $svc->normalizeCurrency('sar'));
        $this->assertSame('SAR', $svc->normalizeCurrency('SAFR'));
        $this->assertSame('SAR', $svc->normalizeCurrency('SAT'));
        $this->assertSame('SAR', $svc->normalizeCurrency('SR'));
        $this->assertSame('SAR', $svc->normalizeCurrency('SAR`'));
        $this->assertSame('SAR', $svc->normalizeCurrency(" SAR "));

        // Valid PKR aliases
        $this->assertSame('PKR', $svc->normalizeCurrency('PKR'));
        $this->assertSame('PKR', $svc->normalizeCurrency('pkr'));
        $this->assertSame('PKR', $svc->normalizeCurrency('RS'));
        $this->assertSame('PKR', $svc->normalizeCurrency('rs'));
        $this->assertSame('PKR', $svc->normalizeCurrency('RUPEES'));
        $this->assertSame('PKR', $svc->normalizeCurrency('RS.'));
        $this->assertSame('PKR', $svc->normalizeCurrency(" PKR "));

        // Other valid currencies
        $this->assertSame('AED', $svc->normalizeCurrency('AED'));
        $this->assertSame('AED', $svc->normalizeCurrency('SAE'));
        $this->assertSame('AED', $svc->normalizeCurrency('DHS'));
        $this->assertSame('AED', $svc->normalizeCurrency('DIRHAM'));
        $this->assertSame('QAR', $svc->normalizeCurrency('QAR'));
        $this->assertSame('QAR', $svc->normalizeCurrency('QR'));
        $this->assertSame('USD', $svc->normalizeCurrency('USD'));
        $this->assertSame('USD', $svc->normalizeCurrency('$'));
        $this->assertSame('OMR', $svc->normalizeCurrency('OMR'));
        $this->assertSame('OMR', $svc->normalizeCurrency('RO'));
        $this->assertSame('BHD', $svc->normalizeCurrency('BHD'));
        $this->assertSame('BHD', $svc->normalizeCurrency('BD'));
    }

    public function test_normalize_currency_flags_missing_invalid_and_unknown_currencies_as_unresolved(): void
    {
        $this->seed();
        $svc = new FeeImportService();

        // Missing, null, whitespace, or punctuation-only must NEVER silently convert to SAR or PKR
        $this->assertSame('UNRESOLVED', $svc->normalizeCurrency(null));
        $this->assertSame('UNRESOLVED', $svc->normalizeCurrency(''));
        $this->assertSame('UNRESOLVED', $svc->normalizeCurrency('   '));
        $this->assertSame('UNRESOLVED', $svc->normalizeCurrency('`'));
        $this->assertSame('UNRESOLVED', $svc->normalizeCurrency('"'));
        $this->assertSame('UNRESOLVED', $svc->normalizeCurrency("'"));

        // Unknown or invalid currency codes must NEVER silently default to SAR or PKR
        $this->assertSame('UNRESOLVED', $svc->normalizeCurrency('XYZ'));
        $this->assertSame('UNRESOLVED', $svc->normalizeCurrency('UNKNOWN'));
        $this->assertSame('UNRESOLVED', $svc->normalizeCurrency('INVALID'));
        $this->assertSame('UNRESOLVED', $svc->normalizeCurrency('123'));
        $this->assertSame('UNRESOLVED', $svc->normalizeCurrency('FOO_BAR'));
        $this->assertSame('UNRESOLVED', $svc->normalizeCurrency('EUR'));
    }

    public function test_historical_and_enrollment_currencies_can_differ_without_silent_conversion(): void
    {
        $admin = $this->setupAdmin();
        $deptId = DB::table('departments')->first()->id;
        $campusId = DB::table('campuses')->first()->id;
        $feeTypeId = DB::table('fee_types')->where('code', 'monthly')->value('id');

        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-MULTICURR-1',
            'guardian_name' => 'Multi Currency Guardian',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'gr_number' => '8801',
            'name' => 'Historical Student',
            'family_id' => $familyId,
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Student's CURRENT enrollment is in SAR
        $enrollId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'grade_label' => 'Grade 2',
            'currency' => 'SAR',
            'monthly_fee_minor' => 25000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-08-01..2026-08-31',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'due_date' => '2026-08-25',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Historical invoice exists in PKR (e.g. before relocation or branch transition)
        $historicalInvId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $familyId,
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'invoice_number' => 'INV-HIST-PKR-001',
            'currency' => 'PKR',
            'total_minor' => 1500000,
            'paid_minor' => 0,
            'status' => 'generated',
            'issued_on' => '2026-08-01',
            'due_date' => '2026-08-25',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $historicalInvId,
            'student_id' => $studentId,
            'enrollment_id' => $enrollId,
            'fee_type_id' => $feeTypeId,
            'description' => 'Historical Session Dues',
            'period_key' => '2026-08',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'currency' => 'PKR',
            'amount_minor' => 1500000,
            'discount_minor' => 0,
            'net_minor' => 1500000,
            'paid_minor' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Historical PKR invoice and item remain explicitly PKR
        $this->assertDatabaseHas('invoices', [
            'id' => $historicalInvId,
            'currency' => 'PKR',
        ]);
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $historicalInvId,
            'currency' => 'PKR',
        ]);

        // Student's active enrollment remains SAR
        $this->assertDatabaseHas('enrollments', [
            'id' => $enrollId,
            'currency' => 'SAR',
        ]);

        // Receive payment in PKR for historical invoice
        $res = $this->actingAs($admin)->post('/challan/receive', [
            'family_id' => $familyId,
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'currency' => 'PKR',
            'receiving_amount' => '15000.00',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $res->assertRedirect();

        // Payment recorded with PKR, never silently converted to SAR
        $this->assertDatabaseHas('payments', [
            'family_id' => $familyId,
            'amount_minor' => 1500000,
            'currency' => 'PKR',
        ]);

        // Historical invoice item settled in PKR with active domain status
        $this->assertDatabaseHas('invoice_items', [
            'id' => DB::table('invoice_items')->where('invoice_id', $historicalInvId)->value('id'),
            'currency' => 'PKR',
            'paid_minor' => 1500000,
            'status' => 'active',
        ]);

        // Parent historical invoice marked paid in PKR
        $this->assertDatabaseHas('invoices', [
            'id' => $historicalInvId,
            'currency' => 'PKR',
            'paid_minor' => 1500000,
            'status' => 'paid',
        ]);
    }
}
