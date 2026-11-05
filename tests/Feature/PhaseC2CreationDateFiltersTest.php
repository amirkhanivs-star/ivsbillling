<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InvoiceDeliveryQueueService;
use App\Services\PdfAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseC2CreationDateFiltersTest extends TestCase
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
            'name' => 'Super Admin C2',
            'email' => 'superadmin_c2@example.test',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->campusAdmin1 = User::create([
            'name' => 'Cashier C1',
            'email' => 'cashier1_c2@example.test',
            'password' => bcrypt('password123'),
            'role' => 'cashier',
            'campus_id' => $this->campus1,
            'department_id' => $this->dept,
            'is_active' => true,
        ]);

        $this->campusAdmin2 = User::create([
            'name' => 'Cashier C2',
            'email' => 'cashier2_c2@example.test',
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
        string $status = 'generated',
        ?string $issuedOn = null,
        ?string $dueDate = null
    ): int {
        $campusId = $campusId ?? $this->campus1;
        $billingRunId = $billingRunId ?? $this->billingRunOct;
        $issuedOn = $issuedOn ?? substr($createdAt, 0, 10);
        $dueDate = $dueDate ?? '2026-10-25';

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
            'issued_on' => $issuedOn,
            'due_date' => $dueDate,
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
        int $campusId = null,
        ?string $receivedOn = null
    ): int {
        $campusId = $campusId ?? $this->campus1;
        $primaryInvId = $invoiceAllocations[0]['invoice_id'] ?? null;
        $famId = $primaryInvId ? DB::table('invoices')->where('id', $primaryInvId)->value('family_id') : 1;
        $receivedOn = $receivedOn ?? substr($createdAt, 0, 10);

        $pId = DB::table('payments')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $campusId,
            'department_id' => $this->dept,
            'receipt_number' => $receiptNumber,
            'currency' => 'SAR',
            'amount_minor' => $amountMinor,
            'unallocated_minor' => 0,
            'received_on' => $receivedOn,
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
     * Test 1: SCH creation-date filtering uses invoices.created_at ONLY.
     * Does NOT use billing month, issue date, or due date.
     */
    public function test_sch_creation_date_filtering_uses_created_at_only(): void
    {
        // Invoice 1: created_at in October 2026, but issued_on and billing_run in September 2026
        $invOctCreated = $this->createInvoice(
            'SCH-OCT-CREATED',
            '2026-10-05 14:00:00',
            $this->campus1,
            $this->billingRunSep, // September billing run
            20000,
            0,
            'generated',
            '2026-09-01', // September issue date
            '2026-09-25'  // September due date
        );

        // Invoice 2: created_at in September 2026, but issued_on and billing_run in October 2026
        $invSepCreated = $this->createInvoice(
            'SCH-SEP-CREATED',
            '2026-09-20 10:00:00',
            $this->campus1,
            $this->billingRunOct, // October billing run
            20000,
            0,
            'generated',
            '2026-10-01', // October issue date
            '2026-10-25'  // October due date
        );

        // Invoice 3: created_at on 2026-10-15
        $invMidOct = $this->createInvoice(
            'SCH-MID-OCT',
            '2026-10-15 11:30:00',
            $this->campus1,
            $this->billingRunOct
        );

        $this->actingAs($this->superAdmin);

        // 1. Filter October 1 to October 10: should find SCH-OCT-CREATED only
        $res = $this->get('/invoices?tab=history&history_type=sch&from_date=2026-10-01&to_date=2026-10-10')->assertOk();
        $ids = array_column($res->viewData('financialHistoryPage')->items(), 'id');

        $this->assertContains($invOctCreated, $ids, 'SCH created in October must match creation-date filter regardless of September issue date/billing month');
        $this->assertNotContains($invSepCreated, $ids, 'SCH created in September must be excluded even if billing month/issue date is October');
        $this->assertNotContains($invMidOct, $ids, 'SCH created on October 15 must be excluded by October 10 to_date');

        // 2. Filter from_date only: from 2026-10-10 onward
        $resFrom = $this->get('/invoices?tab=history&history_type=sch&from_date=2026-10-10')->assertOk();
        $fromIds = array_column($resFrom->viewData('financialHistoryPage')->items(), 'id');
        $this->assertContains($invMidOct, $fromIds);
        $this->assertNotContains($invOctCreated, $fromIds);
        $this->assertNotContains($invSepCreated, $fromIds);

        // 3. Filter to_date only: up to 2026-09-30
        $resTo = $this->get('/invoices?tab=history&history_type=sch&to_date=2026-09-30')->assertOk();
        $toIds = array_column($resTo->viewData('financialHistoryPage')->items(), 'id');
        $this->assertContains($invSepCreated, $toIds);
        $this->assertNotContains($invOctCreated, $toIds);
        $this->assertNotContains($invMidOct, $toIds);

        // 4. Default: shows all historical records
        $resAll = $this->get('/invoices?tab=history&history_type=sch')->assertOk();
        $allIds = array_column($resAll->viewData('financialHistoryPage')->items(), 'id');
        $this->assertContains($invOctCreated, $allIds);
        $this->assertContains($invSepCreated, $allIds);
        $this->assertContains($invMidOct, $allIds);
    }

    /**
     * Test 2: RCP creation-date filtering uses payments.created_at ONLY.
     * Does NOT use received_on, billing month, or linked invoice date.
     */
    public function test_rcp_creation_date_filtering_uses_created_at_only(): void
    {
        $invSep = $this->createInvoice('SCH-BASE-SEP', '2026-09-01 10:00:00', $this->campus1, $this->billingRunSep);
        $invOct = $this->createInvoice('SCH-BASE-OCT', '2026-10-01 10:00:00', $this->campus1, $this->billingRunOct);

        // Receipt 1: created_at in October 2026, received_on in September 2026, pays September invoice
        $rcpOctCreated = $this->createPaymentWithAllocations(
            'RCP-OCT-CREATED',
            '2026-10-06 15:00:00',
            5000,
            [['invoice_id' => $invSep, 'amount_minor' => 5000]],
            $this->campus1,
            '2026-09-15' // September received_on
        );

        // Receipt 2: created_at in September 2026, received_on in October 2026, pays October invoice
        $rcpSepCreated = $this->createPaymentWithAllocations(
            'RCP-SEP-CREATED',
            '2026-09-25 10:00:00',
            5000,
            [['invoice_id' => $invOct, 'amount_minor' => 5000]],
            $this->campus1,
            '2026-10-01' // October received_on
        );

        // Receipt 3: created_at on 2026-10-20
        $rcpLateOct = $this->createPaymentWithAllocations(
            'RCP-LATE-OCT',
            '2026-10-20 09:00:00',
            5000,
            [['invoice_id' => $invOct, 'amount_minor' => 5000]],
            $this->campus1
        );

        $this->actingAs($this->superAdmin);

        // 1. Filter October 1 to October 10: should find RCP-OCT-CREATED only
        $res = $this->get('/invoices?tab=history&history_type=rcp&from_date=2026-10-01&to_date=2026-10-10')->assertOk();
        $pIds = array_column($res->viewData('financialReceiptsPage')->items(), 'id');

        $this->assertContains($rcpOctCreated, $pIds, 'RCP created in October must match creation-date filter regardless of September received_on / linked invoice');
        $this->assertNotContains($rcpSepCreated, $pIds, 'RCP created in September must be excluded even if received_on is October');
        $this->assertNotContains($rcpLateOct, $pIds, 'RCP created on October 20 must be excluded by October 10 to_date');

        // 2. Filter from_date only: from 2026-10-15 onward
        $resFrom = $this->get('/invoices?tab=history&history_type=rcp&from_date=2026-10-15')->assertOk();
        $fromIds = array_column($resFrom->viewData('financialReceiptsPage')->items(), 'id');
        $this->assertContains($rcpLateOct, $fromIds);
        $this->assertNotContains($rcpOctCreated, $fromIds);
        $this->assertNotContains($rcpSepCreated, $fromIds);

        // 3. Filter to_date only: up to 2026-09-30
        $resTo = $this->get('/invoices?tab=history&history_type=rcp&to_date=2026-09-30')->assertOk();
        $toIds = array_column($resTo->viewData('financialReceiptsPage')->items(), 'id');
        $this->assertContains($rcpSepCreated, $toIds);
        $this->assertNotContains($rcpOctCreated, $toIds);
        $this->assertNotContains($rcpLateOct, $toIds);
    }

    /**
     * Test 3: Historical records and opening balances appear in their correct dates.
     */
    public function test_historical_records_appear_in_correct_dates(): void
    {
        // Historical opening balance invoice (billing_run_id = null)
        $invOB = $this->createInvoice('SCH-OB-HIST-1', '2026-07-15 08:00:00', $this->campus1, null, 15000, 0);

        // Historical payment in July
        $rcpOB = $this->createPaymentWithAllocations('RCP-OB-HIST-1', '2026-07-20 12:00:00', 5000, [
            ['invoice_id' => $invOB, 'amount_minor' => 5000]
        ]);

        $this->actingAs($this->superAdmin);

        // Filter July 2026
        $resSch = $this->get('/invoices?tab=history&history_type=sch&from_date=2026-07-01&to_date=2026-07-31')->assertOk();
        $schIds = array_column($resSch->viewData('financialHistoryPage')->items(), 'id');
        $this->assertContains($invOB, $schIds, 'Historical opening balance invoice must match July creation date');

        $resRcp = $this->get('/invoices?tab=history&history_type=rcp&from_date=2026-07-01&to_date=2026-07-31')->assertOk();
        $rcpIds = array_column($resRcp->viewData('financialReceiptsPage')->items(), 'id');
        $this->assertContains($rcpOB, $rcpIds, 'Historical payment must match July creation date');

        // Filter August 2026: neither should appear
        $resAug = $this->get('/invoices?tab=history&history_type=sch&from_date=2026-08-01&to_date=2026-08-31')->assertOk();
        $augIds = array_column($resAug->viewData('financialHistoryPage')->items(), 'id');
        $this->assertNotContains($invOB, $augIds);
    }

    /**
     * Test 4: Correct handling of full-day boundaries (00:00:00 and 23:59:59) and server-side validation.
     */
    public function test_full_day_boundaries_and_server_side_validation(): void
    {
        // Invoice right at start of day 2026-10-10 00:00:00
        $invStart = $this->createInvoice('SCH-BOUND-START', '2026-10-10 00:00:00');

        // Invoice right at end of day 2026-10-10 23:59:59
        $invEnd = $this->createInvoice('SCH-BOUND-END', '2026-10-10 23:59:59');

        // Invoice 1 second before start of day: 2026-10-09 23:59:59
        $invBefore = $this->createInvoice('SCH-BOUND-BEFORE', '2026-10-09 23:59:59');

        // Invoice 1 second after end of day: 2026-10-11 00:00:00
        $invAfter = $this->createInvoice('SCH-BOUND-AFTER', '2026-10-11 00:00:00');

        $this->actingAs($this->superAdmin);

        // Filter for exactly one full day: 2026-10-10 to 2026-10-10
        $res = $this->get('/invoices?tab=history&history_type=sch&from_date=2026-10-10&to_date=2026-10-10')->assertOk();
        $ids = array_column($res->viewData('financialHistoryPage')->items(), 'id');

        $this->assertContains($invStart, $ids, '00:00:00 start boundary must be included');
        $this->assertContains($invEnd, $ids, '23:59:59 end boundary must be included');
        $this->assertNotContains($invBefore, $ids, '2026-10-09 23:59:59 must be excluded');
        $this->assertNotContains($invAfter, $ids, '2026-10-11 00:00:00 must be excluded');

        // Server-side validation: invalid date range (to_date < from_date) fails validation
        $invalidRes = $this->get('/invoices?tab=history&from_date=2026-10-15&to_date=2026-10-10');
        $invalidRes->assertSessionHasErrors(['to_date']);

        // Server-side validation: invalid date format fails validation
        $badFormatRes = $this->get('/invoices?tab=history&from_date=not-a-date');
        $badFormatRes->assertSessionHasErrors(['from_date']);
    }

    /**
     * Test 5: Every genuine SCH appears once.
     */
    public function test_every_genuine_sch_appears_once(): void
    {
        $sch = $this->createInvoice('SCH-SINGLE-1', '2026-10-12 10:00:00');

        // Add 2 extra invoice items to simulate multi-item / multi-fee invoices
        $item = DB::table('invoice_items')->where('invoice_id', $sch)->first();
        DB::table('invoice_items')->insert([
            'invoice_id' => $sch,
            'student_id' => $item->student_id,
            'enrollment_id' => $item->enrollment_id,
            'description' => 'Lab Fee',
            'amount_minor' => 5000,
            'net_minor' => 5000,
            'paid_minor' => 0,
            'currency' => 'SAR',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => $sch,
            'student_id' => $item->student_id,
            'enrollment_id' => $item->enrollment_id,
            'description' => 'Library Fee',
            'amount_minor' => 3000,
            'net_minor' => 3000,
            'paid_minor' => 0,
            'currency' => 'SAR',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->superAdmin);

        $res = $this->get('/invoices?tab=history&history_type=sch&from_date=2026-10-10&to_date=2026-10-15')->assertOk();
        $ids = array_column($res->viewData('financialHistoryPage')->items(), 'id');

        $matches = array_filter($ids, fn ($id) => $id === $sch);
        $this->assertCount(1, $matches, 'SCH with multiple items must appear exactly once');
    }

    /**
     * Test 6: Every genuine RCP appears once.
     */
    public function test_every_genuine_rcp_appears_once(): void
    {
        $schA = $this->createInvoice('SCH-FOR-RCP-A', '2026-10-10 10:00:00');
        $schB = $this->createInvoice('SCH-FOR-RCP-B', '2026-10-10 10:00:00');

        $rcp = $this->createPaymentWithAllocations('RCP-GENUINE-1', '2026-10-12 12:00:00', 30000, [
            ['invoice_id' => $schA, 'amount_minor' => 10000],
            ['invoice_id' => $schB, 'amount_minor' => 20000],
        ]);

        $this->actingAs($this->superAdmin);

        $res = $this->get('/invoices?tab=history&history_type=rcp&from_date=2026-10-10&to_date=2026-10-15')->assertOk();
        $ids = array_column($res->viewData('financialReceiptsPage')->items(), 'id');

        $matches = array_filter($ids, fn ($id) => $id === $rcp);
        $this->assertCount(1, $matches, 'RCP must appear exactly once even with multiple allocations');
    }

    /**
     * Test 7: Multi-invoice RCP allocations remain correct under date filtering.
     */
    public function test_multi_invoice_rcp_allocations_remain_correct(): void
    {
        $sch1 = $this->createInvoice('SCH-ALLOC-1', '2026-10-01 10:00:00', $this->campus1, $this->billingRunOct, 25000);
        $sch2 = $this->createInvoice('SCH-ALLOC-2', '2026-10-01 10:00:00', $this->campus1, $this->billingRunOct, 35000);

        $rcp = $this->createPaymentWithAllocations('RCP-ALLOC-MULTI', '2026-10-08 14:00:00', 40000, [
            ['invoice_id' => $sch1, 'amount_minor' => 15000],
            ['invoice_id' => $sch2, 'amount_minor' => 25000],
        ]);

        $this->actingAs($this->superAdmin);

        $res = $this->get('/invoices?tab=history&history_type=rcp&from_date=2026-10-05&to_date=2026-10-10')->assertOk();
        $items = $res->viewData('financialReceiptsPage')->items();

        $matching = array_values(array_filter($items, fn ($r) => $r->id === $rcp));
        $this->assertCount(1, $matching);

        $row = $matching[0];
        $this->assertSame(40000, (int)$row->amount_minor);
        $this->assertSame('400.00', $row->formatted_amount);
        $this->assertCount(2, $row->allocations_by_invoice);
        $this->assertSame(2, $row->invoices_paid_count);
        $this->assertContains('SCH-ALLOC-1', $row->invoices_paid_numbers);
        $this->assertContains('SCH-ALLOC-2', $row->invoices_paid_numbers);
    }

    /**
     * Test 8: Pagination and counts remain accurate; sub-tab, status filters, and dates are preserved.
     */
    public function test_pagination_counts_and_filter_preservation(): void
    {
        // Create 30 SCH invoices on 2026-10-10
        for ($i = 1; $i <= 30; $i++) {
            $this->createInvoice("SCH-PAGE-C2-{$i}", '2026-10-10 10:00:00', $this->campus1, $this->billingRunOct, 10000, ($i % 2 === 0 ? 10000 : 0), ($i % 2 === 0 ? 'paid' : 'generated'));
        }

        // Create 5 SCH invoices on 2026-09-10 (outside filter)
        for ($i = 31; $i <= 35; $i++) {
            $this->createInvoice("SCH-PAGE-C2-{$i}", '2026-09-10 10:00:00', $this->campus1, $this->billingRunSep);
        }

        $this->actingAs($this->superAdmin);

        // Filter SCH to October 10: exactly 30 invoices match
        $res = $this->get('/invoices?tab=history&history_type=sch&from_date=2026-10-10&to_date=2026-10-10&per_page=25&page=1')->assertOk();
        $paginator = $res->viewData('financialHistoryPage');
        $this->assertSame(30, $paginator->total());
        $this->assertCount(25, $paginator->items());

        // Check counts in badge
        $counts = $res->viewData('historyCounts');
        $this->assertSame(30, $counts['sch']);

        // Check page 2
        $resPage2 = $this->get('/invoices?tab=history&history_type=sch&from_date=2026-10-10&to_date=2026-10-10&per_page=25&page=2')->assertOk();
        $paginator2 = $resPage2->viewData('financialHistoryPage');
        $this->assertCount(5, $paginator2->items());

        // Status filter preserved: payment_status=paid on October 10: exactly 15 invoices
        $resPaid = $this->get('/invoices?tab=history&history_type=sch&payment_status=paid&from_date=2026-10-10&to_date=2026-10-10&per_page=25')->assertOk();
        $this->assertSame(15, $resPaid->viewData('financialHistoryPage')->total());
    }

    /**
     * Test 9: Permissions and financial values remain unchanged.
     */
    public function test_permissions_and_financial_immutability(): void
    {
        $schC1 = $this->createInvoice('SCH-PERM-C1', '2026-10-10 10:00:00', $this->campus1);
        $schC2 = $this->createInvoice('SCH-PERM-C2', '2026-10-10 10:00:00', $this->campus2);

        $rcpC1 = $this->createPaymentWithAllocations('RCP-PERM-C1', '2026-10-10 12:00:00', 5000, [['invoice_id' => $schC1, 'amount_minor' => 5000]], $this->campus1);
        $rcpC2 = $this->createPaymentWithAllocations('RCP-PERM-C2', '2026-10-10 12:00:00', 5000, [['invoice_id' => $schC2, 'amount_minor' => 5000]], $this->campus2);

        // Pre-query snapshot
        $invoicesSnapshot = DB::table('invoices')->get()->toArray();
        $paymentsSnapshot = DB::table('payments')->get()->toArray();
        $allocsSnapshot = DB::table('payment_allocations')->get()->toArray();

        // Campus 1 Admin access under date filtering
        $this->actingAs($this->campusAdmin1);

        $resSch = $this->get('/invoices?tab=history&history_type=sch&from_date=2026-10-01&to_date=2026-10-31')->assertOk();
        $schIds = array_column($resSch->viewData('financialHistoryPage')->items(), 'id');
        $this->assertContains($schC1, $schIds);
        $this->assertNotContains($schC2, $schIds, 'Campus 1 admin cannot see Campus 2 SCH under date filter');

        $resRcp = $this->get('/invoices?tab=history&history_type=rcp&from_date=2026-10-01&to_date=2026-10-31')->assertOk();
        $rcpIds = array_column($resRcp->viewData('financialReceiptsPage')->items(), 'id');
        $this->assertContains($rcpC1, $rcpIds);
        $this->assertNotContains($rcpC2, $rcpIds, 'Campus 1 admin cannot see Campus 2 RCP under date filter');

        // Post-query snapshot verification: zero mutations
        $this->assertEquals($invoicesSnapshot, DB::table('invoices')->get()->toArray());
        $this->assertEquals($paymentsSnapshot, DB::table('payments')->get()->toArray());
        $this->assertEquals($allocsSnapshot, DB::table('payment_allocations')->get()->toArray());
    }

    /**
     * Test 10: Sorting is strictly newest first: created_at DESC, id DESC.
     */
    public function test_sorting_strictly_newest_first(): void
    {
        $sch1 = $this->createInvoice('SCH-NEWEST-1', '2026-10-05 10:00:00');
        $sch2 = $this->createInvoice('SCH-NEWEST-2', '2026-10-08 10:00:00');
        $sch3 = $this->createInvoice('SCH-NEWEST-3', '2026-10-08 10:00:00'); // Same time, higher ID

        $this->actingAs($this->superAdmin);

        $res = $this->get('/invoices?tab=history&history_type=sch&from_date=2026-10-01&to_date=2026-10-31')->assertOk();
        $ids = array_column($res->viewData('financialHistoryPage')->items(), 'id');

        $pos3 = array_search($sch3, $ids, true);
        $pos2 = array_search($sch2, $ids, true);
        $pos1 = array_search($sch1, $ids, true);

        $this->assertLessThan($pos2, $pos3, 'Higher ID with same timestamp must appear before lower ID');
        $this->assertLessThan($pos1, $pos2, 'Newer timestamp must appear before older timestamp');
    }
}
