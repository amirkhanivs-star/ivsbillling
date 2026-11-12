<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ReceivePaymentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentReceiptPdfDesignTest extends TestCase
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

    /**
     * Helper to create a student.
     */
    private function createStudent(int $familyId, string $name, string $gr): int
    {
        return DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'name' => $name,
            'gr_number' => $gr,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Helper to create an invoice with items.
     */
    private function createInvoice(int $familyId, string $invNumber, string $currency, array $items): int
    {
        $totalMinor = array_sum(array_column($items, 'net_minor'));
        $invId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $this->billingRunId,
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'invoice_number' => $invNumber,
            'currency' => $currency,
            'status' => 'issued',
            'total_minor' => $totalMinor,
            'paid_minor' => 0,
            'issued_on' => '2026-11-01',
            'due_date' => '2026-11-10',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($items as $item) {
            DB::table('invoice_items')->insertGetId([
                'invoice_id' => $invId,
                'student_id' => $item['student_id'],
                'fee_type_id' => 1,
                'currency' => $currency,
                'description' => $item['description'] ?? 'Tuition Fee Nov 2026',
                'amount_minor' => $item['amount_minor'],
                'discount_minor' => $item['discount_minor'] ?? 0,
                'net_minor' => $item['net_minor'],
                'paid_minor' => 0,
                'status' => 'active',
                'period_key' => '2026-11',
                'period_start' => '2026-11-01',
                'period_end' => '2026-11-30',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $invId;
    }

    /**
     * Test 1: Historical receipts maintain their original RCP-2026-0001784 format.
     */
    public function test_existing_historical_receipt_preserves_old_number_format_and_renders_cleanly(): void
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-HIST-01',
            'guardian_name' => 'Tariq Mehmood',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = $this->createStudent($familyId, 'Hamza Mehmood', 'GR-7788');

        $invoiceId = $this->createInvoice($familyId, 'SCH-HIST-001', 'SAR', [
            ['student_id' => $studentId, 'amount_minor' => 30000, 'discount_minor' => 0, 'net_minor' => 30000],
        ]);
        $itemId = DB::table('invoice_items')->where('invoice_id', $invoiceId)->value('id');

        // Historical payment explicitly created before this change
        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'currency' => 'SAR',
            'receipt_number' => 'RCP-2026-0001784',
            'amount_minor' => 30000,
            'unallocated_minor' => 0,
            'received_on' => '2026-10-15',
            'status' => 'received',
            'received_by' => $this->admin->id,
            'idempotency_key' => 'IDEM-HIST-1784',
            'request_hash' => hash('sha256', 'IDEM-HIST-1784'),
            'created_at' => '2026-10-15 10:00:00',
            'updated_at' => '2026-10-15 10:00:00',
        ]);

        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $itemId,
            'amount_minor' => 30000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Verify stored receipt number was NOT changed
        $stored = DB::table('payments')->where('id', $paymentId)->value('receipt_number');
        $this->assertSame('RCP-2026-0001784', $stored);

        // Render PDF via controller endpoint
        $response = $this->actingAs($this->admin)->get("/receipts/{$paymentId}/pdf");
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        // Inspect HTML view directly to verify exact string elements
        $viewData = [
            'p' => DB::table('payments')->where('id', $paymentId)->first(),
            'family' => DB::table('families')->where('id', $familyId)->first(),
            'branding' => DB::table('department_branding')->where('department_id', $this->departmentId)->first(),
            'banner' => null,
            'items' => DB::table('payment_allocations as a')
                ->join('invoice_items as i', 'i.id', '=', 'a.invoice_item_id')
                ->join('students as s', 's.id', '=', 'i.student_id')
                ->leftJoin('fee_types as ft', 'ft.id', '=', 'i.fee_type_id')
                ->where('a.payment_id', $paymentId)
                ->select('a.amount_minor', 'i.description', 'i.period_key', 'i.period_start', 'i.amount_minor as original_amount_minor', 'i.discount_minor', 'i.net_minor', 's.name', 's.gr_number', DB::raw("COALESCE(ft.name, i.description, 'Monthly Fee') as fee_type_name"))
                ->get(),
            'remainingBalanceMinor' => 0,
            'resolvedGuardian' => 'Tariq Mehmood',
        ];
        $html = view('pdf.receipt', $viewData)->render();

        $this->assertStringContainsString('RCP-2026-0001784', $html);
        $this->assertStringContainsString('Tariq Mehmood', $html);
        $this->assertStringContainsString('Hamza Mehmood', $html);
        $this->assertStringContainsString('SAR 300.00', $html);
        $this->assertStringContainsString('Receipt RCP-2026-0001784', $html);
    }

    /**
     * Test 2: Newly generated receipt via ReceivePaymentService uses simplified RCP-XXXX format.
     */
    public function test_newly_generated_receipt_via_receive_payment_service_uses_short_format(): void
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-NEW-01',
            'guardian_name' => 'Usman Ghani',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = $this->createStudent($familyId, 'Bilal Ghani', 'GR-9901');

        $this->createInvoice($familyId, 'SCH-NEW-001', 'SAR', [
            ['student_id' => $studentId, 'amount_minor' => 45000, 'discount_minor' => 0, 'net_minor' => 45000],
        ]);

        $service = app(ReceivePaymentService::class);
        $input = [
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'currency' => 'SAR',
            'receiving_amount' => '450.00',
            'receiving_date' => '2026-11-13',
            'idempotency_key' => (string) Str::uuid(),
            'note' => 'Cashier payment test',
        ];

        $payment = $service->receive($input, $this->admin->id);

        // Verification of simplified format: RCP-{$payment->id}
        $expectedReceiptNo = 'RCP-' . $payment->id;
        $this->assertSame($expectedReceiptNo, $payment->receipt_number);
        $this->assertStringNotContainsString('RCP-2026-', $payment->receipt_number);

        // Check stored DB record
        $dbReceiptNo = DB::table('payments')->where('id', $payment->id)->value('receipt_number');
        $this->assertSame($expectedReceiptNo, $dbReceiptNo);

        // Render PDF
        $response = $this->actingAs($this->admin)->get("/receipts/{$payment->id}/pdf");
        $response->assertOk();

        // Check HTML contains simplified receipt number and does NOT contain old format
        $viewData = [
            'p' => DB::table('payments')->where('id', $payment->id)->first(),
            'family' => DB::table('families')->where('id', $familyId)->first(),
            'branding' => null,
            'banner' => null,
            'items' => DB::table('payment_allocations as a')
                ->join('invoice_items as i', 'i.id', '=', 'a.invoice_item_id')
                ->join('students as s', 's.id', '=', 'i.student_id')
                ->leftJoin('fee_types as ft', 'ft.id', '=', 'i.fee_type_id')
                ->where('a.payment_id', $payment->id)
                ->select('a.amount_minor', 'i.description', 'i.period_key', 'i.period_start', 'i.amount_minor as original_amount_minor', 'i.discount_minor', 'i.net_minor', 's.name', 's.gr_number', DB::raw("COALESCE(ft.name, i.description, 'Monthly Fee') as fee_type_name"))
                ->get(),
            'remainingBalanceMinor' => 0,
            'resolvedGuardian' => 'Usman Ghani',
        ];
        $html = view('pdf.receipt', $viewData)->render();

        $this->assertStringContainsString($expectedReceiptNo, $html);
        $this->assertStringContainsString('Receipt ' . $expectedReceiptNo, $html);
        $this->assertStringNotContainsString('RCP-2026-', $html);
    }

    /**
     * Test 3: Receipt with multiple fee allocations across multiple students.
     */
    public function test_receipt_with_multiple_fee_allocations(): void
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-MULTI-01',
            'guardian_name' => 'Rashid Mehmood',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $s1 = $this->createStudent($familyId, 'Rashid Child 1', 'GR-5001');
        $s2 = $this->createStudent($familyId, 'Rashid Child 2', 'GR-5002');

        $this->createInvoice($familyId, 'SCH-MULTI-001', 'SAR', [
            ['student_id' => $s1, 'amount_minor' => 30000, 'discount_minor' => 0, 'net_minor' => 30000],
            ['student_id' => $s2, 'amount_minor' => 25000, 'discount_minor' => 0, 'net_minor' => 25000],
        ]);

        $service = app(ReceivePaymentService::class);
        $payment = $service->receive([
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'currency' => 'SAR',
            'receiving_amount' => '550.00',
            'receiving_date' => '2026-11-13',
            'idempotency_key' => (string) Str::uuid(),
        ], $this->admin->id);

        $allocations = DB::table('payment_allocations')->where('payment_id', $payment->id)->get();
        $this->assertCount(2, $allocations);

        $response = $this->actingAs($this->admin)->get("/receipts/{$payment->id}/pdf");
        $response->assertOk();
    }

    /**
     * Test 4: Receipt with discount applied.
     */
    public function test_receipt_with_discount_display(): void
    {
        $p = (object)[
            'id' => 999,
            'currency' => 'SAR',
            'receipt_number' => 'RCP-999',
            'status' => 'received',
            'received_on' => '2026-11-13',
            'created_at' => '2026-11-13 11:00:00',
            'amount_minor' => 35000,
            'unallocated_minor' => 0,
            'method' => null,
            'reference' => null,
            'reversed_at' => null,
            'reversal_reason' => null,
        ];

        $family = (object)[
            'id' => 50,
            'family_code' => 'FAM-DISC-01',
            'guardian_name' => 'Kareem Ahmed',
        ];

        $items = collect([
            (object)[
                'name' => 'Omar Ahmed',
                'gr_number' => 'GR-3344',
                'fee_type_name' => 'Monthly Tuition Fee',
                'description' => 'Tuition with sibling concession',
                'period_start' => '2026-11-01',
                'original_amount_minor' => 50000,
                'discount_minor' => 15000,
                'amount_minor' => 35000,
            ],
        ]);

        $html = view('pdf.receipt', [
            'p' => $p,
            'family' => $family,
            'items' => $items,
            'branding' => null,
            'banner' => null,
            'remainingBalanceMinor' => 0,
            'resolvedGuardian' => 'Kareem Ahmed',
        ])->render();

        $this->assertStringContainsString('500.00', $html); // Original
        $this->assertStringContainsString('150.00', $html); // Discount
        $this->assertStringContainsString('350.00', $html); // Applied
    }

    /**
     * Test 5: Receipt with remaining family balance.
     */
    public function test_receipt_with_remaining_family_balance(): void
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-BAL-01',
            'guardian_name' => 'Sohail Khan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $s1 = $this->createStudent($familyId, 'Zain Khan', 'GR-6001');

        // Invoice 1: 500.00 SAR
        $this->createInvoice($familyId, 'SCH-BAL-001', 'SAR', [
            ['student_id' => $s1, 'amount_minor' => 50000, 'discount_minor' => 0, 'net_minor' => 50000],
        ]);
        // Invoice 2: 300.00 SAR (will remain unpaid)
        $this->createInvoice($familyId, 'SCH-BAL-002', 'SAR', [
            ['student_id' => $s1, 'amount_minor' => 30000, 'discount_minor' => 0, 'net_minor' => 30000],
        ]);

        // Pay only Invoice 1 (500.00 SAR)
        $service = app(ReceivePaymentService::class);
        $payment = $service->receive([
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'currency' => 'SAR',
            'receiving_amount' => '500.00',
            'receiving_date' => '2026-11-13',
            'idempotency_key' => (string) Str::uuid(),
        ], $this->admin->id);

        // Render PDF
        $response = $this->actingAs($this->admin)->get("/receipts/{$payment->id}/pdf");
        $response->assertOk();

        $p = DB::table('payments')->where('id', $payment->id)->first();
        $family = DB::table('families')->where('id', $familyId)->first();
        $remainingBalanceMinor = (int)DB::table('invoices')
            ->where('family_id', $familyId)
            ->where('currency', 'SAR')
            ->whereNotIn('status', ['void', 'voided', 'paid'])
            ->selectRaw('COALESCE(SUM(total_minor - paid_minor), 0) as remaining')
            ->value('remaining');

        $this->assertSame(30000, $remainingBalanceMinor);

        $html = view('pdf.receipt', [
            'p' => $p,
            'family' => $family,
            'items' => collect([]),
            'branding' => null,
            'banner' => null,
            'remainingBalanceMinor' => $remainingBalanceMinor,
            'resolvedGuardian' => 'Sohail Khan',
        ])->render();

        $this->assertStringContainsString('Remaining family billed balance (SAR):', $html);
        $this->assertStringContainsString('SAR 300.00', $html);
    }

    /**
     * Test 6: Long student names and long fee descriptions wrap gracefully.
     */
    public function test_receipt_with_long_student_names_and_long_descriptions(): void
    {
        $p = (object)[
            'id' => 1234,
            'currency' => 'SAR',
            'receipt_number' => 'RCP-1234',
            'status' => 'received',
            'received_on' => '2026-11-13',
            'created_at' => '2026-11-13 10:00:00',
            'amount_minor' => 88000,
            'unallocated_minor' => 0,
            'method' => 'bank_transfer',
            'reference' => 'TXN-LONG-NAME-TEST',
            'reversed_at' => null,
            'reversal_reason' => null,
        ];

        $family = (object)[
            'id' => 99,
            'family_code' => 'FAM-LONG-99',
            'guardian_name' => 'Dr. Sheikh Mohammad Abdul Qadir Al-Jilani Al-Hasani',
        ];

        $items = collect([
            (object)[
                'name' => 'Syed Muhammad Abdullah Al-Hashimi Bin Abdul Rahman Al-Thani',
                'gr_number' => 'GR-SPECIAL-8888',
                'fee_type_name' => 'Annual Consolidated Composite Education Fee & Co-Curricular Assessment',
                'description' => 'First Installment including Laboratory, Technology, Library, Examination and Extracurricular Activity Infrastructure Surcharge 2026-2027',
                'period_start' => '2026-11-01',
                'original_amount_minor' => 88000,
                'discount_minor' => 0,
                'amount_minor' => 88000,
            ],
        ]);

        $html = view('pdf.receipt', [
            'p' => $p,
            'family' => $family,
            'items' => $items,
            'branding' => null,
            'banner' => null,
            'remainingBalanceMinor' => 0,
            'resolvedGuardian' => $family->guardian_name,
        ])->render();

        $this->assertStringContainsString('Syed Muhammad Abdullah Al-Hashimi Bin Abdul Rahman Al-Thani', $html);
        $this->assertStringContainsString('Annual Consolidated Composite Education Fee', $html);

        // DomPDF renders valid PDF with no overflow errors
        $pdf = Pdf::loadHTML($html)->setPaper('a4')->output();
        $this->assertNotEmpty($pdf);
        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    /**
     * Test 7: Reversed receipt layout displays void badge and void notices.
     */
    public function test_reversed_receipt_layout(): void
    {
        $p = (object)[
            'id' => 555,
            'currency' => 'SAR',
            'receipt_number' => 'RCP-555',
            'status' => 'reversed',
            'received_on' => '2026-11-10',
            'created_at' => '2026-11-10 09:00:00',
            'amount_minor' => 20000,
            'unallocated_minor' => 0,
            'method' => 'cash',
            'reference' => 'CASH-001',
            'reversed_at' => '2026-11-12 14:00:00',
            'reversal_reason' => 'Cheque returned unpaid by bank',
        ];

        $family = (object)[
            'id' => 12,
            'family_code' => 'FAM-REV-01',
            'guardian_name' => 'Farhan Qureshi',
        ];

        $html = view('pdf.receipt', [
            'p' => $p,
            'family' => $family,
            'items' => collect([]),
            'branding' => null,
            'banner' => null,
            'remainingBalanceMinor' => 20000,
            'resolvedGuardian' => 'Farhan Qureshi',
        ])->render();

        $this->assertStringContainsString('REVERSED PAYMENT RECEIPT', $html);
        $this->assertStringContainsString('VOID / REVERSED', $html);
        $this->assertStringContainsString('Cheque returned unpaid by bank', $html);
        $this->assertStringContainsString('ORIGINAL PAYMENT AMOUNT (REVERSED)', $html);
        $this->assertStringContainsString('Receipt RCP-555', $html);

        $pdf = Pdf::loadHTML($html)->setPaper('a4')->output();
        $this->assertNotEmpty($pdf);
        $this->assertStringStartsWith('%PDF-', $pdf);
    }
}
