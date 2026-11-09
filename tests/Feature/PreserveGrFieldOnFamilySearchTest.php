<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PreserveGrFieldOnFamilySearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $campusId;
    private int $deptId;
    private int $family4417Id;
    private int $family9455Id;
    private int $familyUnrelatedId;

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

        // Family 4417: Amna (4417), Aisha (4418), Mohammed Ibrahim (8062)
        $this->family4417Id = DB::table('families')->insertGetId([
            'family_code' => '4417',
            'guardian_name' => 'Muhammad Noman',
            'email' => 'noman@test.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $amnaId = DB::table('students')->insertGetId([
            'family_id' => $this->family4417Id,
            'gr_number' => '4417',
            'name' => 'Amna',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $aishaId = DB::table('students')->insertGetId([
            'family_id' => $this->family4417Id,
            'gr_number' => '4418',
            'name' => 'Aisha',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ibrahimId = DB::table('students')->insertGetId([
            'family_id' => $this->family4417Id,
            'gr_number' => '8062',
            'name' => 'Mohammed Ibrahim',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            [$amnaId, 'Grade 8', 35000],
            [$aishaId, 'Grade 6', 31500],
            [$ibrahimId, 'Grade 3', 27000],
        ] as [$stId, $grade, $amt]) {
            $enrId = DB::table('enrollments')->insertGetId([
                'student_id' => $stId,
                'campus_id' => $campusId,
                'department_id' => $deptId,
                'currency' => 'SAR',
                'grade_label' => $grade,
                'status' => 'active',
                'monthly_fee_minor' => $amt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('fee_plan_items')->insert([
                'enrollment_id' => $enrId,
                'fee_type_id' => $feeTypeId,
                'description' => 'Monthly Tuition',
                'currency' => 'SAR',
                'amount_minor' => $amt,
                'frequency' => 'monthly',
                'effective_from' => '2026-09-01',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Family 9455: Zimal Fatima (9455) & Sibling (9456)
        $this->family9455Id = DB::table('families')->insertGetId([
            'family_code' => '9455',
            'guardian_name' => 'Fatima Father',
            'email' => 'fatima@test.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $zimalId = DB::table('students')->insertGetId([
            'family_id' => $this->family9455Id,
            'gr_number' => '9455',
            'name' => 'Zimal Fatima',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $siblingId = DB::table('students')->insertGetId([
            'family_id' => $this->family9455Id,
            'gr_number' => '9456',
            'name' => 'Sibling Fatima',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([[$zimalId, 25000], [$siblingId, 20000]] as [$stId, $amt]) {
            $enrId = DB::table('enrollments')->insertGetId([
                'student_id' => $stId,
                'campus_id' => $campusId,
                'department_id' => $deptId,
                'currency' => 'SAR',
                'grade_label' => 'KG',
                'status' => 'active',
                'monthly_fee_minor' => $amt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('fee_plan_items')->insert([
                'enrollment_id' => $enrId,
                'fee_type_id' => $feeTypeId,
                'description' => 'Monthly Tuition',
                'currency' => 'SAR',
                'amount_minor' => $amt,
                'frequency' => 'monthly',
                'effective_from' => '2026-09-01',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Unrelated Family 5000
        $this->familyUnrelatedId = DB::table('families')->insertGetId([
            'family_code' => '5000',
            'guardian_name' => 'Unrelated Guardian',
            'email' => 'unrelated@test.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unrelatedStId = DB::table('students')->insertGetId([
            'family_id' => $this->familyUnrelatedId,
            'gr_number' => '5001',
            'name' => 'Unrelated Student',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('enrollments')->insert([
            'student_id' => $unrelatedStId,
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'currency' => 'SAR',
            'grade_label' => 'Grade 1',
            'status' => 'active',
            'monthly_fee_minor' => 15000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Invoices for Family 4417
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

        $inv4417 = DB::table('invoices')->insertGetId([
            'billing_run_id' => $billingRunId,
            'family_id' => $this->family4417Id,
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'currency' => 'SAR',
            'invoice_number' => 'INV-2026-4417-OCT',
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
                'invoice_id' => $inv4417,
                'student_id' => $amnaId,
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
                'invoice_id' => $inv4417,
                'student_id' => $aishaId,
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
                'invoice_id' => $inv4417,
                'student_id' => $ibrahimId,
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

        // Invoice for Family 9455
        $inv9455 = DB::table('invoices')->insertGetId([
            'billing_run_id' => $billingRunId,
            'family_id' => $this->family9455Id,
            'campus_id' => $campusId,
            'department_id' => $deptId,
            'currency' => 'SAR',
            'invoice_number' => 'INV-2026-9455-OCT',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-15',
            'total_minor' => 45000,
            'paid_minor' => 0,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_items')->insert([
            [
                'invoice_id' => $inv9455,
                'student_id' => $zimalId,
                'fee_type_id' => $feeTypeId,
                'currency' => 'SAR',
                'description' => 'Monthly Fee - Oct-2026',
                'amount_minor' => 25000,
                'discount_minor' => 0,
                'exemption_minor' => 0,
                'net_minor' => 25000,
                'paid_minor' => 0,
                'status' => 'active',
                'period_key' => '2026-10',
                'period_start' => '2026-10-01',
                'period_end' => '2026-10-31',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'invoice_id' => $inv9455,
                'student_id' => $siblingId,
                'fee_type_id' => $feeTypeId,
                'currency' => 'SAR',
                'description' => 'Monthly Fee - Oct-2026',
                'amount_minor' => 20000,
                'discount_minor' => 0,
                'exemption_minor' => 0,
                'net_minor' => 20000,
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
     * Test Scenario 1: Identical GR and Family Code (9455).
     * GR 9455 -> Family 9455: Both input values remain, complete family records appear on family search.
     */
    public function test_gr_9455_to_family_9455_preserves_both_inputs_and_shows_complete_family(): void
    {
        // 1. Initial GR search
        $grResp = $this->actingAs($this->admin)->get('/challan?gr=9455&search_by=gr');
        $grResp->assertOk();
        $this->assertCount(1, $grResp->viewData('familyStudents'));
        $this->assertSame('9455', $grResp->viewData('familyStudents')->first()->gr_number);
        // Both GR and Family inputs are 9455
        $grResp->assertSee('id="search-gr" class="myskool-input" value="9455"', false);
        $grResp->assertSee('id="search-family" class="myskool-input" value="9455"', false);

        // 2. Family search with preserved GR
        $famResp = $this->actingAs($this->admin)->get('/challan?family=9455&search_by=family&gr=9455');
        $famResp->assertOk();
        // Displays complete family records (both Zimal and Sibling)
        $this->assertCount(2, $famResp->viewData('familyStudents'));
        $this->assertCount(2, $famResp->viewData('invoiceItems'));
        // Both input values remain
        $famResp->assertSee('id="search-gr" class="myskool-input" value="9455"', false);
        $famResp->assertSee('id="search-family" class="myskool-input" value="9455"', false);
        $famResp->assertSee('id="search-by-hidden" type="hidden" name="search_by" value="family"', false);
    }

    /**
     * Test Scenario 2: Different GR and Family Code (GR 4418 -> Family Code 4417).
     * GR 4418 -> Family 4417: Both input values remain, complete family records appear on family search.
     */
    public function test_gr_4418_to_family_4417_preserves_both_inputs_and_shows_complete_family(): void
    {
        // 1. Initial GR search for Aisha (4418)
        $grResp = $this->actingAs($this->admin)->get('/challan?gr=4418&search_by=gr');
        $grResp->assertOk();
        $this->assertCount(1, $grResp->viewData('familyStudents'));
        $this->assertSame('4418', $grResp->viewData('familyStudents')->first()->gr_number);
        $grResp->assertSee('id="search-gr" class="myskool-input" value="4418"', false);
        $grResp->assertSee('id="search-family" class="myskool-input" value="4417"', false);

        // 2. Family search with preserved GR 4418
        $famResp = $this->actingAs($this->admin)->get('/challan?family=4417&search_by=family&gr=4418');
        $famResp->assertOk();
        // Displays all 3 family members
        $this->assertCount(3, $famResp->viewData('familyStudents'));
        $this->assertCount(3, $famResp->viewData('invoiceItems'));
        // GR field remains 4418, Family Code remains 4417
        $famResp->assertSee('id="search-gr" class="myskool-input" value="4418"', false);
        $famResp->assertSee('id="search-family" class="myskool-input" value="4417"', false);
        $famResp->assertSee('id="search-by-hidden" type="hidden" name="search_by" value="family"', false);
    }

    /**
     * Test switching back to GR search switches to individual student search.
     */
    public function test_pressing_enter_in_gr_field_switches_back_to_individual_student_search(): void
    {
        // After being in family mode, searching GR 4418 again scopes back to Aisha only
        $grResp = $this->actingAs($this->admin)->get('/challan?gr=4418&search_by=gr');
        $grResp->assertOk();
        $this->assertCount(1, $grResp->viewData('familyStudents'));
        $this->assertSame('4418', $grResp->viewData('familyStudents')->first()->gr_number);
        $this->assertCount(1, $grResp->viewData('invoiceItems'));
    }

    /**
     * Test browser refresh on family search with preserved GR maintains both inputs and family scope.
     */
    public function test_browser_refresh_preserves_appropriate_input_values_and_family_scope(): void
    {
        $resp1 = $this->actingAs($this->admin)->get('/challan?family=4417&search_by=family&gr=4418');
        $resp1->assertOk();
        $this->assertCount(3, $resp1->viewData('familyStudents'));
        $resp1->assertSee('id="search-gr" class="myskool-input" value="4418"', false);
        $resp1->assertSee('id="search-family" class="myskool-input" value="4417"', false);

        // Simulated refresh with identical URL
        $resp2 = $this->actingAs($this->admin)->get('/challan?family=4417&search_by=family&gr=4418');
        $resp2->assertOk();
        $this->assertCount(3, $resp2->viewData('familyStudents'));
        $resp2->assertSee('id="search-gr" class="myskool-input" value="4418"', false);
        $resp2->assertSee('id="search-family" class="myskool-input" value="4417"', false);
    }

    /**
     * Test that switching to an unrelated family does NOT retain a misleading GR.
     */
    public function test_switching_to_unrelated_family_does_not_retain_misleading_gr(): void
    {
        // Search unrelated family 5000 with stale GR 4418
        $resp = $this->actingAs($this->admin)->get('/challan?family=5000&search_by=family&gr=4418');
        $resp->assertOk();
        $this->assertCount(1, $resp->viewData('familyStudents'));
        $this->assertSame('5001', $resp->viewData('familyStudents')->first()->gr_number);

        // The GR field must NOT show 4418 because 4418 does not belong to family 5000
        $resp->assertSee('id="search-gr" class="myskool-input" value=""', false);
        $resp->assertDontSee('id="search-gr" class="myskool-input" value="4418"', false);
        $resp->assertSee('id="search-family" class="myskool-input" value="5000"', false);
    }

    /**
     * Test that searches do not mutate financial records.
     */
    public function test_searches_do_not_mutate_financial_records(): void
    {
        $invCountBefore = DB::table('invoices')->count();
        $itemCountBefore = DB::table('invoice_items')->count();
        $paymentCountBefore = DB::table('payments')->count();

        $this->actingAs($this->admin)->get('/challan?gr=4418&search_by=gr');
        $this->actingAs($this->admin)->get('/challan?family=4417&search_by=family&gr=4418');
        $this->actingAs($this->admin)->get('/challan?gr=9455&search_by=gr');
        $this->actingAs($this->admin)->get('/challan?family=9455&search_by=family&gr=9455');
        $this->actingAs($this->admin)->get('/challan?family=5000&search_by=family&gr=4418');

        $this->assertSame($invCountBefore, DB::table('invoices')->count());
        $this->assertSame($itemCountBefore, DB::table('invoice_items')->count());
        $this->assertSame($paymentCountBefore, DB::table('payments')->count());
    }
}
