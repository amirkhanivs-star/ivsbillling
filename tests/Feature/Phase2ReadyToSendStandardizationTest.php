<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ConsolidatedInvoiceBuilder;
use App\Services\InvoiceDeliveryQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase2ReadyToSendStandardizationTest extends TestCase
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
            'name' => 'Main Campus',
            'code' => 'MAIN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->schoolDeptId = DB::table('departments')->where('code', 'SCH')->value('id') ?? DB::table('departments')->insertGetId([
            'name' => 'School',
            'code' => 'SCH',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createBillingRun(string $periodStart, ?string $dueDate = null, ?int $campusId = null, ?int $deptId = null): int
    {
        return DB::table('billing_runs')->insertGetId([
            'department_id' => $deptId ?? $this->schoolDeptId,
            'campus_id' => $campusId ?? $this->campusId,
            'frequency' => 'monthly',
            'period_key' => date('Y-m', strtotime($periodStart)),
            'period_start' => $periodStart,
            'period_end' => date('Y-m-t', strtotime($periodStart)),
            'due_date' => $dueDate ?? date('Y-m-10', strtotime($periodStart)),
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createFamilyWithStudent(
        string $familyCode = 'FAM-P2-100',
        string $grNumber = 'GR-P2-100',
        string $studentName = 'Zaid Al-Harbi',
        string $whatsapp = '+966501112233',
        bool $contactVerified = true,
        bool $messagingOptIn = true
    ): array {
        $familyId = DB::table('families')->insertGetId([
            'family_code' => $familyCode,
            'guardian_name' => 'Tariq Al-Harbi',
            'whatsapp' => $whatsapp,
            'email' => strtolower($familyCode) . '@example.test',
            'contact_verified' => $contactVerified,
            'whatsapp_verified' => $contactVerified,
            'messaging_opt_in' => $messagingOptIn,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => $grNumber,
            'name' => $studentName,
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('student_registration_details')->insert([
            'student_id' => $studentId,
            'father_name' => 'Tariq Al-Harbi',
            'section_current' => 'A',
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

        return [$familyId, $studentId, $enrollmentId];
    }

    private function createMonthlyInvoice(
        int $familyId,
        int $studentId,
        ?int $enrollmentId,
        string $invoiceNumber,
        string $periodStart,
        string $currency,
        int $netMinor,
        int $paidMinor = 0,
        ?string $dueDate = null,
        ?int $campusId = null,
        ?int $deptId = null,
        string $description = 'Monthly Tuition Fee'
    ): array {
        $due = $dueDate ?? date('Y-m-10', strtotime($periodStart));
        $runId = $this->createBillingRun($periodStart, $due, $campusId, $deptId);
        $status = $paidMinor >= $netMinor ? 'paid' : ($paidMinor > 0 ? 'partially_paid' : 'issued');

        $invoiceId = DB::table('invoices')->insertGetId([
            'invoice_number' => $invoiceNumber,
            'billing_run_id' => $runId,
            'family_id' => $familyId,
            'department_id' => $deptId ?? $this->schoolDeptId,
            'campus_id' => $campusId ?? $this->campusId,
            'currency' => $currency,
            'total_minor' => $netMinor,
            'paid_minor' => $paidMinor,
            'issued_on' => $periodStart,
            'due_date' => $due,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId,
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'currency' => $currency,
            'description' => $description . ' - ' . date('M Y', strtotime($periodStart)),
            'period_key' => date('Y-m', strtotime($periodStart)),
            'period_start' => $periodStart,
            'period_end' => date('Y-m-t', strtotime($periodStart)),
            'amount_minor' => $netMinor,
            'discount_minor' => 0,
            'net_minor' => $netMinor,
            'paid_minor' => $paidMinor,
            'status' => $paidMinor >= $netMinor ? 'paid' : ($paidMinor > 0 ? 'partially_paid' : 'unpaid'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$invoiceId, $itemId];
    }

    /**
     * Test A: Previous plus current
     * August: SAR 300, September: SAR 200, October: SAR 350
     * Expected Ready to Send total: SAR 850. Expected PDF total: SAR 850.
     */
    public function test_a_previous_plus_current_totals_reconcile_between_ready_to_send_and_pdf(): void
    {
        [$familyId, $studentId, $enrollmentId] = $this->createFamilyWithStudent('FAM-P2-A', 'GR-P2-A', 'Ahmad Tariq');

        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-A-08', '2026-08-01', 'SAR', 30000);
        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-A-09', '2026-09-01', 'SAR', 20000);
        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-A-10', '2026-10-01', 'SAR', 35000);

        $queueService = app(InvoiceDeliveryQueueService::class);
        $builder = app(ConsolidatedInvoiceBuilder::class);

        $paginator = $queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin);
        $this->assertCount(1, $paginator->items());

        $row = $paginator->items()[0];
        $this->assertEquals($familyId, $row->family_id);
        $this->assertEquals(50000, $row->previous_outstanding_minor); // SAR 500.00
        $this->assertEquals(35000, $row->selected_month_minor);       // SAR 350.00
        $this->assertEquals(0, $row->later_period_minor);             // SAR 0.00
        $this->assertEquals(85000, $row->total_outstanding_payable_minor); // SAR 850.00

        $familyObj = DB::table('families')->find($familyId);
        $pdfData = $builder->buildFamilyViewData($familyObj, $this->admin, false, null, '2026-10');
        $sarGroup = $pdfData['currencyGroups']['SAR'] ?? null;
        $this->assertNotNull($sarGroup);
        $this->assertEquals(85000, $sarGroup['total_balance_minor']);

        $pdfHtml = view('pdf.consolidated-challan', $pdfData)->render();
        $this->assertStringContainsString('850.00', $pdfHtml);

        // Verify HTML view renders SAR 500.00, SAR 350.00, and SAR 850.00
        $response = $this->actingAs($this->admin)->get('/invoices?tab=ready&period=2026-10');
        $response->assertStatus(200);
        $response->assertSee('TOTAL OUTSTANDING PAYABLE');
        $response->assertSee('SAR 500.00');
        $response->assertSee('SAR 350.00');
        $response->assertSee('SAR 850.00');
    }

    /**
     * Test B: Already-generated later month
     * August: SAR 300, September: SAR 200, October: SAR 350, November: SAR 350
     * Expected Ready to Send total: SAR 1,200. Expected PDF total: SAR 1,200.
     * Ensure November is labeled as a generated later-period charge, not automatically overdue.
     */
    public function test_b_already_generated_later_month_included_and_not_labeled_overdue(): void
    {
        [$familyId, $studentId, $enrollmentId] = $this->createFamilyWithStudent('FAM-P2-B', 'GR-P2-B', 'Bilal Tariq');

        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-B-08', '2026-08-01', 'SAR', 30000, 0, '2026-08-10');
        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-B-09', '2026-09-01', 'SAR', 20000, 0, '2026-09-10');
        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-B-10', '2026-10-01', 'SAR', 35000, 0, '2026-10-10');
        // November due date is in the future relative to today
        $futureNovDue = now()->addMonths(2)->format('Y-m-d');
        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-B-11', '2026-11-01', 'SAR', 35000, 0, $futureNovDue);

        $queueService = app(InvoiceDeliveryQueueService::class);
        $builder = app(ConsolidatedInvoiceBuilder::class);

        $paginator = $queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin);
        $this->assertCount(1, $paginator->items());

        $row = $paginator->items()[0];
        $this->assertEquals(50000, $row->previous_outstanding_minor);      // SAR 500.00
        $this->assertEquals(35000, $row->selected_month_minor);            // SAR 350.00
        $this->assertEquals(35000, $row->later_period_minor);              // SAR 350.00
        $this->assertEquals(120000, $row->total_outstanding_payable_minor); // SAR 1,200.00

        // Check November later-period breakdown label is "Generated Later Period" and NOT overdue
        $this->assertCount(1, $row->later_dues_by_month);
        $novEntry = $row->later_dues_by_month[0];
        $this->assertEquals('Nov-26', $novEntry['month_label']);
        $this->assertEquals(35000, $novEntry['dues_minor']);
        $this->assertFalse($novEntry['is_overdue']);
        $this->assertEquals('Generated Later Period', $novEntry['status_label']);

        // Reconcile against PDF view data for period=2026-10
        $familyObj = DB::table('families')->find($familyId);
        $pdfData = $builder->buildFamilyViewData($familyObj, $this->admin, false, null, '2026-10');
        $sarGroup = $pdfData['currencyGroups']['SAR'] ?? null;
        $this->assertNotNull($sarGroup);
        $this->assertEquals(120000, $sarGroup['total_balance_minor']);
        $this->assertCount(1, $sarGroup['upcoming_due_dates']);
        $this->assertEquals('Nov-2026', $sarGroup['upcoming_due_dates'][0]['period_label']);

        $pdfHtml = view('pdf.consolidated-challan', $pdfData)->render();
        $this->assertStringContainsString('1,200.00', $pdfHtml);
        $this->assertStringContainsString('Generated Later Period', $pdfHtml);

        // Verify Ready to Send HTML renders SAR 1,200.00 and Generated Later Period badge
        $response = $this->actingAs($this->admin)->get('/invoices?tab=ready&period=2026-10');
        $response->assertStatus(200);
        $response->assertSee('SAR 500.00');
        $response->assertSee('SAR 350.00');
        $response->assertSee('SAR 1,200.00');
        $response->assertSee('Generated Later Period');
    }

    /**
     * Test C: Partial payments
     * August original net: SAR 300, already paid: SAR 100 -> August remaining: SAR 200.
     * Ensure both Ready to Send and PDF use SAR 200.
     */
    public function test_c_partial_payments_use_remaining_balance_in_both_ready_to_send_and_pdf(): void
    {
        [$familyId, $studentId, $enrollmentId] = $this->createFamilyWithStudent('FAM-P2-C', 'GR-P2-C', 'Fatima Tariq');

        // Aug: net 300, paid 100 => remaining 200
        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-C-08', '2026-08-01', 'SAR', 30000, 10000);
        // Sep: net 200, paid 0 => remaining 200
        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-C-09', '2026-09-01', 'SAR', 20000, 0);
        // Oct: net 350, paid 50 => remaining 300
        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-C-10', '2026-10-01', 'SAR', 35000, 5000);

        $queueService = app(InvoiceDeliveryQueueService::class);
        $builder = app(ConsolidatedInvoiceBuilder::class);

        $paginator = $queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin);
        $row = $paginator->items()[0];

        // Aug remaining (20000) + Sep remaining (20000) = 40000
        $this->assertEquals(40000, $row->previous_outstanding_minor);
        // Oct remaining (30000)
        $this->assertEquals(30000, $row->selected_month_minor);
        // Total payable = 70000 (SAR 700.00)
        $this->assertEquals(70000, $row->total_outstanding_payable_minor);

        $augPill = collect($row->dues_by_month)->firstWhere('month_label', 'Aug-26');
        $this->assertNotNull($augPill);
        $this->assertEquals(20000, $augPill['dues_minor']);

        $familyObj = DB::table('families')->find($familyId);
        $pdfData = $builder->buildFamilyViewData($familyObj, $this->admin, false, null, '2026-10');
        $sarGroup = $pdfData['currencyGroups']['SAR'] ?? null;
        $this->assertNotNull($sarGroup);
        $this->assertEquals(70000, $sarGroup['total_balance_minor']);
    }

    /**
     * Test D: Multi-student family
     * Verify all relevant sibling balances appear in the family total.
     */
    public function test_d_multi_student_family_includes_all_sibling_balances(): void
    {
        [$familyId, $student1Id, $enrollment1Id] = $this->createFamilyWithStudent('FAM-P2-D', 'GR-P2-D1', 'Hassan Al-Harbi');

        $student2Id = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'GR-P2-D2',
            'name' => 'Zainab Al-Harbi',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollment2Id = DB::table('enrollments')->insertGetId([
            'student_id' => $student2Id,
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'grade_label' => 'Grade 4',
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Student 1 has Sep (200) and Oct (350)
        $this->createMonthlyInvoice($familyId, $student1Id, $enrollment1Id, 'INV-P2-D-S1-09', '2026-09-01', 'SAR', 20000);
        $this->createMonthlyInvoice($familyId, $student1Id, $enrollment1Id, 'INV-P2-D-S1-10', '2026-10-01', 'SAR', 35000);

        // Student 2 has Aug (250), Oct (300), and Nov (300)
        $this->createMonthlyInvoice($familyId, $student2Id, $enrollment2Id, 'INV-P2-D-S2-08', '2026-08-01', 'SAR', 25000);
        $this->createMonthlyInvoice($familyId, $student2Id, $enrollment2Id, 'INV-P2-D-S2-10', '2026-10-01', 'SAR', 30000);
        $this->createMonthlyInvoice($familyId, $student2Id, $enrollment2Id, 'INV-P2-D-S2-11', '2026-11-01', 'SAR', 30000, 0, now()->addMonths(2)->format('Y-m-d'));

        $queueService = app(InvoiceDeliveryQueueService::class);
        $builder = app(ConsolidatedInvoiceBuilder::class);

        $paginator = $queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin);
        $this->assertCount(1, $paginator->items());

        $row = $paginator->items()[0];
        // Previous: S1 Sep (200) + S2 Aug (250) = 450
        $this->assertEquals(45000, $row->previous_outstanding_minor);
        // Selected Oct: S1 Oct (350) + S2 Oct (300) = 650
        $this->assertEquals(65000, $row->selected_month_minor);
        // Later Nov: S2 Nov (300) = 300
        $this->assertEquals(30000, $row->later_period_minor);
        // Total: 450 + 650 + 300 = 1,400
        $this->assertEquals(140000, $row->total_outstanding_payable_minor);

        // Both siblings appear in invoice_students
        $studentNames = collect($row->invoice_students)->pluck('student_name')->all();
        $this->assertContains('Hassan Al-Harbi', $studentNames);
        $this->assertContains('Zainab Al-Harbi', $studentNames);

        $familyObj = DB::table('families')->find($familyId);
        $pdfData = $builder->buildFamilyViewData($familyObj, $this->admin, false, null, '2026-10');
        $sarGroup = $pdfData['currencyGroups']['SAR'] ?? null;
        $this->assertNotNull($sarGroup);
        $this->assertEquals(140000, $sarGroup['total_balance_minor']);
    }

    /**
     * Test E: Multi-currency
     * Verify separate SAR and AED totals match the PDF and are never summed together.
     */
    public function test_e_multi_currency_balances_displayed_separately_and_never_summed(): void
    {
        [$familyId, $studentId, $enrollmentId] = $this->createFamilyWithStudent('FAM-P2-E', 'GR-P2-E', 'Omar Al-Harbi');

        // SAR obligations: Sep SAR 500 + Oct SAR 350 = SAR 850
        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-E-SAR-09', '2026-09-01', 'SAR', 50000);
        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-E-SAR-10', '2026-10-01', 'SAR', 35000);

        // AED obligations: Aug AED 150 + Oct AED 250 = AED 400
        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-E-AED-08', '2026-08-01', 'AED', 15000);
        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-E-AED-10', '2026-10-01', 'AED', 25000);

        $queueService = app(InvoiceDeliveryQueueService::class);
        $builder = app(ConsolidatedInvoiceBuilder::class);

        $paginator = $queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin);
        $row = $paginator->items()[0];

        $this->assertTrue($row->is_multi_currency);
        $this->assertArrayHasKey('SAR', $row->currencies_map);
        $this->assertArrayHasKey('AED', $row->currencies_map);

        // SAR breakdown: 500 previous + 350 selected = 850
        $this->assertEquals(50000, $row->currencies_map['SAR']['previous_dues_minor']);
        $this->assertEquals(35000, $row->currencies_map['SAR']['selected_month_minor']);
        $this->assertEquals(85000, $row->currencies_map['SAR']['total_payable_minor']);

        // AED breakdown: 150 previous + 250 selected = 400
        $this->assertEquals(15000, $row->currencies_map['AED']['previous_dues_minor']);
        $this->assertEquals(25000, $row->currencies_map['AED']['selected_month_minor']);
        $this->assertEquals(40000, $row->currencies_map['AED']['total_payable_minor']);

        // PDF reconciliation
        $familyObj = DB::table('families')->find($familyId);
        $pdfData = $builder->buildFamilyViewData($familyObj, $this->admin, false, null, '2026-10');
        $sarGroup = $pdfData['currencyGroups']['SAR'] ?? null;
        $aedGroup = $pdfData['currencyGroups']['AED'] ?? null;
        $this->assertNotNull($sarGroup);
        $this->assertNotNull($aedGroup);
        $this->assertEquals(85000, $sarGroup['total_balance_minor']);
        $this->assertEquals(40000, $aedGroup['total_balance_minor']);

        // Verify HTML view renders separate SAR 850.00 and AED 400.00 and never a combined 1,250.00
        $response = $this->actingAs($this->admin)->get('/invoices?tab=ready&period=2026-10');
        $response->assertStatus(200);
        $response->assertSee('SAR 850.00');
        $response->assertSee('AED 400.00');
        $response->assertDontSee('1,250.00');
    }

    /**
     * Test F: Authorization
     * Verify a restricted staff account cannot include unauthorized campus or department records.
     */
    public function test_f_restricted_staff_cannot_include_unauthorized_campus_or_department_records(): void
    {
        $otherCampusId = DB::table('campuses')->insertGetId([
            'name' => 'North Branch Campus',
            'code' => 'NORTH',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $hifzDeptId = DB::table('departments')->insertGetId([
            'name' => 'Hifz Department',
            'code' => 'HIFZ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $restrictedStaff = User::create([
            'name' => 'Main School Cashier',
            'email' => 'cashier.main@iqra.edu.pk',
            'password' => bcrypt('StrongSecret2026!'),
            'role' => 'campus_cashier',
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'is_active' => true,
        ]);

        [$familyId, $studentId, $enrollmentId] = $this->createFamilyWithStudent('FAM-P2-F', 'GR-P2-F', 'Salman Al-Harbi');

        // Authorized obligation (Main Campus + School Dept): Oct SAR 350
        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-F-AUTH', '2026-10-01', 'SAR', 35000, 0, null, $this->campusId, $this->schoolDeptId);

        // Unauthorized campus obligation (North Campus): Sep SAR 400
        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-F-UNAUTH-CAMPUS', '2026-09-01', 'SAR', 40000, 0, null, $otherCampusId, $this->schoolDeptId);

        // Unauthorized department obligation (Hifz Dept): Aug SAR 250
        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-F-UNAUTH-DEPT', '2026-08-01', 'SAR', 25000, 0, null, $this->campusId, $hifzDeptId);

        $queueService = app(InvoiceDeliveryQueueService::class);
        $builder = app(ConsolidatedInvoiceBuilder::class);

        // Restricted staff only sees SAR 350.00 in both Ready to Send and ConsolidatedInvoiceBuilder
        $staffPaginator = $queueService->getReadyToSendChallans(['period' => '2026-10'], $restrictedStaff);
        $this->assertCount(1, $staffPaginator->items());
        $staffRow = $staffPaginator->items()[0];
        $this->assertEquals(0, $staffRow->previous_outstanding_minor);
        $this->assertEquals(35000, $staffRow->selected_month_minor);
        $this->assertEquals(35000, $staffRow->total_outstanding_payable_minor);

        $familyObj = DB::table('families')->find($familyId);
        $staffPdfData = $builder->buildFamilyViewData($familyObj, $restrictedStaff, false, null, '2026-10');
        $staffSarGroup = $staffPdfData['currencyGroups']['SAR'] ?? null;
        $this->assertNotNull($staffSarGroup);
        $this->assertEquals(35000, $staffSarGroup['total_balance_minor']);

        // Super admin sees all 3 (350 + 400 + 250 = 1,000)
        $adminPaginator = $queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin);
        $adminRow = $adminPaginator->items()[0];
        $this->assertEquals(100000, $adminRow->total_outstanding_payable_minor);
    }

    /**
     * Test G: Unified button
     * Verify the Ready to Send row contains exactly one primary fee invoice PDF action
     * and opens the canonical family consolidated document.
     */
    public function test_g_ready_to_send_has_single_unified_preview_print_fee_invoice_button(): void
    {
        [$familyId, $studentId, $enrollmentId] = $this->createFamilyWithStudent('FAM-P2-G', 'GR-P2-G', 'Yusuf Al-Harbi');
        [$invoiceId] = $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-G-10', '2026-10-01', 'SAR', 35000);

        $response = $this->actingAs($this->admin)->get('/invoices?tab=ready&period=2026-10');
        $response->assertStatus(200);

        $html = $response->getContent();
        $this->assertStringContainsString('Preview / Print Fee Invoice', $html);
        $this->assertStringNotContainsString('Preview Challan', $html);
        $this->assertStringNotContainsString('Invoice PDF', $html);

        // Extract the unified button URL and verify it opens the canonical consolidated PDF in a new tab
        $queueService = app(InvoiceDeliveryQueueService::class);
        $row = $queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin)->items()[0];

        $this->assertStringContainsString("/public/challans/{$familyId}/", $row->consolidated_challan_url);
        $this->assertStringContainsString('period=2026-10', $row->consolidated_challan_url);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);

        // Open the unified URL and verify it returns a valid PDF response
        $pdfResponse = $this->get($row->consolidated_challan_url);
        $pdfResponse->assertStatus(200);
        $this->assertEquals('application/pdf', $pdfResponse->headers->get('Content-Type'));

        // Also confirm individual invoice PDF route still works for accounting history
        $individualPdfResponse = $this->actingAs($this->admin)->get("/invoices/{$invoiceId}/pdf");
        $individualPdfResponse->assertStatus(200);
        $this->assertEquals('application/pdf', $individualPdfResponse->headers->get('Content-Type'));
    }

    /**
     * Test H: No financial writes
     * Verify opening the invoice or calculating Ready to Send totals does not modify
     * invoice, payment, or allocation records.
     */
    public function test_h_calculating_ready_to_send_and_opening_pdf_performs_zero_financial_writes(): void
    {
        [$familyId, $studentId, $enrollmentId] = $this->createFamilyWithStudent('FAM-P2-H', 'GR-P2-H', 'Mariam Al-Harbi');
        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-H-09', '2026-09-01', 'SAR', 20000, 5000);
        $this->createMonthlyInvoice($familyId, $studentId, $enrollmentId, 'INV-P2-H-10', '2026-10-01', 'SAR', 35000, 0);

        $beforeInvoices = DB::table('invoices')->orderBy('id')->get()->toArray();
        $beforeItems = DB::table('invoice_items')->orderBy('id')->get()->toArray();
        $beforePayments = DB::table('payments')->orderBy('id')->get()->toArray();
        $beforeAllocations = DB::table('payment_allocations')->orderBy('id')->get()->toArray();

        // 1. Render Ready to Send HTML page
        $readyResponse = $this->actingAs($this->admin)->get('/invoices?tab=ready&period=2026-10');
        $readyResponse->assertStatus(200);

        // 2. Open the consolidated family PDF via public token URL
        $row = app(InvoiceDeliveryQueueService::class)->getReadyToSendChallans(['period' => '2026-10'], $this->admin)->items()[0];
        $pdfResponse = $this->get($row->consolidated_challan_url);
        $pdfResponse->assertStatus(200);

        // 3. Open the authenticated family challan PDF route
        $authPdfResponse = $this->actingAs($this->admin)->get("/families/{$familyId}/challan/pdf?period=2026-10");
        $authPdfResponse->assertStatus(200);

        $this->assertEquals($beforeInvoices, DB::table('invoices')->orderBy('id')->get()->toArray());
        $this->assertEquals($beforeItems, DB::table('invoice_items')->orderBy('id')->get()->toArray());
        $this->assertEquals($beforePayments, DB::table('payments')->orderBy('id')->get()->toArray());
        $this->assertEquals($beforeAllocations, DB::table('payment_allocations')->orderBy('id')->get()->toArray());
    }

    /**
     * Test I: Existing workflows
     * Confirm pagination, month filters, student/family search, contact status filters,
     * queue selection, send eligibility, and existing paid receipt links still work.
     */
    public function test_i_existing_ready_to_send_and_paid_receipt_workflows_remain_intact(): void
    {
        // Family 1: Eligible contact, October unpaid
        [$fam1, $stu1, $enr1] = $this->createFamilyWithStudent('FAM-P2-I1', 'GR-P2-I1', 'Khadija Siddiqui', '+966501234567', true, true);
        [$inv1] = $this->createMonthlyInvoice($fam1, $stu1, $enr1, 'INV-P2-I1-10', '2026-10-01', 'SAR', 35000);

        // Family 2: Ineligible contact (missing WhatsApp / unverified), October unpaid
        [$fam2, $stu2, $enr2] = $this->createFamilyWithStudent('FAM-P2-I2', 'GR-P2-I2', 'Ibrahim Siddiqui', '', false, false);
        [$inv2] = $this->createMonthlyInvoice($fam2, $stu2, $enr2, 'INV-P2-I2-10', '2026-10-01', 'SAR', 40000);

        // Family 3: Paid October invoice with payment receipt dated >= 2026-10-09
        [$fam3, $stu3, $enr3] = $this->createFamilyWithStudent('FAM-P2-I3', 'GR-P2-I3', 'Noor Siddiqui', '+966509876543', true, true);
        [$inv3, $item3] = $this->createMonthlyInvoice($fam3, $stu3, $enr3, 'INV-P2-I3-10', '2026-10-01', 'SAR', 30000, 30000);

        // Family 4: Eligible contact, October unpaid + September unpaid (tests pagination & month filter)
        [$fam4, $stu4, $enr4] = $this->createFamilyWithStudent('FAM-P2-I4', 'GR-P2-I4', 'Hamza Siddiqui', '+966507654321', true, true);
        [$inv4Sep] = $this->createMonthlyInvoice($fam4, $stu4, $enr4, 'INV-P2-I4-09', '2026-09-01', 'SAR', 20000);
        [$inv4Oct] = $this->createMonthlyInvoice($fam4, $stu4, $enr4, 'INV-P2-I4-10', '2026-10-01', 'SAR', 35000);

        $paymentId = DB::table('payments')->insertGetId([
            'receipt_number' => 'RCP-P2-I3-001',
            'idempotency_key' => 'idem-p2-i3-001',
            'request_hash' => hash('sha256', 'p2-i3-001'),
            'family_id' => $fam3,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'currency' => 'SAR',
            'amount_minor' => 30000,
            'unallocated_minor' => 0,
            'received_on' => '2026-10-15',
            'received_by' => $this->admin->id,
            'note' => 'Bank Transfer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $item3,
            'amount_minor' => 30000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $queueService = app(InvoiceDeliveryQueueService::class);

        // 1. Month filter: October has 2 eligible unpaid families (fam1, fam4); September has 1 (fam4); fam3 is paid and excluded
        $octReady = $queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin);
        $this->assertEquals(2, $octReady->total());

        $sepReady = $queueService->getReadyToSendChallans(['period' => '2026-09'], $this->admin);
        $this->assertEquals(1, $sepReady->total());
        $this->assertEquals($fam4, $sepReady->items()[0]->family_id);

        // 2. Search filter by student name / GR number / family code
        $searchRes = $queueService->getReadyToSendChallans(['period' => '2026-10', 'search' => 'Khadija'], $this->admin);
        $this->assertEquals(1, $searchRes->total());
        $this->assertEquals($fam1, $searchRes->items()[0]->family_id);

        // 3. Contact status filter: 'eligible_only' vs 'ineligible_only'
        $validContactRes = $queueService->getReadyToSendChallans(['period' => '2026-10', 'contact_status' => 'eligible_only'], $this->admin);
        $this->assertEquals(2, $validContactRes->total());

        $missingContactRes = $queueService->getReadyToSendChallans(['period' => '2026-10', 'contact_status' => 'ineligible_only'], $this->admin);
        $this->assertEquals(1, $missingContactRes->total());
        $this->assertEquals($fam2, $missingContactRes->items()[0]->family_id);

        // 4. Queue selection & send eligibility via BatchDeliveryService
        $batchService = app(\App\Services\BatchDeliveryService::class);
        $selection = $batchService->resolveFilteredSelection(['period' => '2026-10'], $this->admin, ['whatsapp']);
        $this->assertEquals(2, $selection['total_eligible_recipients']);
        $this->assertContains($inv1, $selection['eligible_invoice_ids']);
        $this->assertContains($inv4Oct, $selection['eligible_invoice_ids']);
        $this->assertNotContains($inv2, $selection['eligible_invoice_ids']);
        $this->assertNotContains($inv3, $selection['eligible_invoice_ids']);

        // 5. Pagination works (`perPage = 1`)
        $pagedRes = $queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin, 1);
        $this->assertCount(1, $pagedRes->items());
        $this->assertEquals(2, $pagedRes->lastPage());

        // 6. Paid Invoices tab and Paid Receipt PDF links still work
        $paidTabResponse = $this->actingAs($this->admin)->get('/invoices?tab=paid&period=2026-10');
        $paidTabResponse->assertStatus(200);
        $paidTabResponse->assertSee('RCP-P2-I3-001');
        $paidTabResponse->assertSee('INV-P2-I3-10');

        $paidRows = $queueService->getPaidInvoices(['period' => '2026-10'], $this->admin);
        $this->assertEquals(1, $paidRows->total());
        $paidRow = $paidRows->items()[0];
        $this->assertNotEmpty($paidRow->receipts);
        $receiptPdfUrl = $paidRow->receipts[0]['receipt_pdf_url'];
        $this->assertNotEmpty($receiptPdfUrl);

        $receiptResponse = $this->get($receiptPdfUrl);
        $receiptResponse->assertStatus(200);
        $this->assertEquals('application/pdf', $receiptResponse->headers->get('Content-Type'));
    }

    /**
     * Test J (Phase 2.1 Mandatory Security Test):
     * Create one fictional family with:
     * - School department: SAR 350
     * - Another department: SAR 250
     * - Another campus: SAR 400
     * Verify:
     * - School cashier's Ready to Send total: SAR 350.
     * - School cashier's authenticated PDF: SAR 350.
     * - Cashier must not obtain unrestricted access to the full-family PDF through the generated preview link.
     * - Authorized full-family invoice: SAR 1,000.
     * - A valid, appropriately authorized parent-facing link displays the full-family total.
     * - Test access both while authenticated and while logged out.
     */
    public function test_j_phase_2_1_restricted_staff_vs_full_family_pdf_authorization_and_scope(): void
    {
        $otherCampusId = DB::table('campuses')->insertGetId([
            'name' => 'South Branch Campus',
            'code' => 'SOUTH',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherDeptId = DB::table('departments')->insertGetId([
            'name' => 'Tahfeez Department',
            'code' => 'TAHFEEZ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $schoolCashier = User::create([
            'name' => 'School Department Cashier',
            'email' => 'school.cashier.p21@iqra.edu.pk',
            'password' => bcrypt('StrongSecret2026!'),
            'role' => 'cashier',
            'campus_id' => $this->campusId,
            'department_id' => $this->schoolDeptId,
            'is_active' => true,
        ]);

        [$familyId, $student1Id, $enrollment1Id] = $this->createFamilyWithStudent(
            'FAM-P21-SEC',
            'GR-P21-S1',
            'Zayd Al-Qahtani'
        );

        // Student 2 enrolled in another department (Tahfeez) at Main Campus
        $student2Id = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'GR-P21-S2',
            'name' => 'Bilal Al-Qahtani',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $enrollment2Id = DB::table('enrollments')->insertGetId([
            'student_id' => $student2Id,
            'campus_id' => $this->campusId,
            'department_id' => $otherDeptId,
            'grade_label' => 'Hifz Track',
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Student 3 enrolled at another campus (South Branch Campus)
        $student3Id = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'GR-P21-S3',
            'name' => 'Maryam Al-Qahtani',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $enrollment3Id = DB::table('enrollments')->insertGetId([
            'student_id' => $student3Id,
            'campus_id' => $otherCampusId,
            'department_id' => $this->schoolDeptId,
            'grade_label' => 'Grade 5',
            'currency' => 'SAR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. School department (Main Campus): SAR 350
        [$schoolInvId] = $this->createMonthlyInvoice(
            $familyId,
            $student1Id,
            $enrollment1Id,
            'INV-P21-SCHOOL-350',
            '2026-10-01',
            'SAR',
            35000,
            0,
            '2026-10-10',
            $this->campusId,
            $this->schoolDeptId
        );

        // 2. Another department (Main Campus, Tahfeez Dept): SAR 250
        $this->createMonthlyInvoice(
            $familyId,
            $student2Id,
            $enrollment2Id,
            'INV-P21-OTHERDEPT-250',
            '2026-10-01',
            'SAR',
            25000,
            0,
            '2026-10-10',
            $this->campusId,
            $otherDeptId
        );

        // 3. Another campus (South Campus, School Dept): SAR 400
        $this->createMonthlyInvoice(
            $familyId,
            $student3Id,
            $enrollment3Id,
            'INV-P21-OTHERCAMPUS-400',
            '2026-10-01',
            'SAR',
            40000,
            0,
            '2026-10-10',
            $otherCampusId,
            $this->schoolDeptId
        );

        $queueService = app(InvoiceDeliveryQueueService::class);
        $builder = app(ConsolidatedInvoiceBuilder::class);
        $pdfAccess = app(\App\Services\PdfAccessService::class);
        $familyObj = DB::table('families')->find($familyId);

        // A. School cashier's Ready to Send total must be SAR 350 (35000 minor)
        $cashierReady = $queueService->getReadyToSendChallans(['period' => '2026-10'], $schoolCashier);
        $this->assertCount(1, $cashierReady->items());
        $cashierRow = $cashierReady->items()[0];
        $this->assertEquals(35000, $cashierRow->selected_month_minor);
        $this->assertEquals(35000, $cashierRow->total_payable_minor);
        $this->assertEquals(35000, $cashierRow->total_outstanding_payable_minor);
        $this->assertEquals('SAR 350.00', $cashierRow->formatted_total_payable);

        // B. Cashier's generated preview URL must be the authenticated scope-aware route, NOT the public token link
        $cashierPreviewUrl = $cashierRow->consolidated_challan_url;
        $this->assertStringContainsString("/families/{$familyId}/challan/pdf", $cashierPreviewUrl);
        $this->assertStringNotContainsString('/public/challans/', $cashierPreviewUrl);
        $this->assertStringContainsString("/invoices/{$schoolInvId}/pdf", $cashierRow->invoice_pdf_url);
        $this->assertStringNotContainsString('/public/invoices/', $cashierRow->invoice_pdf_url);

        // Verify Ready to Send HTML rendered for School cashier shows SAR 350.00 and authenticated preview link
        $cashierPage = $this->actingAs($schoolCashier)->get('/invoices?tab=ready&period=2026-10');
        $cashierPage->assertStatus(200);
        $cashierPage->assertSee('SAR 350.00');
        $cashierPage->assertDontSee('SAR 1,000.00');
        $cashierPage->assertSee("/families/{$familyId}/challan/pdf?period=2026-10", false);
        $cashierPage->assertDontSee("/public/challans/{$familyId}/", false);

        // C. School cashier's authenticated PDF must use the same SAR 350 scope
        $cashierPdfViewData = $builder->buildFamilyViewData($familyObj, $schoolCashier, false, null, '2026-10');
        $this->assertEquals(1, $cashierPdfViewData['totalItemsCount']);
        $this->assertEquals(35000, $cashierPdfViewData['currencyGroups']['SAR']['total_balance_minor']);

        $cashierPdfResponse = $this->actingAs($schoolCashier)->get($cashierPreviewUrl);
        $cashierPdfResponse->assertStatus(200);
        $this->assertEquals('application/pdf', $cashierPdfResponse->headers->get('Content-Type'));

        // Render HTML of the consolidated template with cashier's view data to verify exact text in PDF
        $cashierRenderedHtml = view('pdf.consolidated-challan', $cashierPdfViewData)->render();
        $this->assertStringContainsString('350.00', $cashierRenderedHtml);
        $this->assertStringNotContainsString('1,000.00', $cashierRenderedHtml);
        $this->assertStringNotContainsString('250.00', $cashierRenderedHtml);
        $this->assertStringNotContainsString('400.00', $cashierRenderedHtml);

        // D. Cashier must NOT obtain unrestricted access to the full-family PDF when opening the generated preview link while logged out
        auth()->logout();
        $anonPreviewAttempt = $this->get($cashierPreviewUrl);
        $this->assertTrue(in_array($anonPreviewAttempt->getStatusCode(), [302, 401, 403], true));

        // E. Authorized administrator's Ready to Send and full-family invoice must be SAR 1,000 (100000 minor)
        $adminReady = $queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin);
        $this->assertCount(1, $adminReady->items());
        $adminRow = $adminReady->items()[0];
        $this->assertEquals(100000, $adminRow->total_payable_minor);
        $this->assertEquals(100000, $adminRow->total_outstanding_payable_minor);
        $this->assertEquals('SAR 1,000.00', $adminRow->formatted_total_payable);

        $adminPdfViewData = $builder->buildFamilyViewData($familyObj, $this->admin, false, null, '2026-10');
        $this->assertEquals(3, $adminPdfViewData['totalItemsCount']);
        $this->assertEquals(100000, $adminPdfViewData['currencyGroups']['SAR']['total_balance_minor']);

        $adminRenderedHtml = view('pdf.consolidated-challan', $adminPdfViewData)->render();
        $this->assertStringContainsString('1,000.00', $adminRenderedHtml);
        $this->assertStringContainsString('350.00', $adminRenderedHtml);
        $this->assertStringContainsString('250.00', $adminRenderedHtml);
        $this->assertStringContainsString('400.00', $adminRenderedHtml);

        // F. Valid parent-facing link displays full-family total (SAR 1,000) both when logged out and when authenticated as admin
        $parentPublicUrl = $pdfAccess->getFamilyChallanPdfUrl($familyId, '2026-10');
        $this->assertStringContainsString("/public/challans/{$familyId}/", $parentPublicUrl);

        // Logged out (anonymous parent opening official link):
        auth()->logout();
        $parentAnonViewData = $builder->buildFamilyViewData($familyObj, null, true, null, '2026-10');
        $this->assertEquals(100000, $parentAnonViewData['currencyGroups']['SAR']['total_balance_minor']);
        $parentAnonResponse = $this->get($parentPublicUrl);
        $parentAnonResponse->assertStatus(200);
        $this->assertEquals('application/pdf', $parentAnonResponse->headers->get('Content-Type'));

        // Authenticated as admin opening parent-facing link:
        $parentAdminResponse = $this->actingAs($this->admin)->get($parentPublicUrl);
        $parentAdminResponse->assertStatus(200);
        $this->assertEquals('application/pdf', $parentAdminResponse->headers->get('Content-Type'));
    }

    /**
     * Test K (Phase 2.1 Mandatory Financial Fallback Test):
     * Create a fictional inconsistent invoice header with no matching valid unpaid invoice items.
     * Verify:
     * - No incorrect payable amount appears as verified.
     * - The PDF and Ready to Send do not present contradictory financial totals as authoritative.
     * - The discrepancy is clearly reported.
     * - No database correction occurs automatically.
     */
    public function test_k_phase_2_1_inconsistent_invoice_header_without_valid_unpaid_items_is_flagged_and_never_substituted(): void
    {
        [$familyId, $studentId, $enrollmentId] = $this->createFamilyWithStudent(
            'FAM-P21-INC',
            'GR-P21-INC',
            'Tariq Al-Dosari'
        );

        $runId = $this->createBillingRun('2026-10-01');

        // Insert an inconsistent unpaid invoice header (SAR 450.00) with NO invoice_items rows
        $inconsistentInvoiceId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-P21-INC-450',
            'family_id' => $familyId,
            'department_id' => $this->schoolDeptId,
            'campus_id' => $this->campusId,
            'billing_run_id' => $runId,
            'currency' => 'SAR',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-10',
            'total_minor' => 45000,
            'paid_minor' => 0,
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Snapshot database tables before inspection to verify zero automatic database corrections
        $beforeInvoices = DB::table('invoices')->orderBy('id')->get()->toArray();
        $beforeItems = DB::table('invoice_items')->orderBy('id')->get()->toArray();
        $beforePayments = DB::table('payments')->orderBy('id')->get()->toArray();
        $beforeAllocations = DB::table('payment_allocations')->orderBy('id')->get()->toArray();

        $queueService = app(InvoiceDeliveryQueueService::class);
        $builder = app(ConsolidatedInvoiceBuilder::class);

        // 1. Inspect Ready to Send row
        $paginator = $queueService->getReadyToSendChallans(['period' => '2026-10'], $this->admin);
        $this->assertCount(1, $paginator->items());
        $row = $paginator->items()[0];

        // Verify the header's 45000 is NEVER substituted as a verified payable figure
        $this->assertTrue($row->has_data_integrity_issue);
        $this->assertFalse($row->is_payable_verified);
        $this->assertEquals('data_integrity_issue', $row->data_integrity_status);
        $this->assertNotEmpty($row->data_integrity_message);
        $this->assertStringContainsString('INV-P21-INC-450', $row->data_integrity_message);
        $this->assertStringContainsString('Data Integrity Issue', $row->data_integrity_message);

        $this->assertEquals(0, $row->previous_outstanding_minor);
        $this->assertEquals(0, $row->selected_month_minor);
        $this->assertEquals(0, $row->current_fee_minor);
        $this->assertEquals(0, $row->later_period_minor);
        $this->assertEquals(0, $row->total_payable_minor);
        $this->assertEquals(0, $row->total_outstanding_payable_minor);
        $this->assertEmpty($row->currencies_map);
        $this->assertEquals('UNVERIFIED — Staff Review Required', $row->formatted_total_payable);

        // 2. Inspect ConsolidatedInvoiceBuilder view data (family & invoice routes)
        $familyObj = DB::table('families')->find($familyId);
        $pdfFamilyData = $builder->buildFamilyViewData($familyObj, $this->admin, false, null, '2026-10');
        $this->assertEquals(0, $pdfFamilyData['totalItemsCount']);
        $this->assertEmpty($pdfFamilyData['currencyGroups']);
        $this->assertTrue($pdfFamilyData['hasDataIntegrityIssue']);
        $this->assertFalse($pdfFamilyData['isPayableVerified']);
        $this->assertEquals('data_integrity_issue', $pdfFamilyData['dataIntegrityStatus']);
        $this->assertStringContainsString('INV-P21-INC-450', (string) $pdfFamilyData['dataIntegrityMessage']);

        // Reconcile Ready to Send vs PDF: both have 0 verified payable minor units and both flag the discrepancy
        $pdfVerifiedTotalMinor = collect($pdfFamilyData['currencyGroups'])->sum('total_balance_minor');
        $this->assertEquals($pdfVerifiedTotalMinor, $row->total_payable_minor);
        $this->assertEquals($pdfFamilyData['hasDataIntegrityIssue'], $row->has_data_integrity_issue);

        // Also check individual invoice PDF builder (buildForInvoice)
        $invoiceObj = DB::table('invoices')->find($inconsistentInvoiceId);
        $pdfInvoiceData = $builder->buildForInvoice($invoiceObj, $this->admin, false, '2026-10');
        $this->assertEquals(0, $pdfInvoiceData['totalItemsCount']);
        $this->assertTrue($pdfInvoiceData['hasDataIntegrityIssue']);
        $this->assertTrue($pdfInvoiceData['referenceInvoice']['has_data_integrity_issue']);
        $this->assertFalse($pdfInvoiceData['referenceInvoice']['is_payable_verified']);
        $this->assertEquals(0, $pdfInvoiceData['referenceInvoice']['total_minor']);
        $this->assertEquals(0, $pdfInvoiceData['referenceInvoice']['remaining_minor']);

        $renderedPdfHtml = view('pdf.consolidated-challan', $pdfInvoiceData)->render();
        $this->assertStringNotContainsString('450.00', $renderedPdfHtml);
        $this->assertStringContainsString('There are no unpaid billed fee items to display for this account.', $renderedPdfHtml);

        // 3. Verify Ready to Send HTML view clearly reports the discrepancy and does not show SAR 450.00 as a verified badge
        $readyHtmlResponse = $this->actingAs($this->admin)->get('/invoices?tab=ready&period=2026-10');
        $readyHtmlResponse->assertStatus(200);
        $readyHtmlResponse->assertSee('Data Integrity Issue — Staff Review Required');
        $readyHtmlResponse->assertSee('UNVERIFIED — Staff Review Required');
        $readyHtmlResponse->assertSee('INV-P21-INC-450');

        // 4. Verify PDF endpoints stream cleanly without mutating any records
        $this->actingAs($this->admin)->get("/families/{$familyId}/challan/pdf?period=2026-10")->assertStatus(200);
        $this->actingAs($this->admin)->get("/invoices/{$inconsistentInvoiceId}/pdf")->assertStatus(200);

        // 5. Confirm zero automatic database repair, deletion, or regeneration occurred
        $this->assertEquals($beforeInvoices, DB::table('invoices')->orderBy('id')->get()->toArray());
        $this->assertEquals($beforeItems, DB::table('invoice_items')->orderBy('id')->get()->toArray());
        $this->assertEquals($beforePayments, DB::table('payments')->orderBy('id')->get()->toArray());
        $this->assertEquals($beforeAllocations, DB::table('payment_allocations')->orderBy('id')->get()->toArray());
    }
}
