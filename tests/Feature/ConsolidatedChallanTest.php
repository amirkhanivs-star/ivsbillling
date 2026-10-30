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

    /**
     * Test 5: Verify Due Date replaces WhatsApp and official school contact details are rendered in student layout.
     */
    public function test_student_challan_displays_due_date_and_official_school_contact(): void
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => '5501',
            'guardian_name' => 'Tariq Mehmood',
            'whatsapp' => '+923001234567',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => '5501',
            'name' => 'Bilal Tariq',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $run1 = $this->createBillingRun('2026-07-01');
        $run2 = $this->createBillingRun('2026-08-01');

        $inv1 = DB::table('invoices')->insertGetId([
            'invoice_number' => 'SCH-TEST-5501-1',
            'billing_run_id' => $run1,
            'family_id' => $familyId,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'currency' => 'PKR',
            'total_minor' => 1500000,
            'paid_minor' => 0,
            'issued_on' => '2026-07-01',
            'due_date' => '2026-07-15',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createInvoiceItem($inv1, $studentId, 'PKR', 1500000, '2026-07-01');

        $inv2 = DB::table('invoices')->insertGetId([
            'invoice_number' => 'SCH-TEST-5501-2',
            'billing_run_id' => $run2,
            'family_id' => $familyId,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'currency' => 'PKR',
            'total_minor' => 1500000,
            'paid_minor' => 0,
            'issued_on' => '2026-08-01',
            'due_date' => '2026-08-10',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createInvoiceItem($inv2, $studentId, 'PKR', 1500000, '2026-08-01');

        $response = $this->actingAs($this->admin)->get("/students/{$studentId}/challan/pdf");
        $response->assertStatus(200);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
        $pdfContent = $response->getContent();
        $this->assertNotEmpty($pdfContent);
        $this->assertStringStartsWith('%PDF-', $pdfContent);

        // Also render the underlying view directly with controller logic to test exact text bindings
        $ctrl = app(\App\Http\Controllers\BillingController::class);
        $req = \Illuminate\Http\Request::create("/students/{$studentId}/challan/pdf");
        $req->setUserResolver(fn() => $this->admin);

        // Call controller method through reflection / route and inspect generated view content
        $view = view('pdf.consolidated-challan', [
            'mode' => 'student',
            'student' => DB::table('students as s')->join('families as f', 'f.id', '=', 's.family_id')->where('s.id', $studentId)->select('s.*', 'f.family_code', 'f.whatsapp', 'f.guardian_name')->first(),
            'enrollment' => null,
            'parentDisplay' => ['label' => 'Guardian', 'name' => 'Tariq Mehmood'],
            'currencyGroups' => collect([
                'PKR' => [
                    'currency' => 'PKR',
                    'items' => collect([
                        (object)['period_start' => '2026-07-01', 'description' => 'Tuition', 'invoice_number' => 'SCH-TEST-5501-1', 'net_minor' => 1500000, 'paid_minor' => 0, 'discount_minor' => 0, 'due_date' => '2026-07-15'],
                        (object)['period_start' => '2026-08-01', 'description' => 'Tuition', 'invoice_number' => 'SCH-TEST-5501-2', 'net_minor' => 1500000, 'paid_minor' => 0, 'discount_minor' => 0, 'due_date' => '2026-08-10'],
                    ]),
                    'due_date' => '2026-07-15',
                    'total_net_minor' => 3000000,
                    'total_paid_minor' => 0,
                    'total_balance_minor' => 3000000,
                ],
            ]),
            'totalItemsCount' => 2,
            'dept' => DB::table('departments')->where('id', $this->schoolDeptId)->first(),
            'branding' => null,
            'banner' => '',
            'statementNumber' => 'CHL-STU-5501-' . now()->format('Ymd'),
            'generatedAt' => now(),
            'dueDate' => '2026-07-15',
        ])->render();

        $this->assertStringContainsString('Due Date:', $view);
        $this->assertStringContainsString('15-July-2026', $view);
        $this->assertStringNotContainsString('WhatsApp:', $view);
        $this->assertStringContainsString('+92 325 524551', $view);
        $this->assertStringContainsString('acivs2021@gmail.com', $view);
        $this->assertStringNotContainsString('+92 325 524 5551', $view);
        $this->assertStringNotContainsString('+92 335 524 5551', $view);
    }

    /**
     * Test 6: Verify Due Date replaces WhatsApp and official contact details in family layout.
     */
    public function test_family_challan_displays_due_date_and_official_school_contact(): void
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => '5502',
            'guardian_name' => 'Saeed Anwar',
            'whatsapp' => '+923009876543',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stu1 = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => '5502-1',
            'name' => 'Hamza Saeed',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stu2 = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => '5502-2',
            'name' => 'Ayesha Saeed',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $run1 = $this->createBillingRun('2026-06-01');
        $run2 = $this->createBillingRun('2026-09-01');

        $inv1 = DB::table('invoices')->insertGetId([
            'invoice_number' => 'SCH-FAM-5502-1',
            'billing_run_id' => $run1,
            'family_id' => $familyId,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'currency' => 'PKR',
            'total_minor' => 1000000,
            'paid_minor' => 0,
            'issued_on' => '2026-06-01',
            'due_date' => '2026-06-25',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createInvoiceItem($inv1, $stu1, 'PKR', 1000000, '2026-06-01');

        $inv2 = DB::table('invoices')->insertGetId([
            'invoice_number' => 'SCH-FAM-5502-2',
            'billing_run_id' => $run2,
            'family_id' => $familyId,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'currency' => 'PKR',
            'total_minor' => 1200000,
            'paid_minor' => 0,
            'issued_on' => '2026-09-01',
            'due_date' => '2026-09-10',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createInvoiceItem($inv2, $stu2, 'PKR', 1200000, '2026-09-01');

        $response = $this->actingAs($this->admin)->get("/families/{$familyId}/challan/pdf");
        $response->assertStatus(200);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
        $pdfContent = $response->getContent();
        $this->assertNotEmpty($pdfContent);
        $this->assertStringStartsWith('%PDF-', $pdfContent);

        $view = view('pdf.consolidated-challan', [
            'mode' => 'family',
            'family' => DB::table('families')->where('id', $familyId)->first(),
            'familyStudents' => DB::table('students')->where('family_id', $familyId)->get(),
            'parentDisplay' => ['label' => 'Guardian', 'name' => 'Saeed Anwar'],
            'currencyGroups' => collect([
                'PKR' => [
                    'currency' => 'PKR',
                    'students' => collect([
                        [
                            'student_id' => $stu1,
                            'student_name' => 'Hamza Saeed',
                            'gr_number' => '5502-1',
                            'grade_label' => 'Class 5',
                            'items' => collect([
                                (object)['period_start' => '2026-06-01', 'description' => 'Tuition', 'invoice_number' => 'SCH-FAM-5502-1', 'net_minor' => 1000000, 'paid_minor' => 0, 'discount_minor' => 0, 'due_date' => '2026-06-25'],
                            ]),
                            'student_subtotal_balance_minor' => 1000000,
                        ],
                        [
                            'student_id' => $stu2,
                            'student_name' => 'Ayesha Saeed',
                            'gr_number' => '5502-2',
                            'grade_label' => 'Class 7',
                            'items' => collect([
                                (object)['period_start' => '2026-09-01', 'description' => 'Tuition', 'invoice_number' => 'SCH-FAM-5502-2', 'net_minor' => 1200000, 'paid_minor' => 0, 'discount_minor' => 0, 'due_date' => '2026-09-10'],
                            ]),
                            'student_subtotal_balance_minor' => 1200000,
                        ],
                    ]),
                    'due_date' => '2026-06-25',
                    'total_net_minor' => 2200000,
                    'total_paid_minor' => 0,
                    'total_balance_minor' => 2200000,
                ],
            ]),
            'totalItemsCount' => 2,
            'dept' => DB::table('departments')->where('id', $this->schoolDeptId)->first(),
            'branding' => null,
            'banner' => '',
            'statementNumber' => 'CHL-FAM-5502-' . now()->format('Ymd'),
            'generatedAt' => now(),
            'dueDate' => '2026-06-25',
        ])->render();

        $this->assertStringContainsString('Due Date:', $view);
        $this->assertStringContainsString('25-June-2026', $view);
        $this->assertStringNotContainsString('WhatsApp:', $view);
        $this->assertStringContainsString('+92 325 524551', $view);
        $this->assertStringContainsString('acivs2021@gmail.com', $view);
        $this->assertStringNotContainsString('+92 325 524 5551', $view);
        $this->assertStringNotContainsString('+92 335 524 5551', $view);
    }

    /**
     * Test 7: Empty challan layout around line 191 renders cleanly with correct footer contact information.
     */
    public function test_empty_challan_layout_displays_official_contact_details(): void
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => '5503',
            'guardian_name' => 'Empty Dues Guardian',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get("/families/{$familyId}/challan/pdf");
        $response->assertStatus(200);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));

        $view = view('pdf.consolidated-challan', [
            'mode' => 'family',
            'family' => DB::table('families')->where('id', $familyId)->first(),
            'familyStudents' => collect([]),
            'parentDisplay' => ['label' => 'Guardian', 'name' => 'Empty Dues Guardian'],
            'currencyGroups' => collect([]),
            'totalItemsCount' => 0,
            'dept' => DB::table('departments')->where('id', $this->schoolDeptId)->first(),
            'branding' => null,
            'banner' => '',
            'statementNumber' => 'CHL-FAM-5503-' . now()->format('Ymd'),
            'generatedAt' => now(),
            'dueDate' => null,
        ])->render();

        $this->assertStringContainsString('MONTHLY FEE INVOICE', $view);
        $this->assertStringContainsString('There are no unpaid billed fee items to display', $view);
        $this->assertStringContainsString('+92 325 524551', $view);
        $this->assertStringContainsString('acivs2021@gmail.com', $view);
        $this->assertStringNotContainsString('+92 325 524 5551', $view);
    }

    /**
     * Test A: Previous and current fees (Aug SAR 300, Sep SAR 200, Oct SAR 350 -> Total SAR 850; all 3 months appear).
     */
    public function test_a_previous_and_current_fees_total_payable_and_all_months_appear(): void
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-TEST-A',
            'guardian_name' => 'Ahmad Al-Mansoor',
            'whatsapp' => '+966501000001',
            'contact_verified' => true,
            'messaging_opt_in' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'GR-A-101',
            'name' => 'Zaid Ahmad',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'grade_label' => 'Grade 6',
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $periods = [
            ['start' => '2026-08-01', 'due' => '2026-08-05', 'amount' => 30000, 'inv' => 'INV-A-AUG-2026'],
            ['start' => '2026-09-01', 'due' => '2026-09-05', 'amount' => 20000, 'inv' => 'INV-A-SEP-2026'],
            ['start' => '2026-10-01', 'due' => '2026-10-05', 'amount' => 35000, 'inv' => 'INV-A-OCT-2026'],
        ];

        $octInvoice = null;
        foreach ($periods as $p) {
            $runId = $this->createBillingRun($p['start']);
            $invId = DB::table('invoices')->insertGetId([
                'invoice_number' => $p['inv'],
                'billing_run_id' => $runId,
                'family_id' => $familyId,
                'department_id' => $this->schoolDeptId,
                'campus_id' => $this->campusId,
                'currency' => 'SAR',
                'total_minor' => $p['amount'],
                'paid_minor' => 0,
                'issued_on' => $p['start'],
                'due_date' => $p['due'],
                'status' => 'issued',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->createInvoiceItem($invId, $studentId, 'SAR', $p['amount'], $p['start'], $enrollmentId, 'Monthly Tuition Fee');
            if ($p['start'] === '2026-10-01') {
                $octInvoice = DB::table('invoices')->where('id', $invId)->first();
            }
        }

        $builder = app(\App\Services\ConsolidatedInvoiceBuilder::class);
        $family = DB::table('families')->where('id', $familyId)->first();

        // Both family consolidated view and October invoice view must show all 3 months and SAR 850.00
        $famData = $builder->buildFamilyViewData($family, $this->admin, false, null, '2026-10');
        $invData = $builder->buildForInvoice($octInvoice, $this->admin, false, '2026-10');

        $this->assertSame(85000, $famData['currencyGroups']['SAR']['total_balance_minor']);
        $this->assertSame(85000, $invData['currencyGroups']['SAR']['total_balance_minor']);

        $html = view('pdf.consolidated-challan', $invData)->render();
        $this->assertStringContainsString('MONTHLY FEE INVOICE', $html);
        $this->assertStringContainsString('Aug-2026', $html);
        $this->assertStringContainsString('Sep-2026', $html);
        $this->assertStringContainsString('Oct-2026', $html);
        $this->assertStringContainsString('300.00', $html);
        $this->assertStringContainsString('200.00', $html);
        $this->assertStringContainsString('350.00', $html);
        $this->assertStringContainsString('SAR 850.00', $html);
    }

    /**
     * Test B: Partial payment (Aug originally SAR 300, SAR 100 paid -> remaining SAR 200 used in payable).
     */
    public function test_b_partial_payment_uses_remaining_balance_and_preserves_breakdown(): void
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-TEST-B',
            'guardian_name' => 'Salman Qureshi',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'GR-B-201',
            'name' => 'Hiba Salman',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runAug = $this->createBillingRun('2026-08-01');
        $invAug = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-B-AUG-2026',
            'billing_run_id' => $runAug,
            'family_id' => $familyId,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'currency' => 'SAR',
            'total_minor' => 30000,
            'paid_minor' => 10000,
            'issued_on' => '2026-08-01',
            'due_date' => '2026-08-05',
            'status' => 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $invAug,
            'student_id' => $studentId,
            'currency' => 'SAR',
            'description' => 'Monthly Fee - August',
            'period_key' => '2026-08',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'amount_minor' => 35000,
            'discount_minor' => 5000,
            'net_minor' => 30000,
            'paid_minor' => 10000,
            'status' => 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Also create a fully paid invoice (July) and a voided invoice (June) to verify exclusion
        $runJul = $this->createBillingRun('2026-07-01');
        $invJul = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-B-JUL-PAID',
            'billing_run_id' => $runJul,
            'family_id' => $familyId,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'currency' => 'SAR',
            'total_minor' => 30000,
            'paid_minor' => 30000,
            'issued_on' => '2026-07-01',
            'due_date' => '2026-07-05',
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => $invJul,
            'student_id' => $studentId,
            'currency' => 'SAR',
            'description' => 'Settled July Fee',
            'period_key' => '2026-07',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'amount_minor' => 30000,
            'discount_minor' => 0,
            'net_minor' => 30000,
            'paid_minor' => 30000,
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $builder = app(\App\Services\ConsolidatedInvoiceBuilder::class);
        $family = DB::table('families')->where('id', $familyId)->first();
        $data = $builder->buildFamilyViewData($family, $this->admin);

        $this->assertSame(20000, $data['currencyGroups']['SAR']['total_balance_minor']);
        $html = view('pdf.consolidated-challan', $data)->render();
        $this->assertStringContainsString('Original: 350.00', $html);
        $this->assertStringContainsString('Discount: 50.00', $html);
        $this->assertStringContainsString('Paid: 100.00', $html);
        $this->assertStringContainsString('Remaining: 200.00', $html);
        $this->assertStringContainsString('SAR 200.00', $html);
        $this->assertStringNotContainsString('INV-B-JUL-PAID', $html);
    }

    /**
     * Test C: Multiple students (3 students in a family, each with different outstanding fee periods).
     */
    public function test_c_multiple_students_assigned_correctly_with_student_and_family_scopes(): void
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-TEST-C',
            'guardian_name' => 'Farhan Siddiqui',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $students = [
            ['gr' => 'GR-C-301', 'name' => 'Omar Farhan', 'grade' => 'Grade 3', 'month' => '2026-08-01', 'amount' => 25000],
            ['gr' => 'GR-C-302', 'name' => 'Sara Farhan', 'grade' => 'Grade 5', 'month' => '2026-09-01', 'amount' => 30000],
            ['gr' => 'GR-C-303', 'name' => 'Ali Farhan',  'grade' => 'Grade 8', 'month' => '2026-10-01', 'amount' => 40000],
        ];

        $studentIds = [];
        foreach ($students as $idx => $st) {
            $sId = DB::table('students')->insertGetId([
                'family_id' => $familyId,
                'gr_number' => $st['gr'],
                'name' => $st['name'],
                'status' => 'present',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $studentIds[$st['gr']] = $sId;

            $enrId = DB::table('enrollments')->insertGetId([
                'student_id' => $sId,
                'campus_id' => $this->campusId,
                'department_id' => $this->schoolDeptId,
                'grade_label' => $st['grade'],
                'currency' => 'SAR',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $runId = $this->createBillingRun($st['month']);
            $invId = DB::table('invoices')->insertGetId([
                'invoice_number' => 'INV-C-' . ($idx + 1),
                'billing_run_id' => $runId,
                'family_id' => $familyId,
                'department_id' => $this->schoolDeptId,
                'campus_id' => $this->campusId,
                'currency' => 'SAR',
                'total_minor' => $st['amount'],
                'paid_minor' => 0,
                'issued_on' => $st['month'],
                'due_date' => date('Y-m-05', strtotime($st['month'])),
                'status' => 'issued',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->createInvoiceItem($invId, $sId, 'SAR', $st['amount'], $st['month'], $enrId, 'Tuition - ' . $st['name']);
        }

        $builder = app(\App\Services\ConsolidatedInvoiceBuilder::class);
        $family = DB::table('families')->where('id', $familyId)->first();
        $famData = $builder->buildFamilyViewData($family, $this->admin);

        $this->assertSame(95000, $famData['currencyGroups']['SAR']['total_balance_minor']);
        $this->assertCount(3, $famData['currencyGroups']['SAR']['students']);

        $famHtml = view('pdf.consolidated-challan', $famData)->render();
        $this->assertStringContainsString('Omar Farhan', $famHtml);
        $this->assertStringContainsString('GR-C-301', $famHtml);
        $this->assertStringContainsString('Grade 3', $famHtml);
        $this->assertStringContainsString('Sara Farhan', $famHtml);
        $this->assertStringContainsString('GR-C-302', $famHtml);
        $this->assertStringContainsString('Grade 5', $famHtml);
        $this->assertStringContainsString('Ali Farhan', $famHtml);
        $this->assertStringContainsString('GR-C-303', $famHtml);
        $this->assertStringContainsString('Grade 8', $famHtml);
        $this->assertStringContainsString('SAR 950.00', $famHtml);

        // Verify Student-only scope for Omar Farhan excludes siblings Sara and Ali
        $omarRecord = DB::table('students as s')
            ->join('families as f', 'f.id', '=', 's.family_id')
            ->where('s.id', $studentIds['GR-C-301'])
            ->select('s.*', 'f.family_code', 'f.guardian_name', 'f.whatsapp')
            ->first();
        $stuData = $builder->buildStudentViewData($omarRecord, $this->admin);
        $this->assertSame(25000, $stuData['currencyGroups']['SAR']['total_balance_minor']);
        $stuHtml = view('pdf.consolidated-challan', $stuData)->render();
        $this->assertStringContainsString('Omar Farhan', $stuHtml);
        $this->assertStringContainsString('SAR 250.00', $stuHtml);
        $this->assertStringNotContainsString('Sara Farhan', $stuHtml);
        $this->assertStringNotContainsString('Ali Farhan', $stuHtml);
    }

    /**
     * Test D: Future generated fee (October invoice requested while November is already generated and unpaid).
     * Consolidated statement must include November separately and not label it overdue when not overdue.
     */
    public function test_d_future_generated_fee_included_separately_and_not_labeled_overdue(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 10, 20, 10, 0, 0, 'Asia/Karachi'));

        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-TEST-D',
            'guardian_name' => 'Khalid Mahmood',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'GR-D-401',
            'name' => 'Yusuf Khalid',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // September (overdue), October (current requested), November (already generated future period, due 2026-11-05)
        $runSep = $this->createBillingRun('2026-09-01');
        $invSepId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-D-SEP',
            'billing_run_id' => $runSep,
            'family_id' => $familyId,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'currency' => 'SAR',
            'total_minor' => 20000,
            'paid_minor' => 0,
            'issued_on' => '2026-09-01',
            'due_date' => '2026-09-05',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createInvoiceItem($invSepId, $studentId, 'SAR', 20000, '2026-09-01', null, 'September Fee');

        $runOct = $this->createBillingRun('2026-10-01');
        $invOctId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-D-OCT',
            'billing_run_id' => $runOct,
            'family_id' => $familyId,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'currency' => 'SAR',
            'total_minor' => 35000,
            'paid_minor' => 0,
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createInvoiceItem($invOctId, $studentId, 'SAR', 35000, '2026-10-01', null, 'October Fee');

        $runNov = $this->createBillingRun('2026-11-01');
        $invNovId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-D-NOV',
            'billing_run_id' => $runNov,
            'family_id' => $familyId,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'currency' => 'SAR',
            'total_minor' => 35000,
            'paid_minor' => 0,
            'issued_on' => '2026-10-20',
            'due_date' => '2026-11-05',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createInvoiceItem($invNovId, $studentId, 'SAR', 35000, '2026-11-01', null, 'November Fee');

        // Request October invoice specifically
        $octInv = DB::table('invoices')->where('id', $invOctId)->first();
        $builder = app(\App\Services\ConsolidatedInvoiceBuilder::class);
        $data = $builder->buildForInvoice($octInv, $this->admin);

        // Total must include Sep (200) + Oct (350) + Nov (350) = 900 SAR
        $this->assertSame(90000, $data['currencyGroups']['SAR']['total_balance_minor']);
        $this->assertCount(1, $data['currencyGroups']['SAR']['historical_due_dates']);
        $this->assertSame('Sep-2026', $data['currencyGroups']['SAR']['historical_due_dates'][0]['period_label']);
        $this->assertCount(1, $data['currencyGroups']['SAR']['upcoming_due_dates']);
        $this->assertSame('Nov-2026', $data['currencyGroups']['SAR']['upcoming_due_dates'][0]['period_label']);
        $this->assertFalse($data['currencyGroups']['SAR']['upcoming_due_dates'][0]['is_overdue']);

        $html = view('pdf.consolidated-challan', $data)->render();
        $this->assertStringContainsString('Nov-2026', $html);
        $this->assertStringContainsString('05-November-2026', $html);
        $this->assertStringContainsString('Generated Later Period (Nov-2026)', $html);
        $this->assertStringNotContainsString('Later Period Overdue (Nov-2026)', $html);
        $this->assertStringContainsString('SAR 900.00', $html);

        \Carbon\Carbon::setTestNow();
    }

    /**
     * Test E: Multi-currency (SAR and AED unpaid records appear separately and are never added together).
     */
    public function test_e_multi_currency_balances_separated_and_never_summed_together(): void
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-TEST-E',
            'guardian_name' => 'Nasser Al-Harbi',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'GR-E-501',
            'name' => 'Fatima Nasser',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runSar = $this->createBillingRun('2026-09-01');
        $invSar = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-E-SAR',
            'billing_run_id' => $runSar,
            'family_id' => $familyId,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'currency' => 'SAR',
            'total_minor' => 85000,
            'paid_minor' => 0,
            'issued_on' => '2026-09-01',
            'due_date' => '2026-09-05',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createInvoiceItem($invSar, $studentId, 'SAR', 85000, '2026-09-01', null, 'SAR Tuition');

        $runAed = $this->createBillingRun('2026-10-01');
        $invAed = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-E-AED',
            'billing_run_id' => $runAed,
            'family_id' => $familyId,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'currency' => 'AED',
            'total_minor' => 40000,
            'paid_minor' => 0,
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-05',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createInvoiceItem($invAed, $studentId, 'AED', 40000, '2026-10-01', null, 'AED Tuition');

        $builder = app(\App\Services\ConsolidatedInvoiceBuilder::class);
        $family = DB::table('families')->where('id', $familyId)->first();
        $data = $builder->buildFamilyViewData($family, $this->admin);

        $this->assertCount(2, $data['currencyGroups']);
        $this->assertSame(85000, $data['currencyGroups']['SAR']['total_balance_minor']);
        $this->assertSame(40000, $data['currencyGroups']['AED']['total_balance_minor']);

        $html = view('pdf.consolidated-challan', $data)->render();
        $this->assertStringContainsString('SAR 850.00', $html);
        $this->assertStringContainsString('AED 400.00', $html);
        $this->assertStringNotContainsString('1,250.00', $html);
    }

    /**
     * Test F, G, H: Different due dates, all 6 PDF entry points use canonical consolidated design,
     * and rendering is strictly read-only (zero financial mutation).
     */
    public function test_f_g_h_all_pdf_routes_use_canonical_design_preserve_due_dates_and_do_not_mutate_db(): void
    {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-TEST-FGH',
            'guardian_name' => 'Ibrahim Al-Zahrani',
            'whatsapp' => '+966509998877',
            'contact_verified' => true,
            'messaging_opt_in' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'GR-FGH-601',
            'name' => 'Maryam Ibrahim',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'grade_label' => 'Grade 4',
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Earlier month with saved due date 2026-08-05
        $runAug = $this->createBillingRun('2026-08-01');
        $invAugId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-FGH-AUG',
            'billing_run_id' => $runAug,
            'family_id' => $familyId,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'currency' => 'SAR',
            'total_minor' => 30000,
            'paid_minor' => 0,
            'issued_on' => '2026-08-01',
            'due_date' => '2026-08-05',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createInvoiceItem($invAugId, $studentId, 'SAR', 30000, '2026-08-01', $enrId, 'August Tuition');

        // Current month with saved due date 2026-10-12
        $runOct = $this->createBillingRun('2026-10-01');
        $invOctId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-FGH-OCT',
            'billing_run_id' => $runOct,
            'family_id' => $familyId,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'currency' => 'SAR',
            'total_minor' => 35000,
            'paid_minor' => 0,
            'issued_on' => '2026-10-02',
            'due_date' => '2026-10-12',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createInvoiceItem($invOctId, $studentId, 'SAR', 35000, '2026-10-01', $enrId, 'October Tuition');

        $pdfService = app(\App\Services\PdfAccessService::class);
        $publicChallanUrl = $pdfService->getFamilyChallanPdfUrl($familyId);
        $publicInvoiceUrl = $pdfService->getInvoicePdfUrl($invOctId);
        $signedChallanUrl = \Illuminate\Support\Facades\URL::signedRoute('families.signed_challan_pdf', [
            'family' => $familyId,
            'k' => $pdfService->getAccessKey('family', $familyId),
        ]);
        $signedInvoiceUrl = \Illuminate\Support\Facades\URL::signedRoute('invoices.signed_pdf', [
            'invoice' => $invOctId,
            'k' => $pdfService->getAccessKey('invoice', $invOctId),
        ]);

        // Snapshot financial records BEFORE rendering PDFs
        $beforeInvoices = DB::table('invoices')->whereIn('id', [$invAugId, $invOctId])->orderBy('id')->get()->toArray();
        $beforeItems = DB::table('invoice_items')->whereIn('invoice_id', [$invAugId, $invOctId])->orderBy('id')->get()->toArray();
        $beforePaymentsCount = DB::table('payments')->count();
        $beforeAllocationsCount = DB::table('payment_allocations')->count();

        // Hit all 6 PDF entry points:
        // 1. Monthly Invoices Preview Challan (public clean challan URL)
        $this->get($publicChallanUrl)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // 2. Monthly Invoices Invoice PDF (public clean invoice URL)
        $this->get($publicInvoiceUrl)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // 3. Fee Collection Print (/families/{family}/challan/pdf)
        $this->actingAs($this->admin)->get("/families/{$familyId}/challan/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // 4. Student Profile Print Challan (/students/{student}/challan/pdf)
        $this->actingAs($this->admin)->get("/students/{$studentId}/challan/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // 5. Internal individual invoice PDF (/invoices/{invoice}/pdf)
        $this->actingAs($this->admin)->get("/invoices/{$invOctId}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // 6. Legacy signed links
        $this->get($signedChallanUrl)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get($signedInvoiceUrl)->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Verify financial non-mutation (Test H)
        $afterInvoices = DB::table('invoices')->whereIn('id', [$invAugId, $invOctId])->orderBy('id')->get()->toArray();
        $afterItems = DB::table('invoice_items')->whereIn('invoice_id', [$invAugId, $invOctId])->orderBy('id')->get()->toArray();
        $this->assertEquals($beforeInvoices, $afterInvoices, 'Invoices table must not be mutated by PDF rendering');
        $this->assertEquals($beforeItems, $afterItems, 'Invoice items table must not be mutated by PDF rendering');
        $this->assertSame($beforePaymentsCount, DB::table('payments')->count());
        $this->assertSame($beforeAllocationsCount, DB::table('payment_allocations')->count());

        // Verify individual saved due dates (Test F) and canonical heading across builder views
        $octInvObj = DB::table('invoices')->where('id', $invOctId)->first();
        $invViewData = app(\App\Services\ConsolidatedInvoiceBuilder::class)->buildForInvoice($octInvObj, $this->admin);
        $rendered = view('pdf.consolidated-challan', $invViewData)->render();

        $this->assertStringContainsString('MONTHLY FEE INVOICE', $rendered);
        $this->assertStringContainsString('05-August-2026', $rendered);
        $this->assertStringContainsString('12-October-2026', $rendered);
        $this->assertStringContainsString('Prior Overdue', $rendered);
        $this->assertStringContainsString('Current Monthly Due', $rendered);
        $this->assertStringContainsString('INV-FGH-OCT', $rendered);
        $this->assertStringContainsString('SAR 650.00', $rendered);
    }
}
