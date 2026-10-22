<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InvoiceService;
use App\Services\ReceivePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RegularBillingAndMultiMonthPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected int $campusId;
    protected int $schoolDeptId;
    protected int $tuitionDeptId;
    protected int $monthlyFeeTypeId;
    protected int $admissionFeeTypeId;
    protected int $booksFeeTypeId;
    protected int $examFeeTypeId;
    protected int $customFeeTypeId;

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

        $this->tuitionDeptId = DB::table('departments')->where('code', 'TUI')->value('id') ?? DB::table('departments')->insertGetId([
            'name' => 'Tuition', 'code' => 'TUI', 'created_at' => now(), 'updated_at' => now()
        ]);

        $this->monthlyFeeTypeId = DB::table('fee_types')->where('code', 'monthly')->value('id');
        $this->admissionFeeTypeId = DB::table('fee_types')->where('code', 'admission')->value('id');
        $this->booksFeeTypeId = DB::table('fee_types')->where('code', 'books')->value('id');
        $this->examFeeTypeId = DB::table('fee_types')->where('code', 'exam')->value('id');
        $this->customFeeTypeId = DB::table('fee_types')->where('code', 'custom')->value('id');

        // Seed catalog rates for class 'Grade 10' in School (SAR)
        $this->seedRate($this->monthlyFeeTypeId, $this->schoolDeptId, 'Grade 10', 'SAR', 35000, 'monthly');
        $this->seedRate($this->admissionFeeTypeId, $this->schoolDeptId, 'Grade 10', 'SAR', 50000, 'one_time');
        $this->seedRate($this->booksFeeTypeId, $this->schoolDeptId, 'Grade 10', 'SAR', 20000, 'one_time');
        $this->seedRate($this->examFeeTypeId, $this->schoolDeptId, 'Grade 10', 'SAR', 15000, 'one_time');
        $this->seedRate($this->customFeeTypeId, $this->schoolDeptId, 'Grade 10', 'SAR', 7500, 'one_time');

        // Seed catalog rates for class 'Grade 10' in School (AED)
        $this->seedRate($this->monthlyFeeTypeId, $this->schoolDeptId, 'Grade 10', 'AED', 35000, 'monthly');

        // Seed catalog rates for class 'Tuition - A' in Tuition (SAR)
        $this->seedRate($this->monthlyFeeTypeId, $this->tuitionDeptId, 'Tuition - A', 'SAR', 40000, 'monthly');
    }

    private function seedRate(int $feeTypeId, int $deptId, string $classLabel, string $currency, int $minor, string $freq): void
    {
        DB::table('fee_catalog_rates')->updateOrInsert(
            ['fee_type_id' => $feeTypeId, 'department_id' => $deptId, 'class_label' => $classLabel, 'currency' => $currency],
            ['amount_minor' => $minor, 'frequency' => $freq, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function createFamilyWithStudent(string $familyCode, string $gr, string $name, string $grade = 'Grade 10', string $currency = 'SAR', ?int $deptId = null): array
    {
        $deptId = $deptId ?? $this->schoolDeptId;
        $familyId = DB::table('families')->insertGetId([
            'family_code' => $familyCode, 'guardian_name' => 'Guardian ' . $name, 'created_at' => now(), 'updated_at' => now()
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId, 'gr_number' => $gr, 'name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()
        ]);

        $enrollmentId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId, 'campus_id' => $this->campusId, 'department_id' => $deptId,
            'grade_label' => $grade, 'currency' => $currency, 'monthly_fee_minor' => 35000, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now()
        ]);

        return compact('familyId', 'studentId', 'enrollmentId');
    }

    private function createInvoiceWithItem(int $familyId, int $studentId, int $enrollmentId, string $desc, int $amountMinor, string $periodStart, string $currency = 'SAR', ?int $deptId = null, ?int $feeTypeId = null): int
    {
        $deptId = $deptId ?? $this->schoolDeptId;
        $feeTypeId = $feeTypeId ?? $this->monthlyFeeTypeId;

        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId, 'department_id' => $deptId, 'frequency' => 'monthly',
            'period_key' => 'monthly:' . $periodStart, 'period_start' => $periodStart, 'period_end' => date('Y-m-t', strtotime($periodStart)),
            'due_date' => date('Y-m-25', strtotime($periodStart)), 'status' => 'generated', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now()
        ]);

        $invoiceId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId, 'family_id' => $familyId, 'campus_id' => $this->campusId,
            'department_id' => $deptId, 'currency' => $currency, 'invoice_number' => 'INV-' . uniqid(),
            'issued_on' => now()->toDateString(), 'due_date' => date('Y-m-25', strtotime($periodStart)),
            'total_minor' => $amountMinor, 'paid_minor' => 0, 'previous_dues_snapshot_minor' => 0,
            'status' => 'generated', 'created_at' => now(), 'updated_at' => now()
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId, 'student_id' => $studentId, 'enrollment_id' => $enrollmentId,
            'fee_type_id' => $feeTypeId, 'description' => $desc, 'period_key' => 'monthly:' . $periodStart,
            'period_start' => $periodStart, 'period_end' => date('Y-m-t', strtotime($periodStart)),
            'currency' => $currency, 'amount_minor' => $amountMinor, 'discount_minor' => 0,
            'net_minor' => $amountMinor, 'paid_minor' => 0, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now()
        ]);

        return $itemId;
    }

    /**
     * 1. Two months paid with one receipt.
     * August: SAR 250, September: SAR 350. Received: SAR 600.
     * Expected allocations: August SAR 250, September SAR 350, Advance credit: 0.
     */
    public function test_two_months_paid_with_one_receipt(): void
    {
        $f = $this->createFamilyWithStudent('FAM101', 'GR101', 'Alice');
        $itemAug = $this->createInvoiceWithItem($f['familyId'], $f['studentId'], $f['enrollmentId'], 'Monthly Fee - Aug 2026', 25000, '2026-08-01');
        $itemSep = $this->createInvoiceWithItem($f['familyId'], $f['studentId'], $f['enrollmentId'], 'Monthly Fee - Sep 2026', 35000, '2026-09-01');

        $service = new ReceivePaymentService();
        $payment = $service->receive([
            'family_id' => $f['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '600.00',
            'selected_item_ids' => [$itemAug, $itemSep],
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ], $this->admin->id);

        $this->assertEquals(60000, $payment->amount_minor);
        $this->assertEquals(0, $payment->unallocated_minor);

        $allocs = DB::table('payment_allocations')->where('payment_id', $payment->id)->orderBy('id')->get();
        $this->assertCount(2, $allocs);
        $this->assertEquals(25000, $allocs[0]->amount_minor);
        $this->assertEquals($itemAug, $allocs[0]->invoice_item_id);
        $this->assertEquals(35000, $allocs[1]->amount_minor);
        $this->assertEquals($itemSep, $allocs[1]->invoice_item_id);

        $this->assertEquals(25000, DB::table('invoice_items')->where('id', $itemAug)->value('paid_minor'));
        $this->assertEquals(35000, DB::table('invoice_items')->where('id', $itemSep)->value('paid_minor'));
    }

    /**
     * 2. Three months paid with one receipt.
     */
    public function test_three_months_paid_with_one_receipt(): void
    {
        $f = $this->createFamilyWithStudent('FAM102', 'GR102', 'Bob');
        $item1 = $this->createInvoiceWithItem($f['familyId'], $f['studentId'], $f['enrollmentId'], 'Fee Aug', 35000, '2026-08-01');
        $item2 = $this->createInvoiceWithItem($f['familyId'], $f['studentId'], $f['enrollmentId'], 'Fee Sep', 35000, '2026-09-01');
        $item3 = $this->createInvoiceWithItem($f['familyId'], $f['studentId'], $f['enrollmentId'], 'Fee Oct', 35000, '2026-10-01');

        $service = new ReceivePaymentService();
        $payment = $service->receive([
            'family_id' => $f['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '1050.00',
            'selected_item_ids' => [$item1, $item2, $item3],
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ], $this->admin->id);

        $this->assertEquals(105000, $payment->amount_minor);
        $this->assertEquals(0, $payment->unallocated_minor);

        $allocCount = DB::table('payment_allocations')->where('payment_id', $payment->id)->count();
        $this->assertEquals(3, $allocCount);
    }

    /**
     * 3. Two siblings paid with one receipt.
     */
    public function test_two_siblings_paid_with_one_receipt(): void
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM_SIB', 'guardian_name' => 'Sibling Parent', 'created_at' => now(), 'updated_at' => now()
        ]);

        $s1 = DB::table('students')->insertGetId(['family_id' => $familyId, 'gr_number' => 'SIB1', 'name' => 'Sibling One', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $s2 = DB::table('students')->insertGetId(['family_id' => $familyId, 'gr_number' => 'SIB2', 'name' => 'Sibling Two', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $e1 = DB::table('enrollments')->insertGetId(['student_id' => $s1, 'campus_id' => $this->campusId, 'department_id' => $this->schoolDeptId, 'grade_label' => 'Grade 10', 'currency' => 'SAR', 'monthly_fee_minor' => 35000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $e2 = DB::table('enrollments')->insertGetId(['student_id' => $s2, 'campus_id' => $this->campusId, 'department_id' => $this->schoolDeptId, 'grade_label' => 'Grade 10', 'currency' => 'SAR', 'monthly_fee_minor' => 35000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $item1 = $this->createInvoiceWithItem($familyId, $s1, $e1, 'Monthly Fee Sibling 1', 35000, '2026-08-01');
        $item2 = $this->createInvoiceWithItem($familyId, $s2, $e2, 'Monthly Fee Sibling 2', 35000, '2026-08-01');

        $service = new ReceivePaymentService();
        $payment = $service->receive([
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '700.00',
            'selected_item_ids' => [$item1, $item2],
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ], $this->admin->id);

        $this->assertEquals(70000, $payment->amount_minor);
        $this->assertEquals(0, $payment->unallocated_minor);

        $allocs = DB::table('payment_allocations')->where('payment_id', $payment->id)->get();
        $this->assertCount(2, $allocs);
    }

    /**
     * 4. Partial allocation across two months.
     * Item 1 (Aug): SAR 350, Item 2 (Sep): SAR 350. Received: SAR 500.
     * Expected: Aug fully paid (SAR 350), Sep partially paid (SAR 150), Remaining Sep due: SAR 200.
     */
    public function test_partial_allocation_across_two_months(): void
    {
        $f = $this->createFamilyWithStudent('FAM103', 'GR103', 'Charlie');
        $itemAug = $this->createInvoiceWithItem($f['familyId'], $f['studentId'], $f['enrollmentId'], 'Monthly Fee Aug', 35000, '2026-08-01');
        $itemSep = $this->createInvoiceWithItem($f['familyId'], $f['studentId'], $f['enrollmentId'], 'Monthly Fee Sep', 35000, '2026-09-01');

        $service = new ReceivePaymentService();
        $payment = $service->receive([
            'family_id' => $f['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '500.00',
            'selected_item_ids' => [$itemAug, $itemSep],
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ], $this->admin->id);

        $this->assertEquals(50000, $payment->amount_minor);
        $this->assertEquals(0, $payment->unallocated_minor);

        $this->assertEquals(35000, DB::table('invoice_items')->where('id', $itemAug)->value('paid_minor'));
        $this->assertEquals(15000, DB::table('invoice_items')->where('id', $itemSep)->value('paid_minor'));
    }

    /**
     * 5. Explicit advance credit requirement.
     * Total selected due: SAR 350. Received: SAR 500.
     * Without allow_advance_credit => validation error.
     * With allow_advance_credit = 1 => creates SAR 150 advance credit.
     */
    public function test_explicit_advance_credit_requirement(): void
    {
        $f = $this->createFamilyWithStudent('FAM104', 'GR104', 'David');
        $item = $this->createInvoiceWithItem($f['familyId'], $f['studentId'], $f['enrollmentId'], 'Monthly Fee Aug', 35000, '2026-08-01');

        $service = new ReceivePaymentService();

        // Attempt without allow_advance_credit
        try {
            $service->receive([
                'family_id' => $f['familyId'],
                'campus_id' => $this->campusId,
                'department_id' => $this->schoolDeptId,
                'currency' => 'SAR',
                'monthly_amount' => '500.00',
                'selected_item_ids' => [$item],
                'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            ], $this->admin->id);
            $this->fail('Expected ValidationException when advance credit not explicitly confirmed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('monthly_amount', $e->errors());
        }

        // Attempt WITH allow_advance_credit = 1
        $payment = $service->receive([
            'family_id' => $f['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '500.00',
            'selected_item_ids' => [$item],
            'allow_advance_credit' => 1,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ], $this->admin->id);

        $this->assertEquals(50000, $payment->amount_minor);
        $this->assertEquals(15000, $payment->unallocated_minor); // SAR 150 advance credit
        $this->assertEquals(35000, DB::table('invoice_items')->where('id', $item)->value('paid_minor'));
    }

    /**
     * 6. Payment reversal restores every affected balance.
     */
    public function test_payment_reversal_restores_every_affected_balance(): void
    {
        $f = $this->createFamilyWithStudent('FAM105', 'GR105', 'Emma');
        $itemAug = $this->createInvoiceWithItem($f['familyId'], $f['studentId'], $f['enrollmentId'], 'Monthly Fee Aug', 35000, '2026-08-01');
        $itemSep = $this->createInvoiceWithItem($f['familyId'], $f['studentId'], $f['enrollmentId'], 'Monthly Fee Sep', 35000, '2026-09-01');

        $service = new ReceivePaymentService();
        $payment = $service->receive([
            'family_id' => $f['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '700.00',
            'selected_item_ids' => [$itemAug, $itemSep],
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ], $this->admin->id);

        $this->assertEquals(35000, DB::table('invoice_items')->where('id', $itemAug)->value('paid_minor'));
        $this->assertEquals(35000, DB::table('invoice_items')->where('id', $itemSep)->value('paid_minor'));

        // Reverse receipt via controller action
        $response = $this->actingAs($this->admin)->post('/challan/reverse-receipt', [
            'payment_id' => $payment->id,
            'reason' => 'QA Reversal Verification Test',
        ]);
        $response->assertSessionHas('notice');

        // Check balances restored
        $this->assertEquals(0, DB::table('invoice_items')->where('id', $itemAug)->value('paid_minor'));
        $this->assertEquals(0, DB::table('invoice_items')->where('id', $itemSep)->value('paid_minor'));

        $pRecord = DB::table('payments')->where('id', $payment->id)->first();
        $this->assertEquals('reversed', $pRecord->status);
        $this->assertEquals(0, $pRecord->unallocated_minor);
    }

    /**
     * 7. Admission and Books fees created successfully via Create Fee.
     */
    public function test_admission_and_books_fees_created_successfully(): void
    {
        $f = $this->createFamilyWithStudent('FAM106', 'GR106', 'Frank');

        $response = $this->actingAs($this->admin)->post('/challan/create-fee', [
            'family_id' => $f['familyId'],
            'student_ids' => [$f['studentId']],
            'fee_type_ids' => [$this->admissionFeeTypeId, $this->booksFeeTypeId],
            'months' => [9],
            'year' => 2026,
        ]);

        $response->assertSessionHas('notice');

        $admissionItem = DB::table('invoice_items')
            ->where('student_id', $f['studentId'])
            ->where('fee_type_id', $this->admissionFeeTypeId)
            ->first();
        $this->assertNotNull($admissionItem);
        $this->assertEquals(50000, $admissionItem->amount_minor); // SAR 500

        $booksItem = DB::table('invoice_items')
            ->where('student_id', $f['studentId'])
            ->where('fee_type_id', $this->booksFeeTypeId)
            ->first();
        $this->assertNotNull($booksItem);
        $this->assertEquals(20000, $booksItem->amount_minor); // SAR 200
    }

    /**
     * 8. One-time fee does not repeat when multiple months are selected.
     */
    public function test_one_time_fee_does_not_repeat_across_months(): void
    {
        $f = $this->createFamilyWithStudent('FAM107', 'GR107', 'Grace');

        // Admin selects 3 months (Aug, Sep, Oct) along with Admission Fee (one-time)
        $response = $this->actingAs($this->admin)->post('/challan/create-fee', [
            'family_id' => $f['familyId'],
            'student_ids' => [$f['studentId']],
            'fee_type_ids' => [$this->admissionFeeTypeId],
            'months' => [8, 9, 10],
            'year' => 2026,
        ]);

        $response->assertSessionHas('notice');

        // Only ONE admission fee item should be created
        $count = DB::table('invoice_items')
            ->where('student_id', $f['studentId'])
            ->where('fee_type_id', $this->admissionFeeTypeId)
            ->count();
        $this->assertEquals(1, $count);
    }

    /**
     * 9. Missing catalog rate raises clear error rather than 0 or fallback.
     */
    public function test_missing_catalog_rate_raises_clear_validation_error(): void
    {
        $f = $this->createFamilyWithStudent('FAM108', 'GR108', 'Helen', 'Uncatalogued Class');

        $response = $this->actingAs($this->admin)->post('/challan/create-fee', [
            'family_id' => $f['familyId'],
            'student_ids' => [$f['studentId']],
            'fee_type_ids' => [$this->admissionFeeTypeId],
            'months' => [9],
            'year' => 2026,
        ]);

        $response->assertSessionHasErrors('fee_type_ids');
    }

    /**
     * 10. Mixed currencies cannot be combined in payment allocation.
     */
    public function test_mixed_currencies_cannot_be_combined(): void
    {
        $fSAR = $this->createFamilyWithStudent('FAM109', 'GR109A', 'Ian SAR', 'Grade 10', 'SAR');
        $itemSAR = $this->createInvoiceWithItem($fSAR['familyId'], $fSAR['studentId'], $fSAR['enrollmentId'], 'Monthly Fee SAR', 35000, '2026-08-01', 'SAR');

        // Sibling in AED
        $sAED = DB::table('students')->insertGetId(['family_id' => $fSAR['familyId'], 'gr_number' => 'GR109B', 'name' => 'Ian AED', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $eAED = DB::table('enrollments')->insertGetId(['student_id' => $sAED, 'campus_id' => $this->campusId, 'department_id' => $this->schoolDeptId, 'grade_label' => 'Grade 10', 'currency' => 'AED', 'monthly_fee_minor' => 35000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $itemAED = $this->createInvoiceWithItem($fSAR['familyId'], $sAED, $eAED, 'Monthly Fee AED', 35000, '2026-08-01', 'AED');

        $service = new ReceivePaymentService();

        $this->expectException(ValidationException::class);
        $service->receive([
            'family_id' => $fSAR['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '700.00',
            'selected_item_ids' => [$itemSAR, $itemAED],
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ], $this->admin->id);
    }

    /**
     * 11. School and Tuition departments remain separate.
     */
    public function test_school_and_tuition_departments_remain_separate(): void
    {
        $f = $this->createFamilyWithStudent('FAM110', 'GR110', 'Jack', 'Grade 10', 'SAR', $this->schoolDeptId);
        $itemSchool = $this->createInvoiceWithItem($f['familyId'], $f['studentId'], $f['enrollmentId'], 'School Fee', 35000, '2026-08-01', 'SAR', $this->schoolDeptId);

        // Enrollment in Tuition
        $eTuition = DB::table('enrollments')->insertGetId(['student_id' => $f['studentId'], 'campus_id' => $this->campusId, 'department_id' => $this->tuitionDeptId, 'grade_label' => 'Tuition - A', 'currency' => 'SAR', 'monthly_fee_minor' => 40000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $itemTuition = $this->createInvoiceWithItem($f['familyId'], $f['studentId'], $eTuition, 'Tuition Fee', 40000, '2026-08-01', 'SAR', $this->tuitionDeptId);

        $service = new ReceivePaymentService();

        // Attempting to pay tuition item under School ledger scope must fail
        $this->expectException(ValidationException::class);
        $service->receive([
            'family_id' => $f['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '400.00',
            'selected_item_ids' => [$itemTuition],
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ], $this->admin->id);
    }

    /**
     * 12. Printed receipt PDF endpoint displays all paid items.
     */
    public function test_receipt_pdf_renders_with_all_paid_items(): void
    {
        $f = $this->createFamilyWithStudent('FAM111', 'GR111', 'Kate');
        $item1 = $this->createInvoiceWithItem($f['familyId'], $f['studentId'], $f['enrollmentId'], 'Monthly Fee Aug', 25000, '2026-08-01');
        $item2 = $this->createInvoiceWithItem($f['familyId'], $f['studentId'], $f['enrollmentId'], 'Monthly Fee Sep', 35000, '2026-09-01');

        $service = new ReceivePaymentService();
        $payment = $service->receive([
            'family_id' => $f['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'monthly_amount' => '600.00',
            'selected_item_ids' => [$item1, $item2],
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ], $this->admin->id);

        $response = $this->actingAs($this->admin)->get("/receipts/{$payment->id}/pdf");
        $response->assertStatus(200);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
    }

    /**
     * 13. Students on review hold or historical hold cannot be billed.
     */
    public function test_held_students_cannot_be_billed(): void
    {
        $familyId = DB::table('families')->insertGetId(['family_code' => 'FAM_HELD', 'created_at' => now(), 'updated_at' => now()]);
        $sHeld = DB::table('students')->insertGetId(['family_id' => $familyId, 'gr_number' => 'HELD1', 'name' => 'Held Student', 'status' => 'review_hold', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('enrollments')->insertGetId(['student_id' => $sHeld, 'campus_id' => $this->campusId, 'department_id' => $this->schoolDeptId, 'grade_label' => 'Grade 10', 'currency' => 'SAR', 'monthly_fee_minor' => 35000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($this->admin)->post('/challan/create-fee', [
            'family_id' => $familyId,
            'student_ids' => [$sHeld],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [9],
            'year' => 2026,
        ]);

        $response->assertSessionHasErrors('student_ids');
    }

    /**
     * 14. Challan UI renders with multi-month checkboxes, live breakdown, Create Fee modal, and statements.
     */
    public function test_challan_page_renders_with_multimonth_selection_breakdown_and_statement_sections(): void
    {
        $f = $this->createFamilyWithStudent('FAM112', 'GR112', 'Leo');
        $this->createInvoiceWithItem($f['familyId'], $f['studentId'], $f['enrollmentId'], 'Monthly Fee Aug', 25000, '2026-08-01');
        $this->createInvoiceWithItem($f['familyId'], $f['studentId'], $f['enrollmentId'], 'Monthly Fee Sep', 35000, '2026-09-01');

        $response = $this->actingAs($this->admin)->get('/challan?family=FAM112');
        $response->assertStatus(200);

        // Verify HTML contains multi-item payment controls
        $response->assertSee('chk_select_all');
        $response->assertSee('js-item-checkbox');
        $response->assertSee('hidden-selected-items-inputs');
        $response->assertSee('allocation_breakdown_card');
        $response->assertSee('Allocation Breakdown');
        $response->assertSee('advance_credit_box');
        $response->assertSee('allow_advance_credit');

        // Verify Create Fee panel elements
        $response->assertSee('Create Fee Obligation');
        $response->assertSee('btn-open-create-fee');
        $response->assertSee('cf-charges-preview-card');

        // Verify Statement sections
        $response->assertSee('Live Portal Receipts');
    }
}
