<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ConsolidatedInvoiceBuilder;
use App\Services\FeeHistoryService;
use App\Services\Money;
use App\Services\ReceivePaymentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PHASE 4: Final Regression Testing and Financial Verification
 *
 * Verifies that the Monthly Fee Invoice PDF and Official Payment Receipt PDF
 * are visually correct, financially accurate, and 100% compatible with the billing ledger.
 */
class Phase4FinalRegressionAndVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected int $campusId;
    protected int $departmentId;
    protected int $billingRunId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::first() ?? User::create([
            'name' => 'Super Admin',
            'email' => 'admin@iqra.edu.pk',
            'password' => bcrypt('StrongSecret2026!'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->campusId = DB::table('campuses')->value('id') ?? DB::table('campuses')->insertGetId([
            'name' => 'Main Campus', 'code' => 'MAIN', 'created_at' => now(), 'updated_at' => now()
        ]);

        $this->departmentId = DB::table('departments')->where('code', 'SCH')->value('id') ?? DB::table('departments')->insertGetId([
            'name' => 'School', 'code' => 'SCH', 'created_at' => now(), 'updated_at' => now()
        ]);

        $this->billingRunId = DB::table('billing_runs')->insertGetId([
            'department_id' => $this->departmentId,
            'campus_id' => $this->campusId,
            'frequency' => 'monthly',
            'period_key' => '2026-11',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-10',
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createFamily(string $code = 'FAM-TEST-01', string $guardian = 'Tariq Mehmood'): int
    {
        return DB::table('families')->insertGetId([
            'family_code' => $code,
            'guardian_name' => $guardian,
            'whatsapp' => '+966500000001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createStudent(int $familyId, string $name, string $gr, string $status = 'present'): int
    {
        $stId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'name' => $name,
            'gr_number' => $gr,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('student_registration_details')->insert([
            'student_id' => $stId,
            'father_name' => 'Father of ' . $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $stId;
    }

    private function createInvoice(int $familyId, string $invNumber, string $currency, array $items, string $status = 'issued', ?string $dueDate = '2026-11-10', ?string $issuedOn = '2026-11-01'): int
    {
        $totalMinor = array_sum(array_column($items, 'net_minor'));
        $paidMinor = array_sum(array_column($items, 'paid_minor'));

        $invId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $this->billingRunId,
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'invoice_number' => $invNumber,
            'currency' => $currency,
            'status' => $status,
            'total_minor' => $totalMinor,
            'paid_minor' => $paidMinor,
            'issued_on' => $issuedOn,
            'due_date' => $dueDate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($items as $item) {
            DB::table('invoice_items')->insertGetId([
                'invoice_id' => $invId,
                'student_id' => $item['student_id'],
                'fee_type_id' => $item['fee_type_id'] ?? 1,
                'currency' => $currency,
                'description' => $item['description'] ?? 'Tuition Fee',
                'amount_minor' => $item['amount_minor'],
                'discount_minor' => $item['discount_minor'] ?? 0,
                'net_minor' => $item['net_minor'],
                'paid_minor' => $item['paid_minor'] ?? 0,
                'status' => ($item['paid_minor'] ?? 0) >= $item['net_minor'] ? 'paid' : (($item['paid_minor'] ?? 0) > 0 ? 'partial' : 'active'),
                'period_key' => $item['period_key'] ?? '2026-11',
                'period_start' => $item['period_start'] ?? '2026-11-01',
                'period_end' => $item['period_end'] ?? '2026-11-30',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $invId;
    }

    // =========================================================================
    // SECTION 2: VERIFY MONTHLY FEE INVOICE FINANCIAL ACCURACY
    // =========================================================================

    /**
     * Test 2.1: Single student, one monthly fee invoice financial reconciliation.
     */
    public function test_monthly_invoice_single_student_single_fee_financial_accuracy(): void
    {
        $familyId = $this->createFamily('FAM-SINGLE-01', 'Zahid Ali');
        $studentId = $this->createStudent($familyId, 'Bilal Ali', 'GR-1001');

        $invId = $this->createInvoice($familyId, 'SCH-2026-1001', 'SAR', [
            ['student_id' => $studentId, 'amount_minor' => 35000, 'discount_minor' => 0, 'net_minor' => 35000, 'paid_minor' => 0],
        ]);

        $invoice = DB::table('invoices')->where('id', $invId)->first();
        $builder = app(ConsolidatedInvoiceBuilder::class);
        $viewData = $builder->buildForInvoice($invoice, $this->admin);

        // Verify ledger snapshot
        $this->assertCount(1, $viewData['currencyGroups']);
        $cGroup = $viewData['currencyGroups']->first();
        $this->assertSame('SAR', $cGroup['currency']);
        $this->assertSame(35000, $cGroup['total_balance_minor']);
        $this->assertCount(1, $cGroup['students']);

        // Check HTML output
        $html = view('pdf.consolidated-challan', $viewData)->render();

        $this->assertStringContainsString('Invoice No: 1001', $html);
        $this->assertStringContainsString('Zahid Ali', $html);
        $this->assertStringContainsString('Bilal Ali', $html);
        $this->assertStringContainsString('350.00', $html);

        // Ensure no database records were modified during rendering
        $afterInv = DB::table('invoices')->where('id', $invId)->first();
        $this->assertSame($invoice->total_minor, $afterInv->total_minor);
        $this->assertSame($invoice->paid_minor, $afterInv->paid_minor);
    }

    /**
     * Test 2.2: Multiple students in same family, multiple months, historical dues, discounts, long descriptions.
     */
    public function test_monthly_invoice_complex_multi_student_multi_month_accuracy(): void
    {
        $familyId = $this->createFamily('FAM-MULTI-99', 'Sheikh Muhammad Al-Otaibi');
        $s1 = $this->createStudent($familyId, 'Abdul Rahman Al-Otaibi', 'GR-2001');
        $s2 = $this->createStudent($familyId, 'Fatimah bint Muhammad Al-Otaibi', 'GR-2002');
        $s3 = $this->createStudent($familyId, 'Zayd ibn Muhammad Al-Otaibi', 'GR-2003');

        // Past October Invoice with partial payment (remaining due: 200 SAR)
        $invOct = $this->createInvoice($familyId, 'SCH-2026-OCT', 'SAR', [
            [
                'student_id' => $s1,
                'amount_minor' => 50000,
                'discount_minor' => 10000, // Sibling discount
                'net_minor' => 40000,
                'paid_minor' => 20000,
                'period_key' => '2026-10',
                'period_start' => '2026-10-01',
                'description' => 'Tuition Fee - October 2026 Advanced Secondary Stage Curriculum',
            ],
        ], 'partially_paid', '2026-10-10', '2026-10-01');

        // Current November Invoice across all 3 students with different fees
        $invNov = $this->createInvoice($familyId, 'SCH-2026-NOV', 'SAR', [
            [
                'student_id' => $s1,
                'amount_minor' => 50000,
                'discount_minor' => 10000,
                'net_minor' => 40000,
                'paid_minor' => 0,
                'period_key' => '2026-11',
                'period_start' => '2026-11-01',
                'description' => 'Tuition Fee - November 2026 Advanced Secondary Stage Curriculum',
            ],
            [
                'student_id' => $s2,
                'amount_minor' => 45000,
                'discount_minor' => 5000,
                'net_minor' => 40000,
                'paid_minor' => 0,
                'period_key' => '2026-11',
                'period_start' => '2026-11-01',
                'description' => 'Middle School Composite Tuition Fee November 2026',
            ],
            [
                'student_id' => $s3,
                'amount_minor' => 30000,
                'discount_minor' => 0,
                'net_minor' => 30000,
                'paid_minor' => 0,
                'period_key' => '2026-11',
                'period_start' => '2026-11-01',
                'description' => 'Primary Stage Tuition Fee November 2026',
            ],
        ], 'issued', '2026-11-10', '2026-11-01');

        // Total expected outstanding:
        // s1 Oct remaining: 200 SAR
        // s1 Nov remaining: 400 SAR (Subtotal s1 = 600 SAR)
        // s2 Nov remaining: 400 SAR (Subtotal s2 = 400 SAR)
        // s3 Nov remaining: 300 SAR (Subtotal s3 = 300 SAR)
        // Family Grand Total Outstanding = 1300.00 SAR
        $builder = app(ConsolidatedInvoiceBuilder::class);
        $invoiceNovObj = DB::table('invoices')->where('id', $invNov)->first();
        $viewData = $builder->buildForInvoice($invoiceNovObj, $this->admin);

        $cGroup = $viewData['currencyGroups']->first();
        $this->assertSame(130000, $cGroup['total_balance_minor'], 'Family outstanding must equal 1,300.00 SAR');
        $this->assertCount(3, $cGroup['students']);

        // Check student subtotals
        $s1Group = $cGroup['students'][$s1];
        $this->assertSame(60000, $s1Group['student_subtotal_balance_minor'], 'Student 1 subtotal must be 600.00 SAR');
        $s2Group = $cGroup['students'][$s2];
        $this->assertSame(40000, $s2Group['student_subtotal_balance_minor'], 'Student 2 subtotal must be 400.00 SAR');
        $s3Group = $cGroup['students'][$s3];
        $this->assertSame(30000, $s3Group['student_subtotal_balance_minor'], 'Student 3 subtotal must be 300.00 SAR');

        // Check HTML rendering
        $html = view('pdf.consolidated-challan', $viewData)->render();
        $this->assertStringContainsString('1,300.00', $html);
        $this->assertStringContainsString('600.00', $html);
        $this->assertStringContainsString('400.00', $html);
        $this->assertStringContainsString('300.00', $html);
        $this->assertStringContainsString('Prior Overdue', $html); // Oct fee is prior overdue

        // Ledger records must remain completely unchanged
        $this->assertSame(20000, DB::table('invoice_items')->where('invoice_id', $invOct)->value('paid_minor'));
        $this->assertSame(40000, DB::table('invoice_items')->where('invoice_id', $invOct)->value('net_minor'));
    }

    // =========================================================================
    // SECTION 3: VERIFY OFFICIAL PAYMENT RECEIPT FINANCIAL ACCURACY
    // =========================================================================

    /**
     * Test 3.1: Receipt with multiple fee allocations across multiple months and students.
     */
    public function test_payment_receipt_multi_allocation_multi_month_accuracy(): void
    {
        $familyId = $this->createFamily('FAM-RCP-01', 'Kamran Akmal');
        $s1 = $this->createStudent($familyId, 'Haris Kamran', 'GR-3001');
        $s2 = $this->createStudent($familyId, 'Saad Kamran', 'GR-3002');

        $invId = $this->createInvoice($familyId, 'SCH-KAMRAN-01', 'SAR', [
            [
                'student_id' => $s1,
                'amount_minor' => 40000,
                'discount_minor' => 5000,
                'net_minor' => 35000,
                'paid_minor' => 0,
                'period_start' => '2026-10-01',
                'description' => 'Tuition October 2026',
            ],
            [
                'student_id' => $s2,
                'amount_minor' => 35000,
                'discount_minor' => 0,
                'net_minor' => 35000,
                'paid_minor' => 0,
                'period_start' => '2026-11-01',
                'description' => 'Tuition November 2026',
            ],
        ]);

        $service = app(ReceivePaymentService::class);
        $payment = $service->receive([
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'currency' => 'SAR',
            'receiving_amount' => '700.00',
            'receiving_date' => '2026-11-13',
            'idempotency_key' => (string) Str::uuid(),
            'note' => 'Payment for Haris and Saad',
        ], $this->admin->id);

        $allocations = DB::table('payment_allocations')->where('payment_id', $payment->id)->get();
        $this->assertCount(2, $allocations);
        $this->assertSame(70000, (int)$allocations->sum('amount_minor'));

        $response = $this->actingAs($this->admin)->get("/receipts/{$payment->id}/pdf");
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        // Verify PDF HTML content
        $items = DB::table('payment_allocations as a')
            ->join('invoice_items as i', 'i.id', '=', 'a.invoice_item_id')
            ->join('students as s', 's.id', '=', 'i.student_id')
            ->leftJoin('fee_types as ft', 'ft.id', '=', 'i.fee_type_id')
            ->where('a.payment_id', $payment->id)
            ->select('a.amount_minor', 'i.description', 'i.period_key', 'i.period_start', 'i.amount_minor as original_amount_minor', 'i.discount_minor', 'i.net_minor', 's.name', 's.gr_number', DB::raw("COALESCE(ft.name, i.description, 'Monthly Fee') as fee_type_name"))
            ->get();

        $html = view('pdf.receipt', [
            'p' => DB::table('payments')->where('id', $payment->id)->first(),
            'family' => DB::table('families')->where('id', $familyId)->first(),
            'branding' => null,
            'banner' => null,
            'items' => $items,
            'remainingBalanceMinor' => 0,
            'resolvedGuardian' => 'Kamran Akmal',
        ])->render();

        $this->assertStringContainsString('TOTAL PAYMENT RECEIVED', $html);
        $this->assertStringContainsString('SAR 700.00', $html);
        $this->assertStringContainsString('Haris Kamran', $html);
        $this->assertStringContainsString('Saad Kamran', $html);
        $this->assertStringContainsString('400.00', $html); // Haris original
        $this->assertStringContainsString('50.00', $html);  // Haris discount
        $this->assertStringContainsString('350.00', $html); // Haris net applied
    }

    /**
     * Test 3.2: Partial payment leaves accurate remaining balance on receipt.
     */
    public function test_payment_receipt_partial_payment_and_remaining_balance(): void
    {
        $familyId = $this->createFamily('FAM-PART-01', 'Nadeem Omar');
        $s1 = $this->createStudent($familyId, 'Sameer Nadeem', 'GR-4001');

        $this->createInvoice($familyId, 'SCH-NAD-01', 'SAR', [
            ['student_id' => $s1, 'amount_minor' => 80000, 'discount_minor' => 0, 'net_minor' => 80000, 'paid_minor' => 0],
        ]);

        $service = app(ReceivePaymentService::class);
        $payment = $service->receive([
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'currency' => 'SAR',
            'receiving_amount' => '300.00',
            'receiving_date' => '2026-11-13',
            'idempotency_key' => (string) Str::uuid(),
        ], $this->admin->id);

        $remainingBalanceMinor = (int)DB::table('invoices')
            ->where('family_id', $familyId)
            ->where('currency', 'SAR')
            ->whereNotIn('status', ['void', 'voided', 'paid'])
            ->selectRaw('COALESCE(SUM(total_minor - paid_minor), 0) as remaining')
            ->value('remaining');

        $this->assertSame(50000, $remainingBalanceMinor, 'Remaining balance must be 500.00 SAR');

        $html = view('pdf.receipt', [
            'p' => DB::table('payments')->where('id', $payment->id)->first(),
            'family' => DB::table('families')->where('id', $familyId)->first(),
            'branding' => null,
            'banner' => null,
            'items' => collect([]),
            'remainingBalanceMinor' => $remainingBalanceMinor,
            'resolvedGuardian' => 'Nadeem Omar',
        ])->render();

        $this->assertStringContainsString('SAR 300.00', $html);
        $this->assertStringContainsString('Remaining family billed balance (SAR):', $html);
        $this->assertStringContainsString('SAR 500.00', $html);
    }

    // =========================================================================
    // SECTION 4: VERIFY OLD AND NEW RECEIPT NUMBER FORMATS
    // =========================================================================

    /**
     * Test 4.1: Historical receipts retain RCP-2026-0001784; newly generated receipts use RCP-{payments.id}.
     */
    public function test_old_and_new_receipt_number_formats_and_search_resolution(): void
    {
        $familyId = $this->createFamily('FAM-FMT-01', 'Arshad Nadeem');
        $s1 = $this->createStudent($familyId, 'Subhan Arshad', 'GR-5001');

        $invId = $this->createInvoice($familyId, 'SCH-FMT-01', 'SAR', [
            ['student_id' => $s1, 'amount_minor' => 25000, 'discount_minor' => 0, 'net_minor' => 25000, 'paid_minor' => 0],
        ]);
        $itemId = DB::table('invoice_items')->where('invoice_id', $invId)->value('id');

        // 1. Create historical payment with RCP-2026-0001784
        $histPayId = DB::table('payments')->insertGetId([
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'currency' => 'SAR',
            'receipt_number' => 'RCP-2026-0001784',
            'amount_minor' => 25000,
            'unallocated_minor' => 0,
            'received_on' => '2026-10-15',
            'status' => 'received',
            'received_by' => $this->admin->id,
            'idempotency_key' => 'HIST-UUID-1784',
            'request_hash' => hash('sha256', 'HIST-UUID-1784'),
            'created_at' => '2026-10-15 10:00:00',
            'updated_at' => '2026-10-15 10:00:00',
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $histPayId,
            'invoice_item_id' => $itemId,
            'amount_minor' => 25000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Historical receipt number must not change
        $this->assertSame('RCP-2026-0001784', DB::table('payments')->where('id', $histPayId)->value('receipt_number'));

        // 2. Generate new receipt via ReceivePaymentService
        $this->createInvoice($familyId, 'SCH-FMT-02', 'SAR', [
            ['student_id' => $s1, 'amount_minor' => 30000, 'discount_minor' => 0, 'net_minor' => 30000, 'paid_minor' => 0],
        ]);
        $service = app(ReceivePaymentService::class);
        $newPay = $service->receive([
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'currency' => 'SAR',
            'receiving_amount' => '300.00',
            'receiving_date' => '2026-11-13',
            'idempotency_key' => (string) Str::uuid(),
        ], $this->admin->id);

        $expectedNewNumber = 'RCP-' . $newPay->id;
        $this->assertSame($expectedNewNumber, $newPay->receipt_number);

        // Verify PDF route succeeds for both
        $histResp = $this->actingAs($this->admin)->get("/receipts/{$histPayId}/pdf");
        $histResp->assertOk();
        $this->assertSame('application/pdf', $histResp->headers->get('content-type'));
        $this->assertNotEmpty($histResp->getContent());

        $newResp = $this->actingAs($this->admin)->get("/receipts/{$newPay->id}/pdf");
        $newResp->assertOk();
        $this->assertSame('application/pdf', $newResp->headers->get('content-type'));
        $this->assertNotEmpty($newResp->getContent());

        // Verify HTML views display correct receipt numbers
        $histHtml = view('pdf.receipt', [
            'p' => DB::table('payments')->where('id', $histPayId)->first(),
            'family' => DB::table('families')->where('id', $familyId)->first(),
            'branding' => null,
            'banner' => null,
            'items' => collect([]),
            'remainingBalanceMinor' => 0,
            'resolvedGuardian' => 'Arshad Nadeem',
        ])->render();
        $this->assertStringContainsString('RCP-2026-0001784', $histHtml);

        $newHtml = view('pdf.receipt', [
            'p' => DB::table('payments')->where('id', $newPay->id)->first(),
            'family' => DB::table('families')->where('id', $familyId)->first(),
            'branding' => null,
            'banner' => null,
            'items' => collect([]),
            'remainingBalanceMinor' => 0,
            'resolvedGuardian' => 'Arshad Nadeem',
        ])->render();
        $this->assertStringContainsString($expectedNewNumber, $newHtml);
        $this->assertStringNotContainsString('RCP-2026-', $newHtml);

        // 3. Search resolution for both formats
        $feeHistoryService = app(FeeHistoryService::class);
        $foundHist = $feeHistoryService->getReceiptByNumber('RCP-2026-0001784', $this->admin);
        $this->assertNotNull($foundHist);
        $this->assertSame($histPayId, (int)($foundHist->payment_id ?? $foundHist->id));

        $foundNew = $feeHistoryService->getReceiptByNumber($expectedNewNumber, $this->admin);
        $this->assertNotNull($foundNew);
        $this->assertSame($newPay->id, (int)($foundNew->payment_id ?? $foundNew->id));
    }

    // =========================================================================
    // SECTION 5: VERIFY CLICKABLE UPLOAD PAYMENT RECEIPT BUTTON
    // =========================================================================

    /**
     * Test 5.1: Upload Payment Receipt button styling, placement, and PDF hyperlink annotation.
     */
    public function test_clickable_upload_payment_receipt_button_in_invoice_pdf(): void
    {
        $familyId = $this->createFamily('FAM-BTN-01', 'Farooq Azam');
        $s1 = $this->createStudent($familyId, 'Babar Farooq', 'GR-6001');

        $invId = $this->createInvoice($familyId, 'SCH-BTN-01', 'SAR', [
            ['student_id' => $s1, 'amount_minor' => 45000, 'discount_minor' => 0, 'net_minor' => 45000, 'paid_minor' => 0],
        ]);

        $invoice = DB::table('invoices')->where('id', $invId)->first();
        $builder = app(ConsolidatedInvoiceBuilder::class);
        $viewData = $builder->buildForInvoice($invoice, $this->admin);
        $html = view('pdf.consolidated-challan', $viewData)->render();

        // 1. Verify ALREADY PAID? section
        $this->assertStringContainsString('ALREADY PAID?', $html);
        $this->assertStringContainsString('If you have already made your payment, please submit your payment receipt for verification.', $html);

        // 2. Verify button styling and link
        $expectedUrl = 'https://forms.gle/W4y3Q1VjyU8cRDvp7';
        $this->assertStringContainsString('href="' . $expectedUrl . '"', $html);
        $this->assertStringContainsString('class="btn-upload"', $html);
        $this->assertStringContainsString('Upload Payment Receipt', $html);

        // 3. Verify CSS properties in template
        $this->assertStringContainsString('.btn-upload { display:inline-block; background-color:#982931;', $html);
        $this->assertStringContainsString('color:#ffffff;', $html);

        // 4. Verify DomPDF generates valid PDF binary containing the URI hyperlink annotation
        $pdf = Pdf::loadHTML($html)->setPaper('a4')->output();
        $this->assertNotEmpty($pdf);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('/URI (https://forms.gle/W4y3Q1VjyU8cRDvp7)', $pdf, 'PDF must contain real clickable hyperlink annotation');
    }

    // =========================================================================
    // SECTION 6: VERIFY PAID STAMP BEHAVIOR
    // =========================================================================

    /**
     * Test 6.1: Monthly fee invoice does not display green PAID stamp when outstanding dues exist.
     */
    public function test_monthly_invoice_outstanding_never_shows_green_paid_stamp(): void
    {
        $familyId = $this->createFamily('FAM-STAMP-01', 'Irfan Pathan');
        $s1 = $this->createStudent($familyId, 'Yusuf Pathan', 'GR-7001');

        $invId = $this->createInvoice($familyId, 'SCH-STAMP-01', 'SAR', [
            ['student_id' => $s1, 'amount_minor' => 50000, 'discount_minor' => 0, 'net_minor' => 50000, 'paid_minor' => 0],
        ]);

        $invoice = DB::table('invoices')->where('id', $invId)->first();
        $builder = app(ConsolidatedInvoiceBuilder::class);
        $viewData = $builder->buildForInvoice($invoice, $this->admin);
        $html = view('pdf.consolidated-challan', $viewData)->render();

        $this->assertStringNotContainsString('alt="Fee Paid Stamp"', $html);
        $this->assertStringNotContainsString('alt="Official School Stamp"', $html);
        // Authorized signature remains
        $this->assertStringContainsString('alt="Authorized Signature"', $html);
    }

    /**
     * Test 6.2: Payment receipt displays green fee-paid stamp for received payments, and VOID stamp for reversed payments.
     */
    public function test_payment_receipt_paid_stamp_and_reversed_status_rules(): void
    {
        // 1. Valid received payment
        $pReceived = (object)[
            'id' => 888,
            'currency' => 'SAR',
            'receipt_number' => 'RCP-888',
            'status' => 'received',
            'received_on' => '2026-11-13',
            'created_at' => '2026-11-13 10:00:00',
            'amount_minor' => 40000,
            'unallocated_minor' => 0,
        ];
        $family = (object)['id' => 1, 'family_code' => 'FAM-888', 'guardian_name' => 'Salim Khan'];

        $htmlReceived = view('pdf.receipt', [
            'p' => $pReceived,
            'family' => $family,
            'branding' => null,
            'banner' => null,
            'items' => collect([]),
            'remainingBalanceMinor' => 0,
            'resolvedGuardian' => 'Salim Khan',
        ])->render();

        $this->assertStringContainsString('alt="Fee Paid Stamp"', $htmlReceived);
        $this->assertStringContainsString('alt="Authorized Signature"', $htmlReceived);
        $this->assertStringNotContainsString('VOID / REVERSED', $htmlReceived);

        // 2. Reversed payment
        $pReversed = (object)[
            'id' => 889,
            'currency' => 'SAR',
            'receipt_number' => 'RCP-889',
            'status' => 'reversed',
            'received_on' => '2026-11-13',
            'created_at' => '2026-11-13 10:00:00',
            'amount_minor' => 40000,
            'unallocated_minor' => 0,
            'reversed_at' => '2026-11-13 12:00:00',
            'reversal_reason' => 'Duplicate payment recorded in error',
        ];

        $htmlReversed = view('pdf.receipt', [
            'p' => $pReversed,
            'family' => $family,
            'branding' => null,
            'banner' => null,
            'items' => collect([]),
            'remainingBalanceMinor' => 40000,
            'resolvedGuardian' => 'Salim Khan',
        ])->render();

        // Must NOT show green fee paid stamp on reversed receipt
        $this->assertStringNotContainsString('alt="Fee Paid Stamp"', $htmlReversed);
        $this->assertStringContainsString('VOID / REVERSED', $htmlReversed);
        $this->assertStringContainsString('REVERSED PAYMENT RECEIPT', $htmlReversed);
        $this->assertStringContainsString('Duplicate payment recorded in error', $htmlReversed);
    }

    // =========================================================================
    // SECTION 7: VERIFY PDF LAYOUT AND PAGINATION
    // =========================================================================

    /**
     * Test 7.1: Single-page invoice renders exactly 1 page with footer.
     */
    public function test_single_page_invoice_layout_and_pagination(): void
    {
        $familyId = $this->createFamily('FAM-PAG-01', 'Naveed Iqbal');
        $s1 = $this->createStudent($familyId, 'Hamza Naveed', 'GR-8001');

        $invId = $this->createInvoice($familyId, 'SCH-PAG-01', 'SAR', [
            ['student_id' => $s1, 'amount_minor' => 30000, 'discount_minor' => 0, 'net_minor' => 30000, 'paid_minor' => 0],
        ]);

        $invoice = DB::table('invoices')->where('id', $invId)->first();
        $builder = app(ConsolidatedInvoiceBuilder::class);
        $viewData = $builder->buildForInvoice($invoice, $this->admin);
        $html = view('pdf.consolidated-challan', $viewData)->render();

        $dompdf = Pdf::loadHTML($html)->setPaper('a4');
        $dompdf->render();

        $pageCount = $dompdf->getDomPDF()->getCanvas()->get_page_count();
        $this->assertSame(1, $pageCount, 'Single student single fee must render on exactly 1 page');

        // Confirm footer present in output
        $this->assertStringContainsString('IQRA VIRTUAL SCHOOL &bull; +92 325 524551 &bull; acivs2021@gmail.com', $html);
    }

    /**
     * Test 7.2: Large family invoice with multiple students and multiple months paginates cleanly without clipping.
     */
    public function test_large_family_multi_page_invoice_paginates_cleanly(): void
    {
        $familyId = $this->createFamily('FAM-LARGE-01', 'Al-Haj Muhammad Siddiq');
        $students = [];
        for ($i = 1; $i <= 6; $i++) {
            $students[$i] = $this->createStudent($familyId, "Siddiq Student {$i}", "GR-900{$i}");
        }

        // Create 3 months of fees for each of the 6 students = 18 fee rows
        $items = [];
        $months = ['2026-09-01', '2026-10-01', '2026-11-01'];
        foreach ($students as $idx => $stId) {
            foreach ($months as $m) {
                $items[] = [
                    'student_id' => $stId,
                    'amount_minor' => 35000,
                    'discount_minor' => 0,
                    'net_minor' => 35000,
                    'paid_minor' => 0,
                    'period_key' => substr($m, 0, 7),
                    'period_start' => $m,
                    'description' => "Tuition Fee {$m} Comprehensive Secondary Academic Education",
                ];
            }
        }

        $invId = $this->createInvoice($familyId, 'SCH-LARGE-01', 'SAR', $items);
        $invoice = DB::table('invoices')->where('id', $invId)->first();
        $builder = app(ConsolidatedInvoiceBuilder::class);
        $viewData = $builder->buildForInvoice($invoice, $this->admin);
        $html = view('pdf.consolidated-challan', $viewData)->render();

        $dompdf = Pdf::loadHTML($html)->setPaper('a4');
        $dompdf->render();

        $pageCount = $dompdf->getDomPDF()->getCanvas()->get_page_count();
        $this->assertGreaterThan(1, $pageCount, '18 fee items across 6 students must paginate cleanly across multiple pages');

        // Render PDF binary
        $pdfOutput = $dompdf->output();
        $this->assertNotEmpty($pdfOutput);
        $this->assertStringStartsWith('%PDF-', $pdfOutput);
    }

    // =========================================================================
    // SECTION 8: VERIFY BANK DETAILS AND PAYPAL QR
    // =========================================================================

    /**
     * Test 8.1: Bank details and PayPal QR code in Monthly Fee Invoice.
     */
    public function test_bank_details_and_paypal_qr_code_integrity(): void
    {
        $familyId = $this->createFamily('FAM-BANK-01', 'Zafar Abbas');
        $s1 = $this->createStudent($familyId, 'Ali Zafar', 'GR-9901');

        $invId = $this->createInvoice($familyId, 'SCH-BANK-01', 'SAR', [
            ['student_id' => $s1, 'amount_minor' => 20000, 'discount_minor' => 0, 'net_minor' => 20000, 'paid_minor' => 0],
        ]);

        $invoice = DB::table('invoices')->where('id', $invId)->first();
        $builder = app(ConsolidatedInvoiceBuilder::class);
        $viewData = $builder->buildForInvoice($invoice, $this->admin);
        $html = view('pdf.consolidated-challan', $viewData)->render();

        // 1. Meezan Bank details
        $this->assertStringContainsString('IQRA VIRTUAL SOLUTIONS', $html);
        $this->assertStringContainsString('Meezan Bank Peshawar', $html);
        $this->assertStringContainsString('PK71MEZN0007810111367793', $html);
        $this->assertStringContainsString('07810111367793', $html);
        $this->assertStringContainsString('0781', $html);
        $this->assertStringContainsString('+92 325 524551', $html);

        // 2. Mashreq Bank details
        $this->assertStringContainsString('IVSGLOBAL', $html);
        $this->assertStringContainsString('Mashreq Bank', $html);
        $this->assertStringContainsString('AE620330000019101455931', $html);
        $this->assertStringContainsString('019101455931', $html);
        $this->assertStringContainsString('+971528838128', $html);
        $this->assertStringContainsString('Ajman Industrial Area 2, UAE', $html);

        // 3. PayPal QR code
        $this->assertStringContainsString('PayPal QR Code', $html);
        $this->assertStringContainsString('alt="Scan to pay"', $html);
        $this->assertStringContainsString('data:image/jpeg;base64,', $html);
    }
}
