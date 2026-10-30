<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase1BVoidedInvoiceRegenerationTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $financeAdmin;
    protected User $cashier;
    protected int $campusId;
    protected int $departmentId;
    protected int $monthlyFeeTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->superAdmin = User::where('role', 'super_admin')->first() ?? User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin-p1b@iqra.test',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->campusId = (int) (DB::table('campuses')->value('id') ?? DB::table('campuses')->insertGetId([
            'name' => 'Main Campus',
            'code' => 'MAIN',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->departmentId = (int) (DB::table('departments')->where('code', 'SCH')->value('id') ?? DB::table('departments')->insertGetId([
            'name' => 'School',
            'code' => 'SCH',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->financeAdmin = User::create([
            'name' => 'Finance Admin',
            'email' => 'finance-p1b@iqra.test',
            'password' => bcrypt('password'),
            'role' => 'finance_admin',
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'is_active' => true,
        ]);

        $this->cashier = User::create([
            'name' => 'Cashier User',
            'email' => 'cashier-p1b@iqra.test',
            'password' => bcrypt('password'),
            'role' => 'cashier',
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'is_active' => true,
        ]);

        $this->monthlyFeeTypeId = (int) (DB::table('fee_types')->where('code', 'monthly')->value('id') ?? DB::table('fee_types')->insertGetId([
            'department_id' => $this->departmentId,
            'name' => 'Monthly Fee',
            'code' => 'monthly',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function createStudentFixture(
        string $studentName,
        string $grNumber,
        string $familyCode,
        string $currency = 'AED',
        int $monthlyFeeMinor = 26500,
        ?int $existingFamilyId = null
    ): array {
        $familyId = $existingFamilyId ?? (int) DB::table('families')->insertGetId([
            'family_code' => $familyCode,
            'guardian_name' => 'Guardian of ' . $studentName,
            'email' => strtolower($grNumber) . '@example.test',
            'whatsapp' => '+971501234567',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = (int) DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => $grNumber,
            'name' => $studentName,
            'gender' => 'Female',
            'date_of_admission' => '2025-09-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('student_registration_details')->insert([
            'student_id' => $studentId,
            'registration_number' => $grNumber,
            'father_name' => 'Guardian of ' . $studentName,
            'registration_date' => '2025-09-01',
            'class_admitted' => 'Grade 4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = (int) DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'grade_label' => 'Grade 4',
            'currency' => $currency,
            'monthly_fee_minor' => $monthlyFeeMinor,
            'started_on' => '2025-09-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $planItemId = (int) DB::table('fee_plan_items')->insertGetId([
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee',
            'frequency' => 'monthly',
            'currency' => $currency,
            'amount_minor' => $monthlyFeeMinor,
            'discount_kind' => null,
            'discount_value' => 0,
            'effective_from' => '2025-09-01',
            'effective_to' => null,
            'is_active' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('familyId', 'familyCode', 'studentId', 'grNumber', 'enrollmentId', 'planItemId');
    }

    private function createMonthlyInvoice(array $studentData, int $year, int $month, int $amountMinor, string $currency): array
    {
        $periodStart = sprintf('%04d-%02d-01', $year, $month);
        $periodEnd = date('Y-m-t', strtotime($periodStart));
        $dueDate = sprintf('%04d-%02d-10', $year, $month);
        $periodKey = "monthly:{$periodStart}..{$periodEnd}";

        $run = DB::table('billing_runs')
            ->where('campus_id', $this->campusId)
            ->where('department_id', $this->departmentId)
            ->where('frequency', 'monthly')
            ->where('period_key', $periodKey)
            ->first();

        $runId = $run ? (int) $run->id : (int) DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'frequency' => 'monthly',
            'period_key' => $periodKey,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'due_date' => $dueDate,
            'status' => 'generated',
            'created_by' => $this->superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoiceId = (int) DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $studentData['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'currency' => $currency,
            'invoice_number' => 'SCH-' . $year . '-' . str_pad((string) mt_rand(10000, 999999), 7, '0', STR_PAD_LEFT),
            'issued_on' => $periodStart,
            'due_date' => $dueDate,
            'total_minor' => $amountMinor,
            'paid_minor' => 0,
            'previous_dues_snapshot_minor' => 0,
            'status' => 'generated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = (int) DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId,
            'student_id' => $studentData['studentId'],
            'enrollment_id' => $studentData['enrollmentId'],
            'fee_plan_item_id' => $studentData['planItemId'],
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee',
            'period_key' => $periodKey,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => $amountMinor,
            'paid_minor' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['invoiceId' => $invoiceId, 'itemId' => $itemId, 'runId' => $runId];
    }

    private function buildStudentUpdatePayload(int $studentId, array $overrides = []): array
    {
        $student = DB::table('students')->where('id', $studentId)->first();
        $family = DB::table('families')->where('id', $student->family_id)->first();
        $details = DB::table('student_registration_details')->where('student_id', $studentId)->first();
        $en = DB::table('enrollments')->where('student_id', $studentId)->first();

        return array_replace([
            'name' => $student->name,
            'father_name' => $details->father_name ?? 'Guardian',
            'registration_number' => $student->gr_number,
            'family_code' => $family->family_code,
            'family_email' => $family->email ?? 'guardian@example.test',
            'family_whatsapp' => $family->whatsapp ?? '+971501234567',
            'grade_label' => $en->grade_label ?? 'Grade 4',
            'monthly_fee' => number_format(((int) $en->monthly_fee_minor) / 100, 2, '.', ''),
            'currency' => $en->currency ?? 'AED',
        ], $overrides);
    }

    /**
     * Test A: Currency change and August 2026 recreation (Momina Noor GR 9705, Family 9702 reproduction).
     */
    public function test_a_currency_change_and_august_2026_recreation_for_momina_noor(): void
    {
        // 1. Setup Momina Noor (GR 9705, Family 9702) with June, July, and August 2026 invoices at AED 265 each (AED 795 total)
        $momina = $this->createStudentFixture('Momina Noor', '9705', '9702', 'AED', 26500);
        $this->createMonthlyInvoice($momina, 2026, 6, 26500, 'AED');
        $this->createMonthlyInvoice($momina, 2026, 7, 26500, 'AED');
        $aug = $this->createMonthlyInvoice($momina, 2026, 8, 26500, 'AED');

        // 2. Confirm August 2026 is unpaid
        $augItemBefore = DB::table('invoice_items')->where('id', $aug['itemId'])->first();
        $this->assertEquals('active', $augItemBefore->status);
        $this->assertEquals(0, (int) $augItemBefore->paid_minor);
        $this->assertEquals('AED', $augItemBefore->currency);
        $this->assertEquals(26500, (int) $augItemBefore->net_minor);

        // 3. Change Momina Noor's currency from AED to SAR via the real student edit route
        $updateResp = $this->actingAs($this->superAdmin)->put(
            "/manage-student/{$momina['studentId']}",
            $this->buildStudentUpdatePayload($momina['studentId'], [
                'currency' => 'SAR',
                'monthly_fee' => '265.00',
            ])
        );
        $updateResp->assertRedirect("/manage-student/{$momina['studentId']}");

        // 4. Void the August 2026 invoice item via Fee Collection
        $voidResp = $this->actingAs($this->superAdmin)->post('/challan/void', [
            'invoice_item_id' => $aug['itemId'],
            'reason' => 'Voiding August 2026 AED 265 to recreate under approved SAR currency',
        ]);
        $voidResp->assertSessionHas('notice');

        // Verify AED outstanding is now AED 530 (53000 minor) after voiding August
        $collectionAfterVoid = $this->actingAs($this->superAdmin)->get('/challan?family=9702');
        $collectionAfterVoid->assertOk();
        $metricsAfterVoid = $collectionAfterVoid->viewData('summaryMetrics');
        $this->assertEquals(53000, $metricsAfterVoid['by_currency']['AED']['total_due_minor']);
        $this->assertArrayNotHasKey('SAR', $metricsAfterVoid['by_currency']);

        // 5. Open the actual Create Fee preview workflow for August 2026
        $previewResp = $this->actingAs($this->superAdmin)->postJson('/challan/preview-fee', [
            'family_id' => $momina['familyId'],
            'student_ids' => [$momina['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [8],
            'year' => 2026,
        ]);
        $previewResp->assertOk();
        $previewData = $previewResp->json();
        $this->assertCount(1, $previewData['items']);
        $previewItem = $previewData['items'][0];

        // 6. Confirm recreation is offered and distinguishes Original: AED 265 (Voided) vs Replacement: SAR 265 (Current approved settings)
        $this->assertEquals('Momina Noor', $previewItem['student_name']);
        $this->assertEquals('9705', $previewItem['student_gr']);
        $this->assertEquals('Aug 2026', $previewItem['period_label']);
        $this->assertEquals('AED', $previewItem['original_voided_currency']);
        $this->assertEquals(26500, $previewItem['original_voided_net_minor']);
        $this->assertEquals('Original: AED 265 (Voided)', $previewItem['original_voided_summary']);
        $this->assertEquals('SAR', $previewItem['replacement_currency']);
        $this->assertEquals(26500, $previewItem['replacement_net_minor']);
        $this->assertEquals('Replacement: SAR 265 (Current approved settings)', $previewItem['replacement_summary']);
        $this->assertTrue($previewItem['can_recreate_current']);
        $this->assertNull($previewItem['recreate_block_reason']);

        // Also inspect via Safe Fee Restoration inspection endpoint
        $inspectResp = $this->actingAs($this->superAdmin)->getJson("/challan/inspect-voided-fee/{$aug['itemId']}");
        $inspectResp->assertOk();
        $inspectData = $inspectResp->json();
        $this->assertTrue($inspectData['is_safe_to_recreate_current']);
        $this->assertEquals('AED', $inspectData['original_approved_values']['currency']);
        $this->assertEquals(26500, $inspectData['original_approved_values']['net_minor']);
        $this->assertEquals('SAR', $inspectData['current_approved_settings']['currency']);
        $this->assertEquals(26500, $inspectData['current_approved_settings']['net_minor']);

        // 7. Recreate using current settings
        $recreateResp = $this->actingAs($this->superAdmin)->postJson('/challan/recreate-voided-fee', [
            'invoice_item_id' => $aug['itemId'],
            'reason' => 'Recreating August 2026 obligation using current approved SAR settings',
        ]);
        $recreateResp->assertOk();
        $recreateJson = $recreateResp->json();
        $this->assertTrue($recreateJson['success']);
        $this->assertEquals('recreated_current_settings', $recreateJson['status']);
        $this->assertEquals('SAR', $recreateJson['currency']);
        $this->assertEquals(26500, $recreateJson['net_minor']);

        $newItemId = (int) $recreateJson['replacement_invoice_item_id'];
        $newInvoiceId = (int) $recreateJson['replacement_invoice_id'];
        $this->assertNotEquals($aug['itemId'], $newItemId);
        $this->assertNotEquals($aug['invoiceId'], $newInvoiceId);

        // 8. Verify the new invoice and item are SAR 265
        $newItem = DB::table('invoice_items')->where('id', $newItemId)->first();
        $this->assertEquals('active', $newItem->status);
        $this->assertEquals('SAR', $newItem->currency);
        $this->assertEquals(26500, (int) $newItem->amount_minor);
        $this->assertEquals(26500, (int) $newItem->net_minor);
        $this->assertEquals(0, (int) $newItem->paid_minor);
        $this->assertEquals($aug['itemId'], (int) $newItem->replaces_invoice_item_id);

        $newInvoice = DB::table('invoices')->where('id', $newInvoiceId)->first();
        $this->assertEquals('generated', $newInvoice->status);
        $this->assertEquals('SAR', $newInvoice->currency);
        $this->assertEquals(26500, (int) $newInvoice->total_minor);
        $this->assertEquals($aug['invoiceId'], (int) $newInvoice->replaces_invoice_id);

        // 9. Verify the old AED invoice and item remain voided
        $oldItem = DB::table('invoice_items')->where('id', $aug['itemId'])->first();
        $this->assertEquals('voided', $oldItem->status);
        $this->assertEquals('AED', $oldItem->currency);
        $this->assertEquals(0, (int) $oldItem->net_minor);
        $this->assertEquals($newItemId, (int) $oldItem->replaced_by_invoice_item_id);

        $oldInvoice = DB::table('invoices')->where('id', $aug['invoiceId'])->first();
        $this->assertEquals('voided', $oldInvoice->status);
        $this->assertEquals('AED', $oldInvoice->currency);
        $this->assertEquals(0, (int) $oldInvoice->total_minor);
        $this->assertEquals($newInvoiceId, (int) $oldInvoice->replaced_by_invoice_id);

        // 10. Verify Fee Collection outstanding total contains the new SAR 265 obligation and AED stays at AED 530 (NOT AED 795)
        $collectionAfterRecreate = $this->actingAs($this->superAdmin)->get('/challan?family=9702');
        $collectionAfterRecreate->assertOk();
        $metricsAfter = $collectionAfterRecreate->viewData('summaryMetrics');
        $this->assertEquals(53000, $metricsAfter['by_currency']['AED']['total_due_minor'], 'AED outstanding must remain AED 530, not increase to AED 795');
        $this->assertEquals(26500, $metricsAfter['by_currency']['SAR']['total_due_minor'], 'SAR outstanding must reflect the recreated SAR 265 obligation');
    }

    /**
     * Test B: Monthly fee change (SAR 265 -> SAR 310), void original, recreate August using current settings.
     */
    public function test_b_monthly_fee_change_and_august_recreation(): void
    {
        $student = $this->createStudentFixture('Amina Khan', 'GR-P1B-B', 'FAM-P1B-B', 'SAR', 26500);
        $aug = $this->createMonthlyInvoice($student, 2026, 8, 26500, 'SAR');

        // Change approved monthly fee to SAR 310
        $this->actingAs($this->superAdmin)->put(
            "/manage-student/{$student['studentId']}",
            $this->buildStudentUpdatePayload($student['studentId'], [
                'currency' => 'SAR',
                'monthly_fee' => '310.00',
            ])
        )->assertRedirect("/manage-student/{$student['studentId']}");

        // Void original August invoice
        $this->actingAs($this->superAdmin)->post('/challan/void', [
            'invoice_item_id' => $aug['itemId'],
            'reason' => 'Voiding old SAR 265 fee to recreate at SAR 310',
        ])->assertSessionHas('notice');

        // Check preview shows Original: SAR 265 (Voided) and Replacement: SAR 310 (Current approved settings)
        $preview = $this->actingAs($this->superAdmin)->postJson('/challan/preview-fee', [
            'family_id' => $student['familyId'],
            'student_ids' => [$student['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [8],
            'year' => 2026,
        ])->json();
        $this->assertEquals('Original: SAR 265 (Voided)', $preview['items'][0]['original_voided_summary']);
        $this->assertEquals('Replacement: SAR 310 (Current approved settings)', $preview['items'][0]['replacement_summary']);
        $this->assertTrue($preview['items'][0]['can_recreate_current']);

        // Recreate August using current settings
        $recreateResp = $this->actingAs($this->superAdmin)->postJson('/challan/recreate-voided-fee', [
            'invoice_item_id' => $aug['itemId'],
            'reason' => 'Recreate August at updated SAR 310 monthly fee',
        ]);
        $recreateResp->assertOk();
        $recreateData = $recreateResp->json();

        // Verify the new charge is SAR 310 and original remains voided
        $newItem = DB::table('invoice_items')->where('id', $recreateData['replacement_invoice_item_id'])->first();
        $this->assertEquals('active', $newItem->status);
        $this->assertEquals('SAR', $newItem->currency);
        $this->assertEquals(31000, (int) $newItem->amount_minor);
        $this->assertEquals(31000, (int) $newItem->net_minor);

        $oldItem = DB::table('invoice_items')->where('id', $aug['itemId'])->first();
        $this->assertEquals('voided', $oldItem->status);
        $this->assertEquals(0, (int) $oldItem->net_minor);

        $oldInv = DB::table('invoices')->where('id', $aug['invoiceId'])->first();
        $this->assertEquals('voided', $oldInv->status);
    }

    /**
     * Test C: Both currency and fee change (Original: AED 265 -> Updated: SAR 310).
     */
    public function test_c_both_currency_and_fee_change_recreation(): void
    {
        $student = $this->createStudentFixture('Momina Noor C', 'GR-P1B-C', 'FAM-P1B-C', 'AED', 26500);
        $aug = $this->createMonthlyInvoice($student, 2026, 8, 26500, 'AED');

        // Update both currency to SAR and monthly fee to 310
        $this->actingAs($this->superAdmin)->put(
            "/manage-student/{$student['studentId']}",
            $this->buildStudentUpdatePayload($student['studentId'], [
                'currency' => 'SAR',
                'monthly_fee' => '310.00',
            ])
        )->assertRedirect("/manage-student/{$student['studentId']}");

        // Void the invoice
        $this->actingAs($this->superAdmin)->post('/challan/void', [
            'invoice_item_id' => $aug['itemId'],
            'reason' => 'Voiding AED 265 to recreate as SAR 310',
        ])->assertSessionHas('notice');

        // Preview check
        $preview = $this->actingAs($this->superAdmin)->postJson('/challan/preview-fee', [
            'family_id' => $student['familyId'],
            'student_ids' => [$student['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [8],
            'year' => 2026,
        ])->json();
        $this->assertEquals('Original: AED 265 (Voided)', $preview['items'][0]['original_voided_summary']);
        $this->assertEquals('Replacement: SAR 310 (Current approved settings)', $preview['items'][0]['replacement_summary']);

        // Recreate using current settings
        $recreateResp = $this->actingAs($this->superAdmin)->postJson('/challan/recreate-voided-fee', [
            'invoice_item_id' => $aug['itemId'],
            'reason' => 'Recreating with updated SAR 310 settings',
        ]);
        $recreateResp->assertOk();

        $activeItems = DB::table('invoice_items')
            ->where('student_id', $student['studentId'])
            ->where('period_start', '2026-08-01')
            ->where('status', 'active')
            ->get();
        $this->assertCount(1, $activeItems);
        $this->assertEquals('SAR', $activeItems[0]->currency);
        $this->assertEquals(31000, (int) $activeItems[0]->amount_minor);
        $this->assertEquals(31000, (int) $activeItems[0]->net_minor);

        $oldItem = DB::table('invoice_items')->where('id', $aug['itemId'])->first();
        $this->assertEquals('voided', $oldItem->status);
    }

    /**
     * Test D: Duplicate scheduled plans prevention and historical audit preservation across multiple updates.
     */
    public function test_d_multiple_currency_and_fee_updates_keep_single_authoritative_active_plan(): void
    {
        $student = $this->createStudentFixture('Momina Noor Plans', 'GR-P1B-D', 'FAM-P1B-D', 'AED', 26500);
        $this->createMonthlyInvoice($student, 2026, 8, 26500, 'AED');

        // Update 1: AED 265 -> SAR 265
        $this->actingAs($this->superAdmin)->put(
            "/manage-student/{$student['studentId']}",
            $this->buildStudentUpdatePayload($student['studentId'], [
                'currency' => 'SAR',
                'monthly_fee' => '265.00',
            ])
        );

        // Update 2: SAR 265 -> SAR 310
        $this->actingAs($this->superAdmin)->put(
            "/manage-student/{$student['studentId']}",
            $this->buildStudentUpdatePayload($student['studentId'], [
                'currency' => 'SAR',
                'monthly_fee' => '310.00',
            ])
        );

        // Update 3: SAR 310 -> QAR 350
        $this->actingAs($this->superAdmin)->put(
            "/manage-student/{$student['studentId']}",
            $this->buildStudentUpdatePayload($student['studentId'], [
                'currency' => 'QAR',
                'monthly_fee' => '350.00',
            ])
        );

        // Update 4: QAR 350 -> SAR 310
        $this->actingAs($this->superAdmin)->put(
            "/manage-student/{$student['studentId']}",
            $this->buildStudentUpdatePayload($student['studentId'], [
                'currency' => 'SAR',
                'monthly_fee' => '310.00',
            ])
        );

        // 1. All 5 historical + current fee_plan_items records are preserved in DB for audit (none deleted)
        $allPlans = DB::table('fee_plan_items')
            ->where('enrollment_id', $student['enrollmentId'])
            ->where('fee_type_id', $this->monthlyFeeTypeId)
            ->orderBy('id')
            ->get();
        $this->assertCount(5, $allPlans, 'All historical fee plans must be preserved for audit');

        // 2. Exactly ONE authoritative active plan exists
        $activePlans = $allPlans->where('is_active', true)->values();
        $this->assertCount(1, $activePlans, 'Exactly one authoritative active monthly fee plan may exist');
        $this->assertEquals('active', $activePlans[0]->status);
        $this->assertEquals('SAR', $activePlans[0]->currency);
        $this->assertEquals(31000, (int) $activePlans[0]->amount_minor);
        $this->assertNull($activePlans[0]->superseded_by_plan_id);
        $this->assertNull($activePlans[0]->effective_to);

        // 3. All 4 older plans are marked superseded and inactive
        $supersededPlans = $allPlans->where('is_active', false)->values();
        $this->assertCount(4, $supersededPlans);
        foreach ($supersededPlans as $sp) {
            $this->assertEquals('superseded', $sp->status);
            $this->assertNotNull($sp->superseded_by_plan_id);
            $this->assertNotNull($sp->effective_to);
        }

        // 4. Fee Collection "Future Scheduled Fees" ($studentFeePlans) contains ONLY the single SAR 310 plan
        $challanResp = $this->actingAs($this->superAdmin)->get('/challan?family=FAM-P1B-D');
        $challanResp->assertOk();
        $scheduledPlans = $challanResp->viewData('studentFeePlans');
        $this->assertCount(1, $scheduledPlans, 'Future Scheduled Fees section must show only the single authoritative active plan');
        $this->assertEquals('SAR', $scheduledPlans[0]->currency);
        $this->assertEquals(31000, (int) $scheduledPlans[0]->amount_minor);

        // 5. Future billing run for October 2026 generates only ONE charge in SAR 310 (never both AED and SAR)
        $octRunId = (int) DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-10',
            'status' => 'draft',
            'created_by' => $this->superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $genResult = app(InvoiceService::class)->generate($octRunId);
        $this->assertEquals(1, $genResult['created']);

        $octItems = DB::table('invoice_items')
            ->where('student_id', $student['studentId'])
            ->where('period_start', '2026-10-01')
            ->get();
        $this->assertCount(1, $octItems);
        $this->assertEquals('SAR', $octItems[0]->currency);
        $this->assertEquals(31000, (int) $octItems[0]->net_minor);
    }

    /**
     * Test E: Duplicate and payment protection (idempotency, paid invoices, MySkool historical settlements, siblings, mixed-currency).
     */
    public function test_e_duplicate_and_payment_protection_and_sibling_isolation(): void
    {
        // Create Family with two siblings: Momina (AED 265 -> SAR 310) and Sibling Zaid (AED 200 unchanged)
        $momina = $this->createStudentFixture('Momina Sibling', 'GR-P1B-E1', 'FAM-P1B-E', 'AED', 26500);
        $zaid = $this->createStudentFixture('Zaid Sibling', 'GR-P1B-E2', 'FAM-P1B-E', 'AED', 20000, $momina['familyId']);

        // Create August 2026 invoices for both siblings
        $mominaAug = $this->createMonthlyInvoice($momina, 2026, 8, 26500, 'AED');
        $zaidAug = $this->createMonthlyInvoice($zaid, 2026, 8, 20000, 'AED');

        // Update Momina to SAR 310
        $this->actingAs($this->superAdmin)->put(
            "/manage-student/{$momina['studentId']}",
            $this->buildStudentUpdatePayload($momina['studentId'], [
                'currency' => 'SAR',
                'monthly_fee' => '310.00',
            ])
        );

        // Void Momina's August 2026 fee
        $this->actingAs($this->superAdmin)->post('/challan/void', [
            'invoice_item_id' => $mominaAug['itemId'],
            'reason' => 'Void Momina August fee',
        ]);

        // 1. First recreation succeeds
        $firstRecreate = $this->actingAs($this->superAdmin)->postJson('/challan/recreate-voided-fee', [
            'invoice_item_id' => $mominaAug['itemId'],
            'reason' => 'First recreation click',
        ]);
        $firstRecreate->assertOk();

        // 2. Repeated click to recreate-voided-fee is rejected and cannot create duplicate active invoices
        $secondRecreate = $this->actingAs($this->superAdmin)->postJson('/challan/recreate-voided-fee', [
            'invoice_item_id' => $mominaAug['itemId'],
            'reason' => 'Repeated click',
        ]);
        $secondRecreate->assertStatus(422);

        // Repeated submission to /challan/create-fee is also rejected
        $createFeeDup = $this->actingAs($this->superAdmin)->post('/challan/create-fee', [
            'family_id' => $momina['familyId'],
            'student_ids' => [$momina['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [8],
            'year' => 2026,
        ]);
        $createFeeDup->assertSessionHasErrors('fee_type_ids');

        $activeMominaAugCount = DB::table('invoice_items')
            ->where('student_id', $momina['studentId'])
            ->where('period_start', '2026-08-01')
            ->where('status', '!=', 'voided')
            ->count();
        $this->assertEquals(1, $activeMominaAugCount, 'Repeated clicks must never create multiple active replacement items');

        // 3. Sibling Zaid's AED 200 fee remains completely unchanged and mixed-currency family summary is accurate
        $zaidItem = DB::table('invoice_items')->where('id', $zaidAug['itemId'])->first();
        $this->assertEquals('active', $zaidItem->status);
        $this->assertEquals('AED', $zaidItem->currency);
        $this->assertEquals(20000, (int) $zaidItem->net_minor);

        $challanResp = $this->actingAs($this->superAdmin)->get('/challan?family=FAM-P1B-E');
        $metrics = $challanResp->viewData('summaryMetrics');
        $this->assertTrue($metrics['is_multi_currency']);
        $this->assertEquals(20000, $metrics['by_currency']['AED']['total_due_minor']);
        $this->assertEquals(31000, $metrics['by_currency']['SAR']['total_due_minor']);

        // 4. Paid invoice protection: mark September 2026 invoice as paid and verify it cannot be voided or recreated
        $mominaSep = $this->createMonthlyInvoice($momina, 2026, 9, 31000, 'SAR');
        $paymentId = (int) DB::table('payments')->insertGetId([
            'family_id' => $momina['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'currency' => 'SAR',
            'receipt_number' => 'REC-P1B-PAID-01',
            'amount_minor' => 31000,
            'unallocated_minor' => 0,
            'received_on' => '2026-09-05',
            'idempotency_key' => 'IDEM-P1B-PAID-01',
            'request_hash' => hash('sha256', 'IDEM-P1B-PAID-01'),
            'received_by' => $this->cashier->id,
            'status' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_item_id' => $mominaSep['itemId'],
            'amount_minor' => 31000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_items')->where('id', $mominaSep['itemId'])->update(['paid_minor' => 31000, 'status' => 'paid']);
        DB::table('invoices')->where('id', $mominaSep['invoiceId'])->update(['paid_minor' => 31000, 'status' => 'paid']);

        $voidPaidResp = $this->actingAs($this->superAdmin)->postJson('/challan/void', [
            'invoice_item_id' => $mominaSep['itemId'],
            'reason' => 'Attempt to void paid invoice',
        ]);
        $voidPaidResp->assertStatus(422);

        // 5. Imported MySkool historical settlement protection:
        // Create July 2026 voided item that has an applied MySkool historical settlement
        $mominaJul = $this->createMonthlyInvoice($momina, 2026, 7, 26500, 'AED');
        $this->actingAs($this->superAdmin)->post('/challan/void', [
            'invoice_item_id' => $mominaJul['itemId'],
            'reason' => 'Void before attaching historical settlement',
        ]);
        DB::table('qa_historical_settlements')->insert([
            'reconciliation_id' => 'RECON-P1B-HIST-01',
            'batch_id' => 'BATCH-20261025-FINAL-MYSKOOL',
            'cutoff_date' => '2026-10-22',
            'invoice_item_id' => $mominaJul['itemId'],
            'invoice_id' => $mominaJul['invoiceId'],
            'student_id' => $momina['studentId'],
            'family_id' => $momina['familyId'],
            'student_gr' => $momina['grNumber'],
            'family_code' => $momina['familyCode'],
            'currency' => 'AED',
            'fee_description' => 'Monthly Fee',
            'period_key' => 'monthly:2026-07-01..2026-07-31',
            'original_obligation_minor' => 26500,
            'settled_amount_minor' => 26500,
            'remaining_obligation_minor' => 0,
            'unmatched_overpayment_minor' => 0,
            'previous_item_paid_minor' => 0,
            'new_item_paid_minor' => 26500,
            'previous_item_status' => 'voided',
            'new_item_status' => 'paid',
            'previous_invoice_paid_minor' => 0,
            'new_invoice_paid_minor' => 26500,
            'previous_invoice_status' => 'voided',
            'new_invoice_status' => 'paid',
            'myskool_slip_numbers' => 'SLIP-HIST-777',
            'reconciliation_classification' => 'POST_CUTOFF_FULL_SETTLEMENT',
            'guard_class' => 'CANDIDATE_FULL_SETTLEMENT',
            'status' => 'applied',
            'applied_by' => $this->superAdmin->id,
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Preview and inspection must block recreation with current settings for historically settled July item
        $julPreview = $this->actingAs($this->superAdmin)->postJson('/challan/preview-fee', [
            'family_id' => $momina['familyId'],
            'student_ids' => [$momina['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [7],
            'year' => 2026,
        ])->json();
        $this->assertFalse($julPreview['items'][0]['can_recreate_current']);
        $this->assertNotEmpty($julPreview['items'][0]['recreate_block_reason']);

        $julRecreateAttempt = $this->actingAs($this->superAdmin)->postJson('/challan/recreate-voided-fee', [
            'invoice_item_id' => $mominaJul['itemId'],
            'reason' => 'Attempt to recreate historically settled MySkool obligation with new settings',
        ]);
        $julRecreateAttempt->assertStatus(422);
    }

    /**
     * Test F: Browser-facing HTTP route behavior and full UI/ledger agreement.
     */
    public function test_f_browser_facing_routes_and_rendered_fee_collection_agreement(): void
    {
        $student = $this->createStudentFixture('Momina Browser Check', '9705-F', '9702-F', 'AED', 26500);
        $aug = $this->createMonthlyInvoice($student, 2026, 8, 26500, 'AED');

        // 1. Update student currency and fee via HTTP PUT /manage-student/{id}
        $this->actingAs($this->financeAdmin)->put(
            "/manage-student/{$student['studentId']}",
            $this->buildStudentUpdatePayload($student['studentId'], [
                'currency' => 'SAR',
                'monthly_fee' => '265.00',
            ])
        )->assertRedirect("/manage-student/{$student['studentId']}");

        // 2. Void August 2026 via HTTP POST /challan/void
        $this->actingAs($this->financeAdmin)->postJson('/challan/void', [
            'invoice_item_id' => $aug['itemId'],
            'reason' => 'Browser void test',
        ])->assertOk();

        // 3. Preview via HTTP POST /challan/preview-fee
        $previewResp = $this->actingAs($this->financeAdmin)->postJson('/challan/preview-fee', [
            'family_id' => $student['familyId'],
            'student_ids' => [$student['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [8],
            'year' => 2026,
        ]);
        $previewResp->assertOk()
            ->assertJsonFragment([
                'original_voided_summary' => 'Original: AED 265 (Voided)',
                'replacement_summary' => 'Replacement: SAR 265 (Current approved settings)',
                'can_recreate_current' => true,
            ]);

        // 4. Inspect via HTTP GET /challan/inspect-voided-fee/{id}
        $inspectResp = $this->actingAs($this->financeAdmin)->getJson("/challan/inspect-voided-fee/{$aug['itemId']}");
        $inspectResp->assertOk()
            ->assertJsonFragment([
                'is_safe_to_recreate_current' => true,
                'has_settings_changed' => true,
            ]);

        // 5. Recreate via HTTP POST /challan/recreate-voided-fee
        $recreateResp = $this->actingAs($this->financeAdmin)->postJson('/challan/recreate-voided-fee', [
            'invoice_item_id' => $aug['itemId'],
            'reason' => 'Browser-facing recreation using current settings',
        ]);
        $recreateResp->assertOk()
            ->assertJsonFragment([
                'success' => true,
                'status' => 'recreated_current_settings',
                'currency' => 'SAR',
                'net_minor' => 26500,
            ]);

        // 6. Re-running preview now shows 'Already invoiced' on the new SAR invoice
        $previewAfter = $this->actingAs($this->financeAdmin)->postJson('/challan/preview-fee', [
            'family_id' => $student['familyId'],
            'student_ids' => [$student['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [8],
            'year' => 2026,
        ])->json();
        $this->assertEquals('Already invoiced', $previewAfter['items'][0]['status']);

        // 7. Load Fee Collection page GET /challan?family=9702-F and confirm table, scheduled plans, and summaries agree
        $pageResp = $this->actingAs($this->financeAdmin)->get('/challan?family=9702-F');
        $pageResp->assertOk();
        $pageResp->assertSee('Momina Browser Check');
        $pageResp->assertSee('SAR 265');

        $collectibleItems = $pageResp->viewData('invoiceItems');
        $this->assertCount(1, $collectibleItems, 'Only the active SAR 265 replacement item should appear in collectible items');
        $this->assertEquals('SAR', $collectibleItems[0]->currency);
        $this->assertEquals(26500, (int) $collectibleItems[0]->net_minor);

        $scheduledPlans = $pageResp->viewData('studentFeePlans');
        $this->assertCount(1, $scheduledPlans, 'Only 1 scheduled active fee plan should appear');
        $this->assertEquals('SAR', $scheduledPlans[0]->currency);
        $this->assertEquals(26500, (int) $scheduledPlans[0]->amount_minor);

        $summaryMetrics = $pageResp->viewData('summaryMetrics');
        $this->assertEquals(26500, $summaryMetrics['total_due_minor']);
        $this->assertEquals(26500, $summaryMetrics['by_currency']['SAR']['total_due_minor']);
        $this->assertArrayNotHasKey('AED', $summaryMetrics['by_currency']);
    }
}
