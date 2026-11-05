<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InvoiceDeliveryQueueService;
use App\Services\PdfAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseC1FinancialHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $campusAdmin1;
    private User $campusAdmin2;
    private int $campus1;
    private int $campus2;
    private int $dept;
    private int $program;
    private int $billingRunSep;
    private int $billingRunOct;
    private InvoiceDeliveryQueueService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->campus1 = DB::table('campuses')->orderBy('id')->value('id');
        $this->campus2 = DB::table('campuses')->insertGetId([
            'name' => 'Campus Two',
            'code' => 'C2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->dept = DB::table('departments')->where('code', 'school')->value('id');
        $this->program = DB::table('programs')->insertGetId([
            'department_id' => $this->dept,
            'name' => 'General',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->superAdmin = User::create([
            'name' => 'Super Admin C1',
            'email' => 'superadmin_c1@example.test',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->campusAdmin1 = User::create([
            'name' => 'Cashier C1',
            'email' => 'cashier1_c1@example.test',
            'password' => bcrypt('password123'),
            'role' => 'cashier',
            'campus_id' => $this->campus1,
            'department_id' => $this->dept,
            'is_active' => true,
        ]);

        $this->campusAdmin2 = User::create([
            'name' => 'Cashier C2',
            'email' => 'cashier2_c1@example.test',
            'password' => bcrypt('password123'),
            'role' => 'cashier',
            'campus_id' => $this->campus2,
            'department_id' => $this->dept,
            'is_active' => true,
        ]);

        $this->billingRunSep = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campus1,
            'department_id' => $this->dept,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-09-01..2026-09-30',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'due_date' => '2026-09-25',
            'created_by' => $this->superAdmin->id,
            'created_at' => '2026-09-01 10:00:00',
            'updated_at' => '2026-09-01 10:00:00',
        ]);

        $this->billingRunOct = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campus1,
            'department_id' => $this->dept,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'created_by' => $this->superAdmin->id,
            'created_at' => '2026-10-01 10:00:00',
            'updated_at' => '2026-10-01 10:00:00',
        ]);

        $this->service = app(InvoiceDeliveryQueueService::class);
    }

    private function createInvoice(
        string $invoiceNumber,
        string $createdAt,
        int $campusId = null,
        ?int $billingRunId = null,
        int $totalMinor = 20000,
        int $paidMinor = 0,
        string $status = 'generated'
    ): int {
        $campusId = $campusId ?? $this->campus1;
        $billingRunId = $billingRunId ?? $this->billingRunOct;
        $famId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-' . uniqid(),
            'guardian_name' => 'Guardian ' . $invoiceNumber,
            'whatsapp' => '+96650' . rand(1000000, 9999999),
            'contact_verified' => true,
            'whatsapp_verified' => true,
            'messaging_opt_in' => true,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $stuId = DB::table('students')->insertGetId([
            'family_id' => $famId,
            'name' => 'Student ' . $invoiceNumber,
            'gr_number' => 'GR-' . uniqid() . '-' . rand(1000, 9999),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $enrId = DB::table('enrollments')->insertGetId([
            'student_id' => $stuId,
            'campus_id' => $campusId,
            'department_id' => $this->dept,
            'program_id' => $this->program,
            'grade_label' => 'Grade 1',
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $invId = DB::table('invoices')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $campusId,
            'department_id' => $this->dept,
            'billing_run_id' => $billingRunId,
            'invoice_number' => $invoiceNumber,
            'currency' => 'SAR',
            'total_minor' => $totalMinor,
            'paid_minor' => $paidMinor,
            'status' => $status,
            'issued_on' => substr($createdAt, 0, 10),
            'due_date' => '2026-10-25',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'description' => 'Tuition ' . $invoiceNumber,
            'amount_minor' => $totalMinor,
            'net_minor' => $totalMinor,
            'paid_minor' => $paidMinor,
            'currency' => 'SAR',
            'period_key' => '2026-10',
            'period_start' => substr($createdAt, 0, 7) . '-01',
            'period_end' => substr($createdAt, 0, 7) . '-28',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $invId;
    }

    private function createPaymentWithAllocations(
        string $receiptNumber,
        string $createdAt,
        int $amountMinor,
        array $invoiceAllocations,
        int $campusId = null
    ): int {
        $campusId = $campusId ?? $this->campus1;
        $primaryInvId = $invoiceAllocations[0]['invoice_id'] ?? null;
        $famId = $primaryInvId ? DB::table('invoices')->where('id', $primaryInvId)->value('family_id') : 1;

        $pId = DB::table('payments')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $campusId,
            'department_id' => $this->dept,
            'receipt_number' => $receiptNumber,
            'currency' => 'SAR',
            'amount_minor' => $amountMinor,
            'unallocated_minor' => 0,
            'received_on' => substr($createdAt, 0, 10),
            'selected_period' => substr($createdAt, 0, 7),
            'status' => 'received',
            'received_by' => $this->superAdmin->id,
            'idempotency_key' => 'IDEMP-' . uniqid(),
            'request_hash' => hash('sha256', $receiptNumber),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        foreach ($invoiceAllocations as $alloc) {
            $invId = $alloc['invoice_id'];
            $allocAmt = $alloc['amount_minor'];
            $itemId = DB::table('invoice_items')->where('invoice_id', $invId)->value('id');

            DB::table('payment_allocations')->insert([
                'payment_id' => $pId,
                'invoice_item_id' => $itemId,
                'amount_minor' => $allocAmt,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            DB::table('invoices')->where('id', $invId)->increment('paid_minor', $allocAmt);
            DB::table('invoice_items')->where('id', $itemId)->increment('paid_minor', $allocAmt);
        }

        return $pId;
    }

    /**
     * Test 1: Every SCH appears once in SCH history.
     */
    public function test_every_sch_appears_once_in_sch_history(): void
    {
        $inv1 = $this->createInvoice('SCH-2026-00001', '2026-10-10 10:00:00', $this->campus1, $this->billingRunOct, 30000, 0);
        $inv2 = $this->createInvoice('SCH-2026-00002', '2026-10-12 11:00:00', $this->campus1, $this->billingRunOct, 40000, 0);

        // Add a second invoice item to inv1 to ensure items don't cause duplicate invoice rows
        $firstItem = DB::table('invoice_items')->where('invoice_id', $inv1)->first();
        DB::table('invoice_items')->insert([
            'invoice_id' => $inv1,
            'student_id' => $firstItem->student_id,
            'enrollment_id' => $firstItem->enrollment_id,
            'description' => 'Tuition Extra Item',
            'amount_minor' => 10000,
            'net_minor' => 10000,
            'paid_minor' => 0,
            'currency' => 'SAR',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->superAdmin);

        // Default tab=history opens SCH sub-tab
        $res = $this->get('/invoices?tab=history')->assertOk();
        $res->assertViewIs('invoices');
        $this->assertSame('sch', $res->viewData('historyType'));

        $items = $res->viewData('financialHistoryPage')->items();
        $ids = array_column($items, 'id');

        // Check uniqueness and presence
        $this->assertCount(count(array_unique($ids)), $ids);
        $this->assertContains($inv1, $ids);
        $this->assertContains($inv2, $ids);

        // Verify count of inv1 in the result is exactly 1
        $inv1Matches = array_filter($ids, fn ($id) => $id === $inv1);
        $this->assertCount(1, $inv1Matches);

        // Explicit history_type=sch query works identically
        $resExplicit = $this->get('/invoices?tab=history&history_type=sch')->assertOk();
        $this->assertSame('sch', $resExplicit->viewData('historyType'));
        $this->assertNotNull($resExplicit->viewData('financialHistoryPage'));
    }

    /**
     * Test 2: Every RCP appears once in RCP history.
     */
    public function test_every_rcp_appears_once_in_rcp_history(): void
    {
        $inv1 = $this->createInvoice('SCH-2026-00010', '2026-10-10 10:00:00', $this->campus1, $this->billingRunOct, 30000, 0);
        $p1 = $this->createPaymentWithAllocations('RCP-2026-00001', '2026-10-11 12:00:00', 15000, [
            ['invoice_id' => $inv1, 'amount_minor' => 15000],
        ]);
        $p2 = $this->createPaymentWithAllocations('RCP-2026-00002', '2026-10-12 14:00:00', 15000, [
            ['invoice_id' => $inv1, 'amount_minor' => 15000],
        ]);

        $this->actingAs($this->superAdmin);

        $res = $this->get('/invoices?tab=history&history_type=rcp')->assertOk();
        $this->assertSame('rcp', $res->viewData('historyType'));

        $items = $res->viewData('financialReceiptsPage')->items();
        $pIds = array_column($items, 'id');

        $this->assertCount(count(array_unique($pIds)), $pIds);
        $this->assertContains($p1, $pIds);
        $this->assertContains($p2, $pIds);

        $p1Matches = array_filter($pIds, fn ($id) => $id === $p1);
        $this->assertCount(1, $p1Matches);
    }

    /**
     * Test 3: One RCP paying multiple invoices is not duplicated and preserves allocation details.
     */
    public function test_one_rcp_paying_multiple_invoices_is_not_duplicated(): void
    {
        $invA = $this->createInvoice('SCH-2026-00100', '2026-10-10 10:00:00', $this->campus1, $this->billingRunOct, 20000, 0);
        $invB = $this->createInvoice('SCH-2026-00101', '2026-10-10 10:00:00', $this->campus1, $this->billingRunOct, 30000, 0);

        // One payment pays both invoices (10,000 for invA and 25,000 for invB = total 35,000)
        $pMulti = $this->createPaymentWithAllocations('RCP-2026-MULTI-01', '2026-10-15 15:30:00', 35000, [
            ['invoice_id' => $invA, 'amount_minor' => 10000],
            ['invoice_id' => $invB, 'amount_minor' => 25000],
        ]);

        $this->actingAs($this->superAdmin);

        $res = $this->get('/invoices?tab=history&history_type=rcp')->assertOk();
        $items = $res->viewData('financialReceiptsPage')->items();

        // Must appear ONCE in receipts listing
        $matching = array_values(array_filter($items, fn ($r) => $r->id === $pMulti));
        $this->assertCount(1, $matching, 'RCP paying multiple invoices must appear exactly once');

        $receiptRow = $matching[0];

        // Authoritative amount is 35000 (350.00 SAR), never calculated by summing joined invoices (which would be 50,000)
        $this->assertSame(35000, (int)$receiptRow->amount_minor);
        $this->assertSame('350.00', $receiptRow->formatted_amount);

        // Full allocation details are preserved
        $this->assertCount(2, $receiptRow->allocations_by_invoice);
        $this->assertSame(2, $receiptRow->invoices_paid_count);
        $this->assertContains('SCH-2026-00100', $receiptRow->invoices_paid_numbers);
        $this->assertContains('SCH-2026-00101', $receiptRow->invoices_paid_numbers);

        $allocatedMap = [];
        foreach ($receiptRow->allocations_by_invoice as $al) {
            $allocatedMap[$al['invoice_number']] = $al['amount_minor'];
        }
        $this->assertSame(10000, $allocatedMap['SCH-2026-00100']);
        $this->assertSame(25000, $allocatedMap['SCH-2026-00101']);
    }

    /**
     * Test 4: Historical records appear correctly across periods.
     */
    public function test_historical_records_appear_correctly(): void
    {
        // September historical invoice
        $invSep = $this->createInvoice('SCH-2026-SEP-01', '2026-09-10 10:00:00', $this->campus1, $this->billingRunSep, 25000, 0);

        // October current invoice
        $invOct = $this->createInvoice('SCH-2026-OCT-01', '2026-10-10 10:00:00', $this->campus1, $this->billingRunOct, 35000, 0);

        // Historical opening balance invoice (billing_run_id = null)
        $invOB = $this->createInvoice('SCH-OB-2026-01', '2026-08-15 08:00:00', $this->campus1, null, 15000, 0);

        $this->actingAs($this->superAdmin);

        // Visiting /invoices?tab=history without period defaults to all-period historical view
        $res = $this->get('/invoices?tab=history')->assertOk();
        $items = $res->viewData('financialHistoryPage')->items();
        $ids = array_column($items, 'id');

        $this->assertContains($invSep, $ids, 'September historical invoice must be present');
        $this->assertContains($invOct, $ids, 'October invoice must be present');
        $this->assertContains($invOB, $ids, 'Opening balance historical invoice must be present');

        // Document counts match
        $counts = $res->viewData('historyCounts');
        $this->assertGreaterThanOrEqual(3, $counts['sch']);
    }

    /**
     * Test 5: Correct creation-date sorting and pagination.
     */
    public function test_correct_creation_date_sorting_and_pagination(): void
    {
        // SCH sorting: invoices.created_at DESC, invoices.id DESC
        $sch1 = $this->createInvoice('SCH-SORT-1', '2026-10-05 09:00:00');
        $sch2 = $this->createInvoice('SCH-SORT-2', '2026-10-20 09:00:00');
        $sch3 = $this->createInvoice('SCH-SORT-3', '2026-10-15 09:00:00');
        $sch4 = $this->createInvoice('SCH-SORT-4', '2026-10-20 09:00:00'); // Same timestamp as sch2, higher ID

        $this->actingAs($this->superAdmin);

        $resSch = $this->get('/invoices?tab=history&history_type=sch&per_page=100')->assertOk();
        $schItems = $resSch->viewData('financialHistoryPage')->items();
        $schIds = array_column($schItems, 'id');

        // sch4 comes before sch2 (same created_at, higher id)
        // sch2 comes before sch3
        // sch3 comes before sch1
        $pos4 = array_search($sch4, $schIds, true);
        $pos2 = array_search($sch2, $schIds, true);
        $pos3 = array_search($sch3, $schIds, true);
        $pos1 = array_search($sch1, $schIds, true);

        $this->assertLessThan($pos2, $pos4);
        $this->assertLessThan($pos3, $pos2);
        $this->assertLessThan($pos1, $pos3);

        // RCP sorting: payments.created_at DESC, payments.id DESC
        $rcp1 = $this->createPaymentWithAllocations('RCP-SORT-1', '2026-10-02 12:00:00', 5000, [['invoice_id' => $sch1, 'amount_minor' => 5000]]);
        $rcp2 = $this->createPaymentWithAllocations('RCP-SORT-2', '2026-10-25 12:00:00', 5000, [['invoice_id' => $sch1, 'amount_minor' => 5000]]);
        $rcp3 = $this->createPaymentWithAllocations('RCP-SORT-3', '2026-10-18 12:00:00', 5000, [['invoice_id' => $sch1, 'amount_minor' => 5000]]);
        $rcp4 = $this->createPaymentWithAllocations('RCP-SORT-4', '2026-10-25 12:00:00', 5000, [['invoice_id' => $sch1, 'amount_minor' => 5000]]); // Same timestamp as rcp2, higher ID

        $resRcp = $this->get('/invoices?tab=history&history_type=rcp&per_page=100')->assertOk();
        $rcpItems = $resRcp->viewData('financialReceiptsPage')->items();
        $rcpIds = array_column($rcpItems, 'id');

        $rpos4 = array_search($rcp4, $rcpIds, true);
        $rpos2 = array_search($rcp2, $rcpIds, true);
        $rpos3 = array_search($rcp3, $rcpIds, true);
        $rpos1 = array_search($rcp1, $rcpIds, true);

        $this->assertLessThan($rpos2, $rpos4);
        $this->assertLessThan($rpos3, $rpos2);
        $this->assertLessThan($rpos1, $rpos3);

        // Independent pagination: create additional records to exceed perPage=25
        for ($i = 5; $i <= 28; $i++) {
            $extraSch = $this->createInvoice("SCH-PAGE-{$i}", '2026-10-01 10:00:00');
            $this->createPaymentWithAllocations("RCP-PAGE-{$i}", '2026-10-01 11:00:00', 1000, [['invoice_id' => $extraSch, 'amount_minor' => 1000]]);
        }

        $pagedSch1 = $this->get('/invoices?tab=history&history_type=sch&per_page=25&page=1')->assertOk();
        $this->assertCount(25, $pagedSch1->viewData('financialHistoryPage')->items());

        $pagedSch2 = $this->get('/invoices?tab=history&history_type=sch&per_page=25&page=2')->assertOk();
        $this->assertCount(3, $pagedSch2->viewData('financialHistoryPage')->items());

        $pagedRcp1 = $this->get('/invoices?tab=history&history_type=rcp&per_page=25&page=1')->assertOk();
        $this->assertCount(25, $pagedRcp1->viewData('financialReceiptsPage')->items());

        $pagedRcp2 = $this->get('/invoices?tab=history&history_type=rcp&per_page=25&page=2')->assertOk();
        $this->assertCount(3, $pagedRcp2->viewData('financialReceiptsPage')->items());
    }

    /**
     * Test 6: Existing PDF links and permission restrictions.
     */
    public function test_existing_pdf_links_and_permission_restrictions(): void
    {
        $invC1 = $this->createInvoice('SCH-C1-01', '2026-10-10 10:00:00', $this->campus1);
        $invC2 = $this->createInvoice('SCH-C2-01', '2026-10-10 10:00:00', $this->campus2);

        $rcpC1 = $this->createPaymentWithAllocations('RCP-C1-01', '2026-10-11 10:00:00', 5000, [['invoice_id' => $invC1, 'amount_minor' => 5000]], $this->campus1);
        $rcpC2 = $this->createPaymentWithAllocations('RCP-C2-01', '2026-10-11 10:00:00', 5000, [['invoice_id' => $invC2, 'amount_minor' => 5000]], $this->campus2);

        // Campus 1 Admin access
        $this->actingAs($this->campusAdmin1);

        $resSch = $this->get('/invoices?tab=history&history_type=sch')->assertOk();
        $schIds = array_column($resSch->viewData('financialHistoryPage')->items(), 'id');
        $this->assertContains($invC1, $schIds);
        $this->assertNotContains($invC2, $schIds, 'Campus 1 admin cannot see Campus 2 SCH invoices');

        $resRcp = $this->get('/invoices?tab=history&history_type=rcp')->assertOk();
        $rcpIds = array_column($resRcp->viewData('financialReceiptsPage')->items(), 'id');
        $this->assertContains($rcpC1, $rcpIds);
        $this->assertNotContains($rcpC2, $rcpIds, 'Campus 1 admin cannot see Campus 2 RCP receipts');

        // Check PDF links
        $firstRcp = $resRcp->viewData('financialReceiptsPage')->first();
        $this->assertNotNull($firstRcp->receipt_pdf_url);
        $this->assertStringContainsString((string)$rcpC1, $firstRcp->receipt_pdf_url);

        // Verify PDF route is accessible
        $pdfRes = $this->get("/receipts/{$rcpC1}/pdf")->assertOk();
        $this->assertSame('application/pdf', $pdfRes->headers->get('Content-Type'));

        // Verify Campus 1 Admin cannot access Campus 2 receipt PDF directly
        $this->get("/receipts/{$rcpC2}/pdf")->assertStatus(403);
    }

    /**
     * Test 7: Financial totals remain unchanged and zero mutations occur.
     */
    public function test_financial_totals_and_allocations_remain_unchanged(): void
    {
        $inv = $this->createInvoice('SCH-SAFE-01', '2026-10-10 10:00:00', $this->campus1, null, 50000, 20000);
        $p = $this->createPaymentWithAllocations('RCP-SAFE-01', '2026-10-11 10:00:00', 20000, [['invoice_id' => $inv, 'amount_minor' => 20000]]);

        $invoicesSnapshot = DB::table('invoices')->get()->toArray();
        $paymentsSnapshot = DB::table('payments')->get()->toArray();
        $allocsSnapshot = DB::table('payment_allocations')->get()->toArray();

        $this->actingAs($this->superAdmin);

        // Query both SCH and RCP financial history sub-tabs
        $this->get('/invoices?tab=history&history_type=sch')->assertOk();
        $this->get('/invoices?tab=history&history_type=rcp')->assertOk();

        // Check zero modifications
        $this->assertEquals($invoicesSnapshot, DB::table('invoices')->get()->toArray());
        $this->assertEquals($paymentsSnapshot, DB::table('payments')->get()->toArray());
        $this->assertEquals($allocsSnapshot, DB::table('payment_allocations')->get()->toArray());
    }
}
