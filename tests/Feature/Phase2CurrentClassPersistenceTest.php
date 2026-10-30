<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ConsolidatedInvoiceBuilder;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase2CurrentClassPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $financeAdmin;
    protected User $scopedManager;
    protected User $otherCampusManager;
    protected User $cashier;
    protected User $auditor;
    protected int $campusId;
    protected int $otherCampusId;
    protected int $departmentId;
    protected int $monthlyFeeTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->campusId = DB::table('campuses')->value('id') ?? DB::table('campuses')->insertGetId([
            'name' => 'Main Campus',
            'code' => 'MAIN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->otherCampusId = DB::table('campuses')->where('id', '!=', $this->campusId)->value('id')
            ?? DB::table('campuses')->insertGetId([
                'name' => 'North Campus',
                'code' => 'NORTH',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $this->departmentId = DB::table('departments')->where('code', 'school')->value('id')
            ?? DB::table('departments')->insertGetId([
                'name' => 'School',
                'code' => 'school',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $this->monthlyFeeTypeId = DB::table('fee_types')->where('code', 'monthly')->value('id')
            ?? DB::table('fee_types')->insertGetId([
                'name' => 'Monthly Fee',
                'code' => 'monthly',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $this->superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin.p2@iqra.test',
            'password' => 'secret123',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->financeAdmin = User::create([
            'name' => 'Finance Admin',
            'email' => 'finance.p2@iqra.test',
            'password' => 'secret123',
            'role' => 'finance_admin',
            'is_active' => true,
        ]);

        $this->scopedManager = User::create([
            'name' => 'Scoped Dept Manager',
            'email' => 'manager.p2@iqra.test',
            'password' => 'secret123',
            'role' => 'department_manager',
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'is_active' => true,
        ]);

        $this->otherCampusManager = User::create([
            'name' => 'Other Campus Manager',
            'email' => 'other.manager.p2@iqra.test',
            'password' => 'secret123',
            'role' => 'department_manager',
            'campus_id' => $this->otherCampusId,
            'department_id' => $this->departmentId,
            'is_active' => true,
        ]);

        $this->cashier = User::create([
            'name' => 'Cashier User',
            'email' => 'cashier.p2@iqra.test',
            'password' => 'secret123',
            'role' => 'cashier',
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'is_active' => true,
        ]);

        $this->auditor = User::create([
            'name' => 'Auditor User',
            'email' => 'auditor.p2@iqra.test',
            'password' => 'secret123',
            'role' => 'auditor',
            'is_active' => true,
        ]);
    }

    /**
     * Tests 1-9: Native student class promotion from Class 11 to Class 12 via HTTP workflow,
     * verifying persistence, student list, family details, Fee Collection, section validity,
     * admitted/historical class protection, monthly fee & currency protection, and no duplicate enrollments.
     */
    public function test_native_student_current_class_change_from_11_to_12_persists_across_portal_and_protects_finances(): void
    {
        $this->actingAs($this->superAdmin);

        // 1. Register a fictional native student in Class 11 with admitted class Grade 9 and last class Class 10
        $registerPayload = [
            'name' => 'Zainab Tariq',
            'father_name' => 'Tariq Mahmood',
            'guardian_name' => 'Tariq Mahmood',
            'registration_number' => 'GR-NAT-1101',
            'family_code' => 'FAM-NAT-1101',
            'family_email' => 'tariq.family@example.test',
            'family_whatsapp' => '+923001112233',
            'contact_verified' => '1',
            'messaging_opt_in' => '1',
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'class_admitted' => 'Grade 9',
            'section_admitted' => 'A',
            'grade_label' => 'Class 11',
            'section_current' => 'A',
            'last_class' => 'Class 10',
            'last_session' => '2025-2026',
            'last_result' => 'Passed (A+)',
            'session' => '2026-2027',
            'currency' => 'SAR',
            'monthly_fee' => '450.00',
            'date_of_admission' => '2024-09-01',
        ];

        $this->post('/manage-student/admission/new', $registerPayload)
            ->assertRedirect('/manage-student');

        $student = DB::table('students')->where('gr_number', 'GR-NAT-1101')->first();
        $this->assertNotNull($student);
        $studentId = (int) $student->id;
        $familyId = (int) $student->family_id;

        $enrollmentBefore = DB::table('enrollments')->where('student_id', $studentId)->first();
        $this->assertNotNull($enrollmentBefore);
        $this->assertSame('Class 11', $enrollmentBefore->grade_label);
        $this->assertSame(45000, (int) $enrollmentBefore->monthly_fee_minor);
        $this->assertSame('SAR', $enrollmentBefore->currency);

        // Create an existing historical invoice (Sep 2026) and payment to verify they remain untouched
        $sepRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-09-01..2026-09-30',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'due_date' => '2026-09-10',
            'status' => 'generated',
            'created_by' => $this->superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(InvoiceService::class)->generate($sepRunId, '2026-09-01');

        $sepInvoiceBefore = DB::table('invoices')->where('billing_run_id', $sepRunId)->where('family_id', $familyId)->first();
        $sepItemBefore = DB::table('invoice_items')->where('invoice_id', $sepInvoiceBefore->id)->where('student_id', $studentId)->first();
        $this->assertNotNull($sepInvoiceBefore);
        $this->assertSame(45000, (int) $sepInvoiceBefore->total_minor);
        $this->assertSame('SAR', $sepInvoiceBefore->currency);

        $paymentId = DB::table('payments')->insertGetId([
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'receipt_number' => 'RCP-NAT-9001',
            'idempotency_key' => 'IDEMP-RCP-NAT-9001',
            'request_hash' => hash('sha256', 'RCP-NAT-9001'),
            'received_on' => '2026-09-05',
            'currency' => 'SAR',
            'amount_minor' => 20000,
            'unallocated_minor' => 0,
            'status' => 'received',
            'received_by' => $this->superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $sepItemBefore->id,
            'amount_minor' => 20000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_items')->where('id', $sepItemBefore->id)->update(['paid_minor' => 20000, 'status' => 'partial']);
        DB::table('invoices')->where('id', $sepInvoiceBefore->id)->update(['paid_minor' => 20000, 'status' => 'partial']);

        // Configure a different catalog rate for Class 12 to prove student's approved fee is NOT silently reset
        DB::table('fee_catalog_rates')->insert([
            'fee_type_id' => $this->monthlyFeeTypeId,
            'department_id' => $this->departmentId,
            'class_label' => 'Class 12',
            'currency' => 'SAR',
            'amount_minor' => 99900,
            'frequency' => 'monthly',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Open student edit page and verify Class 11 is initially selected
        $this->get("/manage-student/{$studentId}/edit")
            ->assertOk()
            ->assertSee('Class 11')
            ->assertSee('Class 12');

        // 3. Submit HTTP PUT to change Current Class from Class 11 to Class 12 and Section to B
        $updatePayload = [
            'name' => 'Zainab Tariq',
            'father_name' => 'Tariq Mahmood',
            'guardian_name' => 'Tariq Mahmood',
            'registration_number' => 'GR-NAT-1101',
            'family_code' => 'FAM-NAT-1101',
            'family_email' => 'tariq.family@example.test',
            'family_whatsapp' => '+923001112233',
            'contact_verified' => '1',
            'messaging_opt_in' => '1',
            'class_admitted' => 'Grade 9',
            'section_admitted' => 'A',
            'grade_label' => 'Class 12',
            'section_current' => 'B',
            'last_class' => 'Class 10',
            'last_session' => '2025-2026',
            'last_result' => 'Passed (A+)',
            'session' => '2026-2027',
            'currency' => 'SAR',
            'monthly_fee' => '450.00',
            'date_of_admission' => '2024-09-01',
        ];

        $this->put("/manage-student/{$studentId}", $updatePayload)
            ->assertRedirect("/manage-student/{$studentId}");

        // 4. Verify database state: authoritative enrollment updated, no duplicate enrollments
        $enrollmentsAfter = DB::table('enrollments')->where('student_id', $studentId)->get();
        $this->assertCount(1, $enrollmentsAfter, 'Must not create duplicate active enrollments.');
        $enrollmentAfter = $enrollmentsAfter->first();
        $this->assertSame((int) $enrollmentBefore->id, (int) $enrollmentAfter->id);
        $this->assertSame('Class 12', $enrollmentAfter->grade_label);
        $this->assertSame('active', $enrollmentAfter->status);

        // Verify program_id and section_id match Class 12 and Section B
        $program12 = DB::table('programs')->where('id', $enrollmentAfter->program_id)->first();
        $this->assertNotNull($program12);
        $this->assertSame('Class 12', $program12->name);
        $sectionB = DB::table('sections')->where('id', $enrollmentAfter->section_id)->first();
        $this->assertNotNull($sectionB);
        $this->assertSame('B', $sectionB->name);
        $this->assertSame((int) $program12->id, (int) $sectionB->program_id);

        // Verify admitted class and historical class records are preserved
        $detailsAfter = DB::table('student_registration_details')->where('student_id', $studentId)->first();
        $this->assertSame('Grade 9', $detailsAfter->class_admitted);
        $this->assertSame('A', $detailsAfter->section_admitted);
        $this->assertSame('B', $detailsAfter->section_current);
        $this->assertSame('Class 10', $detailsAfter->last_class);
        $this->assertSame('2025-2026', $detailsAfter->last_session);
        $this->assertSame('Passed (A+)', $detailsAfter->last_result);

        // 5. Reload Student Profile and Edit Form to verify Class 12 remains saved
        $this->get("/manage-student/{$studentId}")
            ->assertOk()
            ->assertSee('Class 12 - B')
            ->assertSee('Class 12');

        $editResponse = $this->get("/manage-student/{$studentId}/edit")->assertOk();
        $this->assertSame('Class 12', $editResponse->viewData('initial')['grade_label']);
        $this->assertSame('B', $editResponse->viewData('initial')['section_current']);
        $this->assertSame('Grade 9', $editResponse->viewData('initial')['class_admitted']);
        $this->assertSame('Class 10', $editResponse->viewData('initial')['last_class']);

        // 6. Verify Student List and class filters
        $this->get('/manage-student?q=GR-NAT-1101')
            ->assertOk()
            ->assertSee('Zainab Tariq')
            ->assertSee('Class 12');

        $this->get('/manage-student?class=' . urlencode('Class 12'))
            ->assertOk()
            ->assertSee('Zainab Tariq');

        $this->get('/manage-student?class=' . urlencode('Class 11'))
            ->assertOk()
            ->assertDontSee('Zainab Tariq');

        // 7. Verify Family Details / Consolidated Family Record
        $familyRecord = DB::table('families')->where('id', $familyId)->first();
        $familyViewData = app(ConsolidatedInvoiceBuilder::class)->buildFamilyViewData($familyRecord, $this->superAdmin);
        $familyStudentRecord = collect($familyViewData['familyStudents'])->firstWhere('id', $studentId);
        $this->assertNotNull($familyStudentRecord);
        $this->assertSame('Class 12', $familyStudentRecord->grade_label);

        $sarGroupStudents = collect($familyViewData['currencyGroups']['SAR']['students'] ?? []);
        $sarStudentGroup = $sarGroupStudents->get($studentId);
        $this->assertNotNull($sarStudentGroup);
        $this->assertSame('Class 12', $sarStudentGroup['grade_label']);

        // 8. Verify Fee Collection screen (by GR and by Family Code) and Preview Fee endpoint
        $challanByGr = $this->get('/challan?gr=GR-NAT-1101&search_by=gr')->assertOk();
        $challanByGr->assertSee('Zainab Tariq')->assertSee('Class 12');
        $challanFamilyStudents = $challanByGr->viewData('familyStudents');
        $this->assertSame('Class 12', $challanFamilyStudents->firstWhere('id', $studentId)->grade_label);

        $challanByFam = $this->get('/challan?family=FAM-NAT-1101&search_by=family')->assertOk();
        $challanByFam->assertSee('Zainab Tariq')->assertSee('Class 12');

        $previewRes = $this->postJson('/challan/preview-fee', [
            'family_id' => $familyId,
            'student_ids' => [$studentId],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
        ])->assertOk()->json();
        $this->assertSame('Class 12', $previewRes['items'][0]['grade_label']);
        $this->assertSame(45000, $previewRes['items'][0]['amount_minor']);
        $this->assertSame('SAR', $previewRes['items'][0]['currency']);

        // 9. Confirm monthly fee, currency, active fee plans, existing invoices, and payments are unchanged
        $this->assertSame(45000, (int) $enrollmentAfter->monthly_fee_minor);
        $this->assertSame('SAR', $enrollmentAfter->currency);

        $activePlans = DB::table('fee_plan_items')
            ->where('enrollment_id', $enrollmentAfter->id)
            ->where('is_active', true)
            ->get();
        $this->assertCount(1, $activePlans, 'Must not create duplicate active fee plans.');
        $this->assertSame(45000, (int) $activePlans->first()->amount_minor);
        $this->assertSame('SAR', $activePlans->first()->currency);

        $sepInvoiceAfter = DB::table('invoices')->where('id', $sepInvoiceBefore->id)->first();
        $sepItemAfter = DB::table('invoice_items')->where('id', $sepItemBefore->id)->first();
        $paymentAfter = DB::table('payments')->where('id', $paymentId)->first();
        $this->assertSame(45000, (int) $sepInvoiceAfter->total_minor);
        $this->assertSame(20000, (int) $sepInvoiceAfter->paid_minor);
        $this->assertSame('SAR', $sepInvoiceAfter->currency);
        $this->assertSame(45000, (int) $sepItemAfter->net_minor);
        $this->assertSame(20000, (int) $sepItemAfter->paid_minor);
        $this->assertSame(20000, (int) $paymentAfter->amount_minor);

        // Confirm future invoice generation (Oct 2026) uses the preserved 450.00 SAR fee (NOT the 999.00 catalog rate)
        $octRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-10',
            'status' => 'generated',
            'created_by' => $this->superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(InvoiceService::class)->generate($octRunId, '2026-10-01');
        $octItem = DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->where('i.billing_run_id', $octRunId)
            ->where('ii.student_id', $studentId)
            ->select('ii.*', 'i.currency as inv_currency')
            ->first();
        $this->assertNotNull($octItem);
        $this->assertSame(45000, (int) $octItem->amount_minor);
        $this->assertSame('SAR', $octItem->currency);
    }

    /**
     * Test 10: Verify identical class-change persistence and financial protection
     * for a simulated imported MySkool student (including when student_registration_details
     * was initially missing and historical MySkool payments/opening balances exist).
     */
    public function test_imported_myskool_student_current_class_change_persists_and_preserves_historical_records(): void
    {
        $now = now();
        $familyId = DB::table('families')->insertGetId([
            'family_code' => 'FAM-IMP-2201',
            'guardian_name' => 'Imran Siddiqui',
            'email' => 'imran.imported@example.test',
            'whatsapp' => '+923219998877',
            'contact_verified' => true,
            'messaging_opt_in' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Simulated imported MySkool student (with legacy_myskool_id and initially NO student_registration_details row)
        $studentId = DB::table('students')->insertGetId([
            'gr_number' => '4412',
            'name' => 'Hamza Imran',
            'family_id' => $familyId,
            'legacy_myskool_id' => 'MYSKOOL-STU-4412',
            'status' => 'present',
            'gender' => 'Male',
            'date_of_admission' => '2023-08-15',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $enrollmentId = DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'grade_label' => 'Class 11',
            'currency' => 'PKR',
            'monthly_fee_minor' => 850000, // PKR 8,500.00
            'status' => 'active',
            'started_on' => '2023-08-15',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $planItemId = DB::table('fee_plan_items')->insertGetId([
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee',
            'frequency' => 'monthly',
            'currency' => 'PKR',
            'amount_minor' => 850000,
            'effective_from' => '2026-08-01',
            'effective_to' => null,
            'is_active' => true,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Simulate imported opening balance invoice (with enrollment_id = null as seen in legacy imports)
        $obRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'frequency' => 'opening_balance',
            'period_key' => 'opening_balance:2026-08-01..2026-08-31',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'due_date' => '2026-08-10',
            'status' => 'generated',
            'created_by' => $this->superAdmin->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $obInvoiceId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $obRunId,
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'currency' => 'PKR',
            'invoice_number' => 'SCH-OB-0004412',
            'issued_on' => '2026-08-01',
            'due_date' => '2026-08-10',
            'total_minor' => 850000,
            'paid_minor' => 0,
            'status' => 'generated',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $obItemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $obInvoiceId,
            'student_id' => $studentId,
            'enrollment_id' => null,
            'fee_plan_item_id' => null,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee',
            'period_key' => 'monthly:2026-08-01..2026-08-31',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'currency' => 'PKR',
            'amount_minor' => 850000,
            'discount_minor' => 0,
            'net_minor' => 850000,
            'paid_minor' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Simulate imported MySkool staged collection record
        $activeBatch = (string) config('imports.active_batch_id', 'BATCH-20261025-FINAL-MYSKOOL');
        $stagedCollectionId = DB::table('qa_staged_collections')->insertGetId([
            'batch_id' => $activeBatch,
            'source_row' => 101,
            'slip_number' => 'MS-SLIP-7788',
            'gr_number' => '4412',
            'student_name' => 'Hamza Imran',
            'family_code' => 'FAM-IMP-2201',
            'fee_particular' => 'Monthly Fee (Jul 2026)',
            'payment_date_raw' => '2026-07-10',
            'payment_date_parsed' => '2026-07-10',
            'payment_method' => 'Cash',
            'currency' => 'PKR',
            'amount' => 8500.00,
            'amount_minor' => 850000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Scoped department manager updates imported student's Current Class from Class 11 to Class 12
        // Note: department_manager cannot change fee/currency, and leaves them unchanged
        $this->actingAs($this->scopedManager);

        $this->get("/manage-student/{$studentId}/edit")
            ->assertOk()
            ->assertSee('Class 11');

        $this->put("/manage-student/{$studentId}", [
            'name' => 'Hamza Imran',
            'father_name' => 'Imran Siddiqui',
            'guardian_name' => 'Imran Siddiqui',
            'registration_number' => '4412',
            'family_code' => 'FAM-IMP-2201',
            'family_email' => 'imran.imported@example.test',
            'family_whatsapp' => '+923219998877',
            'contact_verified' => '1',
            'messaging_opt_in' => '1',
            'class_admitted' => 'Class 11',
            'section_admitted' => 'A',
            'grade_label' => 'Class 12',
            'section_current' => 'C',
            'currency' => 'PKR',
            'monthly_fee' => '8500.00',
        ])->assertRedirect("/manage-student/{$studentId}");

        // Verify enrollment updated in place without duplicates
        $enrollments = DB::table('enrollments')->where('student_id', $studentId)->get();
        $this->assertCount(1, $enrollments);
        $this->assertSame('Class 12', $enrollments->first()->grade_label);
        $this->assertSame(850000, (int) $enrollments->first()->monthly_fee_minor);
        $this->assertSame('PKR', $enrollments->first()->currency);

        // Verify student_registration_details row was created for the imported student
        $details = DB::table('student_registration_details')->where('student_id', $studentId)->first();
        $this->assertNotNull($details);
        $this->assertSame('Class 11', $details->class_admitted);
        $this->assertSame('C', $details->section_current);

        // Verify student profile, student list, and Fee Collection all show Class 12
        $this->get("/manage-student/{$studentId}")
            ->assertOk()
            ->assertSee('Class 12 - C');

        $this->get('/manage-student?q=4412')
            ->assertOk()
            ->assertSee('Hamza Imran')
            ->assertSee('Class 12');

        $this->get('/challan?gr=4412&search_by=gr')
            ->assertOk()
            ->assertSee('Hamza Imran')
            ->assertSee('Class 12');

        // Verify imported opening balance invoice and MySkool staged payment are completely untouched
        $obInvoiceAfter = DB::table('invoices')->where('id', $obInvoiceId)->first();
        $obItemAfter = DB::table('invoice_items')->where('id', $obItemId)->first();
        $stagedAfter = DB::table('qa_staged_collections')->where('id', $stagedCollectionId)->first();
        $this->assertSame(850000, (int) $obInvoiceAfter->total_minor);
        $this->assertSame('PKR', $obInvoiceAfter->currency);
        $this->assertSame(850000, (int) $obItemAfter->net_minor);
        $this->assertSame(850000, (int) $stagedAfter->amount_minor);
        $this->assertSame('MS-SLIP-7788', $stagedAfter->slip_number, 'Historical MySkool staged slip must remain immutable.');
    }

    /**
     * Test 11: Confirm unauthorized users cannot change a student's class.
     */
    public function test_unauthorized_users_cannot_change_student_current_class(): void
    {
        $this->actingAs($this->superAdmin);

        $this->post('/manage-student/admission/new', [
            'name' => 'Omar Farooq',
            'father_name' => 'Farooq Ahmed',
            'guardian_name' => 'Farooq Ahmed',
            'registration_number' => 'GR-AUTH-3301',
            'family_code' => 'FAM-AUTH-3301',
            'family_email' => 'farooq@example.test',
            'family_whatsapp' => '+923007776655',
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'grade_label' => 'Class 11',
            'currency' => 'SAR',
            'monthly_fee' => '300.00',
        ])->assertRedirect('/manage-student');

        $student = DB::table('students')->where('gr_number', 'GR-AUTH-3301')->first();
        $studentId = (int) $student->id;

        $attemptPayload = [
            'name' => 'Omar Farooq',
            'father_name' => 'Farooq Ahmed',
            'registration_number' => 'GR-AUTH-3301',
            'family_code' => 'FAM-AUTH-3301',
            'family_email' => 'farooq@example.test',
            'family_whatsapp' => '+923007776655',
            'grade_label' => 'Class 12',
        ];

        // 1. Cashier cannot access edit or update class
        $this->actingAs($this->cashier);
        $this->get("/manage-student/{$studentId}/edit")->assertForbidden();
        $this->put("/manage-student/{$studentId}", $attemptPayload)->assertForbidden();

        // 2. Auditor cannot access edit or update class
        $this->actingAs($this->auditor);
        $this->get("/manage-student/{$studentId}/edit")->assertForbidden();
        $this->put("/manage-student/{$studentId}", $attemptPayload)->assertForbidden();

        // 3. Department manager from a different campus cannot access edit or update class
        $this->actingAs($this->otherCampusManager);
        $this->get("/manage-student/{$studentId}/edit")->assertForbidden();
        $this->put("/manage-student/{$studentId}", $attemptPayload)->assertForbidden();

        // Confirm class remained Class 11 in DB
        $this->assertSame('Class 11', DB::table('enrollments')->where('student_id', $studentId)->value('grade_label'));
    }
}
