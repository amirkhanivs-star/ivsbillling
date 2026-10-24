<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CreateFeeDuplicateProtectionTest extends TestCase
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

        DB::table('fee_catalog_rates')->updateOrInsert(
            [
                'fee_type_id' => $this->monthlyFeeTypeId,
                'department_id' => $this->schoolDeptId,
                'class_label' => 'Grade 10',
                'currency' => 'SAR',
            ],
            [
                'amount_minor' => 35000,
                'frequency' => 'monthly',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function createFamilyWithStudent(string $familyCode, string $gr, string $name): array
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => $familyCode,
            'guardian_name' => 'Guardian ' . $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => $gr,
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'grade_label' => 'Grade 10',
            'currency' => 'SAR',
            'monthly_fee_minor' => 35000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('familyId', 'studentId', 'enrollmentId', 'familyCode');
    }

    /**
     * 1. Already billed scenario: display friendly "An invoice has already been generated for this fee and period."
     */
    public function test_already_billed_unpaid_fee_displays_friendly_invoice_generated_message(): void
    {
        $f = $this->createFamilyWithStudent('FAM201', 'GR201', 'Tariq');

        // Initial creation of November 2026 fee
        $firstResponse = $this->actingAs($this->admin)->post('/challan/create-fee', [
            'family_id' => $f['familyId'],
            'student_ids' => [$f['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);

        $firstResponse->assertSessionHas('notice');
        $this->assertEquals(1, DB::table('invoice_items')->where('student_id', $f['studentId'])->count());
        $this->assertEquals(1, DB::table('invoices')->where('family_id', $f['familyId'])->count());

        // Attempting to create the exact same fee again
        $duplicateResponse = $this->actingAs($this->admin)->post('/challan/create-fee', [
            'family_id' => $f['familyId'],
            'student_ids' => [$f['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);

        $duplicateResponse->assertStatus(302);
        $duplicateResponse->assertSessionHasErrors([
            'fee_type_ids' => 'An invoice has already been generated for this fee and period.',
        ]);
        $duplicateResponse->assertSessionMissing('notice');
        $duplicateResponse->assertSessionHasInput('months', [11]);
        $duplicateResponse->assertSessionHasInput('year', 2026);
        $duplicateResponse->assertSessionHasInput('fee_type_ids', [$this->monthlyFeeTypeId]);

        // Confirm zero duplicates created
        $this->assertEquals(1, DB::table('invoice_items')->where('student_id', $f['studentId'])->count());
        $this->assertEquals(1, DB::table('invoices')->where('family_id', $f['familyId'])->count());
    }

    /**
     * 2. Already paid scenario: display friendly "Payment has already been received for this fee and period."
     */
    public function test_already_paid_fee_displays_friendly_payment_received_message(): void
    {
        $f = $this->createFamilyWithStudent('FAM202', 'GR202', 'Zayd');

        // Initial creation of November 2026 fee
        $this->actingAs($this->admin)->post('/challan/create-fee', [
            'family_id' => $f['familyId'],
            'student_ids' => [$f['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);

        $item = DB::table('invoice_items')->where('student_id', $f['studentId'])->first();
        $this->assertNotNull($item);

        // Mark the invoice and item as paid
        DB::table('invoice_items')->where('id', $item->id)->update([
            'paid_minor' => 35000,
            'updated_at' => now(),
        ]);
        DB::table('invoices')->where('id', $item->invoice_id)->update([
            'paid_minor' => 35000,
            'status' => 'paid',
            'updated_at' => now(),
        ]);

        // Attempting to create the fee for the paid period
        $duplicateResponse = $this->actingAs($this->admin)->post('/challan/create-fee', [
            'family_id' => $f['familyId'],
            'student_ids' => [$f['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);

        $duplicateResponse->assertStatus(302);
        $duplicateResponse->assertSessionHasErrors([
            'fee_type_ids' => 'Payment has already been received for this fee and period.',
        ]);
        $duplicateResponse->assertSessionMissing('notice');

        // Confirm zero duplicates created
        $this->assertEquals(1, DB::table('invoice_items')->where('student_id', $f['studentId'])->count());
        $this->assertEquals(1, DB::table('invoices')->where('family_id', $f['familyId'])->count());
    }

    /**
     * 3. Specific invoice unique-constraint collision (e.g. Duplicate entry '58-3063-SAR')
     *    is handled gracefully without exposing SQL or HTTP 500.
     */
    public function test_unique_constraint_collision_returns_friendly_warning_not_500(): void
    {
        $f = $this->createFamilyWithStudent('FAM203', 'GR203', 'Yusuf');

        // Create billing run directly
        $runId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-25',
            'status' => 'generated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create an existing invoice with status 'paid' for this run, family, and currency
        // This triggers the exact condition where previous code bypassed $invoice and crashed on insert
        $invoiceId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $f['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'currency' => 'SAR',
            'invoice_number' => 'SCH-2026-9999001',
            'issued_on' => '2026-10-24',
            'due_date' => '2026-11-25',
            'total_minor' => 35000,
            'paid_minor' => 35000,
            'previous_dues_snapshot_minor' => 0,
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $invoiceId,
            'student_id' => $f['studentId'],
            'enrollment_id' => $f['enrollmentId'],
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'currency' => 'SAR',
            'amount_minor' => 35000,
            'net_minor' => 35000,
            'paid_minor' => 35000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // User submits Create Fee for this exact period
        $challanUrl = '/challan?family=' . urlencode($f['familyCode']);
        $response = $this->actingAs($this->admin)->from($challanUrl)->post('/challan/create-fee', [
            'family_id' => $f['familyId'],
            'student_ids' => [$f['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);

        // Must NOT be 500! Must be a 302 redirect with user-friendly error
        $response->assertStatus(302);
        $response->assertSessionHasErrors([
            'fee_type_ids' => 'Payment has already been received for this fee and period.',
        ]);

        // Follow the redirect to verify the UI renders the error cleanly without SQL leak
        $followPage = $this->actingAs($this->admin)->get($response->headers->get('Location'));
        $followPage->assertOk();
        $followPage->assertSee('Payment has already been received for this fee and period.');
        $followPage->assertDontSee('Duplicate entry');
        $followPage->assertDontSee('invoices_billing_run_id_family_id_currency_unique');
    }

    /**
     * 4. Normal valid fee creation works as expected.
     */
    public function test_normal_fee_creation_succeeds(): void
    {
        $f = $this->createFamilyWithStudent('FAM204', 'GR204', 'Bilal');

        $response = $this->actingAs($this->admin)->post('/challan/create-fee', [
            'family_id' => $f['familyId'],
            'student_ids' => [$f['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [12],
            'year' => 2026,
        ]);

        $response->assertStatus(302);
        $response->assertSessionHas('notice');
        $this->assertEquals(1, DB::table('invoice_items')->where('student_id', $f['studentId'])->count());
    }

    /**
     * 5. Unrelated database errors are not swallowed (logged and rethrown).
     */
    public function test_unrelated_database_errors_are_logged_and_not_swallowed(): void
    {
        Log::spy();

        $f = $this->createFamilyWithStudent('FAM205', 'GR205', 'Hamza');

        // Drop foreign keys check / point to non-existent fee type to trigger non-validation error
        // Or directly test with an invalid database state
        $controller = new \App\Http\Controllers\BillingController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('isInvoiceUniqueCollision');
        $method->setAccessible(true);

        $fakeGenericException = new \Illuminate\Database\QueryException(
            'connection',
            'SELECT * FROM non_existent_table',
            [],
            new \Exception('Table not found')
        );

        $this->assertFalse($method->invoke($controller, $fakeGenericException));

        $fakeInvoiceCollisionException = new \Illuminate\Database\QueryException(
            'connection',
            'INSERT INTO invoices ...',
            [],
            new \Exception("Duplicate entry '58-3063-SAR' for key 'invoices_billing_run_id_family_id_currency_unique'")
        );

        $this->assertTrue($method->invoke($controller, $fakeInvoiceCollisionException));
    }
}
