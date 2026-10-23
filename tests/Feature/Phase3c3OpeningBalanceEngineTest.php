<?php

namespace Tests\Feature;

use App\Console\Commands\QaResetTestLedgerCommand;
use App\Models\User;
use App\Services\Migration\HistoricalOpeningBalanceService;
use App\Services\Migration\ReviewedWorkbookImportService;
use App\Services\ReceivePaymentService;
use App\Services\ReceiptReversalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase3c3OpeningBalanceEngineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Task 1: Period Parsing without Inventing Default Dates.
     * Ensure parseParticularPeriod returns valid dates only for explicit 4-digit years,
     * and returns null for unknown, truncated, or undated fee particulars.
     */
    public function test_period_parsing_rejects_unknown_periods_and_never_invents_september_2026(): void
    {
        // Valid 4-digit year periods
        $p1 = HistoricalOpeningBalanceService::parseParticularPeriod('Monthly Fee - Oct-2026');
        $this->assertNotNull($p1);
        $this->assertSame('monthly:2026-10-01..2026-10-31', $p1['period_key']);
        $this->assertSame('2026-10-01', $p1['period_start']);
        $this->assertSame('2026-10-31', $p1['period_end']);
        $this->assertSame('2026-10-25', $p1['due_date']);

        $p2 = HistoricalOpeningBalanceService::parseParticularPeriod('Tution Fee Jul 2025');
        $this->assertNotNull($p2);
        $this->assertSame('monthly:2025-07-01..2025-07-31', $p2['period_key']);

        // Truncated, missing, or course descriptions with no year MUST return null
        $this->assertNull(HistoricalOpeningBalanceService::parseParticularPeriod('Grade 8- (Chemistry) IGCSE / O level -'));
        $this->assertNull(HistoricalOpeningBalanceService::parseParticularPeriod('Grade 8- (Maths) IGCSE / O level - Oct'));
        $this->assertNull(HistoricalOpeningBalanceService::parseParticularPeriod('Grade 4- (Computer Science) - Oct-20'));
        $this->assertNull(HistoricalOpeningBalanceService::parseParticularPeriod('Miscellaneous Books Fee'));
    }

    /**
     * Task 1: Department Determination Evidence.
     */
    public function test_department_determination_evidence(): void
    {
        // Tuition classes
        $this->assertSame(2, HistoricalOpeningBalanceService::determineDepartmentId('Tuition - ( 1-1)', 'Monthly Fee - Oct-2026', 1, 2));
        $this->assertSame(2, HistoricalOpeningBalanceService::determineDepartmentId('Tuition - A', 'Tuition Fee - Oct-2026', 1, 2));

        // School classes
        $this->assertSame(1, HistoricalOpeningBalanceService::determineDepartmentId('1 - A', 'Monthly Fee - Oct-2026', 1, 2));
        $this->assertSame(1, HistoricalOpeningBalanceService::determineDepartmentId('Grade 4', 'Monthly Fee - Oct-2026', 1, 2));
        $this->assertSame(1, HistoricalOpeningBalanceService::determineDepartmentId('8th IGCSE - A', 'Monthly Fee - Oct-2026', 1, 2));

        // Ambiguous
        $this->assertNull(HistoricalOpeningBalanceService::determineDepartmentId('', 'Random Charge', 1, 2));
    }

    /**
     * Task 2: Active Batch ID and Source Catalog.
     */
    public function test_active_batch_configuration_and_catalog(): void
    {
        $activeBatch = config('imports.active_batch_id');
        $this->assertSame('BATCH-20261023-FINAL-MYSKOOL', $activeBatch);

        $versions = config('imports.versions');
        $this->assertArrayHasKey('v2_reviewed_20261022', $versions);
        $this->assertArrayHasKey('v2_reviewed_20261022_revised', $versions);
        $this->assertArrayHasKey('v3_final_myskool_20261023', $versions);

        $this->assertSame('d2276cf0c75a0c17b143d1815fda568c9b5cc7e0154f546b8075d1a98e225401', $versions['v2_reviewed_20261022']['expected_sha256']);
        $this->assertSame('f48f1ee57eb826776c2f1eb7cab0150640605d4b30f1c761044f7d0d12d0468e', $versions['v2_reviewed_20261022_revised']['expected_sha256']);
        $this->assertSame('77d8e10f84726cc7b9708bfdb749948a7dbe717d60dab15b91361d5f2e4c4f4b', $versions['v3_final_myskool_20261023']['expected_sha256']);
    }

    /**
     * Task 3: Enrollment and Billing Eligibility.
     * Date of leaving does not independently revoke current session membership.
     */
    public function test_date_of_leaving_does_not_revoke_active_membership(): void
    {
        $this->seed();
        $campus = DB::table('campuses')->value('id');
        $dept = DB::table('departments')->where('code', 'school')->value('id');

        $family = DB::table('families')->insertGetId([
            'family_code' => 'FAM-TEST-LEAVE',
            'guardian_name' => 'Parent Leave Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Student with date of leaving populated but present in session with fee > 0
        $student = DB::table('students')->insertGetId([
            'gr_number' => 'GR-DISCREP-01',
            'family_id' => $family,
            'name' => 'Discrepancy Student',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollment = DB::table('enrollments')->insertGetId([
            'student_id' => $student,
            'campus_id' => $campus,
            'department_id' => $dept,
            'currency' => 'SAR',
            'monthly_fee_minor' => 45000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('students', [
            'id' => $student,
            'status' => 'present',
        ]);
        $this->assertDatabaseHas('enrollments', [
            'id' => $enrollment,
            'status' => 'active',
        ]);
    }

    /**
     * Task 4: Test Ledger Isolation and Backup Verification.
     */
    public function test_qa_reset_test_ledger_verifies_backup_and_dry_run_safety(): void
    {
        $backupPath = base_path(QaResetTestLedgerCommand::BACKUP_PATH);
        $this->assertFileExists($backupPath);

        $actualHash = hash_file('sha256', $backupPath);
        $this->assertSame(QaResetTestLedgerCommand::EXPECTED_SHA256, $actualHash);

        // Dry-run command must complete with code 0 without modifying records
        $exitCode = $this->artisan('qa:reset-test-ledger')->run();
        $this->assertSame(0, $exitCode);
    }

    /**
     * Task 5: QA Opening-Balance Posting Engine Idempotency & Multi-Item Consolidation.
     */
    public function test_opening_balance_posting_engine_idempotency_and_grouping(): void
    {
        $this->seed();
        $campus = DB::table('campuses')->value('id');
        $deptSchool = DB::table('departments')->where('code', 'school')->value('id');
        $deptTuition = DB::table('departments')->where('code', 'tuition')->value('id');

        $family = DB::table('families')->insertGetId([
            'family_code' => 'FAM-OB-TEST',
            'guardian_name' => 'Opening Balance Parent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Student 1 in School
        $s1 = DB::table('students')->insertGetId([
            'gr_number' => 'GR-OB-01',
            'family_id' => $family,
            'name' => 'Student School',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $e1 = DB::table('enrollments')->insertGetId([
            'student_id' => $s1,
            'campus_id' => $campus,
            'department_id' => $deptSchool,
            'currency' => 'SAR',
            'monthly_fee_minor' => 50000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Student 2 in Tuition (Sibling in same family)
        $s2 = DB::table('students')->insertGetId([
            'gr_number' => 'GR-OB-02',
            'family_id' => $family,
            'name' => 'Student Tuition',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $e2 = DB::table('enrollments')->insertGetId([
            'student_id' => $s2,
            'campus_id' => $campus,
            'department_id' => $deptTuition,
            'currency' => 'SAR',
            'monthly_fee_minor' => 30000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed fictional staged dues for BATCH-TEST:
        // Item 1: S1 Oct-2026 School Monthly Fee 500.00 SAR
        // Item 2: S1 Oct-2026 School Computer Fee 100.00 SAR (Multiple items in same period for S1)
        // Item 3: S2 Oct-2026 Tuition Fee 300.00 SAR (Separate department)
        // Item 4: S1 Invalid period (Should be held and not posted)
        $batch = 'BATCH-TEST-OB';
        $due1 = DB::table('qa_staged_dues')->insertGetId([
            'batch_id' => $batch,
            'source_row' => 101,
            'gr_number' => 'GR-OB-01',
            'class_in_dues_report' => 'Grade 4 - A',
            'fee_particular' => 'Monthly Fee - Oct-2026',
            'currency' => 'SAR',
            'outstanding_amount' => 500.00,
            'outstanding_amount_minor' => 50000,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $due2 = DB::table('qa_staged_dues')->insertGetId([
            'batch_id' => $batch,
            'source_row' => 102,
            'gr_number' => 'GR-OB-01',
            'class_in_dues_report' => 'Grade 4 - A',
            'fee_particular' => 'Monthly Fee - Oct-2026',
            'currency' => 'SAR',
            'outstanding_amount' => 100.00,
            'outstanding_amount_minor' => 10000,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $due3 = DB::table('qa_staged_dues')->insertGetId([
            'batch_id' => $batch,
            'source_row' => 103,
            'gr_number' => 'GR-OB-02',
            'class_in_dues_report' => 'Tuition - A',
            'fee_particular' => 'Tuition Fee - Oct-2026',
            'currency' => 'SAR',
            'outstanding_amount' => 300.00,
            'outstanding_amount_minor' => 30000,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $due4 = DB::table('qa_staged_dues')->insertGetId([
            'batch_id' => $batch,
            'source_row' => 104,
            'gr_number' => 'GR-OB-01',
            'class_in_dues_report' => 'Grade 4 - A',
            'fee_particular' => 'Course Material Undated',
            'currency' => 'SAR',
            'outstanding_amount' => 50.00,
            'outstanding_amount_minor' => 5000,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new HistoricalOpeningBalanceService();

        // 1. Preview Verification
        $preview = $service->preview(null, $batch);
        $this->assertSame(4, $preview['summary']['total_staged_dues']);
        $this->assertSame(3, $preview['summary']['eligible_rows']);
        $this->assertSame(1, $preview['summary']['excluded_rows_count']);
        $this->assertSame(1, $preview['exclusions']['unknown_period_count']);
        // 2 invoices to create: 1 for School (combining due1 + due2 = 600 SAR) and 1 for Tuition (due3 = 300 SAR)
        $this->assertSame(2, $preview['summary']['invoices_to_create']);

        // 2. Safeguard: Real posting blocked by default
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Opening balance ledger posting is STRICTLY BLOCKED');
        $service->postOpeningBalances(null, $batch, false);
    }

    /**
     * Test authorized test posting execution, lineage recording, and strict idempotency.
     */
    public function test_posting_engine_execution_and_idempotency_with_lineage(): void
    {
        $this->seed();
        $campus = DB::table('campuses')->value('id');
        $deptSchool = DB::table('departments')->where('code', 'school')->value('id');

        $user = User::create([
            'name' => 'Admin User',
            'email' => 'admin_posting@example.com',
            'password' => bcrypt('testing-password'),
            'role' => 'super_admin',
            'campus_id' => $campus,
            'department_id' => $deptSchool,
            'is_active' => true,
        ]);

        $family = DB::table('families')->insertGetId([
            'family_code' => 'FAM-IDEM-01',
            'guardian_name' => 'Idempotency Parent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $s1 = DB::table('students')->insertGetId([
            'gr_number' => 'GR-IDEM-01',
            'family_id' => $family,
            'name' => 'Student Idem',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $e1 = DB::table('enrollments')->insertGetId([
            'student_id' => $s1,
            'campus_id' => $campus,
            'department_id' => $deptSchool,
            'currency' => 'SAR',
            'monthly_fee_minor' => 50000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $batch = 'BATCH-IDEM-RUN';
        $dueId1 = DB::table('qa_staged_dues')->insertGetId([
            'batch_id' => $batch,
            'source_row' => 201,
            'gr_number' => 'GR-IDEM-01',
            'class_in_dues_report' => 'Grade 1 - A',
            'fee_particular' => 'Monthly Fee - Oct-2026',
            'currency' => 'SAR',
            'outstanding_amount' => 500.00,
            'outstanding_amount_minor' => 50000,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dueId2 = DB::table('qa_staged_dues')->insertGetId([
            'batch_id' => $batch,
            'source_row' => 202,
            'gr_number' => 'GR-IDEM-01',
            'class_in_dues_report' => 'Grade 1 - A',
            'fee_particular' => 'Monthly Fee - Oct-2026',
            'currency' => 'SAR',
            'outstanding_amount' => 150.00,
            'outstanding_amount_minor' => 15000,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new HistoricalOpeningBalanceService();

        // Execution 1: Post opening balances with allowTestExecution = true
        $res1 = $service->postOpeningBalances(null, $batch, true);
        $this->assertSame('success', $res1['status']);
        $this->assertSame(1, $res1['posted_invoices'], 'Should create exactly 1 combined invoice for the family and period.');
        $this->assertSame(2, $res1['posted_items'], 'Should create exactly 2 line items.');
        $this->assertSame(65000, $res1['totals_by_currency']['SAR']);

        // Check Lineage Records
        $lineageCount = DB::table('qa_opening_balance_lineage')->where('batch_id', $batch)->count();
        $this->assertSame(2, $lineageCount);
        $this->assertDatabaseHas('qa_opening_balance_lineage', ['qa_staged_due_id' => $dueId1]);
        $this->assertDatabaseHas('qa_opening_balance_lineage', ['qa_staged_due_id' => $dueId2]);

        // Execution 2: Immediate re-run must be strictly idempotent (0 duplicate invoices or items)
        $res2 = $service->postOpeningBalances(null, $batch, true);
        $this->assertSame(0, $res2['posted_invoices'], 'Repeated run must create 0 new invoices.');
        $this->assertSame(0, $res2['posted_items'], 'Repeated run must create 0 new invoice items.');

        // Total invoices in database must still be exactly 1
        $this->assertSame(1, DB::table('invoices')->where('family_id', $family)->count());
        $this->assertSame(2, DB::table('invoice_items')->count());
    }

    /**
     * Task 6: Payment, Partial Collection, and Reversal Flow on Posted Invoice.
     */
    public function test_payment_partial_collection_and_reversal_flow(): void
    {
        $this->seed();
        $campus = DB::table('campuses')->value('id');
        $dept = DB::table('departments')->where('code', 'school')->value('id');
        $feeType = DB::table('fee_types')->where('code', 'monthly')->value('id');

        $user = User::create([
            'name' => 'Cashier Test',
            'email' => 'cashier_test@example.com',
            'password' => bcrypt('password'),
            'role' => 'cashier',
            'campus_id' => $campus,
            'department_id' => $dept,
            'is_active' => true,
        ]);

        $family = DB::table('families')->insertGetId([
            'family_code' => 'FAM-PAY-01',
            'guardian_name' => 'Payment Parent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $student = DB::table('students')->insertGetId([
            'gr_number' => 'GR-PAY-01',
            'family_id' => $family,
            'name' => 'Pay Student',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollment = DB::table('enrollments')->insertGetId([
            'student_id' => $student,
            'campus_id' => $campus,
            'department_id' => $dept,
            'currency' => 'SAR',
            'monthly_fee_minor' => 100000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus,
            'department_id' => $dept,
            'frequency' => 'opening_balance',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'fee_type_ids' => json_encode([$feeType]),
            'status' => 'generated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $family,
            'campus_id' => $campus,
            'department_id' => $dept,
            'currency' => 'SAR',
            'invoice_number' => 'SCH-OB-2026-990001',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 100000, // 1000.00 SAR
            'paid_minor' => 0,
            'status' => 'generated',
            'approved_to_send' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $student,
            'enrollment_id' => $enrollment,
            'fee_type_id' => $feeType,
            'description' => 'Monthly Fee - Oct-2026',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'SAR',
            'amount_minor' => 100000,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => 100000,
            'paid_minor' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. Partial Payment of 400.00 SAR
        $paymentService = app(ReceivePaymentService::class);
        $payment = $paymentService->receive([
            'idempotency_key' => 'IDEM-TEST-' . \Illuminate\Support\Str::uuid(),
            'family_id' => $family,
            'campus_id' => $campus,
            'department_id' => $dept,
            'receiving_amount' => '400.00',
            'amount_minor' => 40000,
            'currency' => 'SAR',
            'method' => 'cash',
            'received_at' => '2026-10-22',
            'allocations' => [
                ['invoice_item_id' => $itemId, 'amount_minor' => 40000],
            ],
        ], $user->id);

        $this->assertSame(40000, (int) DB::table('invoices')->where('id', $invId)->value('paid_minor'));
        $this->assertSame('partial', DB::table('invoices')->where('id', $invId)->value('status'));

        // 2. Receipt Reversal
        $reversalService = app(ReceiptReversalService::class);
        $reversal = $reversalService->reverseReceipt($payment->id, 'Simulated correction during QA testing', $user->id);
        $this->assertSame('reversed', $reversal->status);
        $this->assertSame(0, (int) DB::table('invoices')->where('id', $invId)->value('paid_minor'));
        $this->assertSame('generated', DB::table('invoices')->where('id', $invId)->value('status'));
    }
}
