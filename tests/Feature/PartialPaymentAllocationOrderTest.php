<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FeeAdjustmentService;
use App\Services\ReceivePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartialPaymentAllocationOrderTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected int $campusId;
    protected int $schoolDeptId;
    protected int $monthlyFeeTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::first() ?? User::create([
            'name' => 'Finance Admin',
            'email' => 'admin@iqraschool.edu.sa',
            'password' => bcrypt('StrongSecret2026!'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->campusId = DB::table('campuses')->first()->id ?? DB::table('campuses')->insertGetId([
            'name' => 'Main Campus', 'code' => 'MAIN', 'created_at' => now(), 'updated_at' => now()
        ]);

        $this->schoolDeptId = DB::table('departments')->where('code', 'SCH')->value('id') ?? DB::table('departments')->insertGetId([
            'name' => 'School', 'code' => 'SCH', 'created_at' => now(), 'updated_at' => now()
        ]);

        $this->monthlyFeeTypeId = DB::table('fee_types')->where('code', 'monthly')->value('id');
    }

    private function createFamilyWithStudent(string $familyCode, string $gr, string $name, int $feeMinor = 35000): array
    {
        $familyId = DB::table('families')->where('family_code', $familyCode)->value('id');
        if (!$familyId) {
            $familyId = DB::table('families')->insertGetId([
                'family_code' => $familyCode, 'guardian_name' => 'Guardian ' . $name, 'created_at' => now(), 'updated_at' => now()
            ]);
        }

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId, 'gr_number' => $gr, 'name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()
        ]);

        $enrollmentId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId, 'campus_id' => $this->campusId, 'department_id' => $this->schoolDeptId,
            'grade_label' => 'Grade 10', 'currency' => 'SAR', 'monthly_fee_minor' => $feeMinor, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now()
        ]);

        return compact('familyId', 'studentId', 'enrollmentId');
    }

    private function createInvoiceWithItem(int $familyId, int $studentId, int $enrollmentId, string $desc, int $amountMinor, string $periodStart, string $dueDate = '2026-10-25'): int
    {
        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId, 'department_id' => $this->schoolDeptId, 'frequency' => 'monthly',
            'period_key' => 'monthly:' . $periodStart, 'period_start' => $periodStart, 'period_end' => date('Y-m-t', strtotime($periodStart)),
            'due_date' => $dueDate, 'status' => 'generated', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now()
        ]);

        $invoiceId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId, 'family_id' => $familyId, 'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId, 'currency' => 'SAR', 'invoice_number' => 'INV-' . uniqid(),
            'issued_on' => now()->toDateString(), 'due_date' => $dueDate,
            'total_minor' => $amountMinor, 'paid_minor' => 0, 'previous_dues_snapshot_minor' => 0,
            'status' => 'generated', 'created_at' => now(), 'updated_at' => now()
        ]);

        return DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId, 'student_id' => $studentId, 'enrollment_id' => $enrollmentId,
            'fee_type_id' => $this->monthlyFeeTypeId, 'description' => $desc, 'period_key' => 'monthly:' . $periodStart,
            'period_start' => $periodStart, 'period_end' => date('Y-m-t', strtotime($periodStart)),
            'currency' => 'SAR', 'amount_minor' => $amountMinor, 'discount_minor' => 0,
            'net_minor' => $amountMinor, 'paid_minor' => 0, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now()
        ]);
    }

    /**
     * Scenario 1: Exact Example from prompt.
     * Three fee records:
     * - Mohammed Ibrahim (GR 8062): SAR 270 (27000 minor)
     * - Aisha (GR 4418): SAR 315 (31500 minor)
     * - Amna (GR 4417): SAR 350 (35000 minor)
     * Total selected: SAR 935
     * Receiving Amount: SAR 900
     *
     * Expected:
     * 1. Mohammed Ibrahim: Allocate SAR 270, fully paid (outstanding 0).
     * 2. Aisha: Allocate SAR 315, fully paid (outstanding 0).
     * 3. Amna: Allocate remaining SAR 315 out of SAR 350, partially paid (outstanding 35).
     */
    public function test_exact_example_ascending_outstanding_allocation_900_received(): void
    {
        $familyCode = 'FAM-EXACT';
        $s1 = $this->createFamilyWithStudent($familyCode, 'GR8062', 'Mohammed Ibrahim', 27000);
        $s2 = $this->createFamilyWithStudent($familyCode, 'GR4418', 'Aisha', 31500);
        $s3 = $this->createFamilyWithStudent($familyCode, 'GR4417', 'Amna', 35000);

        $item1 = $this->createInvoiceWithItem($s1['familyId'], $s1['studentId'], $s1['enrollmentId'], 'Monthly Fee Oct', 27000, '2026-10-01');
        $item2 = $this->createInvoiceWithItem($s2['familyId'], $s2['studentId'], $s2['enrollmentId'], 'Monthly Fee Oct', 31500, '2026-10-01');
        $item3 = $this->createInvoiceWithItem($s3['familyId'], $s3['studentId'], $s3['enrollmentId'], 'Monthly Fee Oct', 35000, '2026-10-01');

        $svc = new ReceivePaymentService();
        $payment = $svc->receive([
            'family_id' => $s1['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '900.00',
            'selected_item_ids' => [$item1, $item2, $item3],
            'idempotency_key' => (string) Str::uuid(),
        ], $this->admin->id);

        $this->assertEquals(90000, $payment->amount_minor);
        $this->assertEquals(0, $payment->unallocated_minor);

        $allocs = DB::table('payment_allocations')->where('payment_id', $payment->id)->orderBy('id')->get();
        $this->assertCount(3, $allocs);

        // 1. Mohammed Ibrahim: 270 SAR
        $this->assertEquals($item1, $allocs[0]->invoice_item_id);
        $this->assertEquals(27000, $allocs[0]->amount_minor);

        // 2. Aisha: 315 SAR
        $this->assertEquals($item2, $allocs[1]->invoice_item_id);
        $this->assertEquals(31500, $allocs[1]->amount_minor);

        // 3. Amna: 315 SAR
        $this->assertEquals($item3, $allocs[2]->invoice_item_id);
        $this->assertEquals(31500, $allocs[2]->amount_minor);

        // Item balances
        $this->assertEquals(27000, DB::table('invoice_items')->where('id', $item1)->value('paid_minor'));
        $this->assertEquals(31500, DB::table('invoice_items')->where('id', $item2)->value('paid_minor'));
        $this->assertEquals(31500, DB::table('invoice_items')->where('id', $item3)->value('paid_minor'));

        // Outstanding checks
        $this->assertEquals(0, 27000 - DB::table('invoice_items')->where('id', $item1)->value('paid_minor'));
        $this->assertEquals(0, 31500 - DB::table('invoice_items')->where('id', $item2)->value('paid_minor'));
        $this->assertEquals(3500, 35000 - DB::table('invoice_items')->where('id', $item3)->value('paid_minor')); // SAR 35 outstanding
    }

    /**
     * Scenario 2: 270 + 315 + 350, receive 585
     * Expected: First two fully (270 and 315), third unpaid (0).
     */
    public function test_270_315_350_receive_585(): void
    {
        $familyCode = 'FAM-SCEN2';
        $s1 = $this->createFamilyWithStudent($familyCode, 'GR1', 'Student 1', 27000);
        $s2 = $this->createFamilyWithStudent($familyCode, 'GR2', 'Student 2', 31500);
        $s3 = $this->createFamilyWithStudent($familyCode, 'GR3', 'Student 3', 35000);

        $item1 = $this->createInvoiceWithItem($s1['familyId'], $s1['studentId'], $s1['enrollmentId'], 'Monthly Fee', 27000, '2026-10-01');
        $item2 = $this->createInvoiceWithItem($s2['familyId'], $s2['studentId'], $s2['enrollmentId'], 'Monthly Fee', 31500, '2026-10-01');
        $item3 = $this->createInvoiceWithItem($s3['familyId'], $s3['studentId'], $s3['enrollmentId'], 'Monthly Fee', 35000, '2026-10-01');

        $svc = new ReceivePaymentService();
        $payment = $svc->receive([
            'family_id' => $s1['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '585.00',
            'selected_item_ids' => [$item1, $item2, $item3],
            'idempotency_key' => (string) Str::uuid(),
        ], $this->admin->id);

        $allocs = DB::table('payment_allocations')->where('payment_id', $payment->id)->orderBy('id')->get();
        $this->assertCount(2, $allocs);

        $this->assertEquals($item1, $allocs[0]->invoice_item_id);
        $this->assertEquals(27000, $allocs[0]->amount_minor);

        $this->assertEquals($item2, $allocs[1]->invoice_item_id);
        $this->assertEquals(31500, $allocs[1]->amount_minor);

        $this->assertEquals(0, DB::table('invoice_items')->where('id', $item3)->value('paid_minor'));
    }

    /**
     * Scenario 3: 270 + 315 + 350, receive 200
     * Expected: First item receives 200 partially, others unpaid.
     */
    public function test_270_315_350_receive_200(): void
    {
        $familyCode = 'FAM-SCEN3';
        $s1 = $this->createFamilyWithStudent($familyCode, 'GR1', 'Student 1', 27000);
        $s2 = $this->createFamilyWithStudent($familyCode, 'GR2', 'Student 2', 31500);
        $s3 = $this->createFamilyWithStudent($familyCode, 'GR3', 'Student 3', 35000);

        $item1 = $this->createInvoiceWithItem($s1['familyId'], $s1['studentId'], $s1['enrollmentId'], 'Monthly Fee', 27000, '2026-10-01');
        $item2 = $this->createInvoiceWithItem($s2['familyId'], $s2['studentId'], $s2['enrollmentId'], 'Monthly Fee', 31500, '2026-10-01');
        $item3 = $this->createInvoiceWithItem($s3['familyId'], $s3['studentId'], $s3['enrollmentId'], 'Monthly Fee', 35000, '2026-10-01');

        $svc = new ReceivePaymentService();
        $payment = $svc->receive([
            'family_id' => $s1['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '200.00',
            'selected_item_ids' => [$item1, $item2, $item3],
            'idempotency_key' => (string) Str::uuid(),
        ], $this->admin->id);

        $allocs = DB::table('payment_allocations')->where('payment_id', $payment->id)->orderBy('id')->get();
        $this->assertCount(1, $allocs);

        $this->assertEquals($item1, $allocs[0]->invoice_item_id);
        $this->assertEquals(20000, $allocs[0]->amount_minor);

        $this->assertEquals(20000, DB::table('invoice_items')->where('id', $item1)->value('paid_minor'));
        $this->assertEquals(0, DB::table('invoice_items')->where('id', $item2)->value('paid_minor'));
        $this->assertEquals(0, DB::table('invoice_items')->where('id', $item3)->value('paid_minor'));
    }

    /**
     * Scenario 4: 270 + 315 + 350, receive 935
     * Expected: All three fully paid.
     */
    public function test_270_315_350_receive_935(): void
    {
        $familyCode = 'FAM-SCEN4';
        $s1 = $this->createFamilyWithStudent($familyCode, 'GR1', 'Student 1', 27000);
        $s2 = $this->createFamilyWithStudent($familyCode, 'GR2', 'Student 2', 31500);
        $s3 = $this->createFamilyWithStudent($familyCode, 'GR3', 'Student 3', 35000);

        $item1 = $this->createInvoiceWithItem($s1['familyId'], $s1['studentId'], $s1['enrollmentId'], 'Monthly Fee', 27000, '2026-10-01');
        $item2 = $this->createInvoiceWithItem($s2['familyId'], $s2['studentId'], $s2['enrollmentId'], 'Monthly Fee', 31500, '2026-10-01');
        $item3 = $this->createInvoiceWithItem($s3['familyId'], $s3['studentId'], $s3['enrollmentId'], 'Monthly Fee', 35000, '2026-10-01');

        $svc = new ReceivePaymentService();
        $payment = $svc->receive([
            'family_id' => $s1['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '935.00',
            'selected_item_ids' => [$item1, $item2, $item3],
            'idempotency_key' => (string) Str::uuid(),
        ], $this->admin->id);

        $allocs = DB::table('payment_allocations')->where('payment_id', $payment->id)->orderBy('id')->get();
        $this->assertCount(3, $allocs);

        $this->assertEquals(27000, DB::table('invoice_items')->where('id', $item1)->value('paid_minor'));
        $this->assertEquals(31500, DB::table('invoice_items')->where('id', $item2)->value('paid_minor'));
        $this->assertEquals(35000, DB::table('invoice_items')->where('id', $item3)->value('paid_minor'));
    }

    /**
     * Scenario 5: One fee selected
     * Expected: Existing normal behavior.
     */
    public function test_one_fee_selected(): void
    {
        $familyCode = 'FAM-SCEN5';
        $s1 = $this->createFamilyWithStudent($familyCode, 'GR1', 'Single Student', 30000);
        $item = $this->createInvoiceWithItem($s1['familyId'], $s1['studentId'], $s1['enrollmentId'], 'Monthly Fee', 30000, '2026-10-01');

        $svc = new ReceivePaymentService();
        $payment = $svc->receive([
            'family_id' => $s1['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '200.00',
            'selected_item_ids' => [$item],
            'idempotency_key' => (string) Str::uuid(),
        ], $this->admin->id);

        $allocs = DB::table('payment_allocations')->where('payment_id', $payment->id)->get();
        $this->assertCount(1, $allocs);
        $this->assertEquals(20000, $allocs[0]->amount_minor);
        $this->assertEquals(20000, DB::table('invoice_items')->where('id', $item)->value('paid_minor'));
    }

    /**
     * Scenario 6: Equal balances
     * Expected: Stable, predictable deterministic tie-breaker order (period_start / due_date / id).
     */
    public function test_equal_balances_deterministic_order(): void
    {
        $familyCode = 'FAM-SCEN6';
        $s1 = $this->createFamilyWithStudent($familyCode, 'GR1', 'Student 1', 30000);

        // August 2026 vs September 2026 (both 300 SAR)
        $itemAug = $this->createInvoiceWithItem($s1['familyId'], $s1['studentId'], $s1['enrollmentId'], 'Fee Aug', 30000, '2026-08-01', '2026-08-25');
        $itemSep = $this->createInvoiceWithItem($s1['familyId'], $s1['studentId'], $s1['enrollmentId'], 'Fee Sep', 30000, '2026-09-01', '2026-09-25');

        $svc = new ReceivePaymentService();
        $payment = $svc->receive([
            'family_id' => $s1['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '400.00',
            'selected_item_ids' => [$itemSep, $itemAug], // Passed out of chronological order
            'idempotency_key' => (string) Str::uuid(),
        ], $this->admin->id);

        $allocs = DB::table('payment_allocations')->where('payment_id', $payment->id)->orderBy('id')->get();
        $this->assertCount(2, $allocs);

        // August is settled first because of chronological tie breaker
        $this->assertEquals($itemAug, $allocs[0]->invoice_item_id);
        $this->assertEquals(30000, $allocs[0]->amount_minor);

        // September gets remaining 100
        $this->assertEquals($itemSep, $allocs[1]->invoice_item_id);
        $this->assertEquals(10000, $allocs[1]->amount_minor);
    }

    /**
     * Scenario 7: Previously partially paid fee
     * Fee A was original 500, previously paid 300 -> current outstanding is 200.
     * Fee B is original 250, unpaid -> current outstanding is 250.
     * Received 300.
     * Expected: Sort by remaining outstanding (200 < 250), so Fee A is fully paid first (receives 200), Fee B receives remaining 100.
     */
    public function test_previously_partially_paid_fee_sorts_by_remaining_outstanding(): void
    {
        $familyCode = 'FAM-SCEN7';
        $s1 = $this->createFamilyWithStudent($familyCode, 'GR1', 'Student 1', 50000);
        $s2 = $this->createFamilyWithStudent($familyCode, 'GR2', 'Student 2', 25000);

        $itemA = $this->createInvoiceWithItem($s1['familyId'], $s1['studentId'], $s1['enrollmentId'], 'Fee A (500)', 50000, '2026-10-01');
        $itemB = $this->createInvoiceWithItem($s2['familyId'], $s2['studentId'], $s2['enrollmentId'], 'Fee B (250)', 25000, '2026-10-01');

        // Prior partial payment of 300 to Fee A
        $svc = new ReceivePaymentService();
        $svc->receive([
            'family_id' => $s1['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '300.00',
            'selected_item_ids' => [$itemA],
            'idempotency_key' => (string) Str::uuid(),
        ], $this->admin->id);

        $this->assertEquals(30000, DB::table('invoice_items')->where('id', $itemA)->value('paid_minor'));
        // Remaining outstanding for Fee A is 200 SAR (20000 minor)
        // Fee B outstanding is 250 SAR (25000 minor)

        // Now receive 300 SAR for both Fee A and Fee B
        $payment = $svc->receive([
            'family_id' => $s1['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '300.00',
            'selected_item_ids' => [$itemB, $itemA],
            'idempotency_key' => (string) Str::uuid(),
        ], $this->admin->id);

        $allocs = DB::table('payment_allocations')->where('payment_id', $payment->id)->orderBy('id')->get();
        $this->assertCount(2, $allocs);

        // Fee A (due 200) was smaller than Fee B (due 250), so Fee A gets 200 fully settled
        $this->assertEquals($itemA, $allocs[0]->invoice_item_id);
        $this->assertEquals(20000, $allocs[0]->amount_minor);

        // Fee B receives remaining 100
        $this->assertEquals($itemB, $allocs[1]->invoice_item_id);
        $this->assertEquals(10000, $allocs[1]->amount_minor);

        $this->assertEquals(50000, DB::table('invoice_items')->where('id', $itemA)->value('paid_minor'));
        $this->assertEquals(10000, DB::table('invoice_items')->where('id', $itemB)->value('paid_minor'));
    }

    /**
     * Scenario 8: User selects fees in reverse order
     * User submits selected_item_ids as [350, 315, 270].
     * Receiving 900.
     * Expected: Still allocate smallest first: 270 fully, 315 fully, 350 receives 315.
     */
    public function test_user_selects_fees_in_reverse_order(): void
    {
        $familyCode = 'FAM-SCEN8';
        $s1 = $this->createFamilyWithStudent($familyCode, 'GR1', 'Small Fee', 27000);
        $s2 = $this->createFamilyWithStudent($familyCode, 'GR2', 'Medium Fee', 31500);
        $s3 = $this->createFamilyWithStudent($familyCode, 'GR3', 'Large Fee', 35000);

        $item1 = $this->createInvoiceWithItem($s1['familyId'], $s1['studentId'], $s1['enrollmentId'], 'Monthly Fee 1', 27000, '2026-10-01');
        $item2 = $this->createInvoiceWithItem($s2['familyId'], $s2['studentId'], $s2['enrollmentId'], 'Monthly Fee 2', 31500, '2026-10-01');
        $item3 = $this->createInvoiceWithItem($s3['familyId'], $s3['studentId'], $s3['enrollmentId'], 'Monthly Fee 3', 35000, '2026-10-01');

        $svc = new ReceivePaymentService();
        // Submit in reverse order: largest first
        $payment = $svc->receive([
            'family_id' => $s1['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '900.00',
            'selected_item_ids' => [$item3, $item2, $item1],
            'idempotency_key' => (string) Str::uuid(),
        ], $this->admin->id);

        $allocs = DB::table('payment_allocations')->where('payment_id', $payment->id)->orderBy('id')->get();
        $this->assertCount(3, $allocs);

        // Smallest fee first
        $this->assertEquals($item1, $allocs[0]->invoice_item_id);
        $this->assertEquals(27000, $allocs[0]->amount_minor);

        $this->assertEquals($item2, $allocs[1]->invoice_item_id);
        $this->assertEquals(31500, $allocs[1]->amount_minor);

        $this->assertEquals($item3, $allocs[2]->invoice_item_id);
        $this->assertEquals(31500, $allocs[2]->amount_minor);
    }

    /**
     * Scenario 9: Unselected fee
     * Family has 4 fees (200, 270, 315, 350).
     * User only selects [270, 315, 350], leaving 200 unselected.
     * Expected: Unselected fee (200) is NEVER allocated any amount.
     */
    public function test_unselected_fee_is_never_included(): void
    {
        $familyCode = 'FAM-SCEN9';
        $s0 = $this->createFamilyWithStudent($familyCode, 'GR0', 'Unselected Student', 20000);
        $s1 = $this->createFamilyWithStudent($familyCode, 'GR1', 'Student 1', 27000);
        $s2 = $this->createFamilyWithStudent($familyCode, 'GR2', 'Student 2', 31500);
        $s3 = $this->createFamilyWithStudent($familyCode, 'GR3', 'Student 3', 35000);

        $item0 = $this->createInvoiceWithItem($s0['familyId'], $s0['studentId'], $s0['enrollmentId'], 'Unselected Fee', 20000, '2026-10-01');
        $item1 = $this->createInvoiceWithItem($s1['familyId'], $s1['studentId'], $s1['enrollmentId'], 'Fee 1', 27000, '2026-10-01');
        $item2 = $this->createInvoiceWithItem($s2['familyId'], $s2['studentId'], $s2['enrollmentId'], 'Fee 2', 31500, '2026-10-01');
        $item3 = $this->createInvoiceWithItem($s3['familyId'], $s3['studentId'], $s3['enrollmentId'], 'Fee 3', 35000, '2026-10-01');

        $svc = new ReceivePaymentService();
        $payment = $svc->receive([
            'family_id' => $s0['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '900.00',
            'selected_item_ids' => [$item1, $item2, $item3], // item0 NOT selected
            'idempotency_key' => (string) Str::uuid(),
        ], $this->admin->id);

        $allocs = DB::table('payment_allocations')->where('payment_id', $payment->id)->pluck('invoice_item_id')->all();
        $this->assertNotContains($item0, $allocs);
        $this->assertEquals(0, DB::table('invoice_items')->where('id', $item0)->value('paid_minor'));
    }

    /**
     * Scenario 10: Preview vs saved allocations via HTTP endpoints.
     * Checks that challan page renders the required data attributes for the preview,
     * and submitting via HTTP POST /challan/receive saves allocations matching smallest-first order.
     */
    public function test_preview_and_saved_allocations_via_http(): void
    {
        $familyCode = 'FAM-HTTP';
        $s1 = $this->createFamilyWithStudent($familyCode, 'GR8062', 'Mohammed Ibrahim', 27000);
        $s2 = $this->createFamilyWithStudent($familyCode, 'GR4418', 'Aisha', 31500);
        $s3 = $this->createFamilyWithStudent($familyCode, 'GR4417', 'Amna', 35000);

        $item1 = $this->createInvoiceWithItem($s1['familyId'], $s1['studentId'], $s1['enrollmentId'], 'Monthly Fee', 27000, '2026-10-01');
        $item2 = $this->createInvoiceWithItem($s2['familyId'], $s2['studentId'], $s2['enrollmentId'], 'Monthly Fee', 31500, '2026-10-01');
        $item3 = $this->createInvoiceWithItem($s3['familyId'], $s3['studentId'], $s3['enrollmentId'], 'Monthly Fee', 35000, '2026-10-01');

        // Check challan page GET renders checkboxes with data-balance-minor
        $response = $this->actingAs($this->admin)->get('/challan?family=' . $familyCode);
        $response->assertStatus(200);
        $response->assertSee('data-balance-minor="27000"', false);
        $response->assertSee('data-balance-minor="31500"', false);
        $response->assertSee('data-balance-minor="35000"', false);

        // Submit POST /challan/receive
        $postRes = $this->actingAs($this->admin)->post('/challan/receive', [
            'family_id' => $s1['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'ledger_scope' => "{$this->campusId}:{$this->schoolDeptId}:SAR",
            'receiving_amount' => '900.00',
            'monthly_amount' => '900.00',
            'selected_item_ids' => [$item3, $item1, $item2], // Out of order selection
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $postRes->assertRedirect('/challan?family=' . $familyCode);

        // Verify allocations in database
        $allocs = DB::table('payment_allocations')->orderBy('id')->get();
        $this->assertCount(3, $allocs);

        $this->assertEquals($item1, $allocs[0]->invoice_item_id);
        $this->assertEquals(27000, $allocs[0]->amount_minor);

        $this->assertEquals($item2, $allocs[1]->invoice_item_id);
        $this->assertEquals(31500, $allocs[1]->amount_minor);

        $this->assertEquals($item3, $allocs[2]->invoice_item_id);
        $this->assertEquals(31500, $allocs[2]->amount_minor);
    }
}
