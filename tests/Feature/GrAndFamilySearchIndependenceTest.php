<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GrAndFamilySearchIndependenceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $campusId;
    private int $deptId;
    private int $familyId;
    private int $amnaId;
    private int $aishaId;
    private int $ibrahimId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('role', 'super_admin')->firstOrFail();

        $this->campusId = DB::table('campuses')->value('id') ?? 1;
        $this->deptId = DB::table('departments')->value('id') ?? 1;
        $campusId = $this->campusId;
        $deptId = $this->deptId;
        $feeTypeId = DB::table('fee_types')->where('code', 'monthly')->value('id') ?? 1;

        // Create Family 4417 with 3 students: Amna (4417), Aisha (4418), Mohammed Ibrahim (8062)
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
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'currency' => 'SAR',
            'grade_label' => 'Grade 8',
            'status' => 'active',
            'monthly_fee_minor' => 35000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrAisha = DB::table('enrollments')->insertGetId([
            'student_id' => $this->aishaId,
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'currency' => 'SAR',
            'grade_label' => 'Grade 6',
            'status' => 'active',
            'monthly_fee_minor' => 31500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrIbrahim = DB::table('enrollments')->insertGetId([
            'student_id' => $this->ibrahimId,
            'campus_id' => $campusId,
            'department_id' => $deptId,
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

        // Billing run and invoices
        $billingRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'period_key' => '2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-15',
            'frequency' => 'monthly',
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoiceId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $billingRunId,
            'family_id' => $this->familyId,
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'currency' => 'SAR',
            'invoice_number' => 'INV-2026-TEST-4417',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-15',
            'total_minor' => 93500,
            'paid_minor' => 0,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_items')->insert([
            [
                'invoice_id' => $invoiceId,
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
            ],
            [
                'invoice_id' => $invoiceId,
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
            ],
            [
                'invoice_id' => $invoiceId,
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
            ],
        ]);
    }

    /**
     * Requirement 1: GR Number Search (GR 4418 -> Aisha only, auto-populates Family Code 4417).
     */
    public function test_gr_search_displays_only_targeted_student_and_auto_populates_family_code(): void
    {
        $response = $this->actingAs($this->admin)->get('/challan?gr=4418&search_by=gr');
        $response->assertStatus(200);

        // Right side panel shows Aisha
        $response->assertSee('Aisha');

        // Form fields: GR has 4418, Family Code automatically has 4417
        $response->assertSee('id="search-gr" class="myskool-input" value="4418"', false);
        $response->assertSee('id="search-family" class="myskool-input" value="4417"', false);

        // View data assertions: only Aisha's invoice items
        $invoiceItems = $response->viewData('invoiceItems');
        $this->assertCount(1, $invoiceItems);
        $this->assertSame('4418', $invoiceItems->first()->gr_number);
        $this->assertSame('Aisha', $invoiceItems->first()->student_name);
        $this->assertSame(31500, (int) $invoiceItems->first()->net_minor);

        // Future scheduled fees: only Aisha
        $studentFeePlans = $response->viewData('studentFeePlans');
        $this->assertCount(1, $studentFeePlans);
        $this->assertSame('4418', $studentFeePlans->first()->gr_number);

        // Total dues is Aisha's SAR 315.00, NOT the family total
        $response->assertSee('id="disp_total_dues" readonly value="315"', false);
    }

    /**
     * Requirement 2: Family Code Search (Family Code 4417 -> loads all 3 students).
     */
    public function test_family_code_search_displays_all_family_members(): void
    {
        $response = $this->actingAs($this->admin)->get('/challan?family=4417&search_by=family');
        $response->assertStatus(200);

        // Form fields: Family Code has 4417
        $response->assertSee('id="search-family" class="myskool-input" value="4417"', false);

        // View data assertions: all 3 students' invoice items
        $invoiceItems = $response->viewData('invoiceItems');
        $this->assertCount(3, $invoiceItems);
        $grs = $invoiceItems->pluck('gr_number')->all();
        $this->assertContains('4417', $grs);
        $this->assertContains('4418', $grs);
        $this->assertContains('8062', $grs);

        // Future scheduled fees: all 3 students
        $studentFeePlans = $response->viewData('studentFeePlans');
        $this->assertCount(3, $studentFeePlans);

        // Total dues is family total (350 + 315 + 270 = 935)
        $response->assertSee('id="disp_total_dues" readonly value="935"', false);
    }

    /**
     * Requirement 3: Switching from Family to another GR (8062 -> Mohammed Ibrahim only).
     */
    public function test_switching_to_gr_8062_scopes_to_mohammed_ibrahim_only(): void
    {
        $response = $this->actingAs($this->admin)->get('/challan?gr=8062&search_by=gr');
        $response->assertStatus(200);

        // Right side panel shows Mohammed Ibrahim
        $response->assertSee('Mohammed Ibrahim');

        // Form fields
        $response->assertSee('id="search-gr" class="myskool-input" value="8062"', false);
        $response->assertSee('id="search-family" class="myskool-input" value="4417"', false);

        // View data: only Mohammed Ibrahim's invoice item
        $invoiceItems = $response->viewData('invoiceItems');
        $this->assertCount(1, $invoiceItems);
        $this->assertSame('8062', $invoiceItems->first()->gr_number);
        $this->assertSame('Mohammed Ibrahim', $invoiceItems->first()->student_name);
        $this->assertSame(27000, (int) $invoiceItems->first()->net_minor);

        // Total dues is 270
        $response->assertSee('id="disp_total_dues" readonly value="270"', false);
    }

    /**
     * Requirement 1 & 2: Identical GR and Family Code (4417).
     * GR search for 4417 scopes to Amna, while Family search for 4417 scopes to complete family.
     */
    public function test_identical_gr_and_family_code_behave_independently(): void
    {
        // 1. GR search for 4417 (Amna)
        $grResponse = $this->actingAs($this->admin)->get('/challan?gr=4417&search_by=gr');
        $grResponse->assertStatus(200);
        $grItems = $grResponse->viewData('invoiceItems');
        $this->assertCount(1, $grItems);
        $this->assertSame('Amna', $grItems->first()->student_name);
        $grResponse->assertSee('id="disp_total_dues" readonly value="350"', false);

        // 2. Family search for 4417 (All 3)
        $famResponse = $this->actingAs($this->admin)->get('/challan?family=4417&search_by=family');
        $famResponse->assertStatus(200);
        $famItems = $famResponse->viewData('invoiceItems');
        $this->assertCount(3, $famItems);
        $famResponse->assertSee('id="disp_total_dues" readonly value="935"', false);
    }

    /**
     * Requirement 4: Financial database records remain unchanged after searches.
     */
    public function test_searches_do_not_mutate_financial_records(): void
    {
        $itemCountBefore = DB::table('invoice_items')->count();
        $invoiceCountBefore = DB::table('invoices')->count();
        $paymentCountBefore = DB::table('payments')->count();

        $this->actingAs($this->admin)->get('/challan?gr=4418&search_by=gr');
        $this->actingAs($this->admin)->get('/challan?family=4417&search_by=family');
        $this->actingAs($this->admin)->get('/challan?gr=8062&search_by=gr');

        $this->assertSame($itemCountBefore, DB::table('invoice_items')->count());
        $this->assertSame($invoiceCountBefore, DB::table('invoices')->count());
        $this->assertSame($paymentCountBefore, DB::table('payments')->count());
    }

    /**
     * Requirement 3 & Testing 7: Browser refresh and URL navigation preserve active search scope.
     */
    public function test_browser_refresh_preserves_active_search_scope(): void
    {
        // First navigation to GR 4418
        $resp1 = $this->actingAs($this->admin)->get('/challan?gr=4418&search_by=gr');
        $this->assertCount(1, $resp1->viewData('invoiceItems'));
        $this->assertSame('4418', $resp1->viewData('invoiceItems')->first()->gr_number);

        // Simulated refresh of the exact same URL
        $respRefresh = $this->actingAs($this->admin)->get('/challan?gr=4418&search_by=gr');
        $this->assertCount(1, $respRefresh->viewData('invoiceItems'));
        $this->assertSame('4418', $respRefresh->viewData('invoiceItems')->first()->gr_number);

        // Navigation to Family 4417
        $respFam = $this->actingAs($this->admin)->get('/challan?family=4417&search_by=family');
        $this->assertCount(3, $respFam->viewData('invoiceItems'));

        // Simulated refresh of family URL
        $respFamRefresh = $this->actingAs($this->admin)->get('/challan?family=4417&search_by=family');
        $this->assertCount(3, $respFamRefresh->viewData('invoiceItems'));
    }

    /**
     * Requirement 4: Receiving payment for individual student does not affect siblings.
     */
    public function test_individual_student_payment_does_not_affect_siblings(): void
    {
        $aishaItem = DB::table('invoice_items')->where('student_id', $this->aishaId)->first();
        $this->assertNotNull($aishaItem);

        $receiveData = [
            'family_id' => $this->familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'receiving_amount' => '315.00',
            'monthly_amount' => '315.00',
            'selected_item_ids' => [$aishaItem->id],
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'ledger_scope' => "{$this->campusId}:{$this->deptId}:SAR",
        ];

        $resp = $this->actingAs($this->admin)->post('/challan/receive', $receiveData);
        $resp->assertSessionHasNoErrors();
        $resp->assertRedirect();

        // Aisha's item is paid
        $aishaUpdated = DB::table('invoice_items')->where('id', $aishaItem->id)->first();
        $this->assertSame(31500, (int) $aishaUpdated->paid_minor);

        // Amna's and Ibrahim's items are still completely unpaid
        $amnaItem = DB::table('invoice_items')->where('student_id', $this->amnaId)->first();
        $ibrahimItem = DB::table('invoice_items')->where('student_id', $this->ibrahimId)->first();

        $this->assertSame(0, (int) $amnaItem->paid_minor);
        $this->assertSame(0, (int) $ibrahimItem->paid_minor);
    }
}
