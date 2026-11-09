<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChallanAndReceiptSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $campusId;
    private int $deptId;
    private int $familyId;
    private int $amnaId;
    private int $aishaId;
    private int $ibrahimId;
    private int $billingRunId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('role', 'super_admin')->firstOrFail();

        $this->campusId = DB::table('campuses')->value('id') ?? 1;
        $this->deptId = DB::table('departments')->value('id') ?? 1;

        $this->billingRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'period_key' => '2026-09',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'due_date' => '2026-09-10',
            'frequency' => 'monthly',
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Family 3070 with 3 students: Amna (GR 4417), Aisha (GR 4418), Mohammed Ibrahim (GR 8062)
        $this->familyId = DB::table('families')->insertGetId([
            'id' => 3070,
            'family_code' => '4417',
            'guardian_name' => 'Muhammad Noman',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->amnaId = DB::table('students')->insertGetId([
            'id' => 4374,
            'family_id' => $this->familyId,
            'gr_number' => '4417',
            'name' => 'Amna',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->aishaId = DB::table('students')->insertGetId([
            'id' => 4375,
            'family_id' => $this->familyId,
            'gr_number' => '4418',
            'name' => 'Aisha',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ibrahimId = DB::table('students')->insertGetId([
            'id' => 6118,
            'family_id' => $this->familyId,
            'gr_number' => '8062',
            'name' => 'Mohammed Ibrahim',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('enrollments')->insert([
            [
                'student_id' => $this->amnaId,
                'campus_id' => $this->campusId,
                'department_id' => $this->deptId,
                'currency' => 'SAR',
                'grade_label' => 'Grade 8',
                'status' => 'active',
                'monthly_fee_minor' => 35000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'student_id' => $this->aishaId,
                'campus_id' => $this->campusId,
                'department_id' => $this->deptId,
                'currency' => 'SAR',
                'grade_label' => 'Grade 6',
                'status' => 'active',
                'monthly_fee_minor' => 31500,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'student_id' => $this->ibrahimId,
                'campus_id' => $this->campusId,
                'department_id' => $this->deptId,
                'currency' => 'SAR',
                'grade_label' => 'Grade 3',
                'status' => 'active',
                'monthly_fee_minor' => 28000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Requirement 2 & Specific Test Case:
     * Receipt Number: RCP-2026-0002072
     * Expected existing information:
     * - Amna (GR 4417)
     * - Aisha (GR 4418)
     * - September 2026
     * - Paid: SAR 665.00
     * - Receiving Date: 09 November 2026
     * - Mohammed Ibrahim (GR 8062) must NOT be included in the receipt result.
     * - Read-only lookup, receive button disabled.
     */
    public function test_payment_receipt_search_for_rcp_2026_0002072(): void
    {
        $feeTypeId = DB::table('fee_types')->where('code', 'monthly')->value('id') ?? 1;

        $invoiceId = DB::table('invoices')->insertGetId([
            'family_id' => $this->familyId,
            'billing_run_id' => $this->billingRunId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'invoice_number' => 'INV-2026-09-001',
            'currency' => 'SAR',
            'total_minor' => 66500,
            'paid_minor' => 66500,
            'status' => 'paid',
            'issued_on' => '2026-09-01',
            'due_date' => '2026-09-10',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemAmna = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId,
            'student_id' => $this->amnaId,
            'fee_type_id' => $feeTypeId,
            'currency' => 'SAR',
            'amount_minor' => 35000,
            'discount_minor' => 0,
            'net_minor' => 35000,
            'paid_minor' => 35000,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'period_key' => '2026-09',
            'description' => 'Tuition Fee - September 2026',
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemAisha = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId,
            'student_id' => $this->aishaId,
            'fee_type_id' => $feeTypeId,
            'currency' => 'SAR',
            'amount_minor' => 31500,
            'discount_minor' => 0,
            'net_minor' => 31500,
            'paid_minor' => 31500,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'period_key' => '2026-09',
            'description' => 'Tuition Fee - September 2026',
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $this->familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'receipt_number' => 'RCP-2026-0002072',
            'amount_minor' => 66500,
            'unallocated_minor' => 0,
            'received_on' => '2026-11-09',
            'selected_period' => '2026-09',
            'status' => 'received',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'request_hash' => hash('sha256', 'RCP-2026-0002072'),
            'received_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_allocations')->insert([
            [
                'payment_id' => $paymentId,
                'invoice_item_id' => $itemAmna,
                'amount_minor' => 35000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'payment_id' => $paymentId,
                'invoice_item_id' => $itemAisha,
                'amount_minor' => 31500,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Search by Challan No field with search_by=invoice
        $response = $this->actingAs($this->admin)->get('/challan?invoice=RCP-2026-0002072&search_by=invoice');
        $response->assertOk();

        // 1. Receipt card is rendered with exact receipt number
        $response->assertSee('RCP-2026-0002072');
        $response->assertSee('Paid Payment Receipt');

        // 2. Both students on the receipt are present
        $response->assertSee('Amna');
        $response->assertSee('4417');
        $response->assertSee('Aisha');
        $response->assertSee('4418');

        // 3. Amount and receiving date match
        $response->assertSee('665.00');
        $response->assertSee('09 Nov 2026');
        $response->assertSee('Sep 2026');

        // 4. Mohammed Ibrahim (GR 8062) must NOT be in the receipt result or student chips
        $familyStudents = $response->viewData('familyStudents');
        $this->assertCount(2, $familyStudents);
        $this->assertTrue($familyStudents->pluck('gr_number')->contains('4417'));
        $this->assertTrue($familyStudents->pluck('gr_number')->contains('4418'));
        $this->assertFalse($familyStudents->pluck('gr_number')->contains('8062'));

        // 5. Read-only lookup banner is displayed
        $response->assertSee('Paid Receipt Lookup (Read-Only)');
        $response->assertSee('No collection is required for this receipt');

        // 6. Receive button is disabled with text "Receipt Already Paid"
        $response->assertSee('Receipt Already Paid');
        $response->assertSee('id="btn-submit-receive" disabled', false);

        // 7. Outstanding dues is 0
        $summaryMetrics = $response->viewData('summaryMetrics');
        $this->assertSame(0, $summaryMetrics['total_due_minor']);
    }

    /**
     * Auto-detect receipt search when search_by is omitted or default.
     */
    public function test_payment_receipt_search_auto_detected(): void
    {
        $feeTypeId = DB::table('fee_types')->where('code', 'monthly')->value('id') ?? 1;

        $invoiceId = DB::table('invoices')->insertGetId([
            'family_id' => $this->familyId,
            'billing_run_id' => $this->billingRunId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'invoice_number' => 'INV-2026-AUTO-01',
            'currency' => 'SAR',
            'total_minor' => 35000,
            'paid_minor' => 35000,
            'status' => 'paid',
            'issued_on' => '2026-09-01',
            'due_date' => '2026-09-10',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemAmna = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId,
            'student_id' => $this->amnaId,
            'fee_type_id' => $feeTypeId,
            'currency' => 'SAR',
            'amount_minor' => 35000,
            'discount_minor' => 0,
            'net_minor' => 35000,
            'paid_minor' => 35000,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'period_key' => '2026-09',
            'description' => 'Tuition Fee',
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $this->familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'receipt_number' => 'RCP-2026-AUTO-TEST',
            'amount_minor' => 35000,
            'unallocated_minor' => 0,
            'received_on' => '2026-11-09',
            'selected_period' => '2026-09',
            'status' => 'received',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'request_hash' => hash('sha256', 'RCP-2026-0002072'),
            'received_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $itemAmna,
            'amount_minor' => 35000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/challan?invoice=RCP-2026-AUTO-TEST');
        $response->assertOk();
        $response->assertSee('RCP-2026-AUTO-TEST');
        $response->assertSee('Paid Payment Receipt');
        $response->assertSee('Receipt Already Paid');
    }

    /**
     * Requirement 1: Fee Challan Search
     * When user enters a valid fee challan/invoice number:
     * - Find exact existing invoice
     * - Display associated student and fee details
     * - Show existing payment status and outstanding balance
     * - Do not expand to unrelated family members
     * - Preserve payment workflow (receive button enabled for collecting outstanding balance)
     */
    public function test_fee_challan_search_by_invoice_number(): void
    {
        $feeTypeId = DB::table('fee_types')->where('code', 'monthly')->value('id') ?? 1;

        $invoiceId = DB::table('invoices')->insertGetId([
            'family_id' => $this->familyId,
            'billing_run_id' => $this->billingRunId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'invoice_number' => 'INV-2026-CHALLAN-01',
            'currency' => 'SAR',
            'total_minor' => 35000,
            'paid_minor' => 0,
            'status' => 'unpaid',
            'issued_on' => '2026-09-01',
            'due_date' => '2026-09-10',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $invoiceId,
            'student_id' => $this->amnaId,
            'fee_type_id' => $feeTypeId,
            'currency' => 'SAR',
            'amount_minor' => 35000,
            'discount_minor' => 0,
            'net_minor' => 35000,
            'paid_minor' => 0,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'period_key' => '2026-09',
            'description' => 'Tuition Fee - September 2026',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/challan?invoice=INV-2026-CHALLAN-01&search_by=invoice');
        $response->assertOk();

        // 1. Invoice card is displayed
        $response->assertSee('Fee Challan / Invoice:');
        $response->assertSee('INV-2026-CHALLAN-01');
        $response->assertSee('Amna (GR 4417)');

        // 2. Payment status and balance
        $response->assertSee('Outstanding Balance:');
        $response->assertSee('SAR 350.00');

        // 3. Students are strictly scoped to Amna; Aisha and Mohammed Ibrahim are excluded
        $familyStudents = $response->viewData('familyStudents');
        $this->assertCount(1, $familyStudents);
        $this->assertSame('4417', $familyStudents->first()->gr_number);

        // 4. Payment workflow is preserved: receive button is NOT disabled
        $this->assertFalse($response->viewData('searchedReceipt') !== null);
        $response->assertDontSee('Receipt Already Paid');
    }

    /**
     * Requirement 3: Historical MySkool Receipts
     * Support historical MySkool slip/challan references.
     * Read-only mode, receive button disabled.
     */
    public function test_historical_myskool_slip_search(): void
    {
        $activeBatch = config('imports.active_batch_id', 'BATCH-20261022-PORTAL-V2-REVISED');

        DB::table('qa_staged_collections')->insert([
            'batch_id' => $activeBatch,
            'source_row' => 1,
            'slip_number' => 'MS-HIST-8888',
            'gr_number' => '4417',
            'family_code' => '4417',
            'student_name' => 'Amna',
            'fee_particular' => 'Tuition Fee',
            'payment_date_parsed' => '2023-11-15',
            'amount' => 500.00,
            'amount_minor' => 50000,
            'currency' => 'SAR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/challan?invoice=MS-HIST-8888&search_by=invoice');
        $response->assertOk();

        $response->assertSee('MS-HIST-8888');
        $response->assertSee('500.00');
        $response->assertSee('Amna (4417)');
        $response->assertSee('Paid Receipt Lookup (Read-Only)');
        $response->assertSee('Receipt Already Paid');
    }

    /**
     * Unknown invoice/receipt returns clean no matching record banner.
     */
    public function test_unknown_invoice_or_receipt_shows_no_matching_record(): void
    {
        $response = $this->actingAs($this->admin)->get('/challan?invoice=RCP-NONEXISTENT-9999&search_by=invoice');
        $response->assertOk();
        $response->assertSee('No matching record found');
    }

    /**
     * Requirement 4: Preserve Phase 1 GR & Family Code search behavior.
     */
    public function test_preserves_phase1_gr_and_family_searches(): void
    {
        // GR search: scopes strictly to Amna and auto-populates family code
        $grResponse = $this->actingAs($this->admin)->get('/challan?gr=4417&search_by=gr');
        $grResponse->assertOk();
        $grStudents = $grResponse->viewData('familyStudents');
        $this->assertCount(1, $grStudents);
        $this->assertSame('4417', $grStudents->first()->gr_number);
        $grResponse->assertSee('value="4417"', false);

        // Family code search: loads all 3 family members
        $famResponse = $this->actingAs($this->admin)->get('/challan?family=4417&search_by=family');
        $famResponse->assertOk();
        $famStudents = $famResponse->viewData('familyStudents');
        $this->assertCount(3, $famStudents);
        $this->assertTrue($famStudents->pluck('gr_number')->contains('4417'));
        $this->assertTrue($famStudents->pluck('gr_number')->contains('4418'));
        $this->assertTrue($famStudents->pluck('gr_number')->contains('8062'));
    }
}
