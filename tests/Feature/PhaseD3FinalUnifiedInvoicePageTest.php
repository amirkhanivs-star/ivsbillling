<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BatchDeliveryService;
use App\Services\InvoiceDeliveryQueueService;
use App\Services\WebhookSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PhaseD3FinalUnifiedInvoicePageTest extends TestCase
{
    use RefreshDatabase;

    private array $env;

    protected function setUp(): void
    {
        parent::setUp();
        $this->env = $this->setupEnvironment();
    }

    private function setupEnvironment(): array
    {
        $this->seed();

        $campus1 = DB::table('campuses')->orderBy('id')->first()->id;
        $campus2 = DB::table('campuses')->where('id', '!=', $campus1)->value('id');
        if (!$campus2) {
            $campus2 = DB::table('campuses')->insertGetId([
                'name' => 'Campus Two D3',
                'code' => 'campus_two_d3',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $deptSchool = DB::table('departments')->where('code', 'school')->value('id');
        $program = DB::table('programs')->value('id');

        $superAdmin = User::create([
            'name' => 'Super Admin Phase D3',
            'email' => 'admin_d3@example.test',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $cashierCampus1 = User::create([
            'name' => 'Cashier Campus 1 D3',
            'email' => 'cashier_c1_d3@example.test',
            'password' => bcrypt('password123'),
            'role' => 'cashier',
            'campus_id' => $campus1,
            'department_id' => $deptSchool,
            'is_active' => true,
        ]);

        $monthlyUrl = 'https://n8n.example.test/webhook/monthly-invoices';
        $paidUrl = 'https://n8n.example.test/webhook/paid-invoices';
        $secret = 'test-phase-d3-secret';

        $svc = app(WebhookSettingService::class);
        $svc->updateSettings('monthly', ['url' => $monthlyUrl, 'secret' => $secret, 'is_enabled' => true], $superAdmin->id);
        $svc->updateSettings('paid', ['url' => $paidUrl, 'secret' => $secret, 'is_enabled' => true], $superAdmin->id);

        $runOct = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus1,
            'department_id' => $deptSchool,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'created_by' => $superAdmin->id,
            'status' => 'completed',
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
            'status' => 'completed',
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
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('superAdmin', 'cashierCampus1', 'campus1', 'campus2', 'deptSchool', 'program', 'runOct', 'runNov', 'runDec', 'monthlyUrl', 'paidUrl');
    }

    private function createFamilyWithInvoice(
        int $billingRunId,
        string $familyCode,
        string $invoiceNumber,
        int $amountMinor = 50000,
        int $paidMinor = 0,
        string $currency = 'SAR',
        ?int $campusId = null,
        ?int $familyId = null,
        ?int $studentId = null,
        string $periodMonth = '2026-10',
        ?string $createdAt = null
    ): array {
        $campus = $campusId ?? $this->env['campus1'];
        $created = $createdAt ?? "{$periodMonth}-01 10:00:00";

        if ($familyId) {
            $famId = $familyId;
        } else {
            $fam = DB::table('families')->where('family_code', $familyCode)->first();
            $famId = $fam ? $fam->id : DB::table('families')->insertGetId([
                'family_code' => $familyCode,
                'guardian_name' => "Guardian {$familyCode}",
                'whatsapp' => '+96650' . rand(1000000, 9999999),
                'contact_verified' => true,
                'whatsapp_verified' => true,
                'messaging_opt_in' => true,
                'created_at' => $created,
                'updated_at' => $created,
            ]);
        }

        if ($studentId) {
            $stuId = $studentId;
            $enrId = DB::table('enrollments')->where('student_id', $stuId)->value('id');
        } else {
            $stu = DB::table('students')->where('family_id', $famId)->first();
            if ($stu) {
                $stuId = $stu->id;
                $enrId = DB::table('enrollments')->where('student_id', $stuId)->value('id');
            } else {
                $stuId = DB::table('students')->insertGetId([
                    'family_id' => $famId,
                    'name' => "Student {$familyCode}",
                    'gr_number' => 'GR-' . rand(1000, 9999),
                    'status' => 'active',
                    'created_at' => $created,
                    'updated_at' => $created,
                ]);
                $enrId = DB::table('enrollments')->insertGetId([
                    'student_id' => $stuId,
                    'campus_id' => $campus,
                    'department_id' => $this->env['deptSchool'],
                    'program_id' => $this->env['program'],
                    'grade_label' => 'Grade 1',
                    'currency' => $currency,
                    'status' => 'active',
                    'created_at' => $created,
                    'updated_at' => $created,
                ]);
            }
        }

        $feeTypeId = DB::table('fee_types')->value('id') ?? DB::table('fee_types')->insertGetId([
            'name' => 'Tuition D3',
            'code' => 'tuition_d3',
            'category' => 'tuition',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $status = ($paidMinor >= $amountMinor) ? 'paid' : (($paidMinor > 0) ? 'partial' : 'issued');

        $invId = DB::table('invoices')->insertGetId([
            'family_id' => $famId,
            'campus_id' => $campus,
            'department_id' => $this->env['deptSchool'],
            'billing_run_id' => $billingRunId,
            'invoice_number' => $invoiceNumber,
            'currency' => $currency,
            'total_minor' => $amountMinor,
            'paid_minor' => $paidMinor,
            'status' => $status,
            'issued_on' => "{$periodMonth}-01",
            'due_date' => "{$periodMonth}-25",
            'created_at' => $created,
            'updated_at' => $created,
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $stuId,
            'enrollment_id' => $enrId,
            'fee_type_id' => $feeTypeId,
            'description' => "Tuition Fee {$periodMonth}",
            'amount_minor' => $amountMinor,
            'net_minor' => $amountMinor,
            'paid_minor' => $paidMinor,
            'currency' => $currency,
            'period_key' => $periodMonth,
            'period_start' => "{$periodMonth}-01",
            'period_end' => "{$periodMonth}-28",
            'status' => $paidMinor >= $amountMinor ? 'paid' : ($paidMinor > 0 ? 'partial' : 'unpaid'),
            'created_at' => $created,
            'updated_at' => $created,
        ]);

        return [
            'famId' => $famId,
            'stuId' => $stuId,
            'invId' => $invId,
            'itemId' => $itemId,
        ];
    }

    /**
     * Verification 1 & 2: /invoices opens the correct unified Ready to Send view
     * with each eligible family appearing once, and without a billing-month dropdown.
     */
    public function test_1_invoices_opens_correct_unified_ready_to_send_view_without_dropdown(): void
    {
        // Family with 3 monthly invoices: Oct, Nov, Dec
        $oct = $this->createFamilyWithInvoice($this->env['runOct'], 'FAM-D3-01', 'SCH-D3-OCT', 50000, 0, 'SAR', null, null, null, '2026-10', '2026-10-01 09:00:00');
        $nov = $this->createFamilyWithInvoice($this->env['runNov'], 'FAM-D3-01', 'SCH-D3-NOV', 50000, 0, 'SAR', null, $oct['famId'], $oct['stuId'], '2026-11', '2026-11-01 09:00:00');
        $dec = $this->createFamilyWithInvoice($this->env['runDec'], 'FAM-D3-01', 'SCH-D3-DEC', 50000, 0, 'SAR', null, $oct['famId'], $oct['stuId'], '2026-12', '2026-12-01 09:00:00');

        // Second family with 1 invoice
        $fam2 = $this->createFamilyWithInvoice($this->env['runNov'], 'FAM-D3-02', 'SCH-D3-F2', 60000, 0, 'SAR', null, null, null, '2026-11', '2026-11-01 10:00:00');

        // Open bare /invoices
        $res = $this->actingAs($this->env['superAdmin'])->get('/invoices');
        $res->assertOk();

        // 1. Must default to currentTab = ready and isAllPeriod = true
        $this->assertSame('ready', $res->viewData('currentTab'));
        $this->assertTrue($res->viewData('isAllPeriod'));

        // 2. Billing month dropdown must NOT be present
        $res->assertDontSee('<select name="period"', false);
        $res->assertDontSee('id="month-selector-form"', false);
        $res->assertSee('Billing Scope');
        $res->assertSee('All Periods');

        // Navigation tab links and filter forms must default to period=all
        $res->assertSee('href="?tab=ready&period=all"', false);
        $res->assertSee('name="period" value="all"', false);

        // 3. Exactly one consolidated entry per eligible family (2 families)
        $challans = $res->viewData('readyChallans');
        $this->assertSame(2, $challans->total());

        // Primary invoice for family 1 must be newest authoritative SCH (December)
        $fam1Row = collect($challans->items())->firstWhere('family_id', $oct['famId']);
        $this->assertNotNull($fam1Row);
        $this->assertEquals($dec['invId'], $fam1Row->invoice_id);
        $this->assertEquals('SCH-D3-DEC', $fam1Row->invoice_number);
        $this->assertSame(150000, $fam1Row->total_payable_minor);
    }

    /**
     * Verification 3 & 4: Batch selection and sending controls remain functional;
     * already queued/submitted families remain protected.
     */
    public function test_2_batch_selection_and_sending_controls_functional_and_protected(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $fam = $this->createFamilyWithInvoice($this->env['runOct'], 'FAM-D3-BATCH', 'SCH-D3-B1', 50000, 0, 'SAR', null, null, null, '2026-10', '2026-10-01 09:00:00');

        $res = $this->actingAs($this->env['superAdmin'])->get('/invoices');
        $res->assertOk();

        $snapshot = $res->viewData('selectionSnapshot');
        $this->assertNotNull($snapshot);
        $this->assertSame(1, $snapshot['total_eligible_recipients']);

        // Send batch using snapshot and period=all
        $postRes = $this->actingAs($this->env['superAdmin'])->post('/invoices/send-batch', [
            'selection_mode' => 'all_matching',
            'channels' => ['whatsapp'],
            'review_confirmed' => '1',
            'snapshot_id' => $snapshot['snapshot_id'],
            'period' => 'all',
            'webhook_type' => 'monthly',
        ]);
        $postRes->assertSessionHasNoErrors();

        // Already submitted family cannot be sent again
        $resAfter = $this->actingAs($this->env['superAdmin'])->get('/invoices');
        $this->assertSame(0, $resAfter->viewData('readyChallans')->total());
    }

    /**
     * Verification 5: Existing monthly URLs still behave safely and filter by specific period.
     */
    public function test_3_existing_monthly_urls_still_behave_safely(): void
    {
        $oct = $this->createFamilyWithInvoice($this->env['runOct'], 'FAM-D3-M1', 'SCH-D3-M1', 50000, 0, 'SAR', null, null, null, '2026-10', '2026-10-01 09:00:00');
        $nov = $this->createFamilyWithInvoice($this->env['runNov'], 'FAM-D3-M2', 'SCH-D3-M2', 50000, 0, 'SAR', null, null, null, '2026-11', '2026-11-01 09:00:00');

        // Monthly URL for October
        $resOct = $this->actingAs($this->env['superAdmin'])->get('/invoices?period=2026-10');
        $resOct->assertOk();
        $this->assertFalse($resOct->viewData('isAllPeriod'));
        $this->assertSame('2026-10', $resOct->viewData('selectedPeriod'));
        $this->assertSame(1, $resOct->viewData('readyChallans')->total());
        $this->assertEquals($oct['famId'], $resOct->viewData('readyChallans')->items()[0]->family_id);

        // Card 1 reflects October 2026 campaign without dropdown
        $resOct->assertDontSee('<select name="period"', false);
        $resOct->assertSee('October 2026');
        $resOct->assertSee('Selected campaign: 2026-10');
        $resOct->assertSee('name="period" value="2026-10"', false);
        $resOct->assertSee('href="?tab=ready&period=2026-10"', false);

        // Monthly URL for November
        $resNov = $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=ready&period=2026-11');
        $resNov->assertOk();
        $this->assertFalse($resNov->viewData('isAllPeriod'));
        $this->assertSame('2026-11', $resNov->viewData('selectedPeriod'));
        $this->assertSame(1, $resNov->viewData('readyChallans')->total());
        $this->assertEquals($nov['famId'], $resNov->viewData('readyChallans')->items()[0]->family_id);
    }

    /**
     * Verification 6: Paid Invoices and Financial History remain accurate.
     */
    public function test_4_paid_invoices_and_financial_history_remain_accurate(): void
    {
        // Fully paid invoice with receipt
        $paid = $this->createFamilyWithInvoice($this->env['runOct'], 'FAM-D3-PAID', 'SCH-D3-PAID', 50000, 50000, 'SAR', null, null, null, '2026-10', '2026-10-01 09:00:00');
        $paymentId = DB::table('payments')->insertGetId([
            'receipt_number' => 'RCP-D3-PAID-01',
            'family_id' => $paid['famId'],
            'campus_id' => $this->env['campus1'],
            'department_id' => $this->env['deptSchool'],
            'amount_minor' => 50000,
            'unallocated_minor' => 0,
            'currency' => 'SAR',
            'received_on' => '2026-10-15',
            'selected_period' => '2026-10',
            'status' => 'received',
            'received_by' => $this->env['superAdmin']->id,
            'idempotency_key' => 'IDEMP-D3-' . uniqid(),
            'request_hash' => hash('sha256', 'RCP-D3-PAID-01'),
            'created_at' => '2026-10-15 11:00:00',
            'updated_at' => '2026-10-15 11:00:00',
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $paid['itemId'],
            'amount_minor' => 50000,
            'created_at' => '2026-10-15 11:00:00',
            'updated_at' => '2026-10-15 11:00:00',
        ]);

        // Paid tab under default unified navigation
        $resPaid = $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=paid');
        $resPaid->assertOk();
        $this->assertSame('paid', $resPaid->viewData('currentTab'));
        $paidItems = $resPaid->viewData('paidInvoicesPage')->items();
        $this->assertCount(1, $paidItems);
        $this->assertEquals('SCH-D3-PAID', $paidItems[0]->invoice_number);

        // Financial History sub-tabs: SCH and RCP
        $resHistSch = $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=history&history_type=sch');
        $resHistSch->assertOk();
        $this->assertNotNull($resHistSch->viewData('financialHistoryPage'));

        $resHistRcp = $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=history&history_type=rcp');
        $resHistRcp->assertOk();
        $this->assertNotNull($resHistRcp->viewData('financialReceiptsPage'));
        $this->assertSame(1, $resHistRcp->viewData('financialReceiptsPage')->total());
        $this->assertEquals('RCP-D3-PAID-01', $resHistRcp->viewData('financialReceiptsPage')->items()[0]->receipt_number);
    }

    /**
     * Verification 7 & 8: Creation-date filters, all tabs, counters, and navigation links work.
     */
    public function test_5_creation_date_filters_and_all_tabs_navigation_links(): void
    {
        $f1 = $this->createFamilyWithInvoice($this->env['runOct'], 'FAM-D3-DATE1', 'SCH-D3-D1', 50000, 0, 'SAR', null, null, null, '2026-10', '2026-10-10 10:00:00');
        $f2 = $this->createFamilyWithInvoice($this->env['runNov'], 'FAM-D3-DATE2', 'SCH-D3-D2', 50000, 0, 'SAR', null, null, null, '2026-11', '2026-11-15 10:00:00');

        // SCH date filter
        $resFilter = $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=history&history_type=sch&from_date=2026-10-01&to_date=2026-10-20');
        $resFilter->assertOk();
        $this->assertSame(1, $resFilter->viewData('financialHistoryPage')->total());
        $this->assertEquals('SCH-D3-D1', $resFilter->viewData('financialHistoryPage')->items()[0]->invoice_number);

        // Verify all workflow tabs open successfully without errors
        $tabs = ['ready', 'paid', 'processing', 'sent', 'attention', 'history'];
        foreach ($tabs as $tab) {
            $this->actingAs($this->env['superAdmin'])->get("/invoices?tab={$tab}")->assertOk();
        }
    }

    /**
     * Verification 9 & 10: Preserves newest authoritative SCH ordering and leaves financial data unmodified.
     */
    public function test_6_newest_authoritative_sch_ordering_and_financial_immutability(): void
    {
        $countsBefore = [
            'invoices' => DB::table('invoices')->count(),
            'invoice_items' => DB::table('invoice_items')->count(),
            'payments' => DB::table('payments')->count(),
            'payment_allocations' => DB::table('payment_allocations')->count(),
        ];

        // Family with older and newer SCH
        $famOld = $this->createFamilyWithInvoice($this->env['runOct'], 'FAM-D3-ORD', 'SCH-D3-OLD', 50000, 0, 'SAR', null, null, null, '2026-10', '2026-10-01 08:00:00');
        $famNew = $this->createFamilyWithInvoice($this->env['runDec'], 'FAM-D3-ORD', 'SCH-D3-NEW', 50000, 0, 'SAR', null, $famOld['famId'], $famOld['stuId'], '2026-12', '2026-12-01 08:00:00');

        $res = $this->actingAs($this->env['superAdmin'])->get('/invoices');
        $res->assertOk();

        $item = $res->viewData('readyChallans')->items()[0];
        $this->assertEquals('SCH-D3-NEW', $item->invoice_number);
        $this->assertEquals($famNew['invId'], $item->invoice_id);

        // Ensure financial counts and values are strictly unchanged
        $this->assertSame($countsBefore['invoices'] + 2, DB::table('invoices')->count());
        $this->assertSame($countsBefore['invoice_items'] + 2, DB::table('invoice_items')->count());
        $this->assertSame($countsBefore['payments'], DB::table('payments')->count());
        $this->assertSame($countsBefore['payment_allocations'], DB::table('payment_allocations')->count());
    }
}
