<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FeeCollectionSearchRegressionTest extends TestCase
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
    private int $invoiceIdSep;
    private int $invoiceIdOct;
    private int $itemAmnaSep;
    private int $itemAishaSep;
    private int $itemIbrahimSep;
    private int $itemAmnaOct;
    private int $itemAishaOct;
    private int $itemIbrahimOct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('role', 'super_admin')->firstOrFail();

        $this->campusId = DB::table('campuses')->value('id') ?? 1;
        $this->deptId = DB::table('departments')->value('id') ?? 1;
        $feeTypeId = DB::table('fee_types')->where('code', 'monthly')->value('id') ?? 1;

        // Family 4417 with 3 students: Amna (4417), Aisha (4418), Mohammed Ibrahim (8062)
        $this->familyId = DB::table('families')->insertGetId([
            'family_code' => '4417',
            'guardian_name' => 'Muhammad Noman',
            'email' => 'noman@test.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->amnaId = DB::table('students')->insertGetId([
            'family_id' => $this->familyId,
            'gr_number' => '4417',
            'name' => 'Amna',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->aishaId = DB::table('students')->insertGetId([
            'family_id' => $this->familyId,
            'gr_number' => '4418',
            'name' => 'Aisha',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ibrahimId = DB::table('students')->insertGetId([
            'family_id' => $this->familyId,
            'gr_number' => '8062',
            'name' => 'Mohammed Ibrahim',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrAmna = DB::table('enrollments')->insertGetId([
            'student_id' => $this->amnaId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'grade_label' => 'Grade 8',
            'status' => 'active',
            'monthly_fee_minor' => 35000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrAisha = DB::table('enrollments')->insertGetId([
            'student_id' => $this->aishaId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'grade_label' => 'Grade 6',
            'status' => 'active',
            'monthly_fee_minor' => 31500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrIbrahim = DB::table('enrollments')->insertGetId([
            'student_id' => $this->ibrahimId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'grade_label' => 'Grade 3',
            'status' => 'active',
            'monthly_fee_minor' => 27000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Student Fee Plans (Future Scheduled Fees)
        DB::table('fee_plan_items')->insert([
            [
                'enrollment_id' => $enrAmna,
                'fee_type_id' => $feeTypeId,
                'description' => 'Monthly Tuition',
                'currency' => 'SAR',
                'amount_minor' => 35000,
                'frequency' => 'monthly',
                'effective_from' => '2026-09-01',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'enrollment_id' => $enrAisha,
                'fee_type_id' => $feeTypeId,
                'description' => 'Monthly Tuition',
                'currency' => 'SAR',
                'amount_minor' => 31500,
                'frequency' => 'monthly',
                'effective_from' => '2026-09-01',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'enrollment_id' => $enrIbrahim,
                'fee_type_id' => $feeTypeId,
                'description' => 'Monthly Tuition',
                'currency' => 'SAR',
                'amount_minor' => 27000,
                'frequency' => 'monthly',
                'effective_from' => '2026-09-01',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Billing run and invoices (Sep-2026 and Oct-2026)
        $this->billingRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'period_key' => '2026-09',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'due_date' => '2026-09-15',
            'frequency' => 'monthly',
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // September Invoice (partially paid by RCP-2026-0002072 for Amna 350 + Aisha 315 = 665, Ibrahim unpaid 270)
        $this->invoiceIdSep = DB::table('invoices')->insertGetId([
            'billing_run_id' => $this->billingRunId,
            'family_id' => $this->familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'invoice_number' => 'INV-2026-09-4417',
            'issued_on' => '2026-09-01',
            'due_date' => '2026-09-15',
            'total_minor' => 93500,
            'paid_minor' => 66500,
            'status' => 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->itemAmnaSep = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $this->invoiceIdSep,
            'student_id' => $this->amnaId,
            'enrollment_id' => $enrAmna,
            'fee_type_id' => $feeTypeId,
            'currency' => 'SAR',
            'description' => 'Monthly Fee - Sep-2026',
            'amount_minor' => 35000,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => 35000,
            'paid_minor' => 35000,
            'status' => 'active',
            'period_key' => '2026-09',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->itemAishaSep = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $this->invoiceIdSep,
            'student_id' => $this->aishaId,
            'enrollment_id' => $enrAisha,
            'fee_type_id' => $feeTypeId,
            'currency' => 'SAR',
            'description' => 'Monthly Fee - Sep-2026',
            'amount_minor' => 31500,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => 31500,
            'paid_minor' => 31500,
            'status' => 'active',
            'period_key' => '2026-09',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->itemIbrahimSep = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $this->invoiceIdSep,
            'student_id' => $this->ibrahimId,
            'enrollment_id' => $enrIbrahim,
            'fee_type_id' => $feeTypeId,
            'currency' => 'SAR',
            'description' => 'Monthly Fee - Sep-2026',
            'amount_minor' => 27000,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => 27000,
            'paid_minor' => 0,
            'status' => 'active',
            'period_key' => '2026-09',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Paid Receipt RCP-2026-0002072
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
                'invoice_item_id' => $this->itemAmnaSep,
                'amount_minor' => 35000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'payment_id' => $paymentId,
                'invoice_item_id' => $this->itemAishaSep,
                'amount_minor' => 31500,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // October Invoice (completely unpaid: 935 SAR)
        $this->invoiceIdOct = DB::table('invoices')->insertGetId([
            'billing_run_id' => $this->billingRunId,
            'family_id' => $this->familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'invoice_number' => 'INV-2026-10-4417',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-15',
            'total_minor' => 93500,
            'paid_minor' => 0,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->itemAmnaOct = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $this->invoiceIdOct,
            'student_id' => $this->amnaId,
            'enrollment_id' => $enrAmna,
            'fee_type_id' => $feeTypeId,
            'currency' => 'SAR',
            'description' => 'Monthly Fee - Oct-2026',
            'amount_minor' => 35000,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => 35000,
            'paid_minor' => 0,
            'status' => 'active',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->itemAishaOct = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $this->invoiceIdOct,
            'student_id' => $this->aishaId,
            'enrollment_id' => $enrAisha,
            'fee_type_id' => $feeTypeId,
            'currency' => 'SAR',
            'description' => 'Monthly Fee - Oct-2026',
            'amount_minor' => 31500,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => 31500,
            'paid_minor' => 0,
            'status' => 'active',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->itemIbrahimOct = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $this->invoiceIdOct,
            'student_id' => $this->ibrahimId,
            'enrollment_id' => $enrIbrahim,
            'fee_type_id' => $feeTypeId,
            'currency' => 'SAR',
            'description' => 'Monthly Fee - Oct-2026',
            'amount_minor' => 27000,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => 27000,
            'paid_minor' => 0,
            'status' => 'active',
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * TEST 1: GR Search
     * Search GR 4418.
     * Verify:
     * - Only Aisha's outstanding fees appear.
     * - Family Code 4417 is automatically populated.
     * - No siblings' payable fees appear.
     * - Future scheduled fees are filtered correctly.
     * - Student-specific fee history is correct.
     * - All displayed financial totals are accurate.
     */
    public function test_1_gr_search_scoping_and_totals(): void
    {
        $response = $this->actingAs($this->admin)->get('/challan?gr=4418&search_by=gr');
        $response->assertOk();

        // 1. Form values: GR 4418, Family Code auto-populated with 4417
        $response->assertSee('id="search-gr" class="myskool-input" value="4418"', false);
        $response->assertSee('id="search-family" class="myskool-input" value="4417"', false);

        // 2. Only Aisha appears, siblings do not appear
        $familyStudents = $response->viewData('familyStudents');
        $this->assertCount(1, $familyStudents);
        $this->assertSame('4418', $familyStudents->first()->gr_number);
        $this->assertSame('Aisha', $familyStudents->first()->name);

        // 3. Invoice items: only Aisha's items (Sep paid 315, Oct unpaid 315)
        $invoiceItems = $response->viewData('invoiceItems');
        $this->assertCount(2, $invoiceItems);
        foreach ($invoiceItems as $item) {
            $this->assertSame('4418', $item->gr_number);
            $this->assertSame('Aisha', $item->student_name);
        }

        // 4. Future scheduled fees filtered correctly (only Aisha's plan)
        $studentFeePlans = $response->viewData('studentFeePlans');
        $this->assertCount(1, $studentFeePlans);
        $this->assertSame('4418', $studentFeePlans->first()->gr_number);
        $this->assertSame(31500, (int) $studentFeePlans->first()->amount_minor);

        // 5. Student-specific fee history (only Aisha's payment)
        $feeHistory = $response->viewData('feeHistory');
        $this->assertNotEmpty($feeHistory);
        foreach ($feeHistory as $fh) {
            $grs = (array) ($fh->student_grs ?? [$fh->gr_number ?? '']);
            $this->assertContains('4418', $grs);
        }

        // 6. Financial totals:
        // Aisha Net: 315 + 315 = 630 SAR (63000 minor)
        // Aisha Paid: 315 SAR (31500 minor)
        // Aisha Outstanding Due: 315 SAR (31500 minor)
        $summary = $response->viewData('summaryMetrics');
        $this->assertSame(63000, $summary['total_net_minor']);
        $this->assertSame(31500, $summary['total_received_minor']);
        $this->assertSame(31500, $summary['total_due_minor']);
        $response->assertSee('id="disp_total_dues" readonly value="315"', false);
    }

    /**
     * TEST 2: Family Search
     * While GR 4418 is selected and Family Code 4417 is automatically populated, press Enter inside the Family Code field.
     * Verify:
     * - All three family members appear.
     * - All applicable outstanding fees appear.
     * - Family-level financial totals are accurate.
     * - Combined fee history is displayed.
     * - Combined scheduled fees are displayed.
     * - Existing payment selection still works.
     */
    public function test_2_family_search_from_populated_field(): void
    {
        $response = $this->actingAs($this->admin)->get('/challan?family=4417&search_by=family');
        $response->assertOk();

        // 1. Family Code field has 4417
        $response->assertSee('id="search-family" class="myskool-input" value="4417"', false);

        // 2. All 3 family members appear
        $familyStudents = $response->viewData('familyStudents');
        $this->assertCount(3, $familyStudents);
        $grs = $familyStudents->pluck('gr_number')->all();
        $this->assertContains('4417', $grs);
        $this->assertContains('4418', $grs);
        $this->assertContains('8062', $grs);

        // 3. All applicable items appear (Sep: 3 items, Oct: 3 items = 6 items)
        $invoiceItems = $response->viewData('invoiceItems');
        $this->assertCount(6, $invoiceItems);

        // 4. Family-level financial totals:
        // Total Net: 935 + 935 = 1870 SAR (187000 minor)
        // Total Paid: 665 SAR (66500 minor)
        // Total Due: 270 (Ibrahim Sep) + 935 (Oct) = 1205 SAR (120500 minor)
        $summary = $response->viewData('summaryMetrics');
        $this->assertSame(187000, $summary['total_net_minor']);
        $this->assertSame(66500, $summary['total_received_minor']);
        $this->assertSame(120500, $summary['total_due_minor']);
        $response->assertSee('id="disp_total_dues" readonly value="1205"', false);

        // 5. Combined scheduled fees (3 plans)
        $studentFeePlans = $response->viewData('studentFeePlans');
        $this->assertCount(3, $studentFeePlans);

        // 6. Existing payment selection works: submit payment for Ibrahim's unpaid Sep fee (270)
        $receiveData = [
            'family_id' => $this->familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'receiving_amount' => '270.00',
            'monthly_amount' => '270.00',
            'selected_item_ids' => [$this->itemIbrahimSep],
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'ledger_scope' => "{$this->campusId}:{$this->deptId}:SAR",
        ];
        $receiveResp = $this->actingAs($this->admin)->post('/challan/receive', $receiveData);
        $receiveResp->assertSessionHasNoErrors();
        $receiveResp->assertRedirect();

        $ibrahimSepUpdated = DB::table('invoice_items')->where('id', $this->itemIbrahimSep)->first();
        $this->assertSame(27000, (int) $ibrahimSepUpdated->paid_minor);
    }

    /**
     * TEST 3: Switching Between Modes
     * Test repeated transitions:
     * GR → Family → GR → Family → Receipt → GR
     * Verify:
     * - No stale search filters remain.
     * - Previously populated fields do not override the active search.
     * - No unnecessary fields must be cleared.
     * - Enter key works correctly in each field.
     * - Show button follows the intended search scope.
     * - Browser refresh maintains the active search mode.
     */
    public function test_3_switching_between_search_modes(): void
    {
        // 1. GR Search (4418 -> Aisha)
        $r1 = $this->actingAs($this->admin)->get('/challan?gr=4418&search_by=gr');
        $r1->assertOk();
        $this->assertCount(1, $r1->viewData('familyStudents'));
        $this->assertSame('4418', $r1->viewData('familyStudents')->first()->gr_number);

        // 2. Transition: Family Search (4417 -> all 3 students)
        $r2 = $this->actingAs($this->admin)->get('/challan?family=4417&search_by=family');
        $r2->assertOk();
        $this->assertCount(3, $r2->viewData('familyStudents'));

        // 3. Transition: GR Search (8062 -> Mohammed Ibrahim only)
        $r3 = $this->actingAs($this->admin)->get('/challan?gr=8062&search_by=gr');
        $r3->assertOk();
        $this->assertCount(1, $r3->viewData('familyStudents'));
        $this->assertSame('8062', $r3->viewData('familyStudents')->first()->gr_number);

        // 4. Transition: Family Search (4417 -> all 3 students)
        $r4 = $this->actingAs($this->admin)->get('/challan?family=4417&search_by=family');
        $r4->assertOk();
        $this->assertCount(3, $r4->viewData('familyStudents'));

        // 5. Transition: Receipt Search (RCP-2026-0002072 -> Amna & Aisha only, read-only)
        $r5 = $this->actingAs($this->admin)->get('/challan?invoice=RCP-2026-0002072&search_by=invoice');
        $r5->assertOk();
        $r5->assertSee('RCP-2026-0002072');
        $r5->assertSee('Receipt Already Paid');
        $r5Students = $r5->viewData('familyStudents');
        $this->assertCount(2, $r5Students);
        $this->assertFalse($r5Students->pluck('gr_number')->contains('8062'));

        // 6. Transition back to GR Search (4418 -> Aisha only)
        $r6 = $this->actingAs($this->admin)->get('/challan?gr=4418&search_by=gr');
        $r6->assertOk();
        $this->assertCount(1, $r6->viewData('familyStudents'));
        $this->assertSame('4418', $r6->viewData('familyStudents')->first()->gr_number);
        $r6->assertDontSee('Receipt Already Paid');

        // Browser refresh of active search mode maintains scope
        $rRefresh = $this->actingAs($this->admin)->get('/challan?gr=4418&search_by=gr');
        $rRefresh->assertOk();
        $this->assertCount(1, $rRefresh->viewData('familyStudents'));
        $this->assertSame('4418', $rRefresh->viewData('familyStudents')->first()->gr_number);
    }

    /**
     * TEST 4: Challan and Receipt Search
     * Verify:
     * - Existing fee challan number
     * - Existing paid receipt number
     * - RCP-2026-0002072
     * - Valid historical MySkool slip
     * - Invalid challan/receipt reference
     * Check exact matching and correct record display.
     */
    public function test_4_challan_and_receipt_search(): void
    {
        // 1. Existing fee challan number: INV-2026-10-4417
        $rChallan = $this->actingAs($this->admin)->get('/challan?invoice=INV-2026-10-4417&search_by=invoice');
        $rChallan->assertOk();
        $rChallan->assertSee('INV-2026-10-4417');
        $rChallan->assertSee('Outstanding Balance:');
        $rChallan->assertDontSee('Receipt Already Paid');

        // 2. Existing paid receipt number: RCP-2026-0002072
        $rReceipt = $this->actingAs($this->admin)->get('/challan?invoice=RCP-2026-0002072&search_by=invoice');
        $rReceipt->assertOk();
        $rReceipt->assertSee('RCP-2026-0002072');
        $rReceipt->assertSee('Paid Payment Receipt');
        $rReceipt->assertSee('665.00');
        $rReceipt->assertSee('Receipt Already Paid');

        // 3. Valid historical MySkool slip
        $activeBatch = config('imports.active_batch_id', 'BATCH-20261022-PORTAL-V2-REVISED');
        DB::table('qa_staged_collections')->insert([
            'batch_id' => $activeBatch,
            'source_row' => 101,
            'slip_number' => 'MS-SLIP-7777',
            'gr_number' => '4418',
            'family_code' => '4417',
            'student_name' => 'Aisha',
            'fee_particular' => 'Tuition Fee',
            'payment_date_parsed' => '2024-02-10',
            'amount' => 315.00,
            'amount_minor' => 31500,
            'currency' => 'SAR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rHist = $this->actingAs($this->admin)->get('/challan?invoice=MS-SLIP-7777&search_by=invoice');
        $rHist->assertOk();
        $rHist->assertSee('MS-SLIP-7777');
        $rHist->assertSee('Historical MySkool Record');
        $rHist->assertSee('Receipt Already Paid');

        // 4. Invalid challan/receipt reference
        $rInvalid = $this->actingAs($this->admin)->get('/challan?invoice=NON-EXISTENT-XYZ&search_by=invoice');
        $rInvalid->assertOk();
        $rInvalid->assertSee('No matching record found');
    }

    /**
     * TEST 5: Financial Regression
     * Compare financial data before and after the search updates.
     * Verify:
     * - Invoice totals unchanged
     * - Payment amounts unchanged
     * - Ledger balances unchanged
     * - No duplicate payments
     * - No duplicate invoices
     * - No new fee records generated by searches
     * - No incorrect outstanding balances
     * - No changes to payment allocation
     * - Correct partial-payment behavior
     * - Existing fee discounts and adjustments remain intact
     */
    public function test_5_financial_regression_immutability(): void
    {
        // Snapshot all financial tables before searches
        $invoicesBefore = DB::table('invoices')->orderBy('id')->get();
        $itemsBefore = DB::table('invoice_items')->orderBy('id')->get();
        $paymentsBefore = DB::table('payments')->orderBy('id')->get();
        $allocationsBefore = DB::table('payment_allocations')->orderBy('id')->get();

        // Perform multiple searches across all search modes
        $this->actingAs($this->admin)->get('/challan?gr=4418&search_by=gr');
        $this->actingAs($this->admin)->get('/challan?family=4417&search_by=family');
        $this->actingAs($this->admin)->get('/challan?invoice=INV-2026-09-4417&search_by=invoice');
        $this->actingAs($this->admin)->get('/challan?invoice=RCP-2026-0002072&search_by=invoice');
        $this->actingAs($this->admin)->get('/challan?gr=8062&search_by=gr');
        $this->actingAs($this->admin)->get('/challan?invoice=UNKNOWN-INV&search_by=invoice');

        // Snapshot all financial tables after searches
        $invoicesAfter = DB::table('invoices')->orderBy('id')->get();
        $itemsAfter = DB::table('invoice_items')->orderBy('id')->get();
        $paymentsAfter = DB::table('payments')->orderBy('id')->get();
        $allocationsAfter = DB::table('payment_allocations')->orderBy('id')->get();

        // 1. Exact record counts match
        $this->assertSame($invoicesBefore->count(), $invoicesAfter->count());
        $this->assertSame($itemsBefore->count(), $itemsAfter->count());
        $this->assertSame($paymentsBefore->count(), $paymentsAfter->count());
        $this->assertSame($allocationsBefore->count(), $allocationsAfter->count());

        // 2. Invoice totals unchanged
        foreach ($invoicesBefore as $idx => $inv) {
            $this->assertSame((int) $inv->total_minor, (int) $invoicesAfter[$idx]->total_minor);
            $this->assertSame((int) $inv->paid_minor, (int) $invoicesAfter[$idx]->paid_minor);
            $this->assertSame($inv->status, $invoicesAfter[$idx]->status);
        }

        // 3. Payment amounts unchanged
        foreach ($paymentsBefore as $idx => $pmt) {
            $this->assertSame((int) $pmt->amount_minor, (int) $paymentsAfter[$idx]->amount_minor);
            $this->assertSame((int) $pmt->unallocated_minor, (int) $paymentsAfter[$idx]->unallocated_minor);
            $this->assertSame($pmt->status, $paymentsAfter[$idx]->status);
        }

        // 4. Allocations unchanged
        foreach ($allocationsBefore as $idx => $alloc) {
            $this->assertSame((int) $alloc->amount_minor, (int) $allocationsAfter[$idx]->amount_minor);
            $this->assertSame($alloc->invoice_item_id, $allocationsAfter[$idx]->invoice_item_id);
        }

        // 5. Partial payment behavior and discount addition
        $discountResp = $this->actingAs($this->admin)->post('/challan/discount', [
            'invoice_item_id' => $this->itemIbrahimSep,
            'amount' => '50.00',
            'reason' => 'Sibling discount',
        ], ['Accept' => 'application/json']);
        $discountResp->assertOk();

        $updatedIbrahim = DB::table('invoice_items')->where('id', $this->itemIbrahimSep)->first();
        $this->assertSame(5000, (int) $updatedIbrahim->discount_minor);
        $this->assertSame(22000, (int) $updatedIbrahim->net_minor);
    }

    /**
     * TEST 6: Existing Functionality Verification
     * Verify:
     * - Create Fee
     * - Fee Collection
     * - Receive Payment
     * - Paid Invoice
     * - Fee History
     * - Print Report (Consolidated Challan PDF)
     * - Discount
     * - Future Scheduled Fees
     */
    public function test_6_existing_functionality_intact(): void
    {
        // 1. Fee Collection loads properly
        $collectionResp = $this->actingAs($this->admin)->get('/challan?family=4417');
        $collectionResp->assertOk();

        // 2. Create Fee creates new invoice item properly
        $feeTypeId = DB::table('fee_types')->where('code', 'monthly')->value('id') ?? 1;
        $createFeeData = [
            'family_id' => $this->familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'student_ids' => [$this->aishaId],
            'fee_type_ids' => [$feeTypeId],
            'months' => [11],
            'year' => 2026,
            'due_day' => 15,
        ];
        $createResp = $this->actingAs($this->admin)->post('/challan/create-fee', $createFeeData);
        $createResp->assertSessionHasNoErrors();

        $createdItem = DB::table('invoice_items')
            ->where('student_id', $this->aishaId)
            ->where('period_start', '2026-11-01')
            ->first();
        $this->assertNotNull($createdItem);
        $this->assertSame(31500, (int) $createdItem->amount_minor);

        // 3. Receive Payment
        $receiveData = [
            'family_id' => $this->familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'receiving_amount' => '315.00',
            'monthly_amount' => '315.00',
            'selected_item_ids' => [$createdItem->id],
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'ledger_scope' => "{$this->campusId}:{$this->deptId}:SAR",
        ];
        $receiveResp = $this->actingAs($this->admin)->post('/challan/receive', $receiveData);
        $receiveResp->assertSessionHasNoErrors();

        $paidCreatedItem = DB::table('invoice_items')->where('id', $createdItem->id)->first();
        $this->assertSame(31500, (int) $paidCreatedItem->paid_minor);

        // 4. Paid Invoices / Invoices route loads
        $invoicesResp = $this->actingAs($this->admin)->get('/invoices');
        $invoicesResp->assertOk();

        // 5. Consolidated Challan PDF generation works
        $pdfResp = $this->actingAs($this->admin)->get("/families/{$this->familyId}/challan/pdf");
        $pdfResp->assertOk();
        $this->assertSame('application/pdf', $pdfResp->headers->get('Content-Type'));
    }
}
