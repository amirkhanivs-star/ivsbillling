<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FeeEligibilityService;
use App\Services\Migration\HistoricalOpeningBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase3FPartialPaymentEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private string $batchId;
    private int $campusId;
    private int $deptId;
    private int $monthlyFeeTypeId;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        Config::set('reconciliation.allow_apply', true);
        $this->batchId = (string) config('imports.active_batch_id', 'BATCH-20261025-FINAL-MYSKOOL');

        $this->campusId = (int) DB::table('campuses')->value('id');
        $this->deptId = (int) DB::table('departments')->where('code', 'school')->value('id');
        $this->monthlyFeeTypeId = (int) DB::table('fee_types')->where('code', 'monthly')->value('id');

        $this->adminUser = User::firstOrFail();
        DB::table('users')->where('id', $this->adminUser->id)->update([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $this->adminUser->refresh();
        $this->actingAs($this->adminUser);
    }

    /**
     * Helper to create family and student enrollment.
     */
    private function createStudent(string $gr, string $name, string $curr, int $monthlyMinor, string $famCode = ''): array
    {
        $famCode = $famCode ?: 'FAM-' . $gr;
        $familyId = (int) (DB::table('families')->where('family_code', $famCode)->value('id') ?? DB::table('families')->insertGetId([
            'family_code' => $famCode,
            'guardian_name' => 'Guardian of ' . $name,
            'is_provisional' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $studentId = (int) DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => $gr,
            'name' => $name,
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollmentId = (int) DB::table('enrollments')->insertGetId([
            'student_id' => $studentId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'grade_label' => 'Grade 1',
            'currency' => $curr,
            'monthly_fee_minor' => $monthlyMinor,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('familyId', 'famCode', 'studentId', 'enrollmentId');
    }

    /**
     * Test 1: AED 190 charge with AED 43 paid gives AED 147 outstanding.
     */
    public function test_01_aed_190_charge_with_aed_43_paid_gives_aed_147_outstanding(): void
    {
        $fx = $this->createStudent('TEST-6687', 'Maryam Test', 'AED', 19000);

        // Historical advance payment: AED 43 paid for Nov 2026
        DB::table('qa_staged_collections')->insert([
            'batch_id' => $this->batchId,
            'source_row' => 101,
            'slip_number' => 'SLIP-6687',
            'gr_number' => 'TEST-6687',
            'fee_particular' => 'Monthly Fee - Nov-2026',
            'currency' => 'AED',
            'amount_minor' => 4300,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Evaluate eligibility via FeeEligibilityService
        $eligSvc = app(FeeEligibilityService::class);
        $eval = $eligSvc->evaluateEligibility([
            'family_id' => $fx['familyId'],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);

        $this->assertTrue($eval['success']);
        $this->assertSame(1, $eval['summary']['total_eligible']);
        $item = $eval['items'][0];
        $this->assertTrue($item['is_eligible']);
        $this->assertSame(FeeEligibilityService::STATUS_ELIGIBLE, $item['status']);
        $this->assertSame(4300, $item['historical_paid_minor']);
        $this->assertSame(14700, $item['expected_remaining_minor']);

        // Generate fee via Create Fee HTTP endpoint
        $resp = $this->postJson('/challan/create-fee', [
            'family_id' => $fx['familyId'],
            'student_ids' => [$fx['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);

        $resp->assertOk()->assertJsonPath('success', true);

        // Verify invoice item
        $invItem = DB::table('invoice_items')->where('student_id', $fx['studentId'])->first();
        $this->assertNotNull($invItem);
        $this->assertSame(19000, (int) $invItem->amount_minor);
        $this->assertSame(19000, (int) $invItem->net_minor);
        $this->assertSame(4300, (int) $invItem->paid_minor);
        $this->assertSame('partial', $invItem->status);

        // Outstanding remaining balance = 190 - 43 = 147
        $this->assertSame(14700, (int) $invItem->net_minor - (int) $invItem->paid_minor);

        // Verify qa_historical_settlements
        $settlement = DB::table('qa_historical_settlements')->where('invoice_item_id', $invItem->id)->first();
        $this->assertNotNull($settlement);
        $this->assertSame(4300, (int) $settlement->settled_amount_minor);
        $this->assertSame(14700, (int) $settlement->remaining_obligation_minor);
        $this->assertSame('applied', $settlement->status);
    }

    /**
     * Test 2: SAR 375 charge with SAR 75 paid gives SAR 300 outstanding.
     */
    public function test_02_sar_375_charge_with_sar_75_paid_gives_sar_300_outstanding(): void
    {
        $fx = $this->createStudent('TEST-9258', 'Shawez Test', 'SAR', 37500);

        DB::table('qa_staged_dues')->insert([
            'batch_id' => $this->batchId,
            'source_row' => 201,
            'gr_number' => 'TEST-9258',
            'fee_particular' => 'Monthly Fee - Oct-2026',
            'currency' => 'SAR',
            'outstanding_amount_minor' => 37500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('qa_staged_collections')->insert([
            'batch_id' => $this->batchId,
            'source_row' => 202,
            'slip_number' => 'SLIP-9258',
            'gr_number' => 'TEST-9258',
            'fee_particular' => 'Monthly Fee - Oct-2026',
            'currency' => 'SAR',
            'amount_minor' => 7500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Historical obligation with due and payment gives partial settlement
        $hobSvc = app(HistoricalOpeningBalanceService::class);
        $map = $hobSvc->buildHistoricalObligationMap(['TEST-9258']);
        $chk = HistoricalOpeningBalanceService::checkHistoricalObligation($map, 'TEST-9258', 'monthly', '2026-10-01', 'SAR', null, 37500);

        $this->assertNotNull($chk);
        $this->assertSame(7500, (int) $chk['paid_minor']);
        $this->assertSame(37500, (int) $chk['outstanding_minor']);
        $this->assertSame('partial_same_currency', $chk['status']);
    }

    /**
     * Test 3: Imported payment already recorded does not create duplicate credit.
     */
    public function test_03_imported_payment_already_recorded_does_not_create_duplicate_credit(): void
    {
        $fx = $this->createStudent('TEST-8844', 'Hasan Test', 'SAR', 24400);

        DB::table('qa_staged_collections')->insert([
            'batch_id' => $this->batchId,
            'source_row' => 301,
            'slip_number' => 'SLIP-8844',
            'gr_number' => 'TEST-8844',
            'fee_particular' => 'Monthly Fee - Nov-2026',
            'currency' => 'SAR',
            'amount_minor' => 12100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // First creation
        $this->postJson('/challan/create-fee', [
            'family_id' => $fx['familyId'],
            'student_ids' => [$fx['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ])->assertOk();

        // Check invoice item has paid_minor = 12100
        $items = DB::table('invoice_items')->where('student_id', $fx['studentId'])->get();
        $this->assertCount(1, $items);
        $this->assertSame(12100, (int) $items[0]->paid_minor);
        $this->assertSame(24400, (int) $items[0]->net_minor);

        // Second creation attempt for same month MUST be blocked as already invoiced, not duplicated!
        $this->postJson('/challan/create-fee', [
            'family_id' => $fx['familyId'],
            'student_ids' => [$fx['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ])->assertStatus(422);

        $this->assertCount(1, DB::table('invoice_items')->where('student_id', $fx['studentId'])->get());
    }

    /**
     * Test 4: Existing correct partial invoice remains collectible.
     */
    public function test_04_existing_correct_partial_invoice_remains_collectible(): void
    {
        $fx = $this->createStudent('TEST-COLLECT', 'Collect Test', 'SAR', 20000);

        $runId = (int) DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'monthly',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'status' => 'generated',
            'created_by' => $this->adminUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Pre-create invoice with 200 gross, 50 paid, 150 outstanding
        $invId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $runId,
            'family_id' => $fx['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'invoice_number' => 'TEST-INV-COLLECT-01',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 20000,
            'paid_minor' => 5000,
            'status' => 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $fx['studentId'],
            'enrollment_id' => $fx['enrollmentId'],
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee - Oct-2026',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'SAR',
            'amount_minor' => 20000,
            'discount_minor' => 0,
            'net_minor' => 20000,
            'paid_minor' => 5000,
            'status' => 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Visit challan collection page for this family
        $resp = $this->get('/challan?family=' . urlencode($fx['famCode']));
        $resp->assertOk();
        $resp->assertSee('SAR 150.00');

        // Post fee collection for the remaining 150.00 via /challan/receive
        $collectResp = $this->post('/challan/receive', [
            'family_id' => $fx['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'receiving_amount' => '150.00',
            'receiving_date' => '2026-10-28',
            'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
        ]);

        $collectResp->assertRedirect();

        // Verify item is now fully paid
        $updatedItem = DB::table('invoice_items')->where('id', $itemId)->first();
        $this->assertSame(20000, (int) $updatedItem->paid_minor);
        $this->assertSame('paid', $updatedItem->status);

        $updatedInv = DB::table('invoices')->where('id', $invId)->first();
        $this->assertSame(20000, (int) $updatedInv->paid_minor);
        $this->assertSame('paid', $updatedInv->status);
    }

    /**
     * Test 5: An incomplete imported obligation is safely identified and corrected.
     */
    public function test_05_incomplete_imported_obligation_is_safely_identified_and_corrected(): void
    {
        $fx = $this->createStudent('TEST-INCOMPLETE', 'Incomplete Test', 'AED', 19000);

        // An opening balance invoice created an item with only 43.00 instead of 190.00
        $obRunId = DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'opening_balance',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-25',
            'status' => 'generated',
            'created_by' => $this->adminUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $obRunId,
            'family_id' => $fx['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'AED',
            'invoice_number' => 'SCH-OB-AED-INCOMPLETE',
            'issued_on' => '2026-11-01',
            'due_date' => '2026-11-25',
            'total_minor' => 4300,
            'paid_minor' => 4300,
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $fx['studentId'],
            'enrollment_id' => $fx['enrollmentId'],
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee - Nov-2026',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'currency' => 'AED',
            'amount_minor' => 4300,
            'discount_minor' => 0,
            'net_minor' => 4300,
            'paid_minor' => 4300,
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Evaluate eligibility: must identify as Incomplete imported obligation!
        $eligSvc = app(FeeEligibilityService::class);
        $eval = $eligSvc->evaluateEligibility([
            'family_id' => $fx['familyId'],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);

        $this->assertSame(1, $eval['summary']['status_counts'][FeeEligibilityService::STATUS_INCOMPLETE_OBLIGATION]);
        $this->assertSame('incomplete_obligation', $eval['items'][0]['status_code']);
        $this->assertStringContainsString('Incomplete imported obligation', $eval['items'][0]['reason']);

        // Safely correct the incomplete obligation via correctIncompleteObligation
        $res = HistoricalOpeningBalanceService::correctIncompleteObligation(DB::connection(), $itemId, $this->adminUser->id, 'Corrected truncated imported obligation to approved fee');

        $this->assertTrue($res['success']);
        $this->assertSame(19000, $res['new_net_minor']);
        $this->assertSame(4300, $res['paid_minor']);
        $this->assertSame(14700, $res['remaining_outstanding_minor']);
        $this->assertSame('partial', $res['status']);

        // Check updated invoice item
        $correctedItem = DB::table('invoice_items')->where('id', $itemId)->first();
        $this->assertSame(19000, (int) $correctedItem->amount_minor);
        $this->assertSame(19000, (int) $correctedItem->net_minor);
        $this->assertSame(4300, (int) $correctedItem->paid_minor);
        $this->assertSame('partial', $correctedItem->status);
    }

    /**
     * Test 6: Missing genuine obligation can be generated with partial credit.
     */
    public function test_06_missing_genuine_obligation_can_be_generated(): void
    {
        $fx = $this->createStudent('TEST-9596', 'Usman Test', 'SAR', 22000);

        // Advance payment of SAR 143 in MySkool for Nov 2026
        DB::table('qa_staged_collections')->insert([
            'batch_id' => $this->batchId,
            'source_row' => 601,
            'slip_number' => 'SLIP-9596',
            'gr_number' => 'TEST-9596',
            'fee_particular' => 'Monthly Fee - Nov-2026',
            'currency' => 'SAR',
            'amount_minor' => 14300,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resp = $this->postJson('/challan/create-fee', [
            'family_id' => $fx['familyId'],
            'student_ids' => [$fx['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);

        $resp->assertOk()->assertJsonPath('success', true);

        $item = DB::table('invoice_items')->where('student_id', $fx['studentId'])->first();
        $this->assertSame(22000, (int) $item->amount_minor);
        $this->assertSame(14300, (int) $item->paid_minor);
        $this->assertSame(7700, (int) $item->net_minor - (int) $item->paid_minor);
        $this->assertSame('partial', $item->status);
    }

    /**
     * Test 7: Repeated invoice generation cannot create duplicate debt.
     */
    public function test_07_repeated_invoice_generation_cannot_create_duplicate_debt(): void
    {
        $fx = $this->createStudent('TEST-NODUP', 'NoDup Test', 'AED', 18500);

        DB::table('qa_staged_collections')->insert([
            'batch_id' => $this->batchId,
            'source_row' => 701,
            'slip_number' => 'SLIP-9840',
            'gr_number' => 'TEST-NODUP',
            'fee_particular' => 'Monthly Fee - Nov-2026',
            'currency' => 'AED',
            'amount_minor' => 11200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempt 1: succeeds
        $resp1 = $this->postJson('/challan/create-fee', [
            'family_id' => $fx['familyId'],
            'student_ids' => [$fx['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);
        $resp1->assertOk();

        // Attempt 2: fails gracefully without creating duplicate item
        $resp2 = $this->postJson('/challan/create-fee', [
            'family_id' => $fx['familyId'],
            'student_ids' => [$fx['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ]);
        $resp2->assertStatus(422);

        $this->assertSame(1, DB::table('invoice_items')->where('student_id', $fx['studentId'])->count());
    }

    /**
     * Test 8: Multiple siblings and currencies remain isolated.
     */
    public function test_08_multiple_siblings_and_currencies_remain_isolated(): void
    {
        $familyId = (int) DB::table('families')->insertGetId([
            'family_code' => 'FAM-MULTI-CURR',
            'guardian_name' => 'Multi Parent',
            'is_provisional' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sibling 1 in AED
        $s1 = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'SIB-AED',
            'name' => 'Sibling AED',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enrollments')->insert([
            'student_id' => $s1,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'grade_label' => 'Grade 1',
            'currency' => 'AED',
            'monthly_fee_minor' => 19000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sibling 2 in SAR
        $s2 = DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => 'SIB-SAR',
            'name' => 'Sibling SAR',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enrollments')->insert([
            'student_id' => $s2,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'grade_label' => 'Grade 2',
            'currency' => 'SAR',
            'monthly_fee_minor' => 25000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Bill both siblings for Nov 2026
        $this->postJson('/challan/create-fee', [
            'family_id' => $familyId,
            'student_ids' => [$s1, $s2],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ])->assertOk();

        // Must produce separate invoices for separate currencies
        $invoices = DB::table('invoices')->where('family_id', $familyId)->get();
        $this->assertCount(2, $invoices);
        $currs = $invoices->pluck('currency')->all();
        $this->assertContains('AED', $currs);
        $this->assertContains('SAR', $currs);
    }

    /**
     * Test 9: Original imported MySkool receipts remain unchanged.
     */
    public function test_09_original_imported_myskool_receipts_remain_unchanged(): void
    {
        $fx = $this->createStudent('TEST-ORIG', 'Orig Test', 'SAR', 23000);

        $colId = DB::table('qa_staged_collections')->insertGetId([
            'batch_id' => $this->batchId,
            'source_row' => 901,
            'slip_number' => 'SLIP-9896',
            'gr_number' => 'TEST-ORIG',
            'fee_particular' => 'Monthly Fee - Nov-2026',
            'currency' => 'SAR',
            'amount_minor' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/challan/create-fee', [
            'family_id' => $fx['familyId'],
            'student_ids' => [$fx['studentId']],
            'fee_type_ids' => [$this->monthlyFeeTypeId],
            'months' => [11],
            'year' => 2026,
        ])->assertOk();

        // Verify qa_staged_collections row is completely untouched
        $col = DB::table('qa_staged_collections')->where('id', $colId)->first();
        $this->assertSame(10000, (int) $col->amount_minor);
        $this->assertSame('SLIP-9896', (string) $col->slip_number);
        $this->assertSame('Monthly Fee - Nov-2026', (string) $col->fee_particular);
        $this->assertSame('SAR', (string) $col->currency);
    }

    /**
     * Test 10: Existing dispute and restoration flows remain working.
     */
    public function test_10_existing_dispute_and_restoration_flows_remain_working(): void
    {
        $fx = $this->createStudent('TEST-DISP', 'Disp Test', 'AED', 19000);

        $colId = DB::table('qa_staged_collections')->insertGetId([
            'batch_id' => $this->batchId,
            'source_row' => 1001,
            'slip_number' => 'SLIP-DISP-01',
            'gr_number' => 'TEST-DISP',
            'fee_particular' => 'Monthly Fee - Nov-2026',
            'currency' => 'AED',
            'amount_minor' => 4300,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $obRunId = (int) DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'opening_balance',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'due_date' => '2026-11-25',
            'status' => 'generated',
            'created_by' => $this->adminUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invId = DB::table('invoices')->insertGetId([
            'billing_run_id' => $obRunId,
            'family_id' => $fx['familyId'],
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'AED',
            'invoice_number' => 'SCH-OB-AED-DISP',
            'issued_on' => '2026-11-01',
            'due_date' => '2026-11-25',
            'total_minor' => 19000,
            'paid_minor' => 4300,
            'status' => 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invId,
            'student_id' => $fx['studentId'],
            'enrollment_id' => $fx['enrollmentId'],
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee - Nov-2026',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'currency' => 'AED',
            'amount_minor' => 19000,
            'discount_minor' => 0,
            'net_minor' => 19000,
            'paid_minor' => 4300,
            'status' => 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reconId = 'HREC-TEST-DISP';
        $settlementId = DB::table('qa_historical_settlements')->insertGetId([
            'reconciliation_id' => $reconId,
            'batch_id' => $this->batchId,
            'cutoff_date' => '2026-10-22',
            'invoice_item_id' => $itemId,
            'active_invoice_item_id' => $itemId,
            'invoice_id' => $invId,
            'student_id' => $fx['studentId'],
            'family_id' => $fx['familyId'],
            'student_gr' => 'TEST-DISP',
            'family_code' => $fx['famCode'],
            'currency' => 'AED',
            'fee_description' => 'Monthly Fee - Nov-2026',
            'period_key' => 'monthly:2026-11-01..2026-11-30',
            'original_obligation_minor' => 19000,
            'settled_amount_minor' => 4300,
            'remaining_obligation_minor' => 14700,
            'unmatched_overpayment_minor' => 0,
            'previous_item_paid_minor' => 0,
            'new_item_paid_minor' => 4300,
            'previous_item_status' => 'active',
            'new_item_status' => 'partial',
            'previous_invoice_paid_minor' => 0,
            'new_invoice_paid_minor' => 4300,
            'previous_invoice_status' => 'unpaid',
            'new_invoice_status' => 'partial',
            'qa_staged_due_id' => null,
            'active_primary_collection_id' => $colId,
            'qa_staged_collection_ids' => json_encode([$colId]),
            'myskool_slip_numbers' => 'SLIP-DISP-01',
            'reconciliation_classification' => 'PARTIAL_SETTLEMENT',
            'guard_class' => 'CANDIDATE_PARTIAL_SETTLEMENT',
            'status' => 'applied',
            'applied_by' => $this->adminUser->id,
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('qa_historical_settlement_collections')->insert([
            'settlement_id' => $settlementId,
            'reconciliation_id' => $reconId,
            'invoice_item_id' => $itemId,
            'qa_staged_collection_id' => $colId,
            'active_staged_collection_id' => $colId,
            'status' => 'applied',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Dispute creation works
        $dispResp = $this->postJson('/challan/dispute-myskool-payment', [
            'qa_staged_collection_id' => $colId,
            'reason' => 'Disputing payment entry for test',
        ]);
        $dispResp->assertOk()->assertJsonPath('success', true);
        $this->assertTrue(DB::table('myskool_disputes')->where('student_gr', 'TEST-DISP')->exists());

        // Restoration works
        $restoreResp = $this->postJson('/challan/restore-myskool-payment', [
            'qa_staged_collection_id' => $colId,
            'restoration_mode' => 'full',
            'restore_amount' => 43.00,
            'reason' => 'Testing restoration',
        ]);
        $restoreResp->assertOk()->assertJsonPath('success', true);

        // After full restoration, collection is reflected as reversed in corrections
        $lastCorr = DB::table('qa_myskool_payment_corrections')->where('qa_staged_collection_id', $colId)->orderByDesc('id')->first();
        $this->assertSame('restore_payment', $lastCorr->action_type);
        $this->assertSame('reversed', $lastCorr->resulting_status);
        $this->assertSame(4300, (int) $lastCorr->reversed_amount_minor);
    }
}
