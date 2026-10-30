<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FeeHistoryService;
use App\Services\Migration\HistoricalPaymentReconciliationService;
use App\Services\Migration\MySkoolPaymentCorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase3MySkoolPaymentRestorationTest extends TestCase
{
    use RefreshDatabase;

    private string $batchId = 'BATCH-TEST-PHASE3-RESTORE-20261030';
    private int $campusId;
    private int $deptId;
    private int $monthlyFeeTypeId;
    private int $obRunId;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        Config::set('reconciliation.allow_apply', true);
        Config::set('imports.active_batch_id', $this->batchId);

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

        $this->obRunId = (int) DB::table('billing_runs')->insertGetId([
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'frequency' => 'opening_balance',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_date' => '2026-10-25',
            'status' => 'generated',
            'created_by' => $this->adminUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Helper to seed fictional data modeled on Receipt #24083 (04 August 2026, Total SAR 980.00):
     * - GR 8239, Syed Arham Anis, July 2026: SAR 265
     * - GR 8239, Syed Arham Anis, June 2026: SAR 265
     * - GR 8240, Syeda Haniya Anis, June 2026: SAR 225
     * - GR 8240, Syeda Haniya Anis, July 2026: SAR 225
     */
    private function seedReceipt24083Fixture(bool $reconcileArhamJuly = true): array
    {
        $familyId = (int) DB::table('families')->insertGetId([
            'family_code' => 'FAM-24083',
            'guardian_name' => 'Syed Anis',
            'is_provisional' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sibling 1: GR 8239 (Syed Arham Anis, SAR 265)
        $arhamId = (int) DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => '8239',
            'name' => 'Syed Arham Anis',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $arhamEnrollmentId = (int) DB::table('enrollments')->insertGetId([
            'student_id' => $arhamId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'grade_label' => 'Grade VII',
            'currency' => 'SAR',
            'monthly_fee_minor' => 26500,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sibling 2: GR 8240 (Syeda Haniya Anis, SAR 225)
        $haniyaId = (int) DB::table('students')->insertGetId([
            'family_id' => $familyId,
            'gr_number' => '8240',
            'name' => 'Syeda Haniya Anis',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $haniyaEnrollmentId = (int) DB::table('enrollments')->insertGetId([
            'student_id' => $haniyaId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'grade_label' => 'Grade IV',
            'currency' => 'SAR',
            'monthly_fee_minor' => 22500,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create SAR family invoice with 4 reconciled historical obligations (unless $reconcileArhamJuly = false)
        $sarInvoiceId = (int) DB::table('invoices')->insertGetId([
            'billing_run_id' => $this->obRunId,
            'family_id' => $familyId,
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'SAR',
            'invoice_number' => 'SCH-OB-24083-SAR',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => $reconcileArhamJuly ? 98000 : 71500,
            'paid_minor' => $reconcileArhamJuly ? 98000 : 71500,
            'previous_dues_snapshot_minor' => 0,
            'status' => 'paid',
            'approved_to_send' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4 Staged collection rows on Slip #24083
        $colArhamJul = (int) DB::table('qa_staged_collections')->insertGetId([
            'batch_id' => $this->batchId,
            'source_row' => 2401,
            'slip_number' => '24083',
            'gr_number' => '8239',
            'student_name' => 'Syed Arham Anis',
            'family_code' => 'FAM-24083',
            'fee_particular' => 'Monthly Fee - Jul-2026',
            'payment_date_raw' => '04 Aug 2026',
            'payment_date_parsed' => '2026-08-04',
            'payment_method' => 'Online Transfer',
            'currency' => 'SAR',
            'amount' => 265.00,
            'amount_minor' => 26500,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $colArhamJun = (int) DB::table('qa_staged_collections')->insertGetId([
            'batch_id' => $this->batchId,
            'source_row' => 2402,
            'slip_number' => '24083',
            'gr_number' => '8239',
            'student_name' => 'Syed Arham Anis',
            'family_code' => 'FAM-24083',
            'fee_particular' => 'Monthly Fee - Jun-2026',
            'payment_date_raw' => '04 Aug 2026',
            'payment_date_parsed' => '2026-08-04',
            'payment_method' => 'Online Transfer',
            'currency' => 'SAR',
            'amount' => 265.00,
            'amount_minor' => 26500,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $colHaniyaJun = (int) DB::table('qa_staged_collections')->insertGetId([
            'batch_id' => $this->batchId,
            'source_row' => 2403,
            'slip_number' => '24083',
            'gr_number' => '8240',
            'student_name' => 'Syeda Haniya Anis',
            'family_code' => 'FAM-24083',
            'fee_particular' => 'Monthly Fee - Jun-2026',
            'payment_date_raw' => '04 Aug 2026',
            'payment_date_parsed' => '2026-08-04',
            'payment_method' => 'Online Transfer',
            'currency' => 'SAR',
            'amount' => 225.00,
            'amount_minor' => 22500,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $colHaniyaJul = (int) DB::table('qa_staged_collections')->insertGetId([
            'batch_id' => $this->batchId,
            'source_row' => 2404,
            'slip_number' => '24083',
            'gr_number' => '8240',
            'student_name' => 'Syeda Haniya Anis',
            'family_code' => 'FAM-24083',
            'fee_particular' => 'Monthly Fee - Jul-2026',
            'payment_date_raw' => '04 Aug 2026',
            'payment_date_parsed' => '2026-08-04',
            'payment_method' => 'Online Transfer',
            'currency' => 'SAR',
            'amount' => 225.00,
            'amount_minor' => 22500,
            'is_currency_unresolved' => false,
            'has_currency_conflict' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemArhamJul = null;
        if ($reconcileArhamJuly) {
            $itemArhamJul = $this->createReconciledItemAndSettlement(
                $sarInvoiceId,
                $familyId,
                $arhamId,
                $arhamEnrollmentId,
                '8239',
                'Monthly Fee - Jul-2026',
                'monthly:2026-07-01..2026-07-31',
                '2026-07-01',
                '2026-07-31',
                'SAR',
                26500,
                $colArhamJul
            );
        }

        $itemArhamJun = $this->createReconciledItemAndSettlement(
            $sarInvoiceId,
            $familyId,
            $arhamId,
            $arhamEnrollmentId,
            '8239',
            'Monthly Fee - Jun-2026',
            'monthly:2026-06-01..2026-06-30',
            '2026-06-01',
            '2026-06-30',
            'SAR',
            26500,
            $colArhamJun
        );

        $itemHaniyaJun = $this->createReconciledItemAndSettlement(
            $sarInvoiceId,
            $familyId,
            $haniyaId,
            $haniyaEnrollmentId,
            '8240',
            'Monthly Fee - Jun-2026',
            'monthly:2026-06-01..2026-06-30',
            '2026-06-01',
            '2026-06-30',
            'SAR',
            22500,
            $colHaniyaJun
        );

        $itemHaniyaJul = $this->createReconciledItemAndSettlement(
            $sarInvoiceId,
            $familyId,
            $haniyaId,
            $haniyaEnrollmentId,
            '8240',
            'Monthly Fee - Jul-2026',
            'monthly:2026-07-01..2026-07-31',
            '2026-07-01',
            '2026-07-31',
            'SAR',
            22500,
            $colHaniyaJul
        );

        return [
            'family_id' => $familyId,
            'arham_id' => $arhamId,
            'arham_enrollment_id' => $arhamEnrollmentId,
            'haniya_id' => $haniyaId,
            'haniya_enrollment_id' => $haniyaEnrollmentId,
            'sar_invoice_id' => $sarInvoiceId,
            'col_arham_jul' => $colArhamJul,
            'col_arham_jun' => $colArhamJun,
            'col_haniya_jun' => $colHaniyaJun,
            'col_haniya_jul' => $colHaniyaJul,
            'item_arham_jul' => $itemArhamJul,
            'item_arham_jun' => $itemArhamJun,
            'item_haniya_jun' => $itemHaniyaJun,
            'item_haniya_jul' => $itemHaniyaJul,
        ];
    }

    private function createReconciledItemAndSettlement(
        int $invoiceId,
        int $familyId,
        int $studentId,
        int $enrollmentId,
        string $grNumber,
        string $description,
        string $periodKey,
        string $periodStart,
        string $periodEnd,
        string $currency,
        int $amountMinor,
        int $stagedCollectionId
    ): int {
        $itemId = (int) DB::table('invoice_items')->insertGetId([
            'invoice_id' => $invoiceId,
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => $description,
            'period_key' => $periodKey,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => $amountMinor,
            'paid_minor' => $amountMinor,
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stagedDueId = (int) DB::table('qa_staged_dues')->insertGetId([
            'batch_id' => $this->batchId,
            'source_row' => 9000 + $stagedCollectionId,
            'gr_number' => $grNumber,
            'student_name' => "Student {$grNumber}",
            'family_code_final' => 'FAM-24083',
            'fee_particular' => $description,
            'currency' => $currency,
            'outstanding_amount' => 0,
            'outstanding_amount_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lineageId = (int) DB::table('qa_opening_balance_lineage')->insertGetId([
            'batch_id' => $this->batchId,
            'qa_staged_due_id' => $stagedDueId,
            'source_row' => 9000 + $stagedCollectionId,
            'gr_number' => $grNumber,
            'invoice_id' => $invoiceId,
            'invoice_item_id' => $itemId,
            'family_id' => $familyId,
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'period_key' => $periodKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reconId = 'HREC-' . $stagedCollectionId;
        $settlementId = (int) DB::table('qa_historical_settlements')->insertGetId([
            'reconciliation_id' => $reconId,
            'batch_id' => $this->batchId,
            'cutoff_date' => '2026-10-22',
            'invoice_item_id' => $itemId,
            'active_invoice_item_id' => $itemId,
            'invoice_id' => $invoiceId,
            'student_id' => $studentId,
            'family_id' => $familyId,
            'student_gr' => $grNumber,
            'family_code' => 'FAM-24083',
            'currency' => $currency,
            'fee_description' => $description,
            'period_key' => $periodKey,
            'original_obligation_minor' => $amountMinor,
            'settled_amount_minor' => $amountMinor,
            'remaining_obligation_minor' => 0,
            'unmatched_overpayment_minor' => 0,
            'previous_item_paid_minor' => 0,
            'new_item_paid_minor' => $amountMinor,
            'previous_item_status' => 'active',
            'new_item_status' => 'paid',
            'previous_invoice_paid_minor' => 0,
            'new_invoice_paid_minor' => $amountMinor,
            'previous_invoice_status' => 'unpaid',
            'new_invoice_status' => 'paid',
            'qa_staged_due_id' => $stagedDueId,
            'active_primary_collection_id' => $stagedCollectionId,
            'qa_staged_collection_ids' => json_encode([$stagedCollectionId]),
            'myskool_slip_numbers' => '24083',
            'reconciliation_classification' => 'A1_SAFE_EXACT',
            'guard_class' => 'A1_SAFE_EXACT',
            'status' => 'applied',
            'applied_by' => $this->adminUser->id,
            'applied_at' => now(),
            'affected_snapshots_json' => null,
            'notes' => 'Reconciled historical payment.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('qa_historical_settlement_collections')->insert([
            'settlement_id' => $settlementId,
            'reconciliation_id' => $reconId,
            'invoice_item_id' => $itemId,
            'qa_staged_collection_id' => $stagedCollectionId,
            'active_staged_collection_id' => $stagedCollectionId,
            'status' => 'applied',
            'applied_by' => $this->adminUser->id,
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $itemId;
    }

    /**
     * Test 1, 5, 6, 7: Full restoration of one SAR 265 payment via HTTP endpoint,
     * while the other three receipt allocations remain unchanged, family/student balances
     * update once, and original MySkool receipt + audit history remain intact.
     */
    public function test_full_restoration_of_one_sar_265_payment_keeps_other_three_allocations_unchanged(): void
    {
        $fx = $this->seedReceipt24083Fixture(true);

        // 1. Inspect via HTTP endpoint used by the receipt modal
        $inspectResp = $this->getJson('/challan/inspect-myskool-payment/' . $fx['col_arham_jul']);
        $inspectResp->assertOk()
            ->assertJsonPath('exists', true)
            ->assertJsonPath('slip_number', '24083')
            ->assertJsonPath('student_gr', '8239')
            ->assertJsonPath('currency', 'SAR')
            ->assertJsonPath('original_paid_minor', 26500)
            ->assertJsonPath('previously_reversed_minor', 0)
            ->assertJsonPath('remaining_reversible_minor', 26500)
            ->assertJsonPath('is_credited_in_ledger', true)
            ->assertJsonPath('allocation_status', 'paid');

        // 2. Execute Full Restoration of SAR 265 via POST /challan/restore-myskool-payment
        $restoreResp = $this->postJson('/challan/restore-myskool-payment', [
            'qa_staged_collection_id' => $fx['col_arham_jul'],
            'restoration_mode' => 'full',
            'restore_amount' => 265.00,
            'reason' => 'Full restoration of July 2026 SAR 265 allocation to outstanding dues.',
            'idempotency_key' => 'test-full-restore-24083-jul',
        ]);

        $restoreResp->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('restoration_mode', 'full')
            ->assertJsonPath('reversed_amount_minor', 26500)
            ->assertJsonPath('cumulative_reversed_minor', 26500)
            ->assertJsonPath('remaining_paid_minor', 0)
            ->assertJsonPath('resulting_status', 'reversed');

        // 3. Verify Arham July item is now unpaid (SAR 265 due)
        $julItem = DB::table('invoice_items')->where('id', $fx['item_arham_jul'])->first();
        $this->assertSame(26500, (int) $julItem->net_minor);
        $this->assertSame(0, (int) $julItem->paid_minor);
        $this->assertSame('active', $julItem->status);

        // 4. Verify the other 3 allocations on Receipt #24083 remain 100% paid and unchanged
        foreach (['item_arham_jun' => 26500, 'item_haniya_jun' => 22500, 'item_haniya_jul' => 22500] as $key => $expectedMinor) {
            $otherItem = DB::table('invoice_items')->where('id', $fx[$key])->first();
            $this->assertSame($expectedMinor, (int) $otherItem->paid_minor, "{$key} must remain paid");
            $this->assertSame('paid', $otherItem->status, "{$key} status must remain paid");
        }

        // 5. Verify parent invoice balance updated once (Total SAR 980, Paid SAR 715, Balance SAR 265, Status partial)
        $invoice = DB::table('invoices')->where('id', $fx['sar_invoice_id'])->first();
        $this->assertSame(98000, (int) $invoice->total_minor);
        $this->assertSame(71500, (int) $invoice->paid_minor);
        $this->assertSame('partial', $invoice->status);

        // 6. Verify original MySkool receipt rows in qa_staged_collections are 100% preserved
        $this->assertSame(4, DB::table('qa_staged_collections')->where('slip_number', '24083')->count());
        $origRow = DB::table('qa_staged_collections')->where('id', $fx['col_arham_jul'])->first();
        $this->assertSame(26500, (int) $origRow->amount_minor);

        // 7. Verify FeeHistoryService receipt and allocation statuses + /challan HTTP response
        /** @var FeeHistoryService $feeHistory */
        $feeHistory = app(FeeHistoryService::class);
        $paginator = $feeHistory->getFamilyFeeHistory($fx['family_id'], ['search' => '24083'], $this->adminUser);
        $receipt = collect($paginator->items())->firstWhere('slip_number', '24083');
        $this->assertNotNull($receipt);
        $this->assertSame(98000, (int) $receipt->original_amount_minor);
        $this->assertSame(26500, (int) $receipt->reversed_amount_minor);
        $this->assertSame(71500, (int) $receipt->effective_amount_minor);
        $this->assertSame(71500, (int) $receipt->amount_minor); // Active paid total reflects remaining effective paid
        $this->assertTrue((bool) $receipt->is_partially_reversed);
        $this->assertFalse((bool) $receipt->is_reversed);

        $challanResp = $this->get('/challan?family=FAM-24083');
        $challanResp->assertOk()
            ->assertSee('Individual MySkool Payment Allocation Restoration')
            ->assertSee('24083');
    }

    /**
     * Test 2, 3, 4: Partial restoration of SAR 100 from SAR 265 leaves remaining paid balance SAR 165,
     * blocks a second partial restoration exceeding SAR 165, and allows restoring up to SAR 165.
     */
    public function test_partial_restoration_of_sar_100_leaves_sar_165_and_enforces_remaining_cap(): void
    {
        $fx = $this->seedReceipt24083Fixture(true);

        // Step A: Partial restoration of SAR 100.00 from SAR 265.00
        $part1Resp = $this->postJson('/challan/restore-myskool-payment', [
            'qa_staged_collection_id' => $fx['col_arham_jul'],
            'restoration_mode' => 'partial',
            'restore_amount' => 100.00,
            'reason' => 'Partial restoration of SAR 100 from July 2026 fee.',
            'idempotency_key' => 'test-partial-100-key',
        ]);

        $part1Resp->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('restoration_mode', 'partial')
            ->assertJsonPath('reversed_amount_minor', 10000)
            ->assertJsonPath('cumulative_reversed_minor', 10000)
            ->assertJsonPath('remaining_paid_minor', 16500)
            ->assertJsonPath('resulting_status', 'partially_reversed');

        // Verify remaining paid balance is SAR 165 (16500 minor) and outstanding due is SAR 100 (10000 minor)
        $julItem = DB::table('invoice_items')->where('id', $fx['item_arham_jul'])->first();
        $this->assertSame(16500, (int) $julItem->paid_minor);
        $this->assertSame(10000, (int) $julItem->net_minor - (int) $julItem->paid_minor);
        $this->assertSame('partial', $julItem->status);

        $inspectAfterPart1 = $this->getJson('/challan/inspect-myskool-payment/' . $fx['col_arham_jul']);
        $inspectAfterPart1->assertOk()
            ->assertJsonPath('original_paid_minor', 26500)
            ->assertJsonPath('previously_reversed_minor', 10000)
            ->assertJsonPath('remaining_reversible_minor', 16500)
            ->assertJsonPath('effective_paid_minor', 16500)
            ->assertJsonPath('allocation_status', 'partially_reversed')
            ->assertJsonPath('allocation_status_label', 'Partially Reversed');

        // Step B: Attempting a second partial restoration of SAR 170 (> SAR 165 remaining) MUST fail with 422
        $exceedResp = $this->postJson('/challan/restore-myskool-payment', [
            'qa_staged_collection_id' => $fx['col_arham_jul'],
            'restoration_mode' => 'partial',
            'restore_amount' => 170.00,
            'reason' => 'Attempt to restore more than remaining SAR 165.',
            'idempotency_key' => 'test-partial-exceed-170',
        ]);
        $exceedResp->assertStatus(422);

        // Verify ledger balances did not change after rejected request
        $julItemAfterExceed = DB::table('invoice_items')->where('id', $fx['item_arham_jul'])->first();
        $this->assertSame(16500, (int) $julItemAfterExceed->paid_minor);

        // Step C: Second restoration of the remaining SAR 165 succeeds and transitions allocation to Fully Reversed
        $part2Resp = $this->postJson('/challan/restore-myskool-payment', [
            'qa_staged_collection_id' => $fx['col_arham_jul'],
            'restoration_mode' => 'partial',
            'restore_amount' => 165.00,
            'reason' => 'Restore remaining SAR 165 balance.',
            'idempotency_key' => 'test-partial-165-key',
        ]);

        $part2Resp->assertOk()
            ->assertJsonPath('reversed_amount_minor', 16500)
            ->assertJsonPath('cumulative_reversed_minor', 26500)
            ->assertJsonPath('remaining_paid_minor', 0)
            ->assertJsonPath('resulting_status', 'reversed');

        $julItemFinal = DB::table('invoice_items')->where('id', $fx['item_arham_jul'])->first();
        $this->assertSame(0, (int) $julItemFinal->paid_minor);
        $this->assertSame('active', $julItemFinal->status);
    }

    /**
     * Test 8: Repeating the same restoration (idempotency replay or post-full-restoration repeat) cannot double-charge.
     */
    public function test_repeating_same_restoration_cannot_double_charge(): void
    {
        $fx = $this->seedReceipt24083Fixture(true);

        $payload = [
            'qa_staged_collection_id' => $fx['col_arham_jul'],
            'restoration_mode' => 'partial',
            'restore_amount' => 100.00,
            'reason' => 'Partial restoration idempotency check.',
            'idempotency_key' => 'idemp-unique-key-24083-100',
        ];

        $first = $this->postJson('/challan/restore-myskool-payment', $payload);
        $first->assertOk()->assertJsonPath('remaining_paid_minor', 16500);

        // Replay exact same request with identical idempotency_key is blocked by idempotency protection (422)
        $replay = $this->postJson('/challan/restore-myskool-payment', $payload);
        $replay->assertStatus(422);

        // Verify only 10000 minor was deducted once (paid_minor is 16500, NOT 6500)
        $julItem = DB::table('invoice_items')->where('id', $fx['item_arham_jul'])->first();
        $this->assertSame(16500, (int) $julItem->paid_minor);
        $this->assertSame(1, DB::table('qa_myskool_payment_corrections')->where('qa_staged_collection_id', $fx['col_arham_jul'])->count());
    }

    /**
     * Test 9: Unreconciled historical records display "Requires Reconciliation" and cannot create artificial debt;
     * reconciling lineage first posts 0 unpaid debt and then permits safe restoration.
     */
    public function test_unreconciled_historical_record_blocks_direct_restoration_until_reconciled(): void
    {
        $fx = $this->seedReceipt24083Fixture(false); // Arham July is unreconciled

        // 1. Inspect unreconciled row
        $inspect = $this->getJson('/challan/inspect-myskool-payment/' . $fx['col_arham_jul']);
        $inspect->assertOk()
            ->assertJsonPath('is_credited_in_ledger', false)
            ->assertJsonPath('requires_reconciliation', true)
            ->assertJsonPath('can_reconcile_lineage', true)
            ->assertJsonPath('allocation_status', 'requires_reconciliation')
            ->assertJsonPath('allocation_status_label', 'Requires Reconciliation');

        // 2. Attempting balance-changing restoration on unreconciled row MUST fail without creating debt
        $blockedResp = $this->postJson('/challan/restore-myskool-payment', [
            'qa_staged_collection_id' => $fx['col_arham_jul'],
            'restoration_mode' => 'full',
            'restore_amount' => 265.00,
            'reason' => 'Attempt to restore unreconciled payment.',
        ]);
        $blockedResp->assertStatus(422);

        // Verify invoice balance is still 0 (no artificial debt created)
        $invBefore = DB::table('invoices')->where('id', $fx['sar_invoice_id'])->first();
        $this->assertSame(0, (int) $invBefore->total_minor - (int) $invBefore->paid_minor);

        // 3. Reconcile lineage first via POST /challan/correct-myskool-payment
        $reconcileResp = $this->postJson('/challan/correct-myskool-payment', [
            'qa_staged_collection_id' => $fx['col_arham_jul'],
            'action_type' => MySkoolPaymentCorrectionService::ACTION_RECONCILE_LINEAGE,
            'reason' => 'Reconcile missing July 2026 historical obligation and settlement lineage.',
        ]);
        $reconcileResp->assertOk()->assertJsonPath('success', true);

        // Verify reconciliation created zero unpaid debt (total = 98000, paid = 98000, balance = 0)
        $invAfterReconcile = DB::table('invoices')->where('id', $fx['sar_invoice_id'])->first();
        $this->assertSame(98000, (int) $invAfterReconcile->total_minor);
        $this->assertSame(98000, (int) $invAfterReconcile->paid_minor);

        // 4. Now partial restoration of SAR 100 succeeds safely
        $restoreAfterReconcile = $this->postJson('/challan/restore-myskool-payment', [
            'qa_staged_collection_id' => $fx['col_arham_jul'],
            'restoration_mode' => 'partial',
            'restore_amount' => 100.00,
            'reason' => 'Restore SAR 100 after reconciling July 2026 lineage.',
        ]);
        $restoreAfterReconcile->assertOk()
            ->assertJsonPath('reversed_amount_minor', 10000)
            ->assertJsonPath('remaining_paid_minor', 16500);
    }

    /**
     * Test 10 & 11: Native IQRA reversals continue working and mixed-currency balances (SAR vs AED) remain independent.
     */
    public function test_native_iqra_reversal_and_mixed_currency_isolation(): void
    {
        $fx = $this->seedReceipt24083Fixture(true);

        // Create an AED enrollment & AED invoice for the same family to verify SAR vs AED currency isolation
        $aedEnrollmentId = (int) DB::table('enrollments')->insertGetId([
            'student_id' => $fx['arham_id'],
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'grade_label' => 'Quran Dept',
            'currency' => 'AED',
            'monthly_fee_minor' => 45000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $aedInvoiceId = (int) DB::table('invoices')->insertGetId([
            'billing_run_id' => $this->obRunId,
            'family_id' => $fx['family_id'],
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'currency' => 'AED',
            'invoice_number' => 'SCH-OB-24083-AED',
            'issued_on' => '2026-10-01',
            'due_date' => '2026-10-25',
            'total_minor' => 45000,
            'paid_minor' => 45000,
            'previous_dues_snapshot_minor' => 0,
            'status' => 'paid',
            'approved_to_send' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $aedItemId = (int) DB::table('invoice_items')->insertGetId([
            'invoice_id' => $aedInvoiceId,
            'student_id' => $fx['arham_id'],
            'enrollment_id' => $aedEnrollmentId,
            'fee_type_id' => $this->monthlyFeeTypeId,
            'description' => 'Monthly Fee - Oct-2026 (AED)',
            'period_key' => 'monthly:2026-10-01..2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'AED',
            'amount_minor' => 45000,
            'discount_minor' => 0,
            'exemption_minor' => 0,
            'net_minor' => 45000,
            'paid_minor' => 45000,
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed a native portal payment & receipt for the AED invoice item
        $portalPaymentId = (int) DB::table('payments')->insertGetId([
            'family_id' => $fx['family_id'],
            'campus_id' => $this->campusId,
            'department_id' => $this->deptId,
            'receipt_number' => 'IQRA-RCP-99001',
            'received_on' => '2026-10-25',
            'currency' => 'AED',
            'amount_minor' => 45000,
            'unallocated_minor' => 0,
            'status' => 'received',
            'received_by' => $this->adminUser->id,
            'idempotency_key' => 'PORTAL-AED-99001',
            'request_hash' => 'hash-portal-aed-99001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_allocations')->insert([
            'payment_id' => $portalPaymentId,
            'invoice_item_id' => $aedItemId,
            'amount_minor' => 45000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. Restore SAR 100 on MySkool Receipt #24083 (SAR) and verify AED invoice is untouched
        $this->postJson('/challan/restore-myskool-payment', [
            'qa_staged_collection_id' => $fx['col_arham_jul'],
            'restoration_mode' => 'partial',
            'restore_amount' => 100.00,
            'reason' => 'Restore SAR 100 while AED invoice remains isolated.',
        ])->assertOk();

        $aedInvoiceAfterSarRestore = DB::table('invoices')->where('id', $aedInvoiceId)->first();
        $this->assertSame(45000, (int) $aedInvoiceAfterSarRestore->paid_minor);
        $this->assertSame('paid', $aedInvoiceAfterSarRestore->status);

        // 2. Reverse the native IQRA receipt (AED 450) via POST /challan/reverse-receipt and verify SAR invoice is untouched
        $nativeRevResp = $this->post('/challan/reverse-receipt', [
            'payment_id' => $portalPaymentId,
            'reason' => 'Native portal receipt reversal test.',
        ]);
        $nativeRevResp->assertRedirect();

        $aedInvoiceAfterNativeRev = DB::table('invoices')->where('id', $aedInvoiceId)->first();
        $this->assertSame(0, (int) $aedInvoiceAfterNativeRev->paid_minor);

        $sarInvoiceAfterNativeRev = DB::table('invoices')->where('id', $fx['sar_invoice_id'])->first();
        $this->assertSame(88000, (int) $sarInvoiceAfterNativeRev->paid_minor); // 98000 - 10000 = 88000 SAR
    }

    /**
     * Phase 3E: Dispute creates a persisted case in the Disputes section without changing financial balances.
     */
    public function test_dispute_creation_persists_case_in_disputes_section_without_changing_financial_balances(): void
    {
        $fx = $this->seedReceipt24083Fixture(true);

        $sarInvoiceBefore = DB::table('invoices')->where('id', $fx['sar_invoice_id'])->first();
        $itemBefore = DB::table('invoice_items')->where('id', $fx['item_arham_jul'])->first();

        // 1. Submit dispute on Arham July 2026 collection row
        $disputeResp = $this->postJson('/challan/dispute-myskool-payment', [
            'qa_staged_collection_id' => $fx['col_arham_jul'],
            'reason' => 'Parent claims July fee was already paid via bank deposit in July.',
        ]);

        $disputeResp->assertOk();
        $disputeData = $disputeResp->json();
        $this->assertTrue($disputeData['success']);
        $this->assertNotEmpty($disputeData['dispute_code']);
        $this->assertSame('open', $disputeData['dispute_status']);

        // Verify record in myskool_disputes table
        $disputeRow = DB::table('myskool_disputes')->where('id', $disputeData['dispute_id'])->first();
        $this->assertNotNull($disputeRow);
        $this->assertSame('8239', $disputeRow->student_gr);
        $this->assertSame('Syed Arham Anis', $disputeRow->student_name);
        $this->assertSame('SAR', $disputeRow->currency);
        $this->assertSame(26500, (int) $disputeRow->amount_minor);
        $this->assertSame('open', $disputeRow->status);

        // Verify financial balances are completely UNCHANGED
        $sarInvoiceAfter = DB::table('invoices')->where('id', $fx['sar_invoice_id'])->first();
        $itemAfter = DB::table('invoice_items')->where('id', $fx['item_arham_jul'])->first();

        $this->assertSame((int) $sarInvoiceBefore->paid_minor, (int) $sarInvoiceAfter->paid_minor);
        $this->assertSame((int) $sarInvoiceBefore->total_minor, (int) $sarInvoiceAfter->total_minor);
        $this->assertSame((int) $itemBefore->paid_minor, (int) $itemAfter->paid_minor);
        $this->assertSame((int) $itemBefore->net_minor, (int) $itemAfter->net_minor);

        // 2. Admin reviews and approves the dispute case
        $reviewResp = $this->postJson("/challan/disputes/{$disputeRow->id}/status", [
            'status' => 'under_review',
            'review_notes' => 'Checking bank statement for July 2026.',
        ]);
        $reviewResp->assertOk();
        $this->assertSame('under_review', DB::table('myskool_disputes')->where('id', $disputeRow->id)->value('status'));

        $approveResp = $this->postJson("/challan/disputes/{$disputeRow->id}/status", [
            'status' => 'approved',
            'review_notes' => 'Bank statement confirmed. Dispute approved.',
        ]);
        $approveResp->assertOk();
        $this->assertSame('approved', DB::table('myskool_disputes')->where('id', $disputeRow->id)->value('status'));

        // Verify approving dispute still makes ZERO automatic balance changes
        $sarInvoiceAfterApproval = DB::table('invoices')->where('id', $fx['sar_invoice_id'])->first();
        $this->assertSame(98000, (int) $sarInvoiceAfterApproval->paid_minor);

        // 3. Resolve the dispute
        $resolveResp = $this->postJson("/challan/disputes/{$disputeRow->id}/status", [
            'status' => 'resolved',
            'review_notes' => 'Resolution documented.',
        ]);
        $resolveResp->assertOk();
        $this->assertSame('resolved', DB::table('myskool_disputes')->where('id', $disputeRow->id)->value('status'));
    }

    /**
     * Phase 3E: Dispute can also be created from an imported fee item directly.
     */
    public function test_dispute_creation_via_invoice_item_without_direct_collection_id(): void
    {
        $fx = $this->seedReceipt24083Fixture(true);

        $disputeResp = $this->postJson('/challan/dispute-myskool-payment', [
            'invoice_item_id' => $fx['item_haniya_jun'],
            'reason' => 'Disputing June fee allocation for Syeda Haniya.',
        ]);

        $disputeResp->assertOk();
        $disputeData = $disputeResp->json();
        $this->assertTrue($disputeData['success']);
        $this->assertSame('open', $disputeData['dispute_status']);

        $disputeRow = DB::table('myskool_disputes')->where('id', $disputeData['dispute_id'])->first();
        $this->assertNotNull($disputeRow);
        $this->assertSame('8240', $disputeRow->student_gr);
        $this->assertSame('Syeda Haniya Anis', $disputeRow->student_name);
        $this->assertSame(22500, (int) $disputeRow->amount_minor);
    }

    /**
     * Phase 3E: CSRF token workflow verification for Restore and Dispute POST requests.
     */
    public function test_csrf_token_workflow_on_restore_and_dispute(): void
    {
        $fx = $this->seedReceipt24083Fixture(true);

        $sessionToken = 'test-csrf-token-secret-12345';

        // 1. Restore POST with valid CSRF session token in form body succeeds
        $restoreResp = $this->withSession(['_token' => $sessionToken])
            ->post('/challan/restore-myskool-payment', [
                '_token' => $sessionToken,
                'qa_staged_collection_id' => $fx['col_arham_jul'],
                'restoration_mode' => 'full',
                'reason' => 'Valid CSRF authenticated restoration test.',
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $restoreResp->assertOk();
        $this->assertTrue($restoreResp->json('success'));

        // 2. Dispute POST with valid CSRF header (X-CSRF-TOKEN) succeeds
        $disputeResp = $this->withSession(['_token' => $sessionToken])
            ->post('/challan/dispute-myskool-payment', [
                'qa_staged_collection_id' => $fx['col_arham_jun'],
                'reason' => 'Valid CSRF header dispute submission test.',
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
                'X-CSRF-TOKEN' => $sessionToken,
            ]);

        $disputeResp->assertOk();
        $this->assertTrue($disputeResp->json('success'));
    }

    /**
     * Phase 3E (Issue 1 & 2 Verification):
     * 1. GET /challan loads cleanly both with and without disputes without undefined-property exceptions.
     * 2. Dispute records render properly with original_record_label.
     * 3. Received MySkool receipts display exactly two actions (DISPUTE | RESTORE) in individual allocations.
     * 4. Outstanding fee rows and invoice listings contain neither action.
     * 5. Native IQRA receipts remain unchanged.
     */
    public function test_challan_page_renders_dispute_cleanly_and_enforces_action_placement(): void
    {
        $fx = $this->seedReceipt24083Fixture(true);

        // Verify /challan loads cleanly before any dispute is created
        $respBefore = $this->get('/challan?gr=8239');
        $respBefore->assertOk();

        // Submit a dispute for Arham's July payment
        $disputeResp = $this->postJson('/challan/dispute-myskool-payment', [
            'qa_staged_collection_id' => $fx['col_arham_jul'],
            'reason' => 'Disputing payment entry due to ledger verification query.',
        ]);
        $disputeResp->assertOk();
        $disputeData = $disputeResp->json();
        $this->assertTrue($disputeData['success']);

        $disputeRecord = DB::table('myskool_disputes')->where('id', $disputeData['dispute_id'])->first();
        $this->assertNotNull($disputeRecord);

        // GET /challan for this family must render 200 OK without Undefined property exception
        $respAfter = $this->get('/challan?gr=8239');
        $respAfter->assertOk();
        $respAfter->assertSee($disputeRecord->dispute_code);
        $respAfter->assertSee($disputeRecord->original_record_label);
        $respAfter->assertSee('Disputing payment entry due to ledger verification query.');

        // General /challan without family filter also renders cleanly
        $respGeneral = $this->get('/challan');
        $respGeneral->assertOk();

        // Outstanding fee rows table on /challan must NOT contain Dispute or Restore buttons
        $respAfter->assertDontSee('title="Dispute this imported MySkool fee entry"', false);
        $respAfter->assertDontSee('title="Restore this imported MySkool fee entry"', false);

        // Fee History table row on /challan must NOT contain direct Dispute or Restore buttons
        $respAfter->assertDontSee('title="Dispute MySkool Payment"', false);
        $respAfter->assertDontSee('title="Restore MySkool Payment"', false);

        // Invoice listings (/invoices) must NOT contain dispute or restore actions
        $invoicesResp = $this->get('/invoices');
        $invoicesResp->assertOk();
        $invoicesResp->assertDontSee('js-myskool-dispute-btn');
        $invoicesResp->assertDontSee('js-myskool-restore-btn');

        // Inside received MySkool receipt details modal, individual allocations show exactly DISPUTE | RESTORE
        $respAfter->assertSee('DISPUTE');
        $respAfter->assertSee('RESTORE');
    }
}
