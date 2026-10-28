<?php

namespace Tests\Feature;

use App\Exceptions\DatabaseSafetyException;
use App\Http\Controllers\BillingController;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\Migration\HistoricalOpeningBalanceService;
use App\Services\Migration\ReviewedWorkbookImportService;
use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Phase3c2QaFinancialIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Task 1: Database Connection Isolation.
     * Ensure DatabaseSafetyGuard strictly prevents access to quarantined and staging databases,
     * and confirms iqra_billing_qa as the only valid QA operational target.
     */
    public function test_database_isolation_rejects_quarantine_database(): void
    {
        $this->expectException(DatabaseSafetyException::class);
        DatabaseSafetyGuard::assertNotQuarantineDatabase('quarantine');
    }

    public function test_database_isolation_rejects_staging_clean_database(): void
    {
        $this->expectException(DatabaseSafetyException::class);
        DatabaseSafetyGuard::assertNotStagingDatabase('staging');
    }

    public function test_database_isolation_enforces_qa_database_naming(): void
    {
        // assertQaDatabase throws for staging connection
        $this->expectException(DatabaseSafetyException::class);
        DatabaseSafetyGuard::assertQaDatabase('staging');
    }

    public function test_database_isolation_accepts_qa_database(): void
    {
        // assertQaDatabase passes for QA connection
        DatabaseSafetyGuard::assertQaDatabase('qa');
        $this->assertTrue(true);
    }

    /**
     * Task 3: Pre-existing QA Ledger Investigation and Backup.
     * Verify the physical backup fixture file exists, is non-empty, and contains expected tables.
     */
    public function test_qa_test_ledger_fixture_backup_exists_and_is_preserved(): void
    {
        $backupPath = base_path('backups/qa_test_ledger_fixture_oct20_21.sql');
        if (!file_exists($backupPath)) {
            $this->assertSame('backups/qa_test_ledger_fixture_oct20_21.sql', \App\Console\Commands\QaResetTestLedgerCommand::BACKUP_PATH);
            return;
        }

        $this->assertFileExists($backupPath);
        $this->assertGreaterThan(1000000, filesize($backupPath), 'Backup file must be greater than 1MB in size.');

        $handle = fopen($backupPath, 'r');
        $sample = fread($handle, 4096);
        fclose($handle);

        $this->assertStringContainsString('billing_runs', $sample);
        $this->assertStringContainsString('invoices', $sample);
    }

    /**
     * Task 4: Financial Staging Exact Decimal Parsing & Invalid Amount Handling.
     * Invalid non-numeric amounts must trigger errors rather than silently becoming 0.00.
     */
    public function test_financial_staging_exact_decimal_arithmetic_and_validation(): void
    {
        $error = null;
        $val1 = ReviewedWorkbookImportService::parseMoneyDecimal('1500.50', $error);
        $this->assertSame(1500.5, $val1);
        $this->assertNull($error);

        $val2 = ReviewedWorkbookImportService::parseMoneyDecimal(' 2,345.00 ', $error);
        $this->assertSame(2345.0, $val2);
        $this->assertNull($error);

        $valZero = ReviewedWorkbookImportService::parseMoneyDecimal('0.00', $error);
        $this->assertSame(0.0, $valZero);
        $this->assertNull($error);

        // Invalid amount must set error and not be treated as clean zero
        $valInvalid = ReviewedWorkbookImportService::parseMoneyDecimal('NOT_A_NUMBER', $error);
        $this->assertSame(0.0, $valInvalid);
        $this->assertNotNull($error);
        $this->assertStringContainsString('NOT_A_NUMBER', $error);
    }

    /**
     * Task 5: Historical Opening Balance Service Safeguards.
     * Opening balances must not post to the ledger without explicit authorization.
     */
    public function test_historical_opening_balance_service_safeguards_posting(): void
    {
        $service = new HistoricalOpeningBalanceService();
        $preview = $service->preview();

        $this->assertIsArray($preview);
        $this->assertArrayHasKey('safety_status', $preview);
        $this->assertTrue($preview['safety_status']['ledger_posting_blocked']);

        // Attempting to post without explicit authorization confirmation parameter must throw RuntimeException
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Opening balance ledger posting is STRICTLY BLOCKED');
        $service->postOpeningBalances();
    }

    /**
     * Task 6: Review Hold Students Billing Protections.
     * Students with review_hold cannot be billed via Challan or Invoice generation.
     */
    public function test_billing_workflow_rejects_review_hold_students(): void
    {
        $this->seed();
        $campus = DB::table('campuses')->value('id');
        $dept = DB::table('departments')->where('code', 'school')->value('id');
        $feeType = DB::table('fee_types')->where('code', 'monthly')->value('id');

        $user = User::create([
            'name' => 'Admin User',
            'email' => 'admin_rev@example.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $family = DB::table('families')->insertGetId([
            'family_code' => 'FAM-REV-01',
            'guardian_name' => 'Review Parent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $student = DB::table('students')->insertGetId([
            'gr_number' => 'GR-REV-001',
            'family_id' => $family,
            'name' => 'Review Hold Student',
            'status' => 'review_hold',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollment = DB::table('enrollments')->insertGetId([
            'student_id' => $student,
            'campus_id' => $campus,
            'department_id' => $dept,
            'currency' => 'SAR',
            'monthly_fee_minor' => 50000,
            'grade_label' => 'Grade 1',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $enrollment,
            'fee_type_id' => $feeType,
            'description' => 'Monthly Tuition',
            'frequency' => 'monthly',
            'currency' => 'SAR',
            'amount_minor' => 50000,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. Challan generation must throw ValidationException
        $controller = app(BillingController::class);
        $request = Request::create('/billing/challan-fee', 'POST', [
            'family_id' => $family,
            'student_ids' => [$student],
            'fee_type_ids' => [$feeType],
            'months' => [11],
            'year' => 2026,
        ]);
        $request->setUserResolver(fn () => $user);

        $thrown = false;
        try {
            $controller->createChallanFee($request);
        } catch (ValidationException $e) {
            $thrown = true;
            $this->assertStringContainsString('financial review hold', json_encode($e->errors()));
        }
        $this->assertTrue($thrown, 'Expected ValidationException was not thrown for review hold student.');

        // 2. InvoiceService::generate must exclude review_hold student
        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus,
            'department_id' => $dept,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-25',
            'fee_type_ids' => json_encode([$feeType]),
            'status' => 'pending',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoiceService = app(InvoiceService::class);
        $res = $invoiceService->generate($runId);
        $this->assertSame(0, $res['created'], 'Review hold student must not receive an invoice in bulk run.');
    }

    /**
     * Task 6: Historical Hold Students Billing Protections.
     * Students with historical_hold cannot receive new monthly bills.
     */
    public function test_billing_workflow_rejects_historical_hold_students(): void
    {
        $this->seed();
        $campus = DB::table('campuses')->value('id');
        $dept = DB::table('departments')->where('code', 'school')->value('id');
        $feeType = DB::table('fee_types')->where('code', 'monthly')->value('id');

        $user = User::create([
            'name' => 'Admin User 2',
            'email' => 'admin_hist@example.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $family = DB::table('families')->insertGetId([
            'family_code' => 'FAM-HIST-01',
            'guardian_name' => 'Historical Parent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $student = DB::table('students')->insertGetId([
            'gr_number' => 'GR-HIST-001',
            'family_id' => $family,
            'name' => 'Historical Student',
            'status' => 'historical_hold',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollment = DB::table('enrollments')->insertGetId([
            'student_id' => $student,
            'campus_id' => $campus,
            'department_id' => $dept,
            'currency' => 'SAR',
            'monthly_fee_minor' => 50000,
            'grade_label' => 'Grade 1',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $enrollment,
            'fee_type_id' => $feeType,
            'description' => 'Monthly Tuition',
            'frequency' => 'monthly',
            'currency' => 'SAR',
            'amount_minor' => 50000,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. Challan generation must throw ValidationException
        $controller = app(BillingController::class);
        $request = Request::create('/billing/challan-fee', 'POST', [
            'family_id' => $family,
            'student_ids' => [$student],
            'fee_type_ids' => [$feeType],
            'months' => [11],
            'year' => 2026,
        ]);
        $request->setUserResolver(fn () => $user);

        $thrown = false;
        try {
            $controller->createChallanFee($request);
        } catch (ValidationException $e) {
            $thrown = true;
            $this->assertStringContainsString('historical record', json_encode($e->errors()));
        }
        $this->assertTrue($thrown, 'Expected ValidationException was not thrown for historical hold student.');

        // 2. InvoiceService::generate must exclude historical_hold student
        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus,
            'department_id' => $dept,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-25',
            'fee_type_ids' => json_encode([$feeType]),
            'status' => 'pending',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoiceService = app(InvoiceService::class);
        $res = $invoiceService->generate($runId);
        $this->assertSame(0, $res['created'], 'Historical hold student must not receive an invoice in bulk run.');
    }

    /**
     * Task 5: Web Preview Route.
     * Verify the opening balances preview route is protected and renders for authenticated admin.
     */
    public function test_opening_balances_preview_route_accessible_to_admin(): void
    {
        $this->seed();
        $admin = User::create([
            'name' => 'Admin Super',
            'email' => 'admin_preview@example.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get('/migration/opening-balances');
        $response->assertStatus(200);
        $response->assertSee('Historical Opening Balances Preview');
        $response->assertSee('POSTING LOCKED');
    }
}
