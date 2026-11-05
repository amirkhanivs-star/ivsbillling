<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InvoiceDeliveryQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase3BUnifiedReadyToSendListingTest extends TestCase
{
    use RefreshDatabase;

    private function setupEnvironment(): array
    {
        $this->seed();

        $campus1 = DB::table('campuses')->orderBy('id')->first()->id;
        $campus2 = DB::table('campuses')->where('id', '!=', $campus1)->value('id');
        if (!$campus2) {
            $campus2 = DB::table('campuses')->insertGetId([
                'name' => 'Campus Two',
                'code' => 'campus_two',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $deptSchool = DB::table('departments')->where('code', 'school')->value('id');
        $program = DB::table('programs')->value('id');

        $superAdmin = User::create([
            'name' => 'Super Admin Phase3B',
            'email' => 'superadmin_phase3b@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $cashierCampus1 = User::create([
            'name' => 'Cashier Campus 1',
            'email' => 'cashier_c1_phase3b@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'cashier',
            'campus_id' => $campus1,
            'department_id' => $deptSchool,
            'is_active' => true,
        ]);

        // Create 3 distinct monthly billing runs: Oct 2026, Nov 2026, Dec 2026
        $runOct = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus1,
            'department_id' => $deptSchool,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'created_by' => $superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runNov = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus1,
            'department_id' => $deptSchool,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-25',
            'created_by' => $superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runDec = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus1,
            'department_id' => $deptSchool,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-12-01..2026-12-31',
            'period_start' => '2026-12-01',
            'period_end' => '2026-12-31',
            'due_date' => '2026-12-25',
            'created_by' => $superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('superAdmin', 'cashierCampus1', 'campus1', 'campus2', 'deptSchool', 'program', 'runOct', 'runNov', 'runDec');
    }

    private function createFamilyWithInvoice(
        array $env,
        int $billingRunId,
        string $familyCode,
        string $invoiceNumber,
        int $amountMinor,
        int $paidMinor = 0,
        string $currency = 'SAR',
        ?int $campusId = null,
        ?int $existingFamilyId = null,
        ?int $existingStudentId = null,
        string $periodMonth = '2026-10'
    ): array {
        $campus = $campusId ?? $env['campus1'];

        $famId = $existingFamilyId ?? DB::table('families')->insertGetId([
            'family_code' => $familyCode,
            'guardian_name' => "Guardian {$familyCode}",
            'whatsapp' => '+96650' . rand(1000000, 9999999),
            'contact_verified' => true,
            'whatsapp_verified' => true,
            'messaging_opt_in' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stuId = $existingStudentId ?? DB::table('students')->insertGetId([
            'family_id' => $famId,
            'name' => "Student {$familyCode}",
            'gr_number' => 'GR-' . rand(1000, 9999),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrId = DB::table('enrollments')->insertGetId([
            'student_id' => $stuId,
            'campus_id' => $campus,
            'department_id' => $env['deptSchool'],
            'program_id' => $env['program'],
            'grade_label' => 'Grade 1',
            'currency' => $currency,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $status = ($paidMinor >= $amountMinor) ? 'paid' : (($paidMinor > 0) ? 'partial' : 'unpaid');

        $invId = DB::table('invoices')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $campus,
            'department_id' => $env['deptSchool'],
            'billing_run_id' => $billingRunId,
            'invoice_number' => $invoiceNumber,
            'currency' => $currency,
            'total_minor' => $amountMinor,
            'paid_minor' => $paidMinor,
            'status' => $status,
            'issued_on' => "{$periodMonth}-01",
            'due_date' => "{$periodMonth}-25",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'description' => "Tuition Fee {$periodMonth}",
            'amount_minor' => $amountMinor,
            'net_minor' => $amountMinor,
            'paid_minor' => $paidMinor,
            'currency' => $currency,
            'period_key' => $periodMonth,
            'period_start' => "{$periodMonth}-01",
            'period_end' => "{$periodMonth}-28",
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('famId', 'stuId', 'enrId', 'invId', 'itemId');
    }

    /**
     * A. Family Uniqueness:
     * A family with genuine invoices covering three months appears exactly once in the all-period Ready to Send results.
     */
    public function test_a_family_uniqueness_under_period_all(): void
    {
        $env = $this->setupEnvironment();

        // Create 1 family with invoices in Oct, Nov, and Dec
        $oct = $this->createFamilyWithInvoice($env, $env['runOct'], 'FAM-3M', 'INV-OCT-3M', 100000, 0, 'SAR', null, null, null, '2026-10');
        $nov = $this->createFamilyWithInvoice($env, $env['runNov'], 'FAM-3M', 'INV-NOV-3M', 100000, 0, 'SAR', null, $oct['famId'], $oct['stuId'], '2026-11');
        $dec = $this->createFamilyWithInvoice($env, $env['runDec'], 'FAM-3M', 'INV-DEC-3M', 80000, 0, 'SAR', null, $oct['famId'], $oct['stuId'], '2026-12');

        $this->actingAs($env['superAdmin']);

        $res = $this->get('/invoices?tab=ready&period=all')->assertOk();
        $paginator = $res->viewData('readyChallans');

        // Total unique families is exactly 1
        $this->assertSame(1, $paginator->total());
        $this->assertCount(1, $paginator->items());

        $row = $paginator->items()[0];
        $this->assertEquals($oct['famId'], $row->family_id);
        $this->assertEquals('FAM-3M', $row->family_code);

        // Original invoice references are preserved
        $this->assertCount(3, $row->invoice_ids);
        $this->assertContains($oct['invId'], $row->invoice_ids);
        $this->assertContains($nov['invId'], $row->invoice_ids);
        $this->assertContains($dec['invId'], $row->invoice_ids);

        $this->assertCount(3, $row->invoice_numbers);
        $this->assertContains('INV-OCT-3M', $row->invoice_numbers);
        $this->assertContains('INV-NOV-3M', $row->invoice_numbers);
        $this->assertContains('INV-DEC-3M', $row->invoice_numbers);
    }

    /**
     * B. Consolidated Totals:
     * The displayed outstanding balance equals the authoritative item-level outstanding amount.
     */
    public function test_b_consolidated_totals_reconcile_with_authoritative_item_balances(): void
    {
        $env = $this->setupEnvironment();

        // 1000 + 1000 + 800 = SAR 2,800 (280,000 minor)
        $oct = $this->createFamilyWithInvoice($env, $env['runOct'], 'FAM-BAL', 'INV-OCT-BAL', 100000, 0, 'SAR', null, null, null, '2026-10');
        $nov = $this->createFamilyWithInvoice($env, $env['runNov'], 'FAM-BAL', 'INV-NOV-BAL', 100000, 0, 'SAR', null, $oct['famId'], $oct['stuId'], '2026-11');
        $dec = $this->createFamilyWithInvoice($env, $env['runDec'], 'FAM-BAL', 'INV-DEC-BAL', 80000, 0, 'SAR', null, $oct['famId'], $oct['stuId'], '2026-12');

        $this->actingAs($env['superAdmin']);

        $res = $this->get('/invoices?tab=ready&period=all')->assertOk();
        $row = $res->viewData('readyChallans')->items()[0];

        // Consolidated balance: 280,000 minor (SAR 2,800.00)
        $this->assertSame(280000, $row->total_payable_minor);
        $this->assertSame('SAR 2,800.00', $row->formatted_total_payable);

        // Covered fee months visible in dues_by_month
        $months = array_column($row->dues_by_month, 'month');
        $this->assertContains('2026-10', $months);
        $this->assertContains('2026-11', $months);
        $this->assertContains('2026-12', $months);

        $monthDuesMap = [];
        foreach ($row->dues_by_month as $mEntry) {
            $monthDuesMap[$mEntry['month']] = $mEntry['dues_minor'];
        }
        $this->assertSame(100000, $monthDuesMap['2026-10']);
        $this->assertSame(100000, $monthDuesMap['2026-11']);
        $this->assertSame(80000, $monthDuesMap['2026-12']);
    }

    /**
     * C. Original Invoice Identities:
     * All original monthly invoices remain present in Financial Invoice History without loss.
     */
    public function test_c_original_invoice_identities_preserved_in_financial_history(): void
    {
        $env = $this->setupEnvironment();

        $oct = $this->createFamilyWithInvoice($env, $env['runOct'], 'FAM-HIST', 'INV-OCT-HIST', 100000, 0, 'SAR', null, null, null, '2026-10');
        $nov = $this->createFamilyWithInvoice($env, $env['runNov'], 'FAM-HIST', 'INV-NOV-HIST', 100000, 0, 'SAR', null, $oct['famId'], $oct['stuId'], '2026-11');
        $dec = $this->createFamilyWithInvoice($env, $env['runDec'], 'FAM-HIST', 'INV-DEC-HIST', 80000, 0, 'SAR', null, $oct['famId'], $oct['stuId'], '2026-12');

        $this->actingAs($env['superAdmin']);

        $res = $this->get('/invoices?tab=history&period=all')->assertOk();
        $historyIds = $res->viewData('financialHistoryPage')->pluck('id')->all();

        $this->assertContains($oct['invId'], $historyIds);
        $this->assertContains($nov['invId'], $historyIds);
        $this->assertContains($dec['invId'], $historyIds);
    }

    /**
     * D. Currency Isolation:
     * Different currencies are never incorrectly summed into one combined number.
     */
    public function test_d_currency_isolation_under_period_all(): void
    {
        $env = $this->setupEnvironment();

        // 1 invoice in SAR and 1 invoice in AED for the same family
        $sar = $this->createFamilyWithInvoice($env, $env['runOct'], 'FAM-MC', 'INV-SAR-01', 50000, 0, 'SAR', null, null, null, '2026-10');
        $aed = $this->createFamilyWithInvoice($env, $env['runNov'], 'FAM-MC', 'INV-AED-01', 40000, 0, 'AED', null, $sar['famId'], $sar['stuId'], '2026-11');

        $this->actingAs($env['superAdmin']);

        $res = $this->get('/invoices?tab=ready&period=all')->assertOk();
        $row = $res->viewData('readyChallans')->items()[0];

        $this->assertTrue($row->is_multi_currency);
        $this->assertCount(2, $row->currencies);

        $currMap = $row->currencies_map;
        $this->assertArrayHasKey('SAR', $currMap);
        $this->assertArrayHasKey('AED', $currMap);

        $this->assertSame(50000, $currMap['SAR']['total_payable_minor']);
        $this->assertSame(40000, $currMap['AED']['total_payable_minor']);
        $this->assertSame('SAR 500.00', $currMap['SAR']['formatted_total_payable']);
        $this->assertSame('AED 400.00', $currMap['AED']['formatted_total_payable']);
    }

    /**
     * E. Campus and Department Permissions:
     * Restricted users cannot access unauthorized invoice items or balances through global family aggregation.
     */
    public function test_e_campus_and_department_permissions_enforced_under_period_all(): void
    {
        $env = $this->setupEnvironment();

        // Sibling 1 on Campus 1 (authorized for Cashier C1)
        $fam = $this->createFamilyWithInvoice($env, $env['runOct'], 'FAM-SCOPE', 'INV-C1-01', 60000, 0, 'SAR', $env['campus1'], null, null, '2026-10');

        // Sibling 2 on Campus 2 (UNAUTHORIZED for Cashier C1)
        $c2StuId = DB::table('students')->insertGetId([
            'family_id' => $fam['famId'],
            'name' => 'Sibling C2',
            'gr_number' => 'GR-C2-99',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $c2EnrId = DB::table('enrollments')->insertGetId([
            'student_id' => $c2StuId,
            'campus_id' => $env['campus2'],
            'department_id' => $env['deptSchool'],
            'program_id' => $env['program'],
            'grade_label' => 'Grade 2',
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $c2InvId = DB::table('invoices')->insertGetId([
            'family_id' => $fam['famId'],
            'campus_id' => $env['campus2'],
            'department_id' => $env['deptSchool'],
            'billing_run_id' => $env['runNov'],
            'invoice_number' => 'INV-C2-01',
            'currency' => 'SAR',
            'total_minor' => 70000,
            'paid_minor' => 0,
            'status' => 'unpaid',
            'issued_on' => '2026-11-01',
            'due_date' => '2026-11-25',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => $c2InvId,
            'student_id' => $c2StuId,
            'enrollment_id' => $c2EnrId,
            'description' => 'Tuition C2',
            'amount_minor' => 70000,
            'net_minor' => 70000,
            'paid_minor' => 0,
            'currency' => 'SAR',
            'period_key' => '2026-11',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cashier on Campus 1 requests all-period Ready
        $this->actingAs($env['cashierCampus1']);

        $res = $this->get('/invoices?tab=ready&period=all')->assertOk();
        $items = $res->viewData('readyChallans')->items();
        $this->assertCount(1, $items);

        $row = $items[0];
        // Cashier sees ONLY Campus 1's balance: SAR 600.00, NOT SAR 1,300.00
        $this->assertSame(60000, $row->total_payable_minor);
        $this->assertSame('SAR 600.00', $row->formatted_total_payable);
        $this->assertNotContains($c2InvId, $row->invoice_ids);
        $this->assertNotContains('INV-C2-01', $row->invoice_numbers);
    }

    /**
     * F. Pagination:
     * Families appear once across all result pages, with stable ordering and correct totals.
     */
    public function test_f_pagination_stability_and_uniqueness_across_pages(): void
    {
        $env = $this->setupEnvironment();

        // Create 3 families, each having 2 monthly invoices
        for ($i = 1; $i <= 3; $i++) {
            $f = $this->createFamilyWithInvoice($env, $env['runOct'], "FAM-PAG-{$i}", "INV-OCT-P{$i}", 30000, 0, 'SAR', null, null, null, '2026-10');
            $this->createFamilyWithInvoice($env, $env['runNov'], "FAM-PAG-{$i}", "INV-NOV-P{$i}", 30000, 0, 'SAR', null, $f['famId'], $f['stuId'], '2026-11');
        }

        $queueService = app(InvoiceDeliveryQueueService::class);

        // Page 1 with perPage = 2
        \Illuminate\Pagination\Paginator::currentPageResolver(fn() => 1);
        $p1 = $queueService->getReadyToSendChallans(['period' => 'all'], $env['superAdmin'], 2);
        $p1Families = collect($p1->items())->pluck('family_id')->all();
        $this->assertCount(2, $p1Families);

        // Page 2 with perPage = 2
        \Illuminate\Pagination\Paginator::currentPageResolver(fn() => 2);
        $p2 = $queueService->getReadyToSendChallans(['period' => 'all'], $env['superAdmin'], 2);
        $p2Families = collect($p2->items())->pluck('family_id')->all();
        $this->assertCount(1, $p2Families);

        // Disjoint sets across pages
        $intersect = array_intersect($p1Families, $p2Families);
        $this->assertEmpty($intersect);

        // All 3 families accounted for exactly once
        $combined = array_merge($p1Families, $p2Families);
        $this->assertCount(3, array_unique($combined));
        $this->assertSame(3, $p1->total());

        // Stable deterministic ordering: family_id descending
        $this->assertGreaterThan($p1Families[1], $p1Families[0]);
        $this->assertGreaterThan($p2Families[0], $p1Families[1]);

        // Reset resolver
        \Illuminate\Pagination\Paginator::currentPageResolver(function ($pageName = 'page') {
            return request()->input($pageName, 1);
        });
    }

    /**
     * G. Existing Monthly Behavior:
     * period=YYYY-MM retains previous Ready to Send results and selection behavior.
     */
    public function test_g_existing_monthly_behavior_retained(): void
    {
        $env = $this->setupEnvironment();

        // Family A has Oct invoice
        $famA = $this->createFamilyWithInvoice($env, $env['runOct'], 'FAM-MO-A', 'INV-MO-A', 50000, 0, 'SAR', null, null, null, '2026-10');
        // Family B has Nov invoice
        $famB = $this->createFamilyWithInvoice($env, $env['runNov'], 'FAM-MO-B', 'INV-MO-B', 50000, 0, 'SAR', null, null, null, '2026-11');

        $this->actingAs($env['superAdmin']);

        // Month specific: period=2026-10 returns only Family A
        $resOct = $this->get('/invoices?tab=ready&period=2026-10')->assertOk();
        $octFamilies = $resOct->viewData('readyChallans')->pluck('family_id')->all();
        $this->assertContains($famA['famId'], $octFamilies);
        $this->assertNotContains($famB['famId'], $octFamilies);

        // Month specific has active selectionSnapshot
        $snapshotOct = $resOct->viewData('selectionSnapshot');
        $this->assertNotNull($snapshotOct);
        $this->assertSame('2026-10', $resOct->viewData('selectedPeriod'));

        // Month specific: period=2026-11 returns only Family B
        $resNov = $this->get('/invoices?tab=ready&period=2026-11')->assertOk();
        $novFamilies = $resNov->viewData('readyChallans')->pluck('family_id')->all();
        $this->assertContains($famB['famId'], $novFamilies);
        $this->assertNotContains($famA['famId'], $novFamilies);
    }

    /**
     * H. Dispatch Isolation:
     * period=all cannot initiate a batch send or bypass existing month-specific selection rules.
     */
    public function test_h_dispatch_isolation_and_readonly_state_under_period_all(): void
    {
        $env = $this->setupEnvironment();

        $this->createFamilyWithInvoice($env, $env['runOct'], 'FAM-ISO', 'INV-ISO-01', 50000, 0, 'SAR', null, null, null, '2026-10');

        $this->actingAs($env['superAdmin']);

        $res = $this->get('/invoices?tab=ready&period=all')->assertOk();

        // In period=all, selectionSnapshot must be null
        $this->assertNull($res->viewData('selectionSnapshot'));

        // Response contains read-only banner
        $res->assertSee('Ready to Send — All Billing Periods (Read-Only Inspection Mode)');
        $res->assertSee('Read-Only Mode');

        // Form action for sending batch is NOT exposed in all-period mode
        $res->assertDontSee('action="/invoices/send-batch" id="iq-send-form"');
    }

    /**
     * I. Phase 3A Preservation:
     * Cross-month initial batch protection, retries, and existing monthly sending continue working.
     */
    public function test_i_phase_3a_protection_preserved(): void
    {
        $env = $this->setupEnvironment();

        $fam = $this->createFamilyWithInvoice($env, $env['runOct'], 'FAM-3A', 'INV-3A-OCT', 40000, 0, 'SAR', null, null, null, '2026-10');

        // Queue notification in batch for Oct
        $batchId = 'BATCH-3A-TEST-' . uniqid();
        DB::table('notification_batches')->insert([
            'batch_id' => $batchId,
            'webhook_type' => 'monthly',
            'event_type' => 'monthly.challan.batch',
            'billing_period' => '2026-10',
            'channels' => json_encode(['whatsapp']),
            'status' => 'queued',
            'total_recipients' => 1,
            'approved_by' => $env['superAdmin']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('notifications')->insert([
            'batch_id' => $batchId,
            'invoice_id' => $fam['invId'],
            'channel' => 'whatsapp',
            'kind' => 'monthly',
            'webhook_type' => 'monthly',
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $batchService = app(\App\Services\BatchDeliveryService::class);

        // November selection should exclude this family because Oct is pending
        $this->createFamilyWithInvoice($env, $env['runNov'], 'FAM-3A', 'INV-3A-NOV', 40000, 0, 'SAR', null, $fam['famId'], $fam['stuId'], '2026-11');
        $novSelection = $batchService->resolveFilteredSelection(['period' => '2026-11'], $env['superAdmin'], ['whatsapp']);

        $this->assertSame(0, $novSelection['total_eligible_recipients']);
        $this->assertArrayHasKey('already_queued', $novSelection['excluded_breakdown']);
        $this->assertSame(1, $novSelection['excluded_breakdown']['already_queued']);
    }

    /**
     * J. Route Integrity:
     * All 15 invoice-related routes remain unchanged.
     */
    public function test_j_all_fifteen_invoice_routes_remain_intact(): void
    {
        $expectedUris = [
            'challan/inspect-myskool-item/{id}',
            'invoices',
            'invoices/batches/{batch}',
            'invoices/batches/{batch}/retry-failed',
            'invoices/notifications/process-queue',
            'invoices/notifications/{notification}/retry',
            'invoices/paid/send-queue',
            'invoices/queue',
            'invoices/queue/export',
            'invoices/selection-snapshot',
            'invoices/send',
            'invoices/send-batch',
            'invoices/{invoice}/pdf',
            'public/challans/{family}/{token}/Monthly-Fee-Invoice.pdf',
            'public/families/{family}/challan/Monthly-Fee-Invoice.pdf',
            'public/invoices/{invoice}/Monthly-Fee-Invoice.pdf',
            'public/invoices/{invoice}/pdf',
            'public/invoices/{invoice}/{token}/Monthly-Fee-Invoice.pdf',
        ];

        $routeCollection = \Illuminate\Support\Facades\Route::getRoutes();
        $matchedUris = [];
        foreach ($routeCollection as $route) {
            $uri = $route->uri();
            if (str_contains($uri, 'invoice') || str_contains($uri, 'challan')) {
                $matchedUris[] = $uri;
            }
        }
        $matchedUris = array_values(array_unique($matchedUris));

        foreach ($expectedUris as $expected) {
            $this->assertContains($expected, $matchedUris, "Expected route URI [{$expected}] not found.");
        }
    }

    /**
     * K. Database Integrity:
     * Read-only requests do not modify invoices, payments, allocations, notifications, or billing runs.
     */
    public function test_k_database_integrity_zero_financial_or_queue_mutations(): void
    {
        $env = $this->setupEnvironment();

        $f = $this->createFamilyWithInvoice($env, $env['runOct'], 'FAM-MUT', 'INV-MUT-01', 50000, 0, 'SAR', null, null, null, '2026-10');
        $this->createFamilyWithInvoice($env, $env['runNov'], 'FAM-MUT', 'INV-MUT-02', 50000, 0, 'SAR', null, $f['famId'], $f['stuId'], '2026-11');

        $this->actingAs($env['superAdmin']);

        $invBefore = DB::table('invoices')->count();
        $payBefore = DB::table('payments')->count();
        $allocBefore = DB::table('payment_allocations')->count();
        $notifBefore = DB::table('notifications')->count();
        $batchBefore = DB::table('notification_batches')->count();
        $runsBefore = DB::table('billing_runs')->count();

        $this->get('/invoices?tab=ready&period=all')->assertOk();

        $this->assertSame($invBefore, DB::table('invoices')->count());
        $this->assertSame($payBefore, DB::table('payments')->count());
        $this->assertSame($allocBefore, DB::table('payment_allocations')->count());
        $this->assertSame($notifBefore, DB::table('notifications')->count());
        $this->assertSame($batchBefore, DB::table('notification_batches')->count());
        $this->assertSame($runsBefore, DB::table('billing_runs')->count());
    }
}
