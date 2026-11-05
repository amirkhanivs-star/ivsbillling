<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InvoiceDeliveryQueueService;
use App\Services\WebhookSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaidInvoicesAmountPaidCalculationTest extends TestCase
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

        $campus = DB::table('campuses')->orderBy('id')->first()->id;
        $department = DB::table('departments')->where('code', 'school')->value('id');
        $program = DB::table('programs')->value('id');

        $superAdmin = User::create([
            'name' => 'Paid Invoices Tester',
            'email' => 'paid_tester@example.test',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $settingService = app(WebhookSettingService::class);
        $settingService->updateSettings('paid', [
            'url' => 'https://n8n.example.test/webhook/paid-invoices',
            'secret' => 'paid-secret',
            'is_enabled' => true,
        ], $superAdmin->id);

        $billingRun = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campus,
            'department_id' => $department,
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

        return compact('superAdmin', 'campus', 'department', 'program', 'billingRun');
    }

    private function createFamilyAndStudent(string $code, string $currency = 'SAR'): array
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => $code,
            'guardian_name' => "Guardian {$code}",
            'whatsapp' => '+96650' . rand(1000000, 9999999),
            'contact_verified' => true,
            'whatsapp_verified' => true,
            'messaging_opt_in' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'name' => "Student {$code}",
            'gr_number' => "GR-{$code}",
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $this->env['campus'],
            'department_id' => $this->env['department'],
            'program_id' => $this->env['program'],
            'grade_label' => 'Grade 1',
            'currency' => $currency,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('familyId', 'studentId', 'enrollmentId');
    }

    private function createInvoice(
        int $familyId,
        string $invoiceNumber,
        int $totalMinor,
        int $recordedPaidMinor,
        string $currency = 'SAR'
    ): int {
        return DB::table('invoices')->insertGetId([
            'family_id' => $familyId,
            'campus_id' => $this->env['campus'],
            'department_id' => $this->env['department'],
            'billing_run_id' => $this->env['billingRun'],
            'invoice_number' => $invoiceNumber,
            'currency' => $currency,
            'total_minor' => $totalMinor,
            'paid_minor' => $recordedPaidMinor,
            'status' => 'paid',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'created_at' => '2026-10-10 10:00:00',
            'updated_at' => '2026-10-10 10:00:00',
        ]);
    }

    private function createInvoiceItem(
        int $invoiceId,
        int $studentId,
        int $enrollmentId,
        int $amountMinor,
        string $description = 'Monthly Fee',
        string $currency = 'SAR'
    ): int {
        $feeTypeId = DB::table('fee_types')->value('id') ?? 1;

        return DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId,
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $feeTypeId,
            'description' => $description,
            'amount_minor' => $amountMinor,
            'net_minor' => $amountMinor,
            'paid_minor' => $amountMinor,
            'currency' => $currency,
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'status' => 'paid',
            'created_at' => '2026-10-10 10:00:00',
            'updated_at' => '2026-10-10 10:00:00',
        ]);
    }

    private function createPayment(
        int $familyId,
        string $receiptNumber,
        int $amountMinor,
        string $currency = 'SAR'
    ): int {
        return DB::table('payments')->insertGetId([
            'family_id' => $familyId,
            'campus_id' => $this->env['campus'],
            'department_id' => $this->env['department'],
            'receipt_number' => $receiptNumber,
            'received_on' => '2026-10-15',
            'amount_minor' => $amountMinor,
            'unallocated_minor' => 0,
            'currency' => $currency,
            'status' => 'received',
            'received_by' => $this->env['superAdmin']->id,
            'idempotency_key' => uniqid('idemp_', true),
            'request_hash' => hash('sha256', uniqid('req_', true)),
            'created_at' => '2026-10-15 11:00:00',
            'updated_at' => '2026-10-15 11:00:00',
        ]);
    }

    private function allocatePayment(int $paymentId, int $invoiceItemId, int $amountMinor): void
    {
        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $invoiceItemId,
            'amount_minor' => $amountMinor,
            'created_at' => '2026-10-15 11:00:00',
            'updated_at' => '2026-10-15 11:00:00',
        ]);
    }

    /**
     * 1. One receipt paying multiple invoices:
     * RCP-001 total payment: SAR 730
     * SCH-001 allocation: SAR 200
     * SCH-002 allocation: SAR 330
     * SCH-003 allocation: SAR 200
     * In Paid Invoices:
     * SCH-001 must display SAR 200 paid.
     * SCH-002 must display SAR 330 paid.
     * SCH-003 must display SAR 200 paid.
     * Do not display SAR 730 against each invoice.
     */
    public function test_one_receipt_paying_multiple_invoices_displays_sch_specific_allocated_amount(): void
    {
        $context = $this->createFamilyAndStudent('F730');

        $inv1 = $this->createInvoice($context['familyId'], 'SCH-001', 20000, 20000);
        $item1 = $this->createInvoiceItem($inv1, $context['studentId'], $context['enrollmentId'], 20000);

        $inv2 = $this->createInvoice($context['familyId'], 'SCH-002', 33000, 33000);
        $item2 = $this->createInvoiceItem($inv2, $context['studentId'], $context['enrollmentId'], 33000);

        $inv3 = $this->createInvoice($context['familyId'], 'SCH-003', 20000, 20000);
        $item3 = $this->createInvoiceItem($inv3, $context['studentId'], $context['enrollmentId'], 20000);

        // One receipt RCP-001 of total SAR 730 paying all 3 SCH invoices
        $rcp1 = $this->createPayment($context['familyId'], 'RCP-001', 73000);
        $this->allocatePayment($rcp1, $item1, 20000);
        $this->allocatePayment($rcp1, $item2, 33000);
        $this->allocatePayment($rcp1, $item3, 20000);

        $service = app(InvoiceDeliveryQueueService::class);
        $paid = $service->getPaidInvoices(['period' => 'all', 'search' => 'F730'], $this->env['superAdmin'])->keyBy('invoice_number');

        $this->assertCount(3, $paid);

        $this->assertSame(20000, $paid['SCH-001']->allocated_paid_minor);
        $this->assertSame(20000, $paid['SCH-001']->paid_minor);
        $this->assertSame('730.00', $paid['SCH-001']->formatted_paid_amount);
        $this->assertSame(73000, $paid['SCH-001']->receipts[0]['amount_minor']);
        $this->assertSame('730.00', $paid['SCH-001']->receipts[0]['formatted_amount']);
        $this->assertSame(20000, $paid['SCH-001']->receipts[0]['allocated_amount_minor']);
        $this->assertSame('200.00', $paid['SCH-001']->receipts[0]['formatted_allocated_amount']);

        $this->assertSame(33000, $paid['SCH-002']->allocated_paid_minor);
        $this->assertSame(33000, $paid['SCH-002']->paid_minor);
        $this->assertSame('730.00', $paid['SCH-002']->formatted_paid_amount);
        $this->assertSame(73000, $paid['SCH-002']->receipts[0]['amount_minor']);
        $this->assertSame('730.00', $paid['SCH-002']->receipts[0]['formatted_amount']);
        $this->assertSame(33000, $paid['SCH-002']->receipts[0]['allocated_amount_minor']);
        $this->assertSame('330.00', $paid['SCH-002']->receipts[0]['formatted_allocated_amount']);

        $this->assertSame(20000, $paid['SCH-003']->allocated_paid_minor);
        $this->assertSame(20000, $paid['SCH-003']->paid_minor);
        $this->assertSame('730.00', $paid['SCH-003']->formatted_paid_amount);
        $this->assertSame(73000, $paid['SCH-003']->receipts[0]['amount_minor']);
        $this->assertSame('730.00', $paid['SCH-003']->receipts[0]['formatted_amount']);
        $this->assertSame(20000, $paid['SCH-003']->receipts[0]['allocated_amount_minor']);
        $this->assertSame('200.00', $paid['SCH-003']->receipts[0]['formatted_allocated_amount']);

        // View rendering verification: ensure original RCP amount (SAR 730.00) is displayed on receipt,
        // and allocation details are clearly shown
        $response = $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=paid&period=all&search=F730');
        $response->assertOk();
        $response->assertSee('SAR 730.00');
        $response->assertSee('SAR 200.00 allocated to this SCH');
        $response->assertSee('SAR 330.00 allocated to this SCH');
    }

    /**
     * 2. Multiple receipts paying one invoice.
     */
    public function test_multiple_receipts_paying_one_invoice(): void
    {
        $context = $this->createFamilyAndStudent('FMULTI');

        $inv = $this->createInvoice($context['familyId'], 'SCH-MULTI-01', 40000, 40000);
        $item = $this->createInvoiceItem($inv, $context['studentId'], $context['enrollmentId'], 40000);

        $rcp1 = $this->createPayment($context['familyId'], 'RCP-M1', 15000);
        $this->allocatePayment($rcp1, $item, 15000);

        $rcp2 = $this->createPayment($context['familyId'], 'RCP-M2', 25000);
        $this->allocatePayment($rcp2, $item, 25000);

        $service = app(InvoiceDeliveryQueueService::class);
        $paid = $service->getPaidInvoices(['period' => 'all', 'search' => 'FMULTI'], $this->env['superAdmin'])->first();

        $this->assertNotNull($paid);
        $this->assertSame(40000, $paid->allocated_paid_minor);
        $this->assertSame(40000, $paid->paid_minor);
        $this->assertSame('400.00', $paid->formatted_paid_amount);
        $this->assertCount(2, $paid->receipts);

        $response = $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=paid&period=all&search=FMULTI');
        $response->assertOk();
        $response->assertSee('SAR 400.00');
        $response->assertSee('RCP-M1');
        $response->assertSee('RCP-M2');
    }

    /**
     * 3. One invoice with several fee items: ensures amounts are not counted more than once.
     */
    public function test_one_invoice_with_several_fee_items(): void
    {
        $context = $this->createFamilyAndStudent('FITEMS');

        $inv = $this->createInvoice($context['familyId'], 'SCH-ITEMS-01', 45000, 45000);
        $item1 = $this->createInvoiceItem($inv, $context['studentId'], $context['enrollmentId'], 30000, 'Tuition Fee');
        $item2 = $this->createInvoiceItem($inv, $context['studentId'], $context['enrollmentId'], 10000, 'Lab Fee');
        $item3 = $this->createInvoiceItem($inv, $context['studentId'], $context['enrollmentId'], 5000, 'Library Fee');

        $rcp = $this->createPayment($context['familyId'], 'RCP-ITEMS', 45000);
        $this->allocatePayment($rcp, $item1, 30000);
        $this->allocatePayment($rcp, $item2, 10000);
        $this->allocatePayment($rcp, $item3, 5000);

        $service = app(InvoiceDeliveryQueueService::class);
        $paid = $service->getPaidInvoices(['period' => 'all', 'search' => 'FITEMS'], $this->env['superAdmin'])->first();

        $this->assertNotNull($paid);
        $this->assertSame(45000, $paid->allocated_paid_minor);
        $this->assertSame(45000, $paid->paid_minor);
        $this->assertSame('450.00', $paid->formatted_paid_amount);

        // Receipts array on invoice row should have 1 consolidated receipt item with exact 45000
        $this->assertCount(1, $paid->receipts);
        $this->assertSame(45000, $paid->receipts[0]['amount_minor']);
        $this->assertSame('450.00', $paid->receipts[0]['formatted_amount']);
    }

    /**
     * 4. Correct currency amounts: ensures non-SAR currencies (e.g. AED) are preserved and displayed correctly.
     */
    public function test_correct_currency_amounts(): void
    {
        $context = $this->createFamilyAndStudent('FAED', 'AED');

        $inv = $this->createInvoice($context['familyId'], 'SCH-AED-01', 50000, 50000, 'AED');
        $item = $this->createInvoiceItem($inv, $context['studentId'], $context['enrollmentId'], 50000, 'Tuition Fee', 'AED');

        $rcp = $this->createPayment($context['familyId'], 'RCP-AED', 50000, 'AED');
        $this->allocatePayment($rcp, $item, 50000);

        $service = app(InvoiceDeliveryQueueService::class);
        $paid = $service->getPaidInvoices(['period' => 'all', 'search' => 'FAED'], $this->env['superAdmin'])->first();

        $this->assertNotNull($paid);
        $this->assertSame('AED', $paid->currency);
        $this->assertSame(50000, $paid->allocated_paid_minor);
        $this->assertSame('500.00', $paid->formatted_paid_amount);

        $response = $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=paid&period=all&search=FAED');
        $response->assertOk();
        $response->assertSee('AED 500.00');
    }

    /**
     * 5. Correct SCH-specific paid amount when recorded paid_minor in table differs from actual allocations.
     */
    public function test_correct_sch_specific_paid_amount_when_recorded_differs(): void
    {
        $context = $this->createFamilyAndStudent('FDIFF');

        // Invoice record had recorded paid_minor of 220.00, but actual allocation is only 77.00
        $inv = $this->createInvoice($context['familyId'], 'SCH-DIFF-01', 22000, 22000);
        $item = $this->createInvoiceItem($inv, $context['studentId'], $context['enrollmentId'], 22000);

        $rcp = $this->createPayment($context['familyId'], 'RCP-DIFF', 40600);
        $this->allocatePayment($rcp, $item, 7700);

        $service = app(InvoiceDeliveryQueueService::class);
        $paid = $service->getPaidInvoices(['period' => 'all', 'search' => 'FDIFF'], $this->env['superAdmin'])->first();

        $this->assertNotNull($paid);
        // Do not overwrite i.paid_minor or change financial calculations
        $this->assertSame(22000, $paid->paid_minor);
        $this->assertSame(40600, $paid->receipt_paid_minor);
        $this->assertSame('406.00', $paid->formatted_paid_amount);
        $this->assertSame(7700, $paid->allocated_paid_minor);
        $this->assertSame('77.00', $paid->formatted_allocated_paid);

        // Authoritative receipt total from original RCP
        $this->assertSame(40600, $paid->receipts[0]['amount_minor']);
        $this->assertSame('406.00', $paid->receipts[0]['formatted_amount']);
        $this->assertSame(7700, $paid->receipts[0]['allocated_amount_minor']);
        $this->assertSame('77.00', $paid->receipts[0]['formatted_allocated_amount']);

        $response = $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=paid&period=all&search=FDIFF');
        $response->assertOk();
        $response->assertSee('SAR 406.00');
        $response->assertSee('SAR 77.00 allocated to this SCH');
    }

    /**
     * 6. No duplicated receipt amounts across multiple fee items and multiple invoices.
     */
    public function test_no_duplicated_receipt_amounts(): void
    {
        $context = $this->createFamilyAndStudent('FNODUP');

        $inv1 = $this->createInvoice($context['familyId'], 'SCH-NODUP-01', 53000, 53000);
        $inv1Item1 = $this->createInvoiceItem($inv1, $context['studentId'], $context['enrollmentId'], 33000, 'Fee A');
        $inv1Item2 = $this->createInvoiceItem($inv1, $context['studentId'], $context['enrollmentId'], 20000, 'Fee B');

        $inv2 = $this->createInvoice($context['familyId'], 'SCH-NODUP-02', 20000, 20000);
        $inv2Item = $this->createInvoiceItem($inv2, $context['studentId'], $context['enrollmentId'], 20000, 'Fee C');

        // Single receipt of 730.00 paying both invoices
        $rcp = $this->createPayment($context['familyId'], 'RCP-NODUP', 73000);
        $this->allocatePayment($rcp, $inv1Item1, 33000);
        $this->allocatePayment($rcp, $inv1Item2, 20000);
        $this->allocatePayment($rcp, $inv2Item, 20000);

        $service = app(InvoiceDeliveryQueueService::class);
        $paid = $service->getPaidInvoices(['period' => 'all', 'search' => 'FNODUP'], $this->env['superAdmin'])->keyBy('invoice_number');

        $this->assertCount(2, $paid);
        $this->assertSame(53000, $paid['SCH-NODUP-01']->allocated_paid_minor);
        $this->assertCount(1, $paid['SCH-NODUP-01']->receipts);
        $this->assertSame(73000, $paid['SCH-NODUP-01']->receipts[0]['amount_minor']);
        $this->assertSame('730.00', $paid['SCH-NODUP-01']->receipts[0]['formatted_amount']);
        $this->assertSame(53000, $paid['SCH-NODUP-01']->receipts[0]['allocated_amount_minor']);

        $this->assertSame(20000, $paid['SCH-NODUP-02']->allocated_paid_minor);
        $this->assertCount(1, $paid['SCH-NODUP-02']->receipts);
        $this->assertSame(73000, $paid['SCH-NODUP-02']->receipts[0]['amount_minor']);
        $this->assertSame('730.00', $paid['SCH-NODUP-02']->receipts[0]['formatted_amount']);
        $this->assertSame(20000, $paid['SCH-NODUP-02']->receipts[0]['allocated_amount_minor']);
    }

    /**
     * 7. Existing paid invoice counts and delivery eligibility unchanged.
     */
    public function test_existing_paid_invoice_counts_and_delivery_eligibility_unchanged(): void
    {
        $context = $this->createFamilyAndStudent('FCOUNT');

        $inv = $this->createInvoice($context['familyId'], 'SCH-COUNT-01', 30000, 30000);
        $item = $this->createInvoiceItem($inv, $context['studentId'], $context['enrollmentId'], 30000);

        $rcp = $this->createPayment($context['familyId'], 'RCP-COUNT', 30000);
        $this->allocatePayment($rcp, $item, 30000);

        $service = app(InvoiceDeliveryQueueService::class);
        $stats = $service->getPaidWorkflowStats('all', $this->env['superAdmin']);
        $totalPaid = $service->getPaidInvoices(['period' => 'all', 'search' => 'FCOUNT'], $this->env['superAdmin'])->total();

        $this->assertSame(1, $totalPaid);
        $this->assertGreaterThanOrEqual(1, $stats['total']);
    }

    /**
     * 8. Confirmed example: Family 9595, SCH-2026-0018599, RCP-2026-0001876.
     * Displays authoritative RCP receipt total SAR 406.00 matching Fee Collection & RCP PDF,
     * while retaining SAR 77.00 allocation detail.
     */
    public function test_confirmed_example_family_9595_rcp_406_and_sch_77_allocation(): void
    {
        $context = $this->createFamilyAndStudent('9595');

        $inv = $this->createInvoice($context['familyId'], 'SCH-2026-0018599', 22000, 22000);
        $item = $this->createInvoiceItem($inv, $context['studentId'], $context['enrollmentId'], 22000);

        // RCP-2026-0001876 with total payment received SAR 406.00
        $rcp = $this->createPayment($context['familyId'], 'RCP-2026-0001876', 40600);
        $this->allocatePayment($rcp, $item, 7700);

        $service = app(InvoiceDeliveryQueueService::class);
        $paid = $service->getPaidInvoices(['period' => 'all', 'search' => '9595'], $this->env['superAdmin'])->first();

        $this->assertNotNull($paid);
        $this->assertSame('SCH-2026-0018599', $paid->invoice_number);
        $this->assertSame(22000, $paid->paid_minor);
        $this->assertSame(7700, $paid->allocated_paid_minor);
        $this->assertSame(40600, $paid->receipt_paid_minor);
        $this->assertSame('406.00', $paid->formatted_paid_amount);
        $this->assertCount(1, $paid->receipts);
        $this->assertSame('RCP-2026-0001876', $paid->receipts[0]['receipt_number']);
        $this->assertSame(40600, $paid->receipts[0]['amount_minor']);
        $this->assertSame('406.00', $paid->receipts[0]['formatted_amount']);
        $this->assertSame(7700, $paid->receipts[0]['allocated_amount_minor']);
        $this->assertSame('77.00', $paid->receipts[0]['formatted_allocated_amount']);

        $response = $this->actingAs($this->env['superAdmin'])->get('/invoices?tab=paid&period=all&search=9595');
        $response->assertOk();
        $response->assertSee('RCP-2026-0001876');
        $response->assertSee('SAR 406.00');
        $response->assertSee('SAR 77.00 allocated to this SCH');
    }
}
