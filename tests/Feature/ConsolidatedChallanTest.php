<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConsolidatedChallanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $campusId;
    private int $schoolDeptId;

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

        $this->schoolDeptId = DB::table('departments')->where('code', 'SCH')->value('id') ?? DB::table('departments')->insertGetId([
            'name' => 'School', 'code' => 'SCH', 'created_at' => now(), 'updated_at' => now()
        ]);
    }

    private function createBillingRun(string $periodStart): int
    {
        return DB::table('billing_runs')->insertGetId([
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'frequency' => 'monthly',
            'period_key' => date('Y-m', strtotime($periodStart)),
            'period_start' => $periodStart,
            'period_end' => date('Y-m-t', strtotime($periodStart)),
            'due_date' => date('Y-m-10', strtotime($periodStart)),
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createInvoiceItem(int $invId, int $studentId, string $currency, int $amount, string $periodStart, ?int $enrollmentId = null, string $desc = 'Monthly Fee'): int
    {
        return DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'currency' => $currency,
            'description' => $desc,
            'period_key' => date('Y-m', strtotime($periodStart)),
            'period_start' => $periodStart,
            'period_end' => date('Y-m-t', strtotime($periodStart)),
            'amount_minor' => $amount,
            'discount_minor' => 0,
            'net_minor' => $amount,
            'paid_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test 1: Student consolidated challan aggregates multiple unpaid months (e.g. 3 months = SAR 1,000).
     */
    public function test_student_consolidated_challan_aggregates_multiple_unpaid_months(): void
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => '4348',
            'guardian_name' => '=len(N2)', // Formula parent name
            'whatsapp' => '+966500000001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => '4348',
            'name' => 'Laiqa Nazim',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('student_registration_details')->insert([
            'student_id' => $studentId,
            'father_name' => 'Nazim Ch',
            'section_current' => 'A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'grade_label' => '11th (FED)',
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create 3 invoices across 3 months
        $months = [
            ['month' => '2026-08-01', 'amount' => 30000, 'inv' => 'SCH-OB-2026-0000010-40'],
            ['month' => '2026-09-01', 'amount' => 35000, 'inv' => 'SCH-OB-2026-0000012-76'],
            ['month' => '2026-10-01', 'amount' => 35000, 'inv' => 'SCH-OB-2026-0000011-271'],
        ];

        foreach ($months as $m) {
            $runId = $this->createBillingRun($m['month']);

            $invId = DB::table('invoices')->insertGetId([
                'invoice_number' => $m['inv'],
                'billing_run_id' => $runId,
                'family_id' => $familyId,
                'department_id' => $this->schoolDeptId,
                'campus_id' => $this->campusId,
                'currency' => 'SAR',
                'total_minor' => $m['amount'],
                'paid_minor' => 0,
                'issued_on' => $m['month'],
                'due_date' => date('Y-m-10', strtotime($m['month'])),
                'status' => 'issued',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->createInvoiceItem($invId, $studentId, 'SAR', $m['amount'], $m['month'], $enrollmentId, 'Monthly Fee - ' . date('M-Y', strtotime($m['month'])));
        }

        $response = $this->actingAs($this->admin)->get("/students/{$studentId}/challan/pdf");

        $response->assertStatus(200);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
    }

    /**
     * Test 2: Multi-currency student challan isolates PKR and SAR. Sibling is excluded.
     */
    public function test_multi_currency_student_challan_isolates_currencies_and_excludes_sibling(): void
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => '3230',
            'guardian_name' => '=len(N98)',
            'whatsapp' => '+966507357018',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Student 1: Amna Khan
        $amnaId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => '3230',
            'name' => 'Amna Khan',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('student_registration_details')->insert([
            'student_id' => $amnaId,
            'father_name' => 'Shahzad Umer Khan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Student 2: Mariyam Khan (sibling)
        $mariyamId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => '4115',
            'name' => 'Mariyam Khan',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runPkr = $this->createBillingRun('2025-06-01');

        // 3 PKR invoices for Amna (5,000 PKR each = 15,000 PKR) across Jun, Jul, Aug 2025
        $pkrMonths = ['2025-06-01', '2025-07-01', '2025-08-01'];
        foreach ($pkrMonths as $idx => $m) {
            $runPkrMonth = $this->createBillingRun($m);
            $invId = DB::table('invoices')->insertGetId([
                'invoice_number' => "SCH-PKR-00" . ($idx + 1),
                'billing_run_id' => $runPkrMonth,
                'family_id' => $familyId,
                'department_id' => $this->schoolDeptId,
                'campus_id' => $this->campusId,
                'currency' => 'PKR',
                'total_minor' => 500000,
                'paid_minor' => 0,
                'issued_on' => $m,
                'due_date' => date('Y-m-10', strtotime($m)),
                'status' => 'issued',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->createInvoiceItem($invId, $amnaId, 'PKR', 500000, $m, null, "Monthly Fee PKR " . ($idx + 1));
        }

        $runSar = $this->createBillingRun('2025-09-01');

        // 1 SAR invoice for Amna (4,900 SAR)
        $invSarId = DB::table('invoices')->insertGetId([
            'invoice_number' => "SCH-SAR-001",
            'billing_run_id' => $runSar,
            'family_id' => $familyId,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'currency' => 'SAR',
            'total_minor' => 490000,
            'paid_minor' => 0,
            'issued_on' => '2025-09-01',
            'due_date' => '2025-09-10',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createInvoiceItem($invSarId, $amnaId, 'SAR', 490000, '2025-09-01', null, "Monthly Fee SAR");

        // Sibling Mariyam Khan has 85,000 PKR dues
        $runMariyam = $this->createBillingRun('2025-05-01');
        $invMariyam = DB::table('invoices')->insertGetId([
            'invoice_number' => "SCH-PKR-MARIYAM",
            'billing_run_id' => $runMariyam,
            'family_id' => $familyId,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'currency' => 'PKR',
            'total_minor' => 8500000,
            'paid_minor' => 0,
            'issued_on' => '2025-05-01',
            'due_date' => '2025-05-10',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createInvoiceItem($invMariyam, $mariyamId, 'PKR', 8500000, '2025-05-01', null, "Mariyam Monthly Fee PKR");

        // Test Amna's individual challan
        $response = $this->actingAs($this->admin)->get("/students/{$amnaId}/challan/pdf");
        $response->assertStatus(200);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));

        // Test Family challan
        $famResponse = $this->actingAs($this->admin)->get("/families/{$familyId}/challan/pdf");
        $famResponse->assertStatus(200);
        $this->assertEquals('application/pdf', $famResponse->headers->get('Content-Type'));
    }

    /**
     * Test 3: Individual invoice PDF route still functions.
     */
    public function test_individual_invoice_pdf_still_works(): void
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => '9999',
            'guardian_name' => 'Valid Guardian',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => '9999',
            'name' => 'Test Student',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runId = $this->createBillingRun('2026-10-01');

        $invId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-TEST-001',
            'billing_run_id' => $runId,
            'family_id' => $familyId,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'currency' => 'SAR',
            'total_minor' => 35000,
            'paid_minor' => 0,
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-10',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createInvoiceItem($invId, $studentId, 'SAR', 35000, '2026-10-01');

        $response = $this->actingAs($this->admin)->get("/invoices/{$invId}/pdf");
        $response->assertStatus(200);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
    }

    /**
     * Test 4: Access restrictions are enforced.
     */
    public function test_access_restrictions_enforced(): void
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => '9998',
            'guardian_name' => 'Restricted Guardian',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => '9998',
            'name' => 'Restricted Student',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherCampusId = DB::table('campuses')->insertGetId([
            'name' => 'Other Campus', 'code' => 'OTHER', 'created_at' => now(), 'updated_at' => now()
        ]);

        // Unauthenticated request redirects to login
        $guestResponse = $this->get("/students/{$studentId}/challan/pdf");
        $guestResponse->assertRedirect('/login');

        // Mismatched campus user receives 403
        $cashier = User::create([
            'name' => 'Other Campus Cashier',
            'email' => 'cashier@iqra.edu.pk',
            'password' => bcrypt('StrongSecret2026!'),
            'role' => 'campus_cashier',
            'campus_id' => $otherCampusId,
            'department_id' => null,
            'is_active' => true,
        ]);

        $authResponse = $this->actingAs($cashier)->get("/students/{$studentId}/challan/pdf");
        $authResponse->assertStatus(403);
    }
}
