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

        return compact('campus', 'dept', 'family', 'student', 'enroll', 'feeType', 'user') + ['enrollment' => $enroll];
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
        $this->assertSame('voided', $invoice->status);
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

    /**
     * Regression 1: Fully settled historical fee in the same currency is skipped during InvoiceService::generate()
     * (e.g., Slip 25170 March–June 2026 Monthly Fee in SAR for GR 4039, 4040, 4052).
     */
    public function test_settled_same_currency_historical_fee_is_skipped_in_recurring_invoice_generation(): void
    {
        $d = $this->setupAccountsData();
        $batchId = (string) config('imports.active_batch_id', 'BATCH-20261023-FINAL-MYSKOOL');

        // Align student GR and currency with Slip 25170 example (GR 4040, SAR 320.00)
        DB::table('students')->where('id', $d['student'])->update(['gr_number' => '4040']);
        DB::table('enrollments')->where('id', $d['enrollment'])->update([
            'currency' => 'SAR',
            'monthly_fee_minor' => 32000,
        ]);

        DB::table('fee_plan_items')->where('enrollment_id', $d['enrollment'])->delete();
        $planItemId = DB::table('fee_plan_items')->insertGetId([
            'enrollment_id' => $d['enrollment'],
            'fee_type_id' => $d['feeType'],
            'description' => 'Monthly Fee',
            'frequency' => 'monthly',
            'currency' => 'SAR',
            'amount_minor' => 32000,
            'discount_kind' => 'none',
            'discount_value' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed Paid details staging row for June 2026 (Slip 25170) — zero rows in qa_staged_dues
        DB::table('qa_staged_collections')->insert([
            'batch_id' => $batchId,
            'source_row' => 31343,
            'slip_number' => '25170',
            'gr_number' => '4040',
            'student_name' => 'Ibrahim Majid Ahmed',
            'family_code' => '4040',
            'fee_particular' => 'Monthly Fee - Jun-2026',
            'currency' => 'SAR',
            'registration_currency' => 'SAR',
            'amount' => 320.00,
            'amount_minor' => 32000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $junRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-06-01..2026-06-30',
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'due_date' => '2026-06-25',
            'status' => 'draft',
            'created_by' => $d['user'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = app(InvoiceService::class);
        $resJun = $svc->generate($junRunId);

        $this->assertSame(0, $resJun['created']);
        $this->assertSame(1, $resJun['skipped']);
        $this->assertSame(0, DB::table('invoice_items')
            ->where('fee_plan_item_id', $planItemId)
            ->where('period_key', 'monthly:2026-06-01..2026-06-30')
            ->count());

        // New period after migration cutoff (Nov 2026) with no historical staging rows generates normally
        $novRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-25',
            'status' => 'draft',
            'created_by' => $d['user'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resNov = $svc->generate($novRunId);
        $this->assertSame(1, $resNov['created']);
        $this->assertSame(0, $resNov['skipped']);
    }

    /**
     * Regression 2: Fully settled historical fee in the same currency is blocked in BillingController::createChallanFee()
     * with "Payment has already been received for this fee and period."
     */
    public function test_settled_same_currency_historical_fee_is_blocked_in_manual_challan_fee_creation(): void
    {
        $d = $this->setupAccountsData();
        $user = User::find($d['user']);
        $batchId = (string) config('imports.active_batch_id', 'BATCH-20261023-FINAL-MYSKOOL');

        DB::table('students')->where('id', $d['student'])->update(['gr_number' => '4039']);
        DB::table('enrollments')->where('id', $d['enrollment'])->update([
            'currency' => 'SAR',
            'monthly_fee_minor' => 27000,
        ]);

        DB::table('qa_staged_collections')->insert([
            'batch_id' => $batchId,
            'source_row' => 31340,
            'slip_number' => '25170',
            'gr_number' => '4039',
            'student_name' => 'Rayyan Majid Ahmed',
            'family_code' => '4040',
            'fee_particular' => 'Monthly Fee - Jun-2026',
            'currency' => 'SAR',
            'registration_currency' => 'SAR',
            'amount' => 270.00,
            'amount_minor' => 27000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->from('/challan?family=FAM-TEST-001')
            ->post('/challan/create-fee', [
                'family_code' => 'FAM-TEST-001',
                'student_ids' => [$d['student']],
                'fee_type_ids' => [$d['feeType']],
                'months' => [6],
                'year' => 2026,
            ]);

        $response->assertRedirect('/challan?family=FAM-TEST-001');
        $response->assertSessionHasErrors([
            'fee_type_ids' => 'Payment has already been received for this fee and period.',
        ]);

        $this->assertSame(0, DB::table('invoice_items')
            ->where('student_id', $d['student'])
            ->where('period_start', '2026-06-01')
            ->count());
    }

    /**
     * Regression 3: Partially paid historical fee preserves the workbook's residual outstanding balance
     * without subtracting historical paid collections a second time, and blocks duplicate recurring billing.
     */
    public function test_partially_paid_historical_fee_preserves_residual_outstanding_without_double_subtraction(): void
    {
        $batchId = (string) config('imports.active_batch_id', 'BATCH-20261023-FINAL-MYSKOOL');

        // Student paid 150.00 SAR in Paid details and still owes 170.00 SAR residual in Outstanding (Compiled Valid)
        DB::table('qa_staged_collections')->insert([
            'batch_id' => $batchId,
            'source_row' => 101,
            'slip_number' => '19001',
            'gr_number' => '5101',
            'student_name' => 'Partial Student',
            'fee_particular' => 'Monthly Fee - Sep-2026',
            'currency' => 'SAR',
            'registration_currency' => 'SAR',
            'amount' => 150.00,
            'amount_minor' => 15000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('qa_staged_dues')->insert([
            'batch_id' => $batchId,
            'source_row' => 202,
            'gr_number' => '5101',
            'student_name' => 'Partial Student',
            'fee_particular' => 'Monthly Fee - Sep-2026',
            'currency' => 'SAR',
            'registration_currency' => 'SAR',
            'outstanding_amount' => 170.00,
            'outstanding_amount_minor' => 17000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $histSvc = app(\App\Services\Migration\HistoricalOpeningBalanceService::class);
        $map = $histSvc->buildHistoricalObligationMap(['5101'], $batchId);

        $check = \App\Services\Migration\HistoricalOpeningBalanceService::checkHistoricalObligation(
            $map,
            '5101',
            'monthly',
            '2026-09-01',
            'SAR'
        );

        $this->assertNotNull($check);
        $this->assertSame('partial_same_currency', $check['status']);
        $this->assertFalse($check['is_settled']);
        $this->assertTrue($check['blocks_billing']);
        $this->assertSame(15000, $check['paid_minor']);
        // Residual outstanding must remain untouched at 17000 minor (never 17000 - 15000 = 2000)
        $this->assertSame(17000, $check['outstanding_minor']);
    }

    /**
     * Regression 4: Completely unpaid historical fee in qa_staged_dues is classified as unpaid_same_currency
     * (represented via opening balances and blocked from duplicate recurring generation).
     */
    public function test_unpaid_historical_fee_in_staged_dues_blocks_duplicate_recurring_generation(): void
    {
        $batchId = (string) config('imports.active_batch_id', 'BATCH-20261023-FINAL-MYSKOOL');

        DB::table('qa_staged_dues')->insert([
            'batch_id' => $batchId,
            'source_row' => 601,
            'gr_number' => '4040',
            'student_name' => 'Ibrahim Majid Ahmed',
            'fee_particular' => 'Monthly Fee - Jul-2026',
            'currency' => 'SAR',
            'registration_currency' => 'SAR',
            'outstanding_amount' => 320.00,
            'outstanding_amount_minor' => 32000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $histSvc = app(\App\Services\Migration\HistoricalOpeningBalanceService::class);
        $map = $histSvc->buildHistoricalObligationMap(['4040'], $batchId);

        $check = \App\Services\Migration\HistoricalOpeningBalanceService::checkHistoricalObligation(
            $map,
            '4040',
            'monthly',
            '2026-07-01',
            'SAR'
        );

        $this->assertNotNull($check);
        $this->assertSame('unpaid_same_currency', $check['status']);
        $this->assertFalse($check['is_settled']);
        $this->assertTrue($check['blocks_billing']);
        $this->assertSame(0, $check['paid_minor']);
        $this->assertSame(32000, $check['outstanding_minor']);
    }

    /**
     * Regression 5: Paid Monthly Fee in a month does NOT block Admission Fee, Registration Fee, or Subject Fee
     * in that same month.
     */
    public function test_paid_monthly_fee_does_not_block_other_fee_types_or_subject_fees_in_same_month(): void
    {
        $batchId = (string) config('imports.active_batch_id', 'BATCH-20261023-FINAL-MYSKOOL');

        DB::table('qa_staged_collections')->insert([
            'batch_id' => $batchId,
            'source_row' => 500,
            'slip_number' => '21000',
            'gr_number' => '4489',
            'student_name' => 'Subject Test Student',
            'fee_particular' => 'Monthly Fee - Jan-2026',
            'currency' => 'SAR',
            'registration_currency' => 'SAR',
            'amount' => 200.00,
            'amount_minor' => 20000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $histSvc = app(\App\Services\Migration\HistoricalOpeningBalanceService::class);
        $map = $histSvc->buildHistoricalObligationMap(['4489'], $batchId);

        // Regular Monthly Fee for Jan-2026 is settled
        $monthlyCheck = \App\Services\Migration\HistoricalOpeningBalanceService::checkHistoricalObligation(
            $map,
            '4489',
            'monthly',
            '2026-01-01',
            'SAR',
            null
        );
        $this->assertNotNull($monthlyCheck);
        $this->assertSame('settled_same_currency', $monthlyCheck['status']);

        // Subject-specific Monthly Fee (Urdu) for Jan-2026 is a distinct obligation and is NOT settled
        $urduSubjectCheck = \App\Services\Migration\HistoricalOpeningBalanceService::checkHistoricalObligation(
            $map,
            '4489',
            'monthly',
            '2026-01-01',
            'SAR',
            'Urdu'
        );
        $this->assertNull($urduSubjectCheck);

        // One-time Admission Fee and Registration Fee are distinct fee_type_codes and are NOT settled
        $admissionCheck = \App\Services\Migration\HistoricalOpeningBalanceService::checkHistoricalObligation(
            $map,
            '4489',
            'admission',
            '2026-01-01',
            'SAR'
        );
        $this->assertNull($admissionCheck);

        $registrationCheck = \App\Services\Migration\HistoricalOpeningBalanceService::checkHistoricalObligation(
            $map,
            '4489',
            'registration',
            '2026-01-01',
            'SAR'
        );
        $this->assertNull($registrationCheck);
    }

    /**
     * Regression 6: Multiple payment rows across slips in the same currency for the same obligation
     * aggregate cleanly and mark the obligation settled when no residual due exists.
     */
    public function test_multiple_same_currency_slips_for_one_obligation_aggregate_cleanly(): void
    {
        $batchId = (string) config('imports.active_batch_id', 'BATCH-20261023-FINAL-MYSKOOL');

        DB::table('qa_staged_collections')->insert([
            [
                'batch_id' => $batchId,
                'source_row' => 701,
                'slip_number' => '22001',
                'gr_number' => '5200',
                'student_name' => 'Split Slip Student',
                'fee_particular' => 'Monthly Fee - May-2026',
                'currency' => 'AED',
                'registration_currency' => 'AED',
                'amount' => 100.00,
                'amount_minor' => 10000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'batch_id' => $batchId,
                'source_row' => 702,
                'slip_number' => '22055',
                'gr_number' => '5200',
                'student_name' => 'Split Slip Student',
                'fee_particular' => 'Monthly Fee - May-2026',
                'currency' => 'AED',
                'registration_currency' => 'AED',
                'amount' => 150.00,
                'amount_minor' => 15000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $histSvc = app(\App\Services\Migration\HistoricalOpeningBalanceService::class);
        $map = $histSvc->buildHistoricalObligationMap(['5200'], $batchId);

        $check = \App\Services\Migration\HistoricalOpeningBalanceService::checkHistoricalObligation(
            $map,
            '5200',
            'monthly',
            '2026-05-01',
            'AED'
        );

        $this->assertNotNull($check);
        $this->assertSame('settled_same_currency', $check['status']);
        $this->assertTrue($check['is_settled']);
        $this->assertSame(25000, $check['paid_minor']);
        $this->assertSame(2, $check['collection_rows_count']);
        $this->assertSame(['22001', '22055'], $check['slips']);
    }

    /**
     * Regression 7: Verified historical currency transition (e.g., GR 3230 historical PKR obligation corroborated
     * by prior PKR payment history and registration_currency = SAR) does not re-bill the historical month in SAR
     * and never converts currencies; whereas an uncorroborated cross-currency unpaid due is held as a conflict.
     */
    public function test_verified_currency_transition_blocks_rebilling_in_new_enrollment_currency(): void
    {
        $batchId = (string) config('imports.active_batch_id', 'BATCH-20261023-FINAL-MYSKOOL');

        // GR 3230 (Amna Khan) has corroborating prior PKR payment history + Jun-2025 PKR due while enrolled in SAR
        DB::table('qa_staged_collections')->insert([
            'batch_id' => $batchId,
            'source_row' => 150,
            'slip_number' => '18400',
            'gr_number' => '3230',
            'student_name' => 'Amna Khan',
            'fee_particular' => 'Monthly Fee - May-2025',
            'currency' => 'PKR',
            'registration_currency' => 'SAR',
            'amount' => 5000.00,
            'amount_minor' => 500000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('qa_staged_dues')->insert([
            'batch_id' => $batchId,
            'source_row' => 188,
            'gr_number' => '3230',
            'student_name' => 'Amna Khan',
            'fee_particular' => 'Monthly Fee - Jun-2025',
            'currency' => 'PKR',
            'registration_currency' => 'SAR',
            'outstanding_amount' => 5000.00,
            'outstanding_amount_minor' => 500000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Uncorroborated unpaid PKR due (no PKR payment history) must NOT be treated as a verified transition
        DB::table('qa_staged_dues')->insert([
            'batch_id' => $batchId,
            'source_row' => 189,
            'gr_number' => '3231',
            'student_name' => 'Uncorroborated Student',
            'fee_particular' => 'Monthly Fee - Jun-2025',
            'currency' => 'PKR',
            'registration_currency' => 'SAR',
            'outstanding_amount' => 5000.00,
            'outstanding_amount_minor' => 500000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $histSvc = app(\App\Services\Migration\HistoricalOpeningBalanceService::class);
        $map = $histSvc->buildHistoricalObligationMap(['3230', '3231'], $batchId);

        // Attempting to bill Jun-2025 in SAR for GR 3230 is recognized as a verified currency transition from PKR -> SAR
        $checkSar = \App\Services\Migration\HistoricalOpeningBalanceService::checkHistoricalObligation(
            $map,
            '3230',
            'monthly',
            '2025-06-01',
            'SAR'
        );

        $this->assertNotNull($checkSar);
        $this->assertSame('verified_currency_transition', $checkSar['status']);
        $this->assertSame('unpaid_due', $checkSar['historical_state']);
        $this->assertTrue($checkSar['blocks_billing']);
        $this->assertFalse($checkSar['review_required']);
        $this->assertFalse($checkSar['is_settled']);
        $this->assertSame('PKR', $checkSar['historical_currency']);
        $this->assertSame('SAR', $checkSar['target_currency']);
        // Original PKR amount (500,000 minor = 5,000.00 PKR) is preserved without conversion
        $this->assertSame(500000, $checkSar['outstanding_minor']);

        // Uncorroborated unpaid PKR due for GR 3231 is held as cross_currency_conflict
        $checkUncorroborated = \App\Services\Migration\HistoricalOpeningBalanceService::checkHistoricalObligation(
            $map,
            '3231',
            'monthly',
            '2025-06-01',
            'SAR'
        );
        $this->assertNotNull($checkUncorroborated);
        $this->assertSame('cross_currency_conflict', $checkUncorroborated['status']);
        $this->assertTrue($checkUncorroborated['review_required']);
    }

    /**
     * Regression 8: Ambiguous cross-currency conflict is blocked and held for review (never auto-settled or re-billed),
     * and an incorrect staging batch ID cannot silently make historical fees appear safe to regenerate.
     */
    public function test_ambiguous_cross_currency_match_is_held_for_review_and_blocked_in_challan_creation(): void
    {
        $d = $this->setupAccountsData();
        $user = User::find($d['user']);
        $batchId = (string) config('imports.active_batch_id', 'BATCH-20261023-FINAL-MYSKOOL');

        DB::table('students')->where('id', $d['student'])->update(['gr_number' => '7999']);
        DB::table('enrollments')->where('id', $d['enrollment'])->update([
            'currency' => 'SAR',
            'monthly_fee_minor' => 30000,
        ]);

        // Historical PKR payment where registration_currency is NOT SAR (ambiguous cross-currency conflict)
        DB::table('qa_staged_collections')->insert([
            'batch_id' => $batchId,
            'source_row' => 999,
            'slip_number' => '30001',
            'gr_number' => '7999',
            'student_name' => 'Cross Currency Student',
            'fee_particular' => 'Monthly Fee - Aug-2026',
            'currency' => 'PKR',
            'registration_currency' => 'PKR',
            'amount' => 5000.00,
            'amount_minor' => 500000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $histSvc = app(\App\Services\Migration\HistoricalOpeningBalanceService::class);
        $map = $histSvc->buildHistoricalObligationMap(['7999'], $batchId);

        $check = \App\Services\Migration\HistoricalOpeningBalanceService::checkHistoricalObligation(
            $map,
            '7999',
            'monthly',
            '2026-08-01',
            'SAR'
        );

        $this->assertNotNull($check);
        $this->assertSame('cross_currency_conflict', $check['status']);
        $this->assertFalse($check['is_settled']);
        $this->assertTrue($check['blocks_billing']);
        $this->assertTrue($check['review_required']);
        $this->assertSame(['PKR'], $check['conflicting_currencies']);

        // Also verify manual challan creation surfaces the cross-currency review error
        $response = $this->actingAs($user)
            ->from('/challan?family=FAM-TEST-001')
            ->post('/challan/create-fee', [
                'family_code' => 'FAM-TEST-001',
                'student_ids' => [$d['student']],
                'fee_type_ids' => [$d['feeType']],
                'months' => [8],
                'year' => 2026,
            ]);

        $response->assertRedirect('/challan?family=FAM-TEST-001');
        $response->assertSessionHasErrors('fee_type_ids');
        $errors = session('errors')->get('fee_type_ids');
        $this->assertStringContainsString('requires manual review', $errors[0]);

        // Verify that querying with an incorrect batch ID while staging data exists throws RuntimeException
        // rather than silently returning an empty map
        $this->expectException(\RuntimeException::class);
        $histSvc->buildHistoricalObligationMap(['7999'], 'BATCH-NON-EXISTENT-MISMATCH');
    }

    /**
     * Regression 9: Confirmed Owner Decision for GR 6562 — two genuine AED 200 rows for Oct-2026
     * (source rows 3496 and 3497) preserve both rows and their full AED 400 (40000 minor) value without deduplication.
     */
    public function test_gr_6562_two_october_2026_aed_200_rows_are_both_preserved_totaling_aed_400(): void
    {
        $batchId = (string) config('imports.active_batch_id', 'BATCH-20261023-FINAL-MYSKOOL');

        DB::table('qa_staged_dues')->insert([
            [
                'batch_id' => $batchId,
                'source_row' => 3496,
                'gr_number' => '6562',
                'student_name' => 'Maryam Bint Ali',
                'fee_particular' => 'Monthly Fee - Oct-2026',
                'currency' => 'AED',
                'registration_currency' => 'AED',
                'outstanding_amount' => 200.00,
                'outstanding_amount_minor' => 20000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'batch_id' => $batchId,
                'source_row' => 3497,
                'gr_number' => '6562',
                'student_name' => 'Maryam Bint Ali',
                'fee_particular' => 'Monthly Fee - Oct-2026',
                'currency' => 'AED',
                'registration_currency' => 'AED',
                'outstanding_amount' => 200.00,
                'outstanding_amount_minor' => 20000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $histSvc = app(\App\Services\Migration\HistoricalOpeningBalanceService::class);
        $map = $histSvc->buildHistoricalObligationMap(['6562'], $batchId);

        $primaryKey = \App\Services\Migration\HistoricalOpeningBalanceService::makePrimaryObligationKey(
            $batchId,
            '6562',
            'monthly',
            '2026-10-01',
            '',
            'AED'
        );

        $this->assertArrayHasKey($primaryKey, $map['by_primary']);
        $bucket = $map['by_primary'][$primaryKey];

        // Both source rows 3496 (AED 200) and 3497 (AED 200) are preserved and summed to AED 400 (40000 minor)
        $this->assertSame(40000, $bucket['outstanding_minor']);
        $this->assertSame(2, $bucket['due_rows_count']);
        $this->assertSame([3496, 3497], $bucket['due_source_rows']);
        $this->assertCount(2, $bucket['due_rows']);
        $this->assertSame(3496, $bucket['due_rows'][0]['source_row']);
        $this->assertSame(20000, $bucket['due_rows'][0]['amount_minor']);
        $this->assertSame(3497, $bucket['due_rows'][1]['source_row']);
        $this->assertSame(20000, $bucket['due_rows'][1]['amount_minor']);

        $check = \App\Services\Migration\HistoricalOpeningBalanceService::checkHistoricalObligation(
            $map,
            '6562',
            'monthly',
            '2026-10-01',
            'AED'
        );

        $this->assertNotNull($check);
        $this->assertSame('unpaid_same_currency', $check['status']);
        $this->assertSame(40000, $check['outstanding_minor']);
        $this->assertSame(2, $check['due_rows_count']);
        $this->assertSame([3496, 3497], $check['due_source_rows']);
        $this->assertCount(2, $check['due_rows']);
    }

    /**
     * Phase 4B — Scenarios 1, 2, 7 & 8:
     * Create an unpaid fee, void it (parent invoice becomes 'voided', not 'paid'),
     * then recreate it successfully via both manual challan creation and recurring generation
     * without database uniqueness conflicts or duplicate obligations, while preserving audit history.
     */
    public function test_voided_unpaid_fee_can_be_recreated_via_manual_and_recurring_paths_with_audit_trail(): void
    {
        $d = $this->setupAccountsData();
        $user = User::findOrFail($d['user']);
        $adjService = app(FeeAdjustmentService::class);
        $invoiceService = app(InvoiceService::class);

        // 1. Create unpaid fee for Oct 2026 via recurring generation
        $invoiceId = $this->generateInvoice($d, '2026-10');
        $item = DB::table('invoice_items')->where('invoice_id', $invoiceId)->first();
        $this->assertNotNull($item);
        $this->assertSame('active', $item->status);
        $this->assertSame(30000, (int) $item->net_minor);

        // 2. Void the unpaid fee -> parent invoice must become 'voided', NOT 'paid'
        $adjService->voidFee($item->id, 'Billed in error before schedule confirmation', $d['user']);

        $voidedItem = DB::table('invoice_items')->where('id', $item->id)->first();
        $voidedInvoice = DB::table('invoices')->where('id', $invoiceId)->first();
        $this->assertSame('voided', $voidedItem->status);
        $this->assertSame(0, (int) $voidedItem->net_minor);
        $this->assertSame('voided', $voidedInvoice->status);
        $this->assertSame(0, (int) $voidedInvoice->total_minor);

        // 3. Recreate via manual challan fee creation (BillingController::createChallanFee)
        $response = $this->actingAs($user)->postJson('/challan/create-fee', [
            'family_id' => $d['family'],
            'student_ids' => [$d['student']],
            'fee_type_ids' => [$d['feeType']],
            'months' => [10],
            'year' => 2026,
        ]);
        $response->assertOk()->assertJson([
            'success' => true,
            'created' => 1,
        ]);

        // Verify the same row was reused (respecting unique(fee_plan_item_id, period_key)) and restored to active
        $recreatedItems = DB::table('invoice_items')
            ->where('fee_plan_item_id', $item->fee_plan_item_id)
            ->where('period_key', $item->period_key)
            ->get();
        $this->assertCount(1, $recreatedItems);
        $recreatedItem = $recreatedItems->first();
        $this->assertSame((int) $item->id, (int) $recreatedItem->id);
        $this->assertSame('active', $recreatedItem->status);
        $this->assertSame(30000, (int) $recreatedItem->amount_minor);
        $this->assertSame(30000, (int) $recreatedItem->net_minor);
        $this->assertSame(0, (int) $recreatedItem->paid_minor);

        // Parent invoice is restored to 'generated' with 300.00 SAR total
        $restoredInvoice = DB::table('invoices')->where('id', $recreatedItem->invoice_id)->first();
        $this->assertSame('generated', $restoredInvoice->status);
        $this->assertSame(30000, (int) $restoredInvoice->total_minor);

        // Both 'void' and 'recreated' adjustments are preserved in fee_adjustments and audit_logs
        $this->assertTrue(DB::table('fee_adjustments')->where('invoice_item_id', $item->id)->where('kind', 'void')->exists());
        $this->assertTrue(DB::table('fee_adjustments')->where('invoice_item_id', $item->id)->where('kind', 'recreated')->exists());
        $this->assertTrue(DB::table('audit_logs')->where('subject_type', 'invoice_item')->where('subject_id', $item->id)->where('action', 'fee_voided')->exists());
        $this->assertTrue(DB::table('audit_logs')->where('subject_type', 'invoice_item')->where('subject_id', $item->id)->where('action', 'fee_recreated')->exists());

        // 4. Scenario 7: Repeated recreation request while active does NOT generate a duplicate obligation
        $dupResponse = $this->actingAs($user)
            ->from('/challan?family=FAM-TEST-001')
            ->post('/challan/create-fee', [
                'family_id' => $d['family'],
                'student_ids' => [$d['student']],
                'fee_type_ids' => [$d['feeType']],
                'months' => [10],
                'year' => 2026,
            ]);
        $dupResponse->assertRedirect('/challan?family=FAM-TEST-001');
        $dupResponse->assertSessionHasErrors([
            'fee_type_ids' => 'An invoice has already been generated for this fee and period.',
        ]);

        // 5. Void again and verify recurring InvoiceService::generate() does NOT automatically restore voided fees (Requirement 5)
        $adjService->voidFee($item->id, 'Second void to test recurring path', $d['user']);
        $this->assertSame('voided', DB::table('invoices')->where('id', $recreatedItem->invoice_id)->value('status'));

        $runId = DB::table('invoices')->where('id', $recreatedItem->invoice_id)->value('billing_run_id');
        $genResult = $invoiceService->generate((int) $runId);
        $this->assertSame(0, $genResult['created']);
        $this->assertSame(1, $genResult['skipped']);

        $afterGenItem = DB::table('invoice_items')->where('id', $item->id)->first();
        $afterGenInvoice = DB::table('invoices')->where('id', $recreatedItem->invoice_id)->first();
        $this->assertSame('voided', $afterGenItem->status);
        $this->assertSame(0, (int) $afterGenItem->net_minor);
        $this->assertSame('voided', $afterGenInvoice->status);
        $this->assertSame(0, (int) $afterGenInvoice->total_minor);
    }

    /**
     * Phase 4B — Scenarios 3 & 10:
     * An invoice with two items keeps the correct status ('generated', 'partial', or 'paid')
     * when only one item is voided, and can safely recreate the voided item while preserving
     * payment allocations and amounts on the other item.
     */
    public function test_multi_item_invoice_retains_accurate_status_when_one_item_is_voided_and_recreated(): void
    {
        $d = $this->setupAccountsData();
        $user = User::findOrFail($d['user']);
        $adjService = app(FeeAdjustmentService::class);

        // Add a second student in the same family so the monthly invoice has two items
        $student2 = DB::table('students')->insertGetId([
            'gr_number' => 'GR-901',
            'family_id' => $d['family'],
            'name' => 'Zaid Tariq',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $enroll2 = DB::table('enrollments')->insertGetId([
            'student_id' => $student2,
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'currency' => 'SAR',
            'monthly_fee_minor' => 20000, // 200 SAR
            'grade_label' => 'Grade 2',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $enroll2,
            'fee_type_id' => $d['feeType'],
            'description' => 'Monthly School Fee',
            'frequency' => 'monthly',
            'currency' => 'SAR',
            'amount_minor' => 20000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoiceId = $this->generateInvoice($d, '2026-10');
        $item1 = DB::table('invoice_items')->where('invoice_id', $invoiceId)->where('student_id', $d['student'])->first();
        $item2 = DB::table('invoice_items')->where('invoice_id', $invoiceId)->where('student_id', $student2)->first();

        $invoiceInitial = DB::table('invoices')->where('id', $invoiceId)->first();
        $this->assertSame(50000, (int) $invoiceInitial->total_minor);
        $this->assertSame('generated', $invoiceInitial->status);

        // Void item 2 while item 1 is still unpaid -> invoice remains 'generated' with 300 SAR total
        $adjService->voidFee($item2->id, 'Sibling temporarily on leave', $d['user']);
        $invoiceAfterVoidUnpaid = DB::table('invoices')->where('id', $invoiceId)->first();
        $this->assertSame(30000, (int) $invoiceAfterVoidUnpaid->total_minor);
        $this->assertSame(0, (int) $invoiceAfterVoidUnpaid->paid_minor);
        $this->assertSame('generated', $invoiceAfterVoidUnpaid->status);

        // Pay item 1 in full (300 SAR) -> invoice becomes 'paid' (300/300 of valid obligations paid)
        app(ReceivePaymentService::class)->receive([
            'family_id' => $d['family'],
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'currency' => 'SAR',
            'monthly_amount' => '300.00',
            'selected_item_ids' => [$item1->id],
            'idempotency_key' => (string) Str::uuid(),
        ], $d['user']);

        $invoiceAfterItem1Paid = DB::table('invoices')->where('id', $invoiceId)->first();
        $this->assertSame(30000, (int) $invoiceAfterItem1Paid->total_minor);
        $this->assertSame(30000, (int) $invoiceAfterItem1Paid->paid_minor);
        $this->assertSame('paid', $invoiceAfterItem1Paid->status);

        // Recreate item 2 via manual challan fee creation -> item 2 is restored and invoice becomes 'partial' (300/500 paid)
        $this->actingAs($user)->postJson('/challan/create-fee', [
            'family_id' => $d['family'],
            'student_ids' => [$student2],
            'fee_type_ids' => [$d['feeType']],
            'months' => [10],
            'year' => 2026,
        ])->assertOk();

        $invoiceAfterItem2Recreated = DB::table('invoices')->where('id', $invoiceId)->first();
        $this->assertSame(50000, (int) $invoiceAfterItem2Recreated->total_minor);
        $this->assertSame(30000, (int) $invoiceAfterItem2Recreated->paid_minor);
        $this->assertSame('partial', $invoiceAfterItem2Recreated->status);
    }

    /**
     * Phase 4B — Scenarios 4, 5 & 6:
     * - A genuinely paid fee cannot be voided or recreated.
     * - A partially paid fee cannot be voided or improperly reset.
     * - A fully waived/exempted fee retains status 'exempted' and parent invoice 'paid' (not 'voided'),
     *   and cannot be recreated as a new collectible charge.
     */
    public function test_paid_partial_and_exempted_fees_are_protected_from_void_and_recreation(): void
    {
        $d = $this->setupAccountsData();
        $user = User::findOrFail($d['user']);
        $adjService = app(FeeAdjustmentService::class);

        // 1. Partially paid fee (Oct 2026: 100 SAR paid of 300 SAR)
        $octInvoiceId = $this->generateInvoice($d, '2026-10');
        $octItem = DB::table('invoice_items')->where('invoice_id', $octInvoiceId)->first();

        app(ReceivePaymentService::class)->receive([
            'family_id' => $d['family'],
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'currency' => 'SAR',
            'monthly_amount' => '100.00',
            'selected_period' => '2026-10',
            'idempotency_key' => (string) Str::uuid(),
        ], $d['user']);

        // Cannot void a partially paid fee
        try {
            $adjService->voidFee($octItem->id, 'Attempt void on partial', $d['user']);
            $this->fail('Expected ValidationException when voiding a partially paid fee.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('void', $e->errors());
        }

        // Cannot recreate or reset a partially paid fee via createChallanFee
        $partialResp = $this->actingAs($user)
            ->from('/challan?family=FAM-TEST-001')
            ->post('/challan/create-fee', [
                'family_id' => $d['family'],
                'student_ids' => [$d['student']],
                'fee_type_ids' => [$d['feeType']],
                'months' => [10],
                'year' => 2026,
            ]);
        $partialResp->assertSessionHasErrors([
            'fee_type_ids' => 'Payment has already been received for this fee and period.',
        ]);
        $octItemAfter = DB::table('invoice_items')->where('id', $octItem->id)->first();
        $this->assertSame(10000, (int) $octItemAfter->paid_minor);
        $this->assertSame(30000, (int) $octItemAfter->net_minor);

        // 2. Fully paid fee (pay remaining 200 SAR on Oct 2026)
        app(ReceivePaymentService::class)->receive([
            'family_id' => $d['family'],
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'currency' => 'SAR',
            'monthly_amount' => '200.00',
            'selected_period' => '2026-10',
            'idempotency_key' => (string) Str::uuid(),
        ], $d['user']);

        $paidResp = $this->actingAs($user)
            ->from('/challan?family=FAM-TEST-001')
            ->post('/challan/create-fee', [
                'family_id' => $d['family'],
                'student_ids' => [$d['student']],
                'fee_type_ids' => [$d['feeType']],
                'months' => [10],
                'year' => 2026,
            ]);
        $paidResp->assertSessionHasErrors([
            'fee_type_ids' => 'Payment has already been received for this fee and period.',
        ]);

        // 3. Fully exempted fee (Nov 2026)
        $novInvoiceId = $this->generateInvoice($d, '2026-11');
        $novItem = DB::table('invoice_items')->where('invoice_id', $novInvoiceId)->first();
        $adjService->applyExemption($novItem->id, null, '100% scholarship waiver', $d['user']);

        $novItemAfter = DB::table('invoice_items')->where('id', $novItem->id)->first();
        $novInvoiceAfter = DB::table('invoices')->where('id', $novInvoiceId)->first();
        $this->assertSame('exempted', $novItemAfter->status);
        $this->assertSame(0, (int) $novItemAfter->net_minor);
        // Must remain 'paid' (settled by waiver), NOT 'voided'
        $this->assertSame('paid', $novInvoiceAfter->status);

        // Attempting to recreate an exempted fee is blocked as an already-generated invoice (not recreated)
        $exemptResp = $this->actingAs($user)
            ->from('/challan?family=FAM-TEST-001')
            ->post('/challan/create-fee', [
                'family_id' => $d['family'],
                'student_ids' => [$d['student']],
                'fee_type_ids' => [$d['feeType']],
                'months' => [11],
                'year' => 2026,
            ]);
        $exemptResp->assertSessionHasErrors([
            'fee_type_ids' => 'An invoice has already been generated for this fee and period.',
        ]);
    }

    /**
     * Phase 4B — Scenario 9:
     * A historically settled MySkool fee remains protected even if a voided Laravel invoice_items row
     * exists for that fee and period.
     */
    public function test_historically_settled_myskool_fee_remains_protected_after_voiding_invoice_item(): void
    {
        $d = $this->setupAccountsData();
        $user = User::findOrFail($d['user']);
        $adjService = app(FeeAdjustmentService::class);
        $batchId = (string) config('imports.active_batch_id', 'BATCH-20261023-FINAL-MYSKOOL');

        DB::table('students')->where('id', $d['student'])->update(['gr_number' => '4040']);

        // Create and void a June 2026 invoice item before historical staging is checked
        $junInvoiceId = $this->generateInvoice($d, '2026-06');
        $junItem = DB::table('invoice_items')->where('invoice_id', $junInvoiceId)->first();
        $adjService->voidFee($junItem->id, 'Voided duplicate of historical MySkool settlement', $d['user']);
        $this->assertSame('voided', DB::table('invoice_items')->where('id', $junItem->id)->value('status'));

        // Seed the historical settlement in qa_staged_collections for GR 4040 June 2026
        DB::table('qa_staged_collections')->insert([
            'batch_id' => $batchId,
            'source_row' => 31343,
            'slip_number' => '25170',
            'gr_number' => '4040',
            'student_name' => 'Ibrahim Majid Ahmed',
            'family_code' => '4040',
            'fee_particular' => 'Monthly Fee - Jun-2026',
            'currency' => 'SAR',
            'registration_currency' => 'SAR',
            'amount' => 300.00,
            'amount_minor' => 30000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. Manual recreation attempt via BillingController::createChallanFee is blocked as already paid
        $resp = $this->actingAs($user)
            ->from('/challan?family=FAM-TEST-001')
            ->post('/challan/create-fee', [
                'family_id' => $d['family'],
                'student_ids' => [$d['student']],
                'fee_type_ids' => [$d['feeType']],
                'months' => [6],
                'year' => 2026,
            ]);
        $resp->assertSessionHasErrors([
            'fee_type_ids' => 'Payment has already been received for this fee and period.',
        ]);
        $this->assertSame('voided', DB::table('invoice_items')->where('id', $junItem->id)->value('status'));

        // 2. Recurring generation attempt via InvoiceService::generate is skipped
        $runId = DB::table('invoices')->where('id', $junInvoiceId)->value('billing_run_id');
        $genRes = app(InvoiceService::class)->generate((int) $runId);
        $this->assertSame(0, $genRes['created']);
        $this->assertSame(1, $genRes['skipped']);
        $this->assertSame('voided', DB::table('invoice_items')->where('id', $junItem->id)->value('status'));

        // 3. Direct service call to FeeAdjustmentService::recreateVoidedFee is also blocked by Phase 4A protection
        $this->expectException(ValidationException::class);
        $adjService->recreateVoidedFee(
            (int) $junItem->id,
            [
                'invoice_id' => (int) $junInvoiceId,
                'fee_plan_item_id' => (int) $junItem->fee_plan_item_id,
                'description' => 'Monthly School Fee',
                'period_key' => (string) $junItem->period_key,
                'period_start' => '2026-06-01',
                'period_end' => '2026-06-30',
                'currency' => 'SAR',
                'amount_minor' => 30000,
                'discount_minor' => 0,
                'exemption_minor' => 0,
                'net_minor' => 30000,
            ],
            (int) $d['user'],
            'Direct service recreation attempt'
        );
    }

    /**
     * Phase 4C — Regression 1:
     * Single-currency family summaries continue displaying normally with accurate gross, discount,
     * paid, and outstanding totals.
     */
    public function test_single_currency_family_summaries_display_normally(): void
    {
        $d = $this->setupAccountsData();
        $user = User::findOrFail($d['user']);
        $invoiceId = $this->generateInvoice($d, '2026-10');
        $item = DB::table('invoice_items')->where('invoice_id', $invoiceId)->first();

        // Apply 50.00 SAR discount and receive 100.00 SAR partial payment -> 150.00 SAR remaining due
        app(FeeAdjustmentService::class)->applyDiscount($item->id, 5000, 'Merit discount', $d['user']);
        app(ReceivePaymentService::class)->receive([
            'family_id' => $d['family'],
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'currency' => 'SAR',
            'monthly_amount' => '100.00',
            'selected_period' => '2026-10',
            'idempotency_key' => (string) Str::uuid(),
        ], $d['user']);

        $response = $this->actingAs($user)->get('/challan?family=FAM-TEST-001');
        $response->assertOk();

        $summaryMetrics = $response->viewData('summaryMetrics');
        $this->assertFalse($summaryMetrics['is_multi_currency']);
        $this->assertSame('SAR', $summaryMetrics['currency']);
        $this->assertSame(30000, $summaryMetrics['total_session_fee_minor']);
        $this->assertSame(5000, $summaryMetrics['total_discount_minor']);
        $this->assertSame(10000, $summaryMetrics['total_received_minor']);
        $this->assertSame(15000, $summaryMetrics['total_due_minor']);

        $response->assertSee('Session Fee (Gross) SAR 300.00');
        $response->assertSee('Discount SAR 50.00');
        $response->assertSee('Received (Paid) SAR 100.00');
        $response->assertSee('Pending (Due) SAR 150.00');
        $response->assertSee('value="150"', false);
    }

    /**
     * Phase 4C — Regression 2:
     * A family with SAR and AED obligations displays separate currency summaries and outstanding totals,
     * never combining SAR and AED into a single number.
     */
    public function test_multi_currency_sar_and_aed_family_displays_separate_summaries_without_combining(): void
    {
        $d = $this->setupAccountsData();
        $user = User::findOrFail($d['user']);

        // Update first student to 500.00 SAR (50000 minor)
        DB::table('enrollments')->where('id', $d['enrollment'])->update(['monthly_fee_minor' => 50000]);
        DB::table('fee_plan_items')->where('enrollment_id', $d['enrollment'])->update(['amount_minor' => 50000]);

        // Add second student in the same family enrolled in AED (300.00 AED = 30000 minor)
        $studentAed = DB::table('students')->insertGetId([
            'gr_number' => 'GR-902',
            'family_id' => $d['family'],
            'name' => 'Omar Tariq',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $enrollAed = DB::table('enrollments')->insertGetId([
            'student_id' => $studentAed,
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'currency' => 'AED',
            'monthly_fee_minor' => 30000,
            'grade_label' => 'Grade 6',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $enrollAed,
            'fee_type_id' => $d['feeType'],
            'description' => 'Monthly School Fee',
            'frequency' => 'monthly',
            'currency' => 'AED',
            'amount_minor' => 30000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->generateInvoice($d, '2026-10');

        $response = $this->actingAs($user)->get('/challan?family=FAM-TEST-001');
        $response->assertOk();

        $summaryMetrics = $response->viewData('summaryMetrics');
        $this->assertTrue($summaryMetrics['is_multi_currency']);
        $this->assertNull($summaryMetrics['total_due_minor']);
        $this->assertNull($summaryMetrics['total_session_fee_minor']);
        $this->assertSame(50000, $summaryMetrics['by_currency']['SAR']['total_due_minor']);
        $this->assertSame(30000, $summaryMetrics['by_currency']['AED']['total_due_minor']);

        $response->assertSee('Session Fee (Gross) SAR 500.00');
        $response->assertSee('Pending (Due) SAR 500.00');
        $response->assertSee('Session Fee (Gross) AED 300.00');
        $response->assertSee('Pending (Due) AED 300.00');
        // Never sums 500 SAR + 300 AED into 800
        $response->assertDontSee('value="800"', false);
        $response->assertDontSee('SAR 800.00');
        $response->assertDontSee('AED 800.00');
    }

    /**
     * Phase 4C — Regression 3:
     * A family with PKR and SAR obligations, multi-currency advance credit balances, and registration fees
     * displays each currency separately without cross-currency addition.
     */
    public function test_multi_currency_pkr_and_sar_family_with_registration_and_advance_credits_remains_isolated(): void
    {
        $d = $this->setupAccountsData();
        $user = User::findOrFail($d['user']);

        // Update first student to 500.00 SAR (50000 minor)
        DB::table('enrollments')->where('id', $d['enrollment'])->update(['monthly_fee_minor' => 50000]);
        DB::table('fee_plan_items')->where('enrollment_id', $d['enrollment'])->update(['amount_minor' => 50000]);

        // Add second student in PKR (10,000.00 PKR = 1000000 minor)
        $studentPkr = DB::table('students')->insertGetId([
            'gr_number' => 'GR-903',
            'family_id' => $d['family'],
            'name' => 'Fatima Tariq',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $enrollPkr = DB::table('enrollments')->insertGetId([
            'student_id' => $studentPkr,
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'currency' => 'PKR',
            'monthly_fee_minor' => 1000000,
            'grade_label' => 'Grade 8',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('fee_plan_items')->insert([
            'enrollment_id' => $enrollPkr,
            'fee_type_id' => $d['feeType'],
            'description' => 'Monthly School Fee',
            'frequency' => 'monthly',
            'currency' => 'PKR',
            'amount_minor' => 1000000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->generateInvoice($d, '2026-10');

        // Seed unallocated advance credits in both SAR (200.00 SAR) and PKR (3,000.00 PKR)
        DB::table('payments')->insert([
            [
                'family_id' => $d['family'],
                'campus_id' => $d['campus'],
                'department_id' => $d['dept'],
                'currency' => 'SAR',
                'amount_minor' => 20000,
                'unallocated_minor' => 20000,
                'received_on' => '2026-10-05',
                'receipt_number' => 'REC-SAR-001',
                'idempotency_key' => (string) Str::uuid(),
                'request_hash' => hash('sha256', 'sar-adv'),
                'status' => 'received',
                'received_by' => $d['user'],
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'family_id' => $d['family'],
                'campus_id' => $d['campus'],
                'department_id' => $d['dept'],
                'currency' => 'PKR',
                'amount_minor' => 300000,
                'unallocated_minor' => 300000,
                'received_on' => '2026-10-06',
                'receipt_number' => 'REC-PKR-001',
                'idempotency_key' => (string) Str::uuid(),
                'request_hash' => hash('sha256', 'pkr-adv'),
                'status' => 'received',
                'received_by' => $d['user'],
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Add registration fee items in SAR (150.00 SAR) and PKR (5,000.00 PKR)
        $regFeeTypeId = DB::table('fee_types')->where('code', 'registration')->value('id');
        $sarInvoiceId = DB::table('invoices')->where('family_id', $d['family'])->where('currency', 'SAR')->value('id');
        $pkrInvoiceId = DB::table('invoices')->where('family_id', $d['family'])->where('currency', 'PKR')->value('id');

        DB::table('invoice_items')->insert([
            [
                'invoice_id' => $sarInvoiceId,
                'student_id' => $d['student'],
                'enrollment_id' => $d['enrollment'],
                'fee_type_id' => $regFeeTypeId,
                'description' => 'Registration Fee',
                'period_key' => 'one_time:registration:2026-10-01',
                'period_start' => '2026-10-01',
                'period_end' => '2026-10-31',
                'currency' => 'SAR',
                'amount_minor' => 15000,
                'discount_minor' => 0,
                'exemption_minor' => 0,
                'net_minor' => 15000,
                'paid_minor' => 0,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'invoice_id' => $pkrInvoiceId,
                'student_id' => $studentPkr,
                'enrollment_id' => $enrollPkr,
                'fee_type_id' => $regFeeTypeId,
                'description' => 'Registration Fee',
                'period_key' => 'one_time:registration:2026-10-01',
                'period_start' => '2026-10-01',
                'period_end' => '2026-10-31',
                'currency' => 'PKR',
                'amount_minor' => 500000,
                'discount_minor' => 0,
                'exemption_minor' => 0,
                'net_minor' => 500000,
                'paid_minor' => 0,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        app(InvoiceService::class)->refreshInvoice((int) $sarInvoiceId);
        app(InvoiceService::class)->refreshInvoice((int) $pkrInvoiceId);

        $response = $this->actingAs($user)->get('/challan?family=FAM-TEST-001');
        $response->assertOk();

        // Verify per-currency advance credits
        $advByCurr = $response->viewData('advanceCreditsByCurrency');
        $this->assertSame(20000, $advByCurr['SAR']['unallocated_minor']);
        $this->assertSame(300000, $advByCurr['PKR']['unallocated_minor']);

        // Verify per-currency registration status
        $regStatus = $response->viewData('registrationStatus');
        $this->assertTrue($regStatus['is_multi_currency']);
        $this->assertNull($regStatus['balance_minor']);
        $this->assertSame(15000, $regStatus['by_currency']['SAR']['balance_minor']);
        $this->assertSame(500000, $regStatus['by_currency']['PKR']['balance_minor']);

        // Verify HTML displays separate currency amounts and never combines SAR + PKR
        $response->assertSee('SAR 200.00');
        $response->assertSee('PKR 3,000.00');
        $response->assertDontSee('SAR 3,200.00');
        $response->assertSee('SAR 650.00');
        $response->assertSee('PKR 15,000.00');
        $response->assertDontSee('15650');
    }

    /**
     * Phase 4C — Regression 4:
     * A family without monetary activity (no invoices, no payments, no advance credits)
     * renders cleanly with zeroed defaults and no currency-mixing errors.
     */
    public function test_family_without_monetary_activity_displays_zero_state_cleanly(): void
    {
        $d = $this->setupAccountsData();
        $user = User::findOrFail($d['user']);

        $response = $this->actingAs($user)->get('/challan?family=FAM-TEST-001');
        $response->assertOk();

        $summaryMetrics = $response->viewData('summaryMetrics');
        $this->assertFalse($summaryMetrics['is_multi_currency']);
        $this->assertEmpty($summaryMetrics['by_currency']);
        $this->assertSame(0, $summaryMetrics['total_due_minor']);
        $this->assertSame(0, $summaryMetrics['total_session_fee_minor']);

        $advByCurr = $response->viewData('advanceCreditsByCurrency');
        $this->assertTrue($advByCurr->isEmpty());

        $regStatus = $response->viewData('registrationStatus');
        $this->assertFalse($regStatus['has_obligation']);
        $this->assertSame(0, $regStatus['balance_minor']);

        $response->assertSee('No outstanding fee obligations for this family.');
        $response->assertSee('Session Fee (Gross) 0.00');
        $response->assertSee('Pending (Due) 0.00');
        $response->assertSee('value="0"', false);
    }

    /**
     * Phase 3 — Task 5 Regression:
     * Dashboard financial totals and Cashier collection reports must exclude reversed receipts,
     * exclude voided invoices, and never inflate new cashier collections with historical MySkool settlements.
     */
    public function test_dashboard_and_cashier_reports_exclude_reversed_receipts_voided_invoices_and_historical_myskool_settlements(): void
    {
        $d = $this->setupAccountsData();
        $user = User::findOrFail($d['user']);
        $thisMonth = now()->format('Y-m');

        // 1. Create an active November invoice for SAR 300.00
        $this->actingAs($user)->post('/challan/create-fee', [
            'family_id' => $d['family'],
            'student_ids' => [$d['student']],
            'fee_type_ids' => [$d['feeType']],
            'months' => [11],
            'year' => 2026,
        ]);

        // 2. Receive a cashier payment of SAR 300.00 today, then reverse it
        $receiveSvc = app(ReceivePaymentService::class);
        $payment1 = $receiveSvc->receive([
            'family_id' => $d['family'],
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'currency' => 'SAR',
            'monthly_amount' => '300.00',
            'idempotency_key' => 'IDEMP-DASH-REV-1',
        ], $d['user']);
        $this->assertGreaterThan(0, (int) $payment1->id);

        app(ReceiptReversalService::class)->reverseReceipt((int) $payment1->id, 'Reversed cashier test receipt', $d['user']);

        // 3. Receive a second valid cashier payment of SAR 100.00 today
        $payment2 = $receiveSvc->receive([
            'family_id' => $d['family'],
            'campus_id' => $d['campus'],
            'department_id' => $d['dept'],
            'currency' => 'SAR',
            'monthly_amount' => '100.00',
            'idempotency_key' => 'IDEMP-DASH-VALID-2',
        ], $d['user']);
        $this->assertGreaterThan(0, (int) $payment2->id);

        // 4. Create a December fee and void it so its invoice is voided
        $this->actingAs($user)->post('/challan/create-fee', [
            'family_id' => $d['family'],
            'student_ids' => [$d['student']],
            'fee_type_ids' => [$d['feeType']],
            'months' => [12],
            'year' => 2026,
        ]);
        $decItemId = (int) DB::table('invoice_items')->where('period_start', '2026-12-01')->value('id');
        app(FeeAdjustmentService::class)->voidFee($decItemId, 'Voided December fee', $d['user']);

        // 5. Verify Dashboard view data excludes the reversed SAR 300.00 receipt and voided December invoice
        $dashRes = $this->actingAs($user)->get('/dashboard');
        $dashRes->assertOk();
        $todayCollections = collect($dashRes->viewData('todayCollections'))->keyBy('currency');
        $this->assertSame(10000, (int) ($todayCollections['SAR']->amount ?? 0));
        $this->assertSame(1, (int) ($dashRes->viewData('stats')['receipts_today'] ?? 0));
        $this->assertSame(1, (int) ($dashRes->viewData('stats')['invoices'] ?? 0));

        // 6. Verify Reports view data excludes the reversed SAR 300.00 receipt and voided December invoice
        $repRes = $this->actingAs($user)->get('/reports?month=' . $thisMonth);
        $repRes->assertOk();
        $collections = collect($repRes->viewData('collections'));
        $sarCol = $collections->firstWhere('currency', 'SAR');
        $this->assertNotNull($sarCol);
        $this->assertSame(10000, (int) $sarCol->collected);
    }
}

