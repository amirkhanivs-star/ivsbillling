<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FeeAdjustmentService;
use App\Services\InvoiceService;
use App\Services\ReceivePaymentService;
use App\Services\ReceiptReversalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FeeCollectionAccountingTest extends TestCase
{
    use RefreshDatabase;

    private function setupAccountsData(): array
    {
        $this->seed();
        $campus = DB::table('campuses')->value('id');
        $dept = DB::table('departments')->where('code', 'school')->value('id');
        $family = DB::table('families')->insertGetId([
            'family_code' => 'FAM-TEST-001',
            'guardian_name' => 'Tariq Khan',
            'email' => 'tariq@example.test',
            'whatsapp' => '+923001234567',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $feeType = DB::table('fee_types')->where('code', 'monthly')->value('id');
        $user = DB::table('users')->insertGetId([
            'name' => 'Cashier Test',
            'email' => 'cashier@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'cashier',
            'campus_id' => $campus,
            'department_id' => $dept,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $student = DB::table('students')->insertGetId([
            'gr_number' => 'GR-900',
            'family_id' => $family,
            'name' => 'Aisha Tariq',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enroll = DB::table('enrollments')->insertGetId([
            'student_id' => $student,
            'campus_id' => $campus,
            'department_id' => $dept,
            'currency' => 'SAR',
            'monthly_fee_minor' => 30000, // 300 SAR
            'grade_label' => 'Grade 4',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $enroll,
            'fee_type_id' => $feeType,
            'description' => 'Monthly School Fee',
            'frequency' => 'monthly',
            'currency' => 'SAR',
            'amount_minor' => 30000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('campus', 'dept', 'family', 'student', 'enroll', 'user');
    }

    private function generateInvoice(array $d, string $period = '2026-10'): int
    {
        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'frequency' => 'monthly',
            'period_key' => 'monthly:' . $period . '-01..' . date('Y-m-t', strtotime($period . '-01')),
            'period_start' => $period . '-01',
            'period_end' => date('Y-m-t', strtotime($period . '-01')),
            'due_date' => $period . '-25',
            'created_by' => $d['user'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $res = app(InvoiceService::class)->generate($runId);
        return $res['invoices'][0] ?? DB::table('invoices')->where('billing_run_id', $runId)->value('id');
    }

    public function test_registration_and_monthly_fee_received_in_single_transaction_with_separate_allocations(): void
    {
        $d = $this->setupAccountsData();
        $invoiceId = $this->generateInvoice($d, '2026-10');

        $service = app(ReceivePaymentService::class);
        $payment = $service->receive([
            'family_id' => $d['family'],
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'currency' => 'SAR',
            'receive_registration_fee' => '1',
            'create_registration_charge' => '500.00',
            'registration_amount' => '200.00',
            'monthly_amount' => '300.00',
            'idempotency_key' => (string) Str::uuid(),
        ], $d['user']);

        // Single receipt with total 500 SAR
        $this->assertSame(50000, (int) $payment->amount_minor);
        $this->assertSame('received', $payment->status);

        // Verify two distinct payment allocations under the same payment ID
        $allocs = DB::table('payment_allocations')->where('payment_id', $payment->id)->get();
        $this->assertCount(2, $allocs);

        $regAlloc = $allocs->firstWhere('amount_minor', 20000);
        $monthlyAlloc = $allocs->firstWhere('amount_minor', 30000);
        $this->assertNotNull($regAlloc);
        $this->assertNotNull($monthlyAlloc);

        // Registration obligation has 300 SAR remaining due
        $regFeeTypeId = DB::table('fee_types')->where('code', 'registration')->value('id');
        $regItem = DB::table('invoice_items')->where('fee_type_id', $regFeeTypeId)->first();
        $this->assertSame(50000, (int) $regItem->net_minor);
        $this->assertSame(20000, (int) $regItem->paid_minor);

        // Duplicate registration charge creation is rejected
        $this->expectException(ValidationException::class);
        $service->receive([
            'family_id' => $d['family'],
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'currency' => 'SAR',
            'create_registration_charge' => '500.00',
            'registration_amount' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
        ], $d['user']);
    }

    public function test_month_specific_receiving_only_settles_targeted_month(): void
    {
        $d = $this->setupAccountsData();
        $this->generateInvoice($d, '2026-10');
        $this->generateInvoice($d, '2026-11');

        $service = app(ReceivePaymentService::class);
        // Pay targeting November 2026
        $payment = $service->receive([
            'family_id' => $d['family'],
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'currency' => 'SAR',
            'monthly_amount' => '300.00',
            'selected_period' => '2026-11',
            'idempotency_key' => (string) Str::uuid(),
        ], $d['user']);

        // Oct invoice is unpaid, Nov invoice is paid
        $octInvoice = DB::table('invoices')->where('due_date', '2026-10-25')->first();
        $novInvoice = DB::table('invoices')->where('due_date', '2026-11-25')->first();

        $this->assertSame(0, (int) $octInvoice->paid_minor);
        $this->assertSame('generated', $octInvoice->status);
        $this->assertSame(30000, (int) $novInvoice->paid_minor);
        $this->assertSame('paid', $novInvoice->status);
    }

    public function test_discount_reduces_balance_without_inflating_cash_received(): void
    {
        $d = $this->setupAccountsData();
        $invoiceId = $this->generateInvoice($d, '2026-10');
        $item = DB::table('invoice_items')->where('invoice_id', $invoiceId)->first();

        $adjService = app(FeeAdjustmentService::class);
        $adjService->applyDiscount($item->id, 5000, 'Academic excellence discount', $d['user']); // 50 SAR

        $updatedItem = DB::table('invoice_items')->where('id', $item->id)->first();
        $updatedInvoice = DB::table('invoices')->where('id', $invoiceId)->first();

        // Net balance reduced from 300 to 250
        $this->assertSame(5000, (int) $updatedItem->discount_minor);
        $this->assertSame(25000, (int) $updatedItem->net_minor);
        $this->assertSame(0, (int) $updatedItem->paid_minor); // Cash received is still 0!
        $this->assertSame(25000, (int) $updatedInvoice->total_minor);

        // Audit log and adjustment record created
        $this->assertTrue(DB::table('fee_adjustments')->where('invoice_item_id', $item->id)->where('kind', 'discount')->exists());

        // Discount cannot exceed remaining balance
        $this->expectException(ValidationException::class);
        $adjService->applyDiscount($item->id, 30000, 'Over discount', $d['user']);
    }

    public function test_fee_exemption_full_and_partial(): void
    {
        $d = $this->setupAccountsData();
        $invoiceId = $this->generateInvoice($d, '2026-10');
        $item = DB::table('invoice_items')->where('invoice_id', $invoiceId)->first();

        $adjService = app(FeeAdjustmentService::class);
        // Partial exemption of 100 SAR
        $adjService->applyExemption($item->id, 10000, 'Partial hardship waiver', $d['user']);

        $item1 = DB::table('invoice_items')->where('id', $item->id)->first();
        $this->assertSame(10000, (int) $item1->exemption_minor);
        $this->assertSame(20000, (int) $item1->net_minor);
        $this->assertSame('active', $item1->status);

        // Full exemption of the remaining 200 SAR
        $adjService->applyExemption($item->id, null, 'Full orphan waiver', $d['user']);
        $item2 = DB::table('invoice_items')->where('id', $item->id)->first();

        $this->assertSame(30000, (int) $item2->exemption_minor);
        $this->assertSame(0, (int) $item2->net_minor);
        $this->assertSame('exempted', $item2->status);

        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        $this->assertSame(0, (int) $invoice->total_minor);
        $this->assertSame('paid', $invoice->status);
    }

    public function test_installment_schedule_and_payment_sync(): void
    {
        $d = $this->setupAccountsData();
        $invoiceId = $this->generateInvoice($d, '2026-10');
        $item = DB::table('invoice_items')->where('invoice_id', $invoiceId)->first();

        $adjService = app(FeeAdjustmentService::class);
        // Schedule 2 installments: 100 SAR and 200 SAR
        $plan = $adjService->createInstallmentPlan($item->id, [
            ['amount_minor' => 10000, 'due_date' => '2026-10-30'],
            ['amount_minor' => 20000, 'due_date' => '2026-11-30'],
        ], $d['user']);

        $this->assertCount(2, $plan);
        $this->assertSame(2, DB::table('fee_installments')->where('invoice_item_id', $item->id)->count());

        // Paying 100 SAR satisfies installment #1
        app(ReceivePaymentService::class)->receive([
            'family_id' => $d['family'],
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'currency' => 'SAR',
            'monthly_amount' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
        ], $d['user']);

        $inst1 = DB::table('fee_installments')->where('invoice_item_id', $item->id)->where('installment_number', 1)->first();
        $inst2 = DB::table('fee_installments')->where('invoice_item_id', $item->id)->where('installment_number', 2)->first();

        $this->assertSame('paid', $inst1->status);
        $this->assertSame(10000, (int) $inst1->paid_minor);
        $this->assertSame('pending', $inst2->status);
    }

    public function test_receipt_reversal_restores_invoice_balances_and_prevents_double_reversal(): void
    {
        $d = $this->setupAccountsData();
        $invoiceId = $this->generateInvoice($d, '2026-10');

        $payment = app(ReceivePaymentService::class)->receive([
            'family_id' => $d['family'],
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'currency' => 'SAR',
            'monthly_amount' => '200.00',
            'idempotency_key' => (string) Str::uuid(),
        ], $d['user']);

        $itemBefore = DB::table('invoice_items')->where('invoice_id', $invoiceId)->first();
        $invoiceBefore = DB::table('invoices')->where('id', $invoiceId)->first();

        $this->assertSame(20000, (int) $itemBefore->paid_minor);
        $this->assertSame(20000, (int) $invoiceBefore->paid_minor);
        $this->assertSame('partial', $invoiceBefore->status);

        // Reverse the receipt
        $revService = app(ReceiptReversalService::class);
        $reversedPayment = $revService->reverseReceipt($payment->id, 'Entered wrong amount by mistake', $d['user']);

        $this->assertSame('reversed', $reversedPayment->status);
        $this->assertNotNull($reversedPayment->reversed_at);
        $this->assertSame('Entered wrong amount by mistake', $reversedPayment->reversal_reason);

        // Verify balances restored
        $itemAfter = DB::table('invoice_items')->where('invoice_id', $invoiceId)->first();
        $invoiceAfter = DB::table('invoices')->where('id', $invoiceId)->first();

        $this->assertSame(0, (int) $itemAfter->paid_minor);
        $this->assertSame(0, (int) $invoiceAfter->paid_minor);
        $this->assertSame('generated', $invoiceAfter->status); // Reverted back to generated/unpaid!

        // Attempting to reverse again is rejected
        $this->expectException(ValidationException::class);
        $revService->reverseReceipt($payment->id, 'Double reverse attempt', $d['user']);
    }

    public function test_void_unpaid_fee_obligation(): void
    {
        $d = $this->setupAccountsData();
        $invoiceId = $this->generateInvoice($d, '2026-10');
        $item = DB::table('invoice_items')->where('invoice_id', $invoiceId)->first();

        $adjService = app(FeeAdjustmentService::class);
        $adjService->voidFee($item->id, 'Student enrolled in wrong course', $d['user']);

        $voidedItem = DB::table('invoice_items')->where('id', $item->id)->first();
        $this->assertSame('voided', $voidedItem->status);
        $this->assertSame(0, (int) $voidedItem->net_minor);

        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        $this->assertSame(0, (int) $invoice->total_minor);
    }

    public function test_http_challan_endpoints_for_discount_and_reversal(): void
    {
        $d = $this->setupAccountsData();
        $user = User::findOrFail($d['user']);
        $invoiceId = $this->generateInvoice($d, '2026-10');
        $item = DB::table('invoice_items')->where('invoice_id', $invoiceId)->first();

        // 1. HTTP Discount
        $this->actingAs($user)->post('/challan/discount', [
            'invoice_item_id' => $item->id,
            'amount' => '30.00',
            'reason' => 'Online discount application',
        ])->assertRedirect();

        $itemAfterDisc = DB::table('invoice_items')->where('id', $item->id)->first();
        $this->assertSame(3000, (int) $itemAfterDisc->discount_minor);
        $this->assertSame(27000, (int) $itemAfterDisc->net_minor);

        // 2. HTTP Payment
        $this->actingAs($user)->post('/challan/receive', [
            'family_id' => $d['family'],
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'currency' => 'SAR',
            'receiving_amount' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $payment = DB::table('payments')->where('family_id', $d['family'])->first();
        $this->assertSame(10000, (int) $payment->amount_minor);

        // 3. HTTP Reversal
        $this->actingAs($user)->post('/challan/reverse-receipt', [
            'payment_id' => $payment->id,
            'reason' => 'Online reversal test',
        ])->assertRedirect();

        $paymentAfter = DB::table('payments')->where('id', $payment->id)->first();
        $this->assertSame('reversed', $paymentAfter->status);
    }

    public function test_myskool_fee_collection_ui_renders_outstanding_fees_and_history(): void
    {
        $d = $this->setupAccountsData();
        $user = User::findOrFail($d['user']);
        $invoiceId = $this->generateInvoice($d, '2026-10');
        $item = DB::table('invoice_items')->where('invoice_id', $invoiceId)->first();

        // Receive payment to generate fee history
        $this->actingAs($user)->post('/challan/receive', [
            'family_id' => $d['family'],
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'currency' => 'SAR',
            'receiving_amount' => '50.00',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $response = $this->actingAs($user)->get('/challan?family=FAM-TEST-001');
        $response->assertOk();
        $response->assertSee('Fee Collection');
        $response->assertSee('Aisha Tariq');
        $response->assertSee('GR-900');
        $response->assertSee('Grade 4');
        $response->assertSee('Session Fee (Gross)');
        $response->assertSee('Pending (Due)');
        $response->assertSee('Fee History');
        $response->assertSee('Cashier Test');
    }

    public function test_discount_ajax_returns_json_and_updates_live_state(): void
    {
        $d = $this->setupAccountsData();
        $user = User::findOrFail($d['user']);
        $invoiceId = $this->generateInvoice($d, '2026-10');
        $item = DB::table('invoice_items')->where('invoice_id', $invoiceId)->first();

        $response = $this->actingAs($user)->postJson('/challan/discount', [
            'invoice_item_id' => $item->id,
            'amount' => '25.00',
            'reason' => 'Ajax discount live update',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'discount_minor' => 2500,
            'balance_minor' => 27500,
        ]);
    }

    public function test_challan_search_by_invoice_number(): void
    {
        $d = $this->setupAccountsData();
        $user = User::findOrFail($d['user']);
        $invoiceId = $this->generateInvoice($d, '2026-10');
        $invoiceNumber = DB::table('invoices')->where('id', $invoiceId)->value('invoice_number');

        $response = $this->actingAs($user)->get('/challan?family=' . $invoiceNumber);
        $response->assertOk();
        $response->assertSee('FAM-TEST-001');
        $response->assertSee('Aisha Tariq');
    }

    public function test_challan_index_renders_without_query_params(): void
    {
        $d = $this->setupAccountsData();
        $user = User::findOrFail($d['user']);

        $response = $this->actingAs($user)->get('/challan');
        $response->assertOk();
        $response->assertSee('Fee Collection');
    }

    public function test_remove_discount_reverts_balance_and_records_audit(): void
    {
        $d = $this->setupAccountsData();
        $user = User::findOrFail($d['user']);
        $invoiceId = $this->generateInvoice($d, '2026-10');
        $item = DB::table('invoice_items')->where('invoice_id', $invoiceId)->first();

        // 1. Apply discount
        $this->actingAs($user)->postJson('/challan/discount', [
            'invoice_item_id' => $item->id,
            'amount' => '40.00',
            'reason' => 'Need based concession',
        ])->assertOk();

        $discountedItem = DB::table('invoice_items')->where('id', $item->id)->first();
        $this->assertSame(4000, (int) $discountedItem->discount_minor);
        $this->assertSame(26000, (int) $discountedItem->net_minor);

        // 2. Remove discount
        $response = $this->actingAs($user)->postJson('/challan/discount', [
            'invoice_item_id' => $item->id,
            'remove_discount' => 1,
            'reason' => 'Removed by admin request',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'discount_minor' => 0,
            'balance_minor' => 30000,
        ]);

        $restoredItem = DB::table('invoice_items')->where('id', $item->id)->first();
        $this->assertSame(0, (int) $restoredItem->discount_minor);
        $this->assertSame(30000, (int) $restoredItem->net_minor);

        // 3. Verify audit trail and adjustment record
        $this->assertDatabaseHas('fee_adjustments', [
            'invoice_item_id' => $item->id,
            'kind' => 'discount_removed',
            'amount_minor' => 4000,
            'previous_amount_minor' => 26000,
            'new_amount_minor' => 30000,
            'reason' => 'Removed by admin request',
        ]);
    }

    public function test_create_fee_in_fee_collection_generates_invoices_for_specific_entries_with_structure_and_admission_amounts(): void
    {
        $d = $this->setupAccountsData();
        $user = User::find($d['user']);

        // 1. Configure fee structure rate for SLC Charges
        $slcTypeId = DB::table('fee_types')->where('code', 'slc_charges')->value('id');
        if (!$slcTypeId) {
            $slcTypeId = DB::table('fee_types')->insertGetId([
                'name' => 'SLC Charges',
                'code' => 'slc_charges',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('fee_catalog_rates')->insert([
            'fee_type_id' => $slcTypeId,
            'department_id' => $d['dept'],
            'class_label' => 'Grade 4',
            'currency' => 'SAR',
            'amount_minor' => 5000, // 50.00 SAR decided in fee structure
            'frequency' => 'one_time',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $monthlyTypeId = DB::table('fee_types')->where('code', 'monthly')->value('id');

        // 2. Submit Create Fee from Fee Collection for Month 11 (Nov 2026)
        $response = $this->actingAs($user)->post('/challan/create-fee', [
            'family_id' => $d['family'],
            'fee_type_ids' => [$monthlyTypeId, $slcTypeId],
            'months' => [11],
            'year' => 2026,
        ]);

        $familyCode = DB::table('families')->where('id', $d['family'])->value('family_code');
        $response->assertRedirect('/challan?family=' . urlencode($familyCode));
        $response->assertSessionHas('notice');

        // 3. Verify Monthly Fee used admission amount (300.00 SAR = 30000 minor)
        $monthlyItem = DB::table('invoice_items')
            ->where('student_id', $d['student'])
            ->where('fee_type_id', $monthlyTypeId)
            ->where('period_start', '2026-11-01')
            ->first();
        $this->assertNotNull($monthlyItem);
        $this->assertSame(30000, (int) $monthlyItem->amount_minor);
        $this->assertSame(30000, (int) $monthlyItem->net_minor);
        $this->assertSame(0, (int) $monthlyItem->paid_minor);

        // 4. Verify SLC Charges used Fee Structure rate (50.00 SAR = 5000 minor)
        $slcItem = DB::table('invoice_items')
            ->where('student_id', $d['student'])
            ->where('fee_type_id', $slcTypeId)
            ->where('period_start', '2026-11-01')
            ->first();
        $this->assertNotNull($slcItem);
        $this->assertSame(5000, (int) $slcItem->amount_minor);
        $this->assertSame(5000, (int) $slcItem->net_minor);
        $this->assertSame('SLC Charges', $slcItem->description);

        // 5. Verify the invoice total is refreshed to 350.00 SAR (35000 minor)
        $invoice = DB::table('invoices')->where('id', $monthlyItem->invoice_id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame(35000, (int) $invoice->total_minor);

        // 6. Verify Fee Collection page renders both new entries in Outstanding Fees
        $page = $this->actingAs($user)->get('/challan?family=' . urlencode($familyCode));
        $page->assertOk();
        $page->assertSee('Monthly Fee');
        $page->assertSee('SLC Charges');
        $page->assertSee('Nov-2026');
        $page->assertSee('350');
    }
}
