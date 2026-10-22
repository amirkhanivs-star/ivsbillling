<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InvoiceService;
use App\Services\Migration\HistoricalOpeningBalanceService;
use App\Services\Migration\QaControlledReimportService;
use App\Services\ReceiptReversalService;
use App\Services\ReceivePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class Phase3c3ReimportReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function setupBaseEnvironment(): array
    {
        $this->seed();
        $campus = (int) DB::table('campuses')->value('id');
        $deptSchool = (int) DB::table('departments')->where('code', 'school')->value('id');
        $deptTuition = (int) DB::table('departments')->where('code', 'tuition')->value('id');

        $user = User::create([
            'name' => 'QA Officer',
            'email' => 'qa_officer@example.com',
            'password' => bcrypt('testing-password-2026'),
            'role' => 'super_admin',
            'campus_id' => $campus,
            'department_id' => $deptSchool,
            'is_active' => true,
        ]);

        return compact('campus', 'deptSchool', 'deptTuition', 'user');
    }

    /**
     * Test 1: Safe Reset Scope, Backup Verification, and Unexpected Fixture Abort.
     */
    public function test_safe_reset_scope_verifies_backup_and_aborts_on_unexpected_records(): void
    {
        $env = $this->setupBaseEnvironment();

        // 1. Dry run on clean DB
        $this->artisan('qa:reset-test-ledger')
            ->expectsOutputToContain('DRY-RUN / AUDIT MODE COMPLETE')
            ->assertExitCode(0);

        // 2. Insert an unexpected payment record not in the verified fixture set (ID != 1)
        $family = DB::table('families')->insertGetId([
            'family_code' => 'FAM-UNEXP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payments')->insert([
            'id' => 9999,
            'family_id' => $family,
            'campus_id' => $env['campus'],
            'department_id' => $env['deptSchool'],
            'currency' => 'SAR',
            'receipt_number' => 'RCP-UNEXPECTED-9999',
            'amount_minor' => 50000,
            'unallocated_minor' => 50000,
            'received_on' => '2026-10-22',
            'idempotency_key' => 'IDEM-UNEXP-9999',
            'request_hash' => hash('sha256', 'unexp'),
            'received_by' => $env['user']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Command must detect unexpected record and abort
        $this->artisan('qa:reset-test-ledger')
            ->expectsOutputToContain('CRITICAL SAFETY STOP: Unexpected financial records detected in ledger!')
            ->expectsOutputToContain('Unexpected payment ID(s) found: 9999')
            ->assertExitCode(1);
    }

    /**
     * Test 2: Partial Invoice-Group Idempotency and Accurate Invoice Totals.
     */
    public function test_partial_posting_and_reruns_attaches_new_items_and_refreshes_invoice_totals(): void
    {
        $env = $this->setupBaseEnvironment();

        $family = DB::table('families')->insertGetId([
            'family_code' => 'FAM-PARTIAL-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $student = DB::table('students')->insertGetId([
            'gr_number' => 'GR-PART-01',
            'family_id' => $family,
            'name' => 'Partial Student',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollment = DB::table('enrollments')->insertGetId([
            'student_id' => $student,
            'campus_id' => $env['campus'],
            'department_id' => $env['deptSchool'],
            'currency' => 'SAR',
            'monthly_fee_minor' => 30000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $batch = 'BATCH-PARTIAL-TEST';

        // Staged Item 1 (300 SAR)
        $due1 = DB::table('qa_staged_dues')->insertGetId([
            'batch_id' => $batch,
            'source_row' => 101,
            'gr_number' => 'GR-PART-01',
            'class_in_dues_report' => 'Grade 1',
            'fee_particular' => 'Monthly Fee - Oct-2026',
            'currency' => 'SAR',
            'outstanding_amount' => 300.00,
            'outstanding_amount_minor' => 30000,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(HistoricalOpeningBalanceService::class);

        // Run 1: Post only Item 1
        $res1 = $service->postOpeningBalances('sqlite', $batch, true);
        $this->assertSame(1, $res1['posted_invoices']);
        $this->assertSame(1, $res1['posted_items']);

        $inv = DB::table('invoices')->where('family_id', $family)->first();
        $this->assertNotNull($inv);
        $this->assertSame(30000, (int) $inv->total_minor);
        $this->assertSame(1, DB::table('invoice_items')->where('invoice_id', $inv->id)->count());

        // Now add Item 2 to the same student/period (200 SAR)
        $due2 = DB::table('qa_staged_dues')->insertGetId([
            'batch_id' => $batch,
            'source_row' => 102,
            'gr_number' => 'GR-PART-01',
            'class_in_dues_report' => 'Grade 1',
            'fee_particular' => 'Monthly Fee - Oct-2026',
            'currency' => 'SAR',
            'outstanding_amount' => 200.00,
            'outstanding_amount_minor' => 20000,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run 2: Post batch again
        $res2 = $service->postOpeningBalances('sqlite', $batch, true);
        // Should attach item 2 to the EXISTING invoice without creating a new invoice!
        $this->assertSame(0, $res2['posted_invoices'], 'Must NOT create a duplicate parent invoice.');
        $this->assertSame(1, $res2['posted_items'], 'Must post the 1 newly eligible unposted item.');

        // Verify parent invoice totals updated to 300 + 200 = 500 SAR
        $invUpdated = DB::table('invoices')->where('id', $inv->id)->first();
        $this->assertSame(50000, (int) $invUpdated->total_minor, 'Parent invoice total must match sum of all active line items.');
        $this->assertSame(2, DB::table('invoice_items')->where('invoice_id', $inv->id)->count());

        // Run 3: Rerun when all items are posted
        $res3 = $service->postOpeningBalances('sqlite', $batch, true);
        $this->assertSame(0, $res3['posted_invoices']);
        $this->assertSame(0, $res3['posted_items']);
        $this->assertSame(50000, (int) DB::table('invoices')->where('id', $inv->id)->value('total_minor'));
    }

    /**
     * Test 3: Duplicate Monthly Fee Prevention with Opening Balance Obligations.
     */
    public function test_duplicate_monthly_fee_prevention_skips_represented_opening_balance_obligations(): void
    {
        $env = $this->setupBaseEnvironment();
        $feeTypeMonthly = (int) DB::table('fee_types')->where('code', 'monthly')->value('id');

        $family = DB::table('families')->insertGetId([
            'family_code' => 'FAM-DUP-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $student = DB::table('students')->insertGetId([
            'gr_number' => 'GR-DUP-01',
            'family_id' => $family,
            'name' => 'Dup Student',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollment = DB::table('enrollments')->insertGetId([
            'student_id' => $student,
            'campus_id' => $env['campus'],
            'department_id' => $env['deptSchool'],
            'currency' => 'SAR',
            'monthly_fee_minor' => 40000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $planItem = DB::table('fee_plan_items')->insertGetId([
            'enrollment_id' => $enrollment,
            'fee_type_id' => $feeTypeMonthly,
            'description' => 'Monthly Fee',
            'frequency' => 'monthly',
            'currency' => 'SAR',
            'amount_minor' => 40000,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Post an Opening Balance invoice covering October 2026 for this enrollment
        $batch = 'BATCH-DUP-OB';
        DB::table('qa_staged_dues')->insert([
            'batch_id' => $batch,
            'source_row' => 301,
            'gr_number' => 'GR-DUP-01',
            'class_in_dues_report' => 'Grade 1',
            'fee_particular' => 'Monthly Fee - Oct-2026',
            'currency' => 'SAR',
            'outstanding_amount' => 400.00,
            'outstanding_amount_minor' => 40000,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $obService = app(HistoricalOpeningBalanceService::class);
        $obService->postOpeningBalances('sqlite', $batch, true);

        $this->assertSame(1, DB::table('invoices')->where('family_id', $family)->count());
        $this->assertSame(1, DB::table('invoice_items')->count());

        // Now trigger monthly recurring fee generation for October 2026
        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $env['campus'],
            'department_id' => $env['deptSchool'],
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'created_by' => $env['user']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoiceService = app(InvoiceService::class);
        $monthlyResult = $invoiceService->generate($runId);

        // Must skip the student obligation because it is already represented in opening balance
        $this->assertSame(0, $monthlyResult['created'], 'Must not create a new invoice.');
        $this->assertSame(1, $monthlyResult['skipped'], 'Must skip the obligation already represented in opening balances.');
        $this->assertSame(1, DB::table('invoices')->where('family_id', $family)->count(), 'Total invoices must remain 1.');
        $this->assertSame(1, DB::table('invoice_items')->count(), 'Total items must remain 1.');
    }

    /**
     * Test 4: Unverified Enrollments & Review Hold Students Are Not Billable.
     */
    public function test_unverified_enrollments_or_currencies_are_not_billable(): void
    {
        $env = $this->setupBaseEnvironment();
        $feeTypeMonthly = (int) DB::table('fee_types')->where('code', 'monthly')->value('id');

        $family = DB::table('families')->insertGetId(['family_code' => 'FAM-HOLD-01', 'created_at' => now(), 'updated_at' => now()]);

        // Student 1: review_hold
        $s1 = DB::table('students')->insertGetId(['gr_number' => 'GR-HOLD-01', 'family_id' => $family, 'status' => 'review_hold', 'name' => 'Review Hold Student', 'created_at' => now(), 'updated_at' => now()]);
        $e1 = DB::table('enrollments')->insertGetId(['student_id' => $s1, 'campus_id' => $env['campus'], 'department_id' => $env['deptSchool'], 'currency' => 'SAR', 'monthly_fee_minor' => 30000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fee_plan_items')->insert(['enrollment_id' => $e1, 'fee_type_id' => $feeTypeMonthly, 'description' => 'Monthly Fee', 'frequency' => 'monthly', 'currency' => 'SAR', 'amount_minor' => 30000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        // Student 2: PENDING currency
        $s2 = DB::table('students')->insertGetId(['gr_number' => 'GR-HOLD-02', 'family_id' => $family, 'status' => 'present', 'name' => 'Pending Currency Student', 'created_at' => now(), 'updated_at' => now()]);
        $e2 = DB::table('enrollments')->insertGetId(['student_id' => $s2, 'campus_id' => $env['campus'], 'department_id' => $env['deptSchool'], 'currency' => 'PENDING', 'monthly_fee_minor' => 20000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fee_plan_items')->insert(['enrollment_id' => $e2, 'fee_type_id' => $feeTypeMonthly, 'description' => 'Monthly Fee', 'frequency' => 'monthly', 'currency' => 'PENDING', 'amount_minor' => 20000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        // Student 3: inactive enrollment
        $s3 = DB::table('students')->insertGetId(['gr_number' => 'GR-HOLD-03', 'family_id' => $family, 'status' => 'present', 'name' => 'Inactive Enrollment Student', 'created_at' => now(), 'updated_at' => now()]);
        $e3 = DB::table('enrollments')->insertGetId(['student_id' => $s3, 'campus_id' => $env['campus'], 'department_id' => $env['deptSchool'], 'currency' => 'SAR', 'monthly_fee_minor' => 25000, 'status' => 'pending_admission', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fee_plan_items')->insert(['enrollment_id' => $e3, 'fee_type_id' => $feeTypeMonthly, 'description' => 'Monthly Fee', 'frequency' => 'monthly', 'currency' => 'SAR', 'amount_minor' => 25000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $env['campus'],
            'department_id' => $env['deptSchool'],
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-25',
            'created_by' => $env['user']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoiceService = app(InvoiceService::class);
        $res = $invoiceService->generate($runId);

        $this->assertSame(0, $res['created']);
        $this->assertSame(0, DB::table('invoices')->where('family_id', $family)->count());
        $this->assertSame(0, DB::table('invoice_items')->count());
    }

    /**
     * Test 5: Multi-Currency Siblings and Department Separation.
     */
    public function test_multi_currency_siblings_and_department_separation(): void
    {
        $env = $this->setupBaseEnvironment();

        $family = DB::table('families')->insertGetId(['family_code' => 'FAM-MIX-01', 'created_at' => now(), 'updated_at' => now()]);

        // Sibling 1: School (SAR)
        $s1 = DB::table('students')->insertGetId(['gr_number' => 'GR-MIX-01', 'family_id' => $family, 'status' => 'present', 'name' => 'Sibling School SAR', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('enrollments')->insert(['student_id' => $s1, 'campus_id' => $env['campus'], 'department_id' => $env['deptSchool'], 'currency' => 'SAR', 'monthly_fee_minor' => 60000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        // Sibling 2: Tuition (AED)
        $s2 = DB::table('students')->insertGetId(['gr_number' => 'GR-MIX-02', 'family_id' => $family, 'status' => 'present', 'name' => 'Sibling Tuition AED', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('enrollments')->insert(['student_id' => $s2, 'campus_id' => $env['campus'], 'department_id' => $env['deptTuition'], 'currency' => 'AED', 'monthly_fee_minor' => 35000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $batch = 'BATCH-MIX-CURR';
        DB::table('qa_staged_dues')->insert([
            'batch_id' => $batch,
            'source_row' => 401,
            'gr_number' => 'GR-MIX-01',
            'class_in_dues_report' => 'Grade 5',
            'fee_particular' => 'Monthly Fee - Oct-2026',
            'currency' => 'SAR',
            'outstanding_amount' => 600.00,
            'outstanding_amount_minor' => 60000,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('qa_staged_dues')->insert([
            'batch_id' => $batch,
            'source_row' => 402,
            'gr_number' => 'GR-MIX-02',
            'class_in_dues_report' => 'Tuition G9 (Maths)',
            'fee_particular' => 'Monthly Fee - Oct-2026',
            'currency' => 'AED',
            'outstanding_amount' => 350.00,
            'outstanding_amount_minor' => 35000,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(HistoricalOpeningBalanceService::class);
        $preview = $service->preview('sqlite', $batch);

        $this->assertSame(2, $preview['summary']['eligible_rows']);
        $this->assertSame(2, $preview['summary']['invoices_to_create'], 'Mixed currencies and departments must produce separate invoices.');
        $this->assertEquals(600.00, (float) $preview['totals_by_currency']['SAR']['total_decimal']);
        $this->assertEquals(350.00, (float) $preview['totals_by_currency']['AED']['total_decimal']);

        $res = $service->postOpeningBalances('sqlite', $batch, true);
        $this->assertSame(2, $res['posted_invoices']);

        // Verify two separate invoices in database: one for School SAR, one for Tuition AED
        $invoices = DB::table('invoices')->where('family_id', $family)->get();
        $this->assertCount(2, $invoices);
        $this->assertTrue($invoices->contains('currency', 'SAR'));
        $this->assertTrue($invoices->contains('currency', 'AED'));
    }

    /**
     * Test 6: Unknown Fee Periods Are Held and Never Posted.
     */
    public function test_unknown_fee_periods_are_held_and_never_posted(): void
    {
        $env = $this->setupBaseEnvironment();

        $family = DB::table('families')->insertGetId(['family_code' => 'FAM-UNK-01', 'created_at' => now(), 'updated_at' => now()]);
        $student = DB::table('students')->insertGetId(['gr_number' => 'GR-UNK-01', 'family_id' => $family, 'status' => 'present', 'name' => 'Unknown Period Student', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('enrollments')->insert(['student_id' => $student, 'campus_id' => $env['campus'], 'department_id' => $env['deptSchool'], 'currency' => 'SAR', 'monthly_fee_minor' => 50000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $batch = 'BATCH-UNK-PERIOD';
        DB::table('qa_staged_dues')->insert([
            'batch_id' => $batch,
            'source_row' => 501,
            'gr_number' => 'GR-UNK-01',
            'class_in_dues_report' => 'Grade 8',
            'fee_particular' => 'Grade 8- (Chemistry) IGCSE / O level -', // Truncated/missing month & 4-digit year
            'currency' => 'SAR',
            'outstanding_amount' => 500.00,
            'outstanding_amount_minor' => 50000,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(HistoricalOpeningBalanceService::class);
        $preview = $service->preview('sqlite', $batch);

        $this->assertSame(0, $preview['summary']['eligible_rows']);
        $this->assertSame(1, $preview['exclusions']['unknown_period_count']);

        $res = $service->postOpeningBalances('sqlite', $batch, true);
        $this->assertSame(0, $res['posted_invoices']);
        $this->assertSame(0, $res['posted_items']);
        $this->assertSame(0, DB::table('invoices')->count());
    }

    /**
     * Test 7: No Hardcoded Admin Backdoor in Posting Engine.
     */
    public function test_no_hardcoded_admin_backdoor_in_posting(): void
    {
        $this->seed();
        // Ensure users table is completely empty
        DB::table('users')->delete();

        $campus = (int) DB::table('campuses')->value('id');
        $deptSchool = (int) DB::table('departments')->where('code', 'school')->value('id');
        $family = DB::table('families')->insertGetId(['family_code' => 'FAM-TEST-USER', 'created_at' => now(), 'updated_at' => now()]);
        $student = DB::table('students')->insertGetId(['gr_number' => 'GR-USER-01', 'family_id' => $family, 'status' => 'present', 'name' => 'User Check Student', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('enrollments')->insert(['student_id' => $student, 'campus_id' => $campus, 'department_id' => $deptSchool, 'currency' => 'SAR', 'monthly_fee_minor' => 30000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $batch = 'BATCH-USER-CHECK';
        DB::table('qa_staged_dues')->insert([
            'batch_id' => $batch,
            'source_row' => 999,
            'gr_number' => 'GR-USER-01',
            'class_in_dues_report' => 'Grade 1',
            'fee_particular' => 'Monthly Fee - Oct-2026',
            'currency' => 'SAR',
            'outstanding_amount' => 300.00,
            'outstanding_amount_minor' => 30000,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(HistoricalOpeningBalanceService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot post opening balances: No active system user found to record audit ownership.');

        // Attempting to post without an active user must fail, NOT create an admin with 'secret' password
        $service->postOpeningBalances('sqlite', $batch, true);
    }

    /**
     * Test 8: Controlled Reimport Service Verifies Workbook and Prepares 10-Step Plan.
     */
    public function test_controlled_reimport_service_verifies_workbook_and_generates_safe_plan(): void
    {
        $this->setupBaseEnvironment();

        $service = app(QaControlledReimportService::class);
        $plan = $service->plan('sqlite');

        $this->assertSame('Controlled Single QA Reimport Pipeline', $plan['pipeline_name']);
        $this->assertStringContainsString('LIVE DEPLOYMENT NOTICE', $plan['live_deployment_notice']);
        $this->assertCount(10, $plan['steps']);

        $step1 = $plan['steps']['1_verify_workbook'];
        $this->assertTrue($step1['valid']);
        $this->assertSame(QaControlledReimportService::EXPECTED_WORKBOOK_SHA256, $step1['sha256']);

        $step4 = $plan['steps']['4_test_ledger_reset_plan'];
        $this->assertTrue($step4['backup_verified']);
    }

    /**
     * Test 9: Payment Collection, Receipt, and Reversal Lifecycle.
     */
    public function test_payment_collection_receipt_and_reversal_lifecycle(): void
    {
        $env = $this->setupBaseEnvironment();

        $family = DB::table('families')->insertGetId(['family_code' => 'FAM-REV-01', 'created_at' => now(), 'updated_at' => now()]);
        $student = DB::table('students')->insertGetId(['gr_number' => 'GR-REV-01', 'family_id' => $family, 'status' => 'present', 'name' => 'Rev Student', 'created_at' => now(), 'updated_at' => now()]);
        $enrollment = DB::table('enrollments')->insertGetId(['student_id' => $student, 'campus_id' => $env['campus'], 'department_id' => $env['deptSchool'], 'currency' => 'SAR', 'monthly_fee_minor' => 50000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $batch = 'BATCH-REV-TEST';
        DB::table('qa_staged_dues')->insert([
            'batch_id' => $batch,
            'source_row' => 601,
            'gr_number' => 'GR-REV-01',
            'class_in_dues_report' => 'Grade 3',
            'fee_particular' => 'Monthly Fee - Oct-2026',
            'currency' => 'SAR',
            'outstanding_amount' => 500.00,
            'outstanding_amount_minor' => 50000,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $obService = app(HistoricalOpeningBalanceService::class);
        $obService->postOpeningBalances('sqlite', $batch, true);

        $inv = DB::table('invoices')->where('family_id', $family)->first();
        $this->assertSame(50000, (int) $inv->total_minor);
        $this->assertSame('generated', $inv->status);

        // Receive payment of 200 SAR
        $payService = app(ReceivePaymentService::class);
        $payment = $payService->receive([
            'family_id' => $family,
            'campus_id' => $env['campus'],
            'department_id' => $env['deptSchool'],
            'currency' => 'SAR',
            'receiving_amount' => '200.00',
            'idempotency_key' => (string) Str::uuid(),
        ], $env['user']->id);

        $this->assertSame(20000, (int) DB::table('invoices')->where('id', $inv->id)->value('paid_minor'));
        $this->assertSame('partial', DB::table('invoices')->where('id', $inv->id)->value('status'));

        // Reverse receipt
        $revService = app(ReceiptReversalService::class);
        $rev = $revService->reverseReceipt($payment->id, 'Testing receipt reversal lifecycle', $env['user']->id);

        $this->assertSame('reversed', $rev->status);
        $this->assertSame(0, (int) DB::table('invoices')->where('id', $inv->id)->value('paid_minor'));
        $this->assertSame('generated', DB::table('invoices')->where('id', $inv->id)->value('status'));
        $this->assertSame(0, DB::table('payment_allocations')->where('payment_id', $payment->id)->count());
    }
}
