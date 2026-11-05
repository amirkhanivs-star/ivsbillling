<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PaidInvoiceEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase2AllPeriodListingTest extends TestCase
{
    use RefreshDatabase;

    private function setupBillingEnvironment(): array
    {
        $this->seed();

        $campus1 = DB::table('campuses')->orderBy('id')->first()->id;
        $campus2 = DB::table('campuses')->where('id', '!=', $campus1)->value('id');
        if (!$campus2) {
            $campus2 = DB::table('campuses')->insertGetId(['name' => 'Campus Two', 'code' => 'campus_two', 'created_at' => now(), 'updated_at' => now()]);
        }
        $dept = DB::table('departments')->where('code', 'school')->value('id');
        $program = DB::table('programs')->value('id');

        $superAdmin = User::create([
            'name' => 'Super Admin Phase2',
            'email' => 'superadmin_phase2@example.test',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $cashierCampus1 = User::create([
            'name' => 'Cashier Campus 1',
            'email' => 'cashier_c1@example.test',
            'password' => bcrypt('password123'),
            'role' => 'cashier',
            'campus_id' => $campus1,
            'department_id' => $dept,
            'is_active' => true,
        ]);

        // Create billing runs for 2 distinct periods: September 2026 and October 2026
        $runSep = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus1,
            'department_id' => $dept,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-09-01..2026-09-30',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'due_date' => '2026-09-25',
            'created_by' => $superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runOct = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus1,
            'department_id' => $dept,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'created_by' => $superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('superAdmin', 'cashierCampus1', 'campus1', 'campus2', 'dept', 'program', 'runSep', 'runOct');
    }

    private function createInvoiceWithPayment(
        array $env,
        int $billingRunId,
        string $familyCode,
        string $invoiceNumber,
        string $status,
        int $amountMinor,
        int $paidMinor,
        ?string $receiptNumber = null,
        ?string $receivedOn = null,
        ?int $campusId = null
    ): array {
        $campus = $campusId ?? $env['campus1'];

        $famId = DB::table('families')->insertGetId([
            'family_code' => $familyCode,
            'guardian_name' => "Guardian {$familyCode}",
            'whatsapp' => '+96650' . rand(1000000, 9999999),
            'contact_verified' => true,
            'whatsapp_verified' => true,
            'messaging_opt_in' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stuId = DB::table('students')->insertGetId([
            'family_id' => $famId,
            'name' => "Student {$familyCode}",
            'gr_number' => 'GR-' . rand(1000, 9999),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrId = DB::table('enrollments')->insertGetId([
            'student_id' => $stuId,
            'campus_id' => $campus,
            'department_id' => $env['dept'],
            'program_id' => $env['program'],
            'grade_label' => 'Grade 1',
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invId = DB::table('invoices')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $campus,
            'department_id' => $env['dept'],
            'billing_run_id' => $billingRunId,
            'invoice_number' => $invoiceNumber,
            'currency' => 'SAR',
            'total_minor' => $amountMinor,
            'paid_minor' => $paidMinor,
            'status' => $status,
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'description' => 'Tuition Fee',
            'amount_minor' => $amountMinor,
            'net_minor' => $amountMinor,
            'paid_minor' => $paidMinor,
            'currency' => 'SAR',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paymentId = null;
        if ($receiptNumber && $receivedOn && $paidMinor > 0) {
            $paymentId = DB::table('payments')->insertGetId([
                'family_id' => $famId,
                'campus_id' => $campus,
                'department_id' => $env['dept'],
                'receipt_number' => $receiptNumber,
                'received_on' => $receivedOn,
                'amount_minor' => $paidMinor,
                'unallocated_minor' => 0,
                'currency' => 'SAR',
                'note' => 'Payment for ' . $invoiceNumber,
                'idempotency_key' => 'IDEMP-' . uniqid(),
                'request_hash' => hash('sha256', $receiptNumber . uniqid()),
                'received_by' => $env['superAdmin']->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('payment_allocations')->insert([
                'payment_id' => $paymentId,
                'invoice_item_id' => $itemId,
                'amount_minor' => $paidMinor,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return compact('famId', 'stuId', 'enrId', 'invId', 'itemId', 'paymentId');
    }

    /**
     * Requirement 1 & 2: period=all returns invoices across multiple periods; period=YYYY-MM remains month-specific.
     */
    public function test_period_all_returns_multi_period_records_while_month_filters_remain_specific(): void
    {
        $env = $this->setupBillingEnvironment();

        // September paid invoice (cutoff receipt date >= Oct 9, 2026)
        $sep = $this->createInvoiceWithPayment(
            $env, $env['runSep'], 'FAM-SEP-01', 'INV-SEP-001', 'paid', 20000, 20000,
            'RCP-SEP-001', '2026-10-15'
        );

        // October paid invoice
        $oct = $this->createInvoiceWithPayment(
            $env, $env['runOct'], 'FAM-OCT-01', 'INV-OCT-001', 'paid', 25000, 25000,
            'RCP-OCT-001', '2026-10-16'
        );

        $this->actingAs($env['superAdmin']);

        // Paid Tab: period=all must return both September and October invoices
        $paidAll = $this->get('/invoices?tab=paid&period=all')->assertOk();
        $paidAllIds = $paidAll->viewData('paidInvoicesPage')->pluck('id')->all();
        $this->assertContains($sep['invId'], $paidAllIds);
        $this->assertContains($oct['invId'], $paidAllIds);

        // Paid Tab: month-specific period=2026-09 returns only September (not October)
        $paidSep = $this->get('/invoices?tab=paid&period=2026-09')->assertOk();
        $paidSepIds = $paidSep->viewData('paidInvoicesPage')->pluck('id')->all();
        $this->assertContains($sep['invId'], $paidSepIds);
        $this->assertNotContains($oct['invId'], $paidSepIds);

        // Financial History Tab: period=all returns both
        $histAll = $this->get('/invoices?tab=history&period=all')->assertOk();
        $histAllIds = $histAll->viewData('financialHistoryPage')->pluck('id')->all();
        $this->assertContains($sep['invId'], $histAllIds);
        $this->assertContains($oct['invId'], $histAllIds);

        // Financial History Tab: period=2026-09 returns only September
        $histSep = $this->get('/invoices?tab=history&period=2026-09')->assertOk();
        $histSepIds = $histSep->viewData('financialHistoryPage')->pluck('id')->all();
        $this->assertContains($sep['invId'], $histSepIds);
        $this->assertNotContains($oct['invId'], $histSepIds);
    }

    /**
     * Requirement 3: Paid invoice eligibility is strictly preserved (unpaid and pre-cutoff without portal are excluded).
     */
    public function test_paid_invoice_eligibility_preserved_under_period_all(): void
    {
        $env = $this->setupBillingEnvironment();

        // 1. Fully paid with eligible receipt (received >= Oct 9, 2026) -> should be included
        $eligible = $this->createInvoiceWithPayment(
            $env, $env['runSep'], 'FAM-ELIG', 'INV-ELIG-001', 'paid', 30000, 30000,
            'RCP-ELIG-001', '2026-10-12'
        );

        // 2. Unpaid invoice -> should be excluded from Paid Tab
        $unpaid = $this->createInvoiceWithPayment(
            $env, $env['runSep'], 'FAM-UNPAID', 'INV-UNPAID-001', 'unpaid', 30000, 0
        );

        // 3. Partially paid invoice -> should be excluded from Paid Tab
        $partial = $this->createInvoiceWithPayment(
            $env, $env['runSep'], 'FAM-PART', 'INV-PART-001', 'partial', 30000, 15000,
            'RCP-PART-001', '2026-10-12'
        );

        // 4. Paid invoice with historical receipt BEFORE cutoff (e.g. 2026-10-01) without portal provenance -> excluded
        $preCutoff = $this->createInvoiceWithPayment(
            $env, $env['runSep'], 'FAM-OLD', 'INV-OLD-001', 'paid', 30000, 30000,
            'RCP-OLD-001', '2026-10-01'
        );

        $this->actingAs($env['superAdmin']);
        $resp = $this->get('/invoices?tab=paid&period=all')->assertOk();
        $ids = $resp->viewData('paidInvoicesPage')->pluck('id')->all();

        $this->assertContains($eligible['invId'], $ids);
        $this->assertNotContains($unpaid['invId'], $ids);
        $this->assertNotContains($partial['invId'], $ids);
        $this->assertNotContains($preCutoff['invId'], $ids);
    }

    /**
     * Requirement 4: Role and campus scoping permissions are strictly enforced under period=all.
     */
    public function test_campus_and_role_permissions_enforced_under_period_all(): void
    {
        $env = $this->setupBillingEnvironment();

        // Invoice on Campus 1
        $invC1 = $this->createInvoiceWithPayment(
            $env, $env['runSep'], 'FAM-C1', 'INV-C1-001', 'paid', 20000, 20000,
            'RCP-C1-001', '2026-10-14', $env['campus1']
        );

        // Invoice on Campus 2
        $invC2 = $this->createInvoiceWithPayment(
            $env, $env['runSep'], 'FAM-C2', 'INV-C2-001', 'paid', 20000, 20000,
            'RCP-C2-001', '2026-10-14', $env['campus2']
        );

        // Cashier scoped strictly to Campus 1
        $this->actingAs($env['cashierCampus1']);

        // Paid tab
        $paid = $this->get('/invoices?tab=paid&period=all')->assertOk();
        $paidIds = $paid->viewData('paidInvoicesPage')->pluck('id')->all();
        $this->assertContains($invC1['invId'], $paidIds);
        $this->assertNotContains($invC2['invId'], $paidIds);

        // History tab
        $hist = $this->get('/invoices?tab=history&period=all')->assertOk();
        $histIds = $hist->viewData('financialHistoryPage')->pluck('id')->all();
        $this->assertContains($invC1['invId'], $histIds);
        $this->assertNotContains($invC2['invId'], $histIds);
    }

    /**
     * Requirement 5 & 6: Canonical invoice and receipt identity preservation (no duplicate invoice identities or payment amounts).
     */
    public function test_financial_history_and_paid_listings_preserve_canonical_identities_without_duplication(): void
    {
        $env = $this->setupBillingEnvironment();

        // Invoice with 2 separate line items and 2 distinct receipts
        $famId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-MULTI-RCP',
            'guardian_name' => 'Guardian Multi Receipt',
            'whatsapp' => '+966501234567',
            'contact_verified' => true,
            'whatsapp_verified' => true,
            'messaging_opt_in' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $stuId = DB::table('students')->insertGetId([
            'family_id' => $famId,
            'name' => 'Student Multi',
            'gr_number' => 'GR-MULTI',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $enrId = DB::table('enrollments')->insertGetId([
            'student_id' => $stuId,
            'campus_id' => $env['campus1'],
            'department_id' => $env['dept'],
            'program_id' => $env['program'],
            'grade_label' => 'Grade 1',
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $invId = DB::table('invoices')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $env['campus1'],
            'department_id' => $env['dept'],
            'billing_run_id' => $env['runSep'],
            'invoice_number' => 'INV-MULTI-001',
            'currency' => 'SAR',
            'total_minor' => 50000,
            'paid_minor' => 50000,
            'status' => 'paid',
            'issued_on' => '2026-09-01',
            'due_date' => '2026-09-25',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $item1 = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'description' => 'Tuition Part 1',
            'amount_minor' => 25000,
            'net_minor' => 25000,
            'paid_minor' => 25000,
            'currency' => 'SAR',
            'period_key' => '2026-09',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $item2 = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'description' => 'Tuition Part 2',
            'amount_minor' => 25000,
            'net_minor' => 25000,
            'paid_minor' => 25000,
            'currency' => 'SAR',
            'period_key' => '2026-09',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Payment 1
        $p1 = DB::table('payments')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $env['campus1'],
            'department_id' => $env['dept'],
            'receipt_number' => 'RCP-M-001',
            'received_on' => '2026-10-10',
            'amount_minor' => 25000,
            'unallocated_minor' => 0,
            'currency' => 'SAR',
            'note' => 'Part 1',
            'idempotency_key' => 'IDEMP-M1',
            'request_hash' => hash('sha256', 'M1'),
            'received_by' => $env['superAdmin']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $p1,
            'invoice_item_id' => $item1,
            'amount_minor' => 25000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Payment 2
        $p2 = DB::table('payments')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $env['campus1'],
            'department_id' => $env['dept'],
            'receipt_number' => 'RCP-M-002',
            'received_on' => '2026-10-12',
            'amount_minor' => 25000,
            'unallocated_minor' => 0,
            'currency' => 'SAR',
            'note' => 'Part 2',
            'idempotency_key' => 'IDEMP-M2',
            'request_hash' => hash('sha256', 'M2'),
            'received_by' => $env['superAdmin']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $p2,
            'invoice_item_id' => $item2,
            'amount_minor' => 25000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($env['superAdmin']);

        // In Financial History: invoice must appear exactly ONCE
        $hist = $this->get('/invoices?tab=history&period=all')->assertOk();
        $historyRows = $hist->viewData('financialHistoryPage')->items();
        $matchingHistory = array_filter($historyRows, fn ($r) => $r->id === $invId);
        $this->assertCount(1, $matchingHistory);

        // In Paid Invoices tab: invoice appears once and has 2 distinct receipts with their canonical payment IDs
        $paid = $this->get('/invoices?tab=paid&period=all')->assertOk();
        $paidRows = $paid->viewData('paidInvoicesPage')->items();
        $matchingPaid = array_filter($paidRows, fn ($r) => $r->id === $invId);
        $this->assertCount(1, $matchingPaid);

        $row = reset($matchingPaid);
        $this->assertCount(2, $row->receipts);
        $receiptPaymentIds = array_column($row->receipts, 'payment_id');
        $this->assertContains($p1, $receiptPaymentIds);
        $this->assertContains($p2, $receiptPaymentIds);
        $this->assertSame(25000, $row->receipts[0]['amount_minor']);
        $this->assertSame(25000, $row->receipts[1]['amount_minor']);
    }

    /**
     * Requirement 7, 8 & 9: Batch selection isolation, zero financial writes, and valid dashboard month counters.
     */
    public function test_batch_delivery_isolation_zero_writes_and_valid_month_counters(): void
    {
        $env = $this->setupBillingEnvironment();

        $sep = $this->createInvoiceWithPayment(
            $env, $env['runSep'], 'FAM-ISOL-SEP', 'INV-ISOL-001', 'paid', 20000, 20000,
            'RCP-ISOL-001', '2026-10-15'
        );
        $oct = $this->createInvoiceWithPayment(
            $env, $env['runOct'], 'FAM-ISOL-OCT', 'INV-ISOL-002', 'paid', 25000, 25000,
            'RCP-ISOL-002', '2026-10-16'
        );

        $this->actingAs($env['superAdmin']);

        // Snapshot DB records counts before request
        $invCountBefore = DB::table('invoices')->count();
        $payCountBefore = DB::table('payments')->count();
        $allocCountBefore = DB::table('payment_allocations')->count();
        $runsCountBefore = DB::table('billing_runs')->count();
        $notifsCountBefore = DB::table('notifications')->count();

        // Perform GET request with period=all on Paid tab
        $resp = $this->get('/invoices?tab=paid&period=all')->assertOk();

        // Zero writes verification:
        $this->assertSame($invCountBefore, DB::table('invoices')->count());
        $this->assertSame($payCountBefore, DB::table('payments')->count());
        $this->assertSame($allocCountBefore, DB::table('payment_allocations')->count());
        $this->assertSame($runsCountBefore, DB::table('billing_runs')->count());
        $this->assertSame($notifsCountBefore, DB::table('notifications')->count());

        // Dashboard counters & period verification:
        // selectedPeriod must remain a valid YYYY-MM month, NOT 'all'
        $selectedPeriod = $resp->viewData('selectedPeriod');
        $this->assertNotSame('all', $selectedPeriod);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $selectedPeriod);

        // Dashboard stats received valid month (e.g. selected_month contains month name)
        $dashboardStats = $resp->viewData('dashboardStats');
        $this->assertIsArray($dashboardStats);
        $this->assertArrayHasKey('selected_month', $dashboardStats);
        $this->assertArrayHasKey('selected_period', $dashboardStats);
        $this->assertNotSame('all', $dashboardStats['selected_period']);

        // Batch selection snapshot must NOT have evaluated for period=all
        $selectionSnapshot = $resp->viewData('selectionSnapshot');
        if ($selectionSnapshot !== null) {
            // Snapshot filters must not have period = 'all'
            $this->assertNotSame('all', $selectionSnapshot['filters']['period'] ?? null);
        }
    }

    /**
     * Requirement 10: Verify all 15 invoice routes remain unchanged.
     */
    public function test_all_fifteen_invoice_routes_remain_intact(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'invoices') || str_contains($r->uri(), 'invoices'))
            ->map(fn ($r) => implode('|', $r->methods()) . ' ' . $r->uri())
            ->values();

        $this->assertCount(15, $routes);
    }
}
