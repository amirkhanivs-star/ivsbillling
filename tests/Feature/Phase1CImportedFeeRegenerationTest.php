<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase1CImportedFeeRegenerationTest extends TestCase
{
    use RefreshDatabase;

    protected string $batchId;
    protected User $superAdmin;
    protected User $financeAdmin;
    protected User $cashier;
    protected int $campusId;
    protected int $departmentId;
    protected int $monthlyFeeTypeId;
    protected int $obRunId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        Config::set('reconciliation.allow_apply', true);
        $this->batchId = (string) config('imports.active_batch_id', 'BATCH-20261025-FINAL-MYSKOOL');

        $this->superAdmin = User::where('role', 'super_admin')->first() ?? User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin-p1c@iqra.test',
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

        $this->departmentId = (int) (DB::table('departments')->where('code', 'school')->value('id')
            ?? DB::table('departments')->where('code', 'SCH')->value('id')
            ?? DB::table('departments')->insertGetId([
                'name' => 'School',
                'code' => 'school',
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        $this->financeAdmin = User::create([
            'name' => 'Finance Admin',
            'email' => 'finance-p1c@iqra.test',
            'password' => bcrypt('password'),
            'role' => 'finance_admin',
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'is_active' => true,
        ]);

        $this->cashier = User::create([
            'name' => 'Cashier User',
            'email' => 'cashier-p1c@iqra.test',
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

        $this->obRunId = (int) DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->departmentId,
            'frequency' => 'opening_balance',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'status' => 'generated',
            'created_by' => $this->superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    /**
     * Create an imported MySkool obligation (qa_staged_dues + opening_balance invoice + invoice_item + qa_opening_balance_lineage).
     */
    private function createImportedObligation(
        array $studentData,
        string $periodStart,
        string $periodEnd,
        string $particular,
        string $currency,
        int $amountMinor,
        int $sourceRow = 1001,
        ?int $existingObInvoiceId = null
    ): array {
        $periodKey = "monthly:{$periodStart}..{$periodEnd}";

        $stagedDueId = (int) DB::table('qa_staged_dues')->insertGetId([
            'batch_id' => $this->batchId,
            'source_row' => $sourceRow,
            'gr_number' => $studentData['grNumber'],
            'student_name' => 'Student ' . $studentData['grNumber'],
            'family_code_final' => $studentData['familyCode'],
            'fee_particular' => $particular,
            'currency' => $currency,
            'outstanding_amount' => $amountMinor / 100,
            'outstanding_amount_minor' => $amountMinor,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($existingObInvoiceId) {
            $invoiceId = $existingObInvoiceId;
            DB::table('invoices')->where('id', $invoiceId)->increment('total_minor', $amountMinor);
        } else {
            $invoiceId = (int) DB::table('invoices')->insertGetId([
                'billing_run_id' => $this->obRunId,
                'family_id' => $studentData['familyId'],
                'campus_id' => $this->campusId,
                'department_id' => $this->departmentId,
                'currency' => $currency,
                'invoice_number' => 'SCH-OB-2026-0000' . $sourceRow . '-' . $studentData['familyId'],
                'issued_on' => $periodStart,
                'due_date' => date('Y-m-d', strtotime($periodStart . ' +24 days')),
                'total_minor' => $amountMinor,
                'paid_minor' => 0,
                'status' => 'generated',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $itemId = (int) DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId,
            'student_id' => $studentData['studentId'],
            'enrollment_id' => $studentData['enrollmentId'],
            'fee_plan_item_id' => null,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => $particular,
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

        $lineageId = (int) DB::table('qa_opening_balance_lineage')->insertGetId([
            'batch_id' => $this->batchId,
            'qa_staged_due_id' => $stagedDueId,
            'source_row' => $sourceRow,
            'gr_number' => $studentData['grNumber'],
            'invoice_id' => $invoiceId,
            'invoice_item_id' => $itemId,
            'family_id' => $studentData['familyId'],
            'student_id' => $studentData['studentId'],
            'enrollment_id' => $studentData['enrollmentId'],
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'period_key' => $periodKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('stagedDueId', 'invoiceId', 'itemId', 'lineageId');
    }

    private function insertStagedCollection(
        int $sourceRow,
        string $slipNumber,
        string $grNumber,
        string $familyCode,
        string $particular,
        string $paymentDateParsed,
        string $currency,
        int $amountMinor
    ): int {
        return (int) DB::table('qa_staged_collections')->insertGetId([
            'batch_id' => $this->batchId,
            'source_row' => $sourceRow,
            'slip_number' => $slipNumber,
            'gr_number' => $grNumber,
            'student_name' => 'Student ' . $grNumber,
            'family_code' => $familyCode,
            'fee_particular' => $particular,
            'payment_date_raw' => $paymentDateParsed,
            'payment_date_parsed' => $paymentDateParsed,
            'payment_method' => 'Cash',
            'currency' => $currency,
            'amount' => $amountMinor / 100,
            'amount_minor' => $amountMinor,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    private function updateStudentSettingsViaHttp(int $studentId, string $currency, string $monthlyFeeDecimal): void
    {
        $response = $this->actingAs($this->financeAdmin)->put(
            "/manage-student/{$studentId}",
            $this->buildStudentUpdatePayload($studentId, [
                'currency' => $currency,
                'monthly_fee' => $monthlyFeeDecimal,
            ])
        );

        $response->assertRedirect("/manage-student/{$studentId}");
    }

    /**
     * Scenario 1: Imported fully unpaid fee -> void -> regenerate with current currency.
     */
    public function test_01_imported_unpaid_fee_void_and_regenerate_with_current_currency(): void
    {
        $student = $this->createStudentFixture('Amina Tariq', 'GR-P1C-01', 'FAM-P1C-01', 'AED', 26500);
        $imported = $this->createImportedObligation(
            $student,
            '2026-10-01',
            '2026-10-31',
            'Tuition Fee - Oct-2026',
            'AED',
            26500,
            1001
        );

        // 1. Void the imported October 2026 obligation via HTTP
        $this->actingAs($this->financeAdmin)->postJson('/challan/void', [
            'invoice_item_id' => $imported['itemId'],
            'reason' => 'Imported in wrong currency AED instead of SAR',
        ])->assertStatus(200)->assertJsonPath('success', true);

        // 2. Update currency from AED 265 to SAR 265 via Manage Student HTTP
        $this->updateStudentSettingsViaHttp($student['studentId'], 'SAR', '265.00');

        // 3. Preview fee eligibility via HTTP -> must be previously_voided with can_recreate_current = true
        $preview = $this->actingAs($this->financeAdmin)->postJson('/challan/preview-fee', [
            'family_id' => $student['familyId'],
            'student_ids' => [$student['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
        ]);
        $preview->assertStatus(200)
            ->assertJsonPath('items.0.status_code', 'previously_voided')
            ->assertJsonPath('items.0.can_recreate_current', true)
            ->assertJsonPath('items.0.recreate_block_reason', null)
            ->assertJsonPath('items.0.original_voided_currency', 'AED')
            ->assertJsonPath('items.0.replacement_currency', 'SAR');

        // 4. Regenerate via HTTP /challan/recreate-voided-fee
        $recreate = $this->actingAs($this->financeAdmin)->postJson('/challan/recreate-voided-fee', [
            'invoice_item_id' => $imported['itemId'],
            'reason' => 'Regenerate October 2026 in SAR',
        ]);
        $recreate->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('currency', 'SAR')
            ->assertJsonPath('net_minor', 26500);

        $newItemId = (int) $recreate->json('replacement_invoice_item_id');
        $newItem = DB::table('invoice_items')->where('id', $newItemId)->first();
        $newInvoice = DB::table('invoices')->where('id', $newItem->invoice_id)->first();

        $this->assertEquals('active', $newItem->status);
        $this->assertEquals('SAR', $newItem->currency);
        $this->assertEquals(26500, (int) $newItem->net_minor);
        $this->assertEquals('2026-10-01', $newItem->period_start);
        $this->assertEquals('2026-10-31', $newItem->period_end);
        $this->assertEquals($this->monthlyFeeTypeId, (int) $newItem->fee_type_id);
        $this->assertNotEquals($imported['invoiceId'], (int) $newInvoice->id);
        $this->assertEquals('SAR', $newInvoice->currency);
    }

    /**
     * Scenario 2: Imported fully unpaid fee -> void -> regenerate with updated monthly fee (same currency).
     */
    public function test_02_imported_unpaid_fee_void_and_regenerate_with_updated_monthly_fee(): void
    {
        $student = $this->createStudentFixture('Bilal Tariq', 'GR-P1C-02', 'FAM-P1C-02', 'AED', 26500);
        $imported = $this->createImportedObligation(
            $student,
            '2026-10-01',
            '2026-10-31',
            'Tuition Fee - Oct-2026',
            'AED',
            26500,
            1002
        );

        // Void the imported fee
        $this->actingAs($this->financeAdmin)->postJson('/challan/void', [
            'invoice_item_id' => $imported['itemId'],
            'reason' => 'Wrong monthly fee imported',
        ])->assertStatus(200)->assertJsonPath('success', true);

        // Update monthly fee from AED 265 to AED 310 (same currency)
        $this->updateStudentSettingsViaHttp($student['studentId'], 'AED', '310.00');

        // Inspect voided fee via HTTP -> must allow recreate with current settings
        $inspect = $this->actingAs($this->financeAdmin)->getJson("/challan/inspect-voided-fee/{$imported['itemId']}");
        $inspect->assertStatus(200)
            ->assertJsonPath('is_safe_to_recreate_current', true)
            ->assertJsonPath('recreate_block_reason', null)
            ->assertJsonPath('current_approved_settings.currency', 'AED')
            ->assertJsonPath('current_approved_settings.net_minor', 31000);

        // Regenerate via HTTP /challan/create-fee (Fee Collection "Create Fee" flow)
        $createRes = $this->actingAs($this->financeAdmin)->postJson('/challan/create-fee', [
            'family_id' => $student['familyId'],
            'student_ids' => [$student['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
            'recreate_voided' => true,
            'recreate_reason' => 'Regenerate October 2026 with updated AED 310 rate',
        ]);
        $createRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('created', 1);

        $activeItems = DB::table('invoice_items')
            ->where('student_id', $student['studentId'])
            ->where('period_start', '2026-10-01')
            ->where('status', 'active')
            ->get();

        $this->assertCount(1, $activeItems);
        $this->assertEquals('AED', $activeItems[0]->currency);
        $this->assertEquals(31000, (int) $activeItems[0]->net_minor);
        // Must NOT be attached to the voided SCH-OB opening-balance invoice
        $this->assertNotEquals($imported['invoiceId'], (int) $activeItems[0]->invoice_id);
        $this->assertTrue(in_array(DB::table('invoices')->where('id', $imported['invoiceId'])->value('status'), ['void', 'voided'], true));
    }

    /**
     * Scenario 3 & 6: Imported fee with both currency and fee changed (e.g. Momina Noor case: AED 265 -> SAR 250),
     * and historical source records remain intact.
     */
    public function test_03_and_06_imported_fee_both_currency_and_fee_changed_preserves_historical_source_records(): void
    {
        $student = $this->createStudentFixture('Momina Fictional', 'GR-P1C-03', 'FAM-P1C-03', 'AED', 26500);
        $imported = $this->createImportedObligation(
            $student,
            '2026-10-01',
            '2026-10-31',
            'Tuition Fee - Oct-2026',
            'AED',
            26500,
            1003
        );

        // Void the imported AED 265 item
        $this->actingAs($this->financeAdmin)->postJson('/challan/void', [
            'invoice_item_id' => $imported['itemId'],
            'reason' => 'Incorrect currency and rate in MySkool import',
        ])->assertStatus(200)->assertJsonPath('success', true);

        // Update both currency and monthly fee: AED 265 -> SAR 250
        $this->updateStudentSettingsViaHttp($student['studentId'], 'SAR', '250.00');

        // Inspect via HTTP
        $inspect = $this->actingAs($this->financeAdmin)->getJson("/challan/inspect-voided-fee/{$imported['itemId']}");
        $inspect->assertStatus(200)
            ->assertJsonPath('is_safe_to_recreate_current', true)
            ->assertJsonPath('recreate_block_reason', null)
            ->assertJsonPath('original_approved_values.currency', 'AED')
            ->assertJsonPath('original_approved_values.net_minor', 26500)
            ->assertJsonPath('current_approved_settings.currency', 'SAR')
            ->assertJsonPath('current_approved_settings.net_minor', 25000);

        // Regenerate via HTTP /challan/recreate-voided-fee
        $recreate = $this->actingAs($this->financeAdmin)->postJson('/challan/recreate-voided-fee', [
            'invoice_item_id' => $imported['itemId'],
            'reason' => 'Regenerate October 2026 in SAR 250',
        ]);
        $recreate->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('currency', 'SAR')
            ->assertJsonPath('net_minor', 25000);

        $newItemId = (int) $recreate->json('replacement_invoice_item_id');

        // Scenario 6 verification: Historical source records and audit trail remain 100% intact
        $stagedDue = DB::table('qa_staged_dues')->where('id', $imported['stagedDueId'])->first();
        $this->assertNotNull($stagedDue);
        $this->assertEquals('AED', $stagedDue->currency);
        $this->assertEquals(26500, (int) $stagedDue->outstanding_amount_minor);

        $lineage = DB::table('qa_opening_balance_lineage')->where('id', $imported['lineageId'])->first();
        $this->assertNotNull($lineage);
        $this->assertEquals($imported['stagedDueId'], (int) $lineage->qa_staged_due_id);
        $this->assertEquals($imported['invoiceId'], (int) $lineage->invoice_id);
        $this->assertEquals($imported['itemId'], (int) $lineage->invoice_item_id);
        $this->assertEquals('AED', $lineage->currency);
        $this->assertEquals(26500, (int) $lineage->amount_minor);

        $voidedItem = DB::table('invoice_items')->where('id', $imported['itemId'])->first();
        $this->assertTrue(in_array($voidedItem->status, ['void', 'voided'], true));
        $this->assertEquals('AED', $voidedItem->currency);
        $this->assertEquals(26500, (int) $voidedItem->amount_minor);
        $this->assertEquals(0, (int) $voidedItem->net_minor);
        $this->assertEquals($newItemId, (int) $voidedItem->replaced_by_invoice_item_id);

        $voidedInvoice = DB::table('invoices')->where('id', $imported['invoiceId'])->first();
        $this->assertTrue(in_array($voidedInvoice->status, ['void', 'voided'], true));
        $this->assertEquals('AED', $voidedInvoice->currency);

        // Fee adjustment and audit log recorded the recreation link
        $recreateAdj = DB::table('fee_adjustments')
            ->where('invoice_item_id', $imported['itemId'])
            ->where('kind', 'recreated')
            ->first();
        $this->assertNotNull($recreateAdj);

        $auditLog = DB::table('audit_logs')
            ->where('subject_type', 'invoice_item')
            ->where('subject_id', $newItemId)
            ->where('action', 'voided_fee_recreated_current_settings')
            ->first();
        $this->assertNotNull($auditLog);
        $meta = json_decode($auditLog->metadata, true);
        $this->assertEquals($imported['itemId'], (int) $meta['original_voided_item_id']);
        $this->assertEquals('AED', $meta['original_currency']);
        $this->assertEquals('SAR', $meta['new_currency']);
    }

    /**
     * Scenario 4: Imported fully paid fee remains protected.
     */
    public function test_04_imported_fully_paid_fee_remains_protected(): void
    {
        $student = $this->createStudentFixture('Zainab Paid', 'GR-P1C-04', 'FAM-P1C-04', 'AED', 26500);
        $imported = $this->createImportedObligation(
            $student,
            '2026-10-01',
            '2026-10-31',
            'Tuition Fee - Oct-2026',
            'AED',
            26500,
            1004
        );

        // Case 4A: Active item with payment recorded cannot be voided via /challan/void
        DB::table('invoice_items')->where('id', $imported['itemId'])->update([
            'paid_minor' => 26500,
            'status' => 'paid',
        ]);
        DB::table('invoices')->where('id', $imported['invoiceId'])->update([
            'paid_minor' => 26500,
            'status' => 'paid',
        ]);

        $voidPaidRes = $this->actingAs($this->financeAdmin)->postJson('/challan/void', [
            'invoice_item_id' => $imported['itemId'],
            'reason' => 'Attempt to void paid imported fee',
        ]);
        $voidPaidRes->assertStatus(422);

        // Case 4B: Voided imported item where a full MySkool payment exists in qa_staged_collections
        // Reset item to voided (paid_minor = 0) as if voided before reconciliation, with full payment in qa_staged_collections
        DB::table('invoice_items')->where('id', $imported['itemId'])->update([
            'paid_minor' => 0,
            'net_minor' => 0,
            'status' => 'voided',
        ]);
        DB::table('invoices')->where('id', $imported['invoiceId'])->update([
            'total_minor' => 0,
            'paid_minor' => 0,
            'status' => 'void',
        ]);

        $this->insertStagedCollection(
            2004,
            'SLIP-P1C-FULL',
            $student['grNumber'],
            $student['familyCode'],
            'Tuition Fee - Oct-2026',
            '2026-10-20',
            'AED',
            26500
        );

        // Update student settings to SAR 250
        $this->updateStudentSettingsViaHttp($student['studentId'], 'SAR', '250.00');

        // Inspect -> must block recreation with current settings
        $inspect = $this->actingAs($this->financeAdmin)->getJson("/challan/inspect-voided-fee/{$imported['itemId']}");
        $inspect->assertStatus(200)
            ->assertJsonPath('is_safe_to_recreate_current', false);
        $this->assertNotEmpty($inspect->json('recreate_block_reason'));

        // Preview eligibility -> must NOT allow recreation
        $preview = $this->actingAs($this->financeAdmin)->postJson('/challan/preview-fee', [
            'family_id' => $student['familyId'],
            'student_ids' => [$student['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
        ]);
        $preview->assertStatus(200)
            ->assertJsonPath('items.0.can_recreate_current', false);

        // Attempt to force recreate via /challan/recreate-voided-fee -> must fail with 422
        $recreate = $this->actingAs($this->financeAdmin)->postJson('/challan/recreate-voided-fee', [
            'invoice_item_id' => $imported['itemId'],
            'reason' => 'Attempt to bypass full MySkool payment protection',
        ]);
        $recreate->assertStatus(422);

        // Attempt to force create via /challan/create-fee -> must fail with 422
        $createFee = $this->actingAs($this->financeAdmin)->postJson('/challan/create-fee', [
            'family_id' => $student['familyId'],
            'student_ids' => [$student['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
            'recreate_voided' => true,
            'recreate_reason' => 'Attempt to bypass via create-fee',
        ]);
        $createFee->assertStatus(422);

        // Confirm zero active items created
        $this->assertEquals(0, DB::table('invoice_items')
            ->where('student_id', $student['studentId'])
            ->where('period_start', '2026-10-01')
            ->where('status', 'active')
            ->count());
    }

    /**
     * Scenario 5: Imported partially paid fee does not generate duplicate debt;
     * preserves settled portion and requires safe reconciliation/restoration procedure.
     */
    public function test_05_imported_partially_paid_fee_blocks_full_recreation_and_preserves_settled_portion(): void
    {
        $student = $this->createStudentFixture('Hamza Partial', 'GR-P1C-05', 'FAM-P1C-05', 'AED', 26500);
        // Residual staged due of AED 100.00 and staged collection of AED 165.00 (total AED 265.00 partially paid)
        $imported = $this->createImportedObligation(
            $student,
            '2026-10-01',
            '2026-10-31',
            'Tuition Fee - Oct-2026',
            'AED',
            10000,
            1005
        );

        $this->insertStagedCollection(
            2005,
            'SLIP-P1C-PARTIAL',
            $student['grNumber'],
            $student['familyCode'],
            'Tuition Fee - Oct-2026',
            '2026-10-18',
            'AED',
            16500
        );

        // Void the residual item
        $this->actingAs($this->financeAdmin)->postJson('/challan/void', [
            'invoice_item_id' => $imported['itemId'],
            'reason' => 'Mistakenly voided residual item',
        ])->assertStatus(200)->assertJsonPath('success', true);

        // Update student settings to SAR 250
        $this->updateStudentSettingsViaHttp($student['studentId'], 'SAR', '250.00');

        // Inspect -> automatic full recreation in new currency/rate MUST be blocked
        $inspect = $this->actingAs($this->financeAdmin)->getJson("/challan/inspect-voided-fee/{$imported['itemId']}");
        $inspect->assertStatus(200)
            ->assertJsonPath('is_safe_to_recreate_current', false);
        $this->assertNotEmpty($inspect->json('recreate_block_reason'));

        // Attempt to recreate full amount via /challan/recreate-voided-fee -> blocked (422)
        $recreate = $this->actingAs($this->financeAdmin)->postJson('/challan/recreate-voided-fee', [
            'invoice_item_id' => $imported['itemId'],
            'reason' => 'Try to recreate full amount on partially paid imported fee',
        ]);
        $recreate->assertStatus(422);

        // Attempt via /challan/create-fee -> blocked (422)
        $createFee = $this->actingAs($this->financeAdmin)->postJson('/challan/create-fee', [
            'family_id' => $student['familyId'],
            'student_ids' => [$student['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
            'recreate_voided' => true,
            'recreate_reason' => 'Try via create-fee',
        ]);
        $createFee->assertStatus(422);

        // Confirm no duplicate SAR or full-amount obligation was created
        $this->assertEquals(0, DB::table('invoice_items')
            ->where('student_id', $student['studentId'])
            ->where('period_start', '2026-10-01')
            ->where('status', 'active')
            ->count());
    }

    /**
     * Scenario 7: Repeated regeneration does not create duplicate obligations.
     */
    public function test_07_repeated_regeneration_does_not_create_duplicate_obligations(): void
    {
        $student = $this->createStudentFixture('Sana Repeat', 'GR-P1C-07', 'FAM-P1C-07', 'AED', 26500);
        $imported = $this->createImportedObligation(
            $student,
            '2026-10-01',
            '2026-10-31',
            'Tuition Fee - Oct-2026',
            'AED',
            26500,
            1007
        );

        // Void original imported item
        $this->actingAs($this->financeAdmin)->postJson('/challan/void', [
            'invoice_item_id' => $imported['itemId'],
            'reason' => 'Void for currency change',
        ])->assertStatus(200);

        $this->updateStudentSettingsViaHttp($student['studentId'], 'SAR', '250.00');

        // First regeneration succeeds
        $first = $this->actingAs($this->financeAdmin)->postJson('/challan/recreate-voided-fee', [
            'invoice_item_id' => $imported['itemId'],
            'reason' => 'First regeneration',
        ]);
        $first->assertStatus(200)->assertJsonPath('success', true);
        $firstNewItemId = (int) $first->json('replacement_invoice_item_id');

        // Second regeneration on the same voided item -> blocked (422)
        $second = $this->actingAs($this->financeAdmin)->postJson('/challan/recreate-voided-fee', [
            'invoice_item_id' => $imported['itemId'],
            'reason' => 'Duplicate click on recreate',
        ]);
        $second->assertStatus(422);

        // Attempting /challan/create-fee for the same period -> blocked (422 Already invoiced)
        $third = $this->actingAs($this->financeAdmin)->postJson('/challan/create-fee', [
            'family_id' => $student['familyId'],
            'student_ids' => [$student['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [10],
            'year' => 2026,
            'recreate_voided' => true,
            'recreate_reason' => 'Duplicate create-fee attempt',
        ]);
        $third->assertStatus(422);

        // Now void the regenerated SAR 250 item, change settings to SAR 275, and regenerate again
        $this->actingAs($this->financeAdmin)->postJson('/challan/void', [
            'invoice_item_id' => $firstNewItemId,
            'reason' => 'Adjust SAR rate to 275',
        ])->assertStatus(200);

        $this->updateStudentSettingsViaHttp($student['studentId'], 'SAR', '275.00');

        $recreateAgain = $this->actingAs($this->financeAdmin)->postJson('/challan/recreate-voided-fee', [
            'invoice_item_id' => $firstNewItemId,
            'reason' => 'Regenerate at SAR 275',
        ]);
        $recreateAgain->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('currency', 'SAR')
            ->assertJsonPath('net_minor', 27500);

        // Only 1 active obligation exists for October 2026
        $activeItems = DB::table('invoice_items')
            ->where('student_id', $student['studentId'])
            ->where('period_start', '2026-10-01')
            ->where('status', 'active')
            ->get();
        $this->assertCount(1, $activeItems);
        $this->assertEquals(27500, (int) $activeItems[0]->net_minor);
        $this->assertEquals('SAR', $activeItems[0]->currency);
    }

    /**
     * Scenario 8: Family outstanding totals reflect only the correct active currency-specific charges,
     * preserving sibling records and other currencies.
     */
    public function test_08_family_outstanding_totals_reflect_active_currency_charges_and_preserve_siblings(): void
    {
        // Sister 1 (Momina): AED 265 imported for Oct 2026 -> will be voided and regenerated as SAR 250
        $sister1 = $this->createStudentFixture('Momina Sister1', 'GR-P1C-08A', 'FAM-P1C-08', 'AED', 26500);
        // Sister 2 (Sibling): AED 265 imported for Oct 2026 -> remains active in AED 265 on the shared OB invoice
        $sister2 = $this->createStudentFixture('Ayesha Sister2', 'GR-P1C-08B', 'FAM-P1C-08', 'AED', 26500, $sister1['familyId']);

        $imp1 = $this->createImportedObligation(
            $sister1,
            '2026-10-01',
            '2026-10-31',
            'Tuition Fee - Oct-2026',
            'AED',
            26500,
            1008
        );

        // Put Sister 2 on the same opening-balance invoice (total = AED 530.00)
        $imp2 = $this->createImportedObligation(
            $sister2,
            '2026-10-01',
            '2026-10-31',
            'Tuition Fee - Oct-2026',
            'AED',
            26500,
            1009,
            $imp1['invoiceId']
        );

        // Void only Sister 1's October 2026 AED 265 item
        $this->actingAs($this->financeAdmin)->postJson('/challan/void', [
            'invoice_item_id' => $imp1['itemId'],
            'reason' => 'Sister 1 moved to SAR 250 plan',
        ])->assertStatus(200);

        // Shared OB invoice should still be active with AED 265.00 for Sister 2
        $sharedObInv = DB::table('invoices')->where('id', $imp1['invoiceId'])->first();
        $this->assertEquals(26500, (int) $sharedObInv->total_minor);
        $this->assertEquals('generated', $sharedObInv->status);

        // Update Sister 1 to SAR 250 and regenerate Sister 1's Oct 2026 fee
        $this->updateStudentSettingsViaHttp($sister1['studentId'], 'SAR', '250.00');

        $this->actingAs($this->financeAdmin)->postJson('/challan/recreate-voided-fee', [
            'invoice_item_id' => $imp1['itemId'],
            'reason' => 'Regenerate Sister 1 Oct 2026 in SAR 250',
        ])->assertStatus(200)->assertJsonPath('success', true);

        // Fetch Fee Collection (/challan) page for the family and inspect summaryMetrics
        $challanPage = $this->actingAs($this->financeAdmin)->get('/challan?family=' . $sister1['familyCode']);
        $challanPage->assertStatus(200);

        $summaryMetrics = $challanPage->viewData('summaryMetrics');
        $this->assertNotNull($summaryMetrics);

        // Family should now have:
        // - AED: 26500 minor (Sister 2's untouched imported AED obligation; Sister 1's voided AED 265 is excluded)
        // - SAR: 25000 minor (Sister 1's regenerated SAR obligation)
        $this->assertEquals(26500, $summaryMetrics['by_currency']['AED']['total_net_minor'] ?? 0);
        $this->assertEquals(26500, $summaryMetrics['by_currency']['AED']['total_due_minor'] ?? 0);
        $this->assertEquals(25000, $summaryMetrics['by_currency']['SAR']['total_net_minor'] ?? 0);
        $this->assertEquals(25000, $summaryMetrics['by_currency']['SAR']['total_due_minor'] ?? 0);

        // Verify Sister 2's item is still active and untouched
        $sister2Item = DB::table('invoice_items')->where('id', $imp2['itemId'])->first();
        $this->assertEquals('active', $sister2Item->status);
        $this->assertEquals('AED', $sister2Item->currency);
        $this->assertEquals(26500, (int) $sister2Item->net_minor);
    }
}
