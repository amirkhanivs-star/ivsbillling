<?php

namespace Tests\Feature;

use App\Services\BatchDispatchLock;
use App\Services\Migration\Gr5130HistoricalPaymentCorrection;
use App\Services\Migration\Gr5130HistoricalPaymentReversal;
use App\Services\Migration\HistoricalPaymentReconciliationService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class Gr5130HistoricalPaymentCorrectionTest extends TestCase
{
    private int $actor;
    private Gr5130HistoricalPaymentCorrection $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Assert isolation BEFORE any migration, independently of RefreshDatabase.
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('These tests require isolated in-memory SQLite.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed();
        config(['reconciliation.allow_apply' => true]);
        $this->actor = (int) DB::table('users')->value('id');
        $campus = DB::table('campuses')->value('id');
        $department = DB::table('departments')->where('code', 'school')->value('id');
        $family = DB::table('families')->insertGetId(['family_code' => '5130', 'guardian_name' => 'Guardian']);
        $student = DB::table('students')->insertGetId(['family_id' => $family, 'gr_number' => '5130', 'name' => 'Rashid', 'status' => 'present']);
        $enrollment = DB::table('enrollments')->insertGetId(['student_id' => $student, 'campus_id' => $campus, 'department_id' => $department, 'currency' => 'SAR', 'monthly_fee_minor' => 33000, 'status' => 'active']);
        foreach ([85 => '2026-10-01', 117 => '2026-11-01'] as $run => $date) {
            DB::table('billing_runs')->insert(['id' => $run, 'campus_id' => $campus, 'department_id' => $department, 'frequency' => $run === 85 ? 'opening_balance' : 'monthly', 'period_key' => 'monthly:'.$date, 'period_start' => $date, 'period_end' => $run === 85 ? '2026-10-31' : '2026-11-30', 'due_date' => $date, 'status' => 'generated', 'created_by' => $this->actor]);
            DB::table('invoices')->insert(['id' => $run === 85 ? 9539 : 16072, 'billing_run_id' => $run, 'family_id' => $family, 'campus_id' => $campus, 'department_id' => $department, 'currency' => 'SAR', 'invoice_number' => 'TEST-'.$run, 'issued_on' => $date, 'due_date' => $date, 'total_minor' => $run === 85 ? 6000 : 33000, 'paid_minor' => 0, 'previous_dues_snapshot_minor' => $run === 85 ? 0 : 33000, 'status' => 'generated']);
        }
        DB::table('invoice_items')->insert(['id' => 13378, 'invoice_id' => 9539, 'student_id' => $student, 'enrollment_id' => $enrollment, 'description' => 'Monthly Fee - Oct-2026', 'period_key' => 'monthly:2026-10-01..2026-10-31', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'currency' => 'SAR', 'amount_minor' => 33000, 'discount_minor' => 27000, 'exemption_minor' => 0, 'net_minor' => 6000, 'paid_minor' => 0, 'status' => 'active']);
        DB::table('fee_adjustments')->insert(['id' => 194, 'invoice_item_id' => 13378, 'user_id' => $this->actor, 'kind' => 'discount', 'amount_minor' => 27000, 'previous_amount_minor' => 33000, 'new_amount_minor' => 6000, 'reason' => '.']);
        DB::table('qa_staged_collections')->insert(['id' => 74218, 'batch_id' => 'TEST-GR5130', 'source_row' => 34492, 'slip_number' => '27223', 'gr_number' => '5130', 'currency' => 'SAR', 'amount' => 270, 'amount_minor' => 27000, 'payment_date_parsed' => '2026-10-23', 'fee_particular' => 'Monthly Fee - Oct-2026']);
        DB::table('qa_opening_balance_lineage')->insert(['batch_id' => 'TEST-GR5130', 'qa_staged_due_id' => 1, 'source_row' => 1, 'gr_number' => '5130', 'invoice_id' => 9539, 'invoice_item_id' => 13378, 'family_id' => $family, 'student_id' => $student, 'enrollment_id' => $enrollment, 'currency' => 'SAR', 'amount_minor' => 33000, 'period_key' => 'monthly:2026-10-01..2026-10-31']);
        $this->service = app(Gr5130HistoricalPaymentCorrection::class);
    }

    private function correctionOptions(): array
    {
        return ['apply' => true, 'confirm-owner' => true, 'actor-id' => $this->actor, 'reason' => 'Owner approved historical payment reclassification', 'approve-fingerprint' => $this->service->run()['fingerprint']];
    }

    private function financialState(): string
    {
        $state = [];
        foreach (['invoice_items', 'invoices', 'fee_adjustments', 'payments', 'payment_allocations', 'qa_staged_collections', 'qa_opening_balance_lineage', 'qa_historical_settlements', 'qa_historical_settlement_collections', 'audit_logs'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get();
        }
        return json_encode($state);
    }

    public function test_default_and_command_preview_perform_zero_writes(): void
    {
        $before = $this->financialState();
        $result = $this->service->run();
        $this->assertSame(6000, $result['after']['outstanding_minor']);
        $this->assertSame(6000, $result['snapshots'][0]['after_snapshot_minor']);
        $this->artisan('billing:correct-gr5130')->assertExitCode(0);
        $this->assertSame($before, $this->financialState());
    }

    public function test_atomic_reclassification_preserves_history_and_is_idempotent(): void
    {
        $original = DB::table('fee_adjustments')->where('id', 194)->first();
        $lineage = DB::table('qa_opening_balance_lineage')->get();
        $collection = DB::table('qa_staged_collections')->get();
        $options = $this->correctionOptions();
        $this->assertSame('applied', $this->service->run($options)['status']);
        $this->assertDatabaseHas('invoice_items', ['id' => 13378, 'amount_minor' => 33000, 'discount_minor' => 0, 'net_minor' => 33000, 'paid_minor' => 27000, 'status' => 'partial']);
        $this->assertDatabaseHas('invoices', ['id' => 9539, 'total_minor' => 33000, 'paid_minor' => 27000, 'status' => 'partial']);
        $this->assertDatabaseHas('invoices', ['id' => 16072, 'total_minor' => 33000, 'paid_minor' => 0, 'previous_dues_snapshot_minor' => 6000]);
        $this->assertDatabaseHas('fee_adjustments', ['kind' => 'discount_removed', 'previous_amount_minor' => 6000, 'new_amount_minor' => 33000]);
        $this->assertEquals($original, DB::table('fee_adjustments')->where('id', 194)->first());
        $this->assertEquals($lineage, DB::table('qa_opening_balance_lineage')->get());
        $this->assertEquals($collection, DB::table('qa_staged_collections')->get());
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $beforeRepeat = $this->financialState();
        $this->assertSame('already_applied', $this->service->run($options)['status']);
        $this->assertSame($beforeRepeat, $this->financialState());
    }

    public function test_failure_after_discount_and_settlement_rolls_everything_back(): void
    {
        $options = $this->correctionOptions();
        $before = $this->financialState();
        DB::statement("CREATE TRIGGER fail_gr5130_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'gr5130_discount_reclassified_as_historical_payment' BEGIN SELECT RAISE(ABORT, 'injected final audit failure'); END");
        try {
            $this->service->run($options);
            $this->fail('Expected injected failure.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('injected final audit failure', $e->getMessage());
        }
        $this->assertSame($before, $this->financialState());
        DB::statement('DROP TRIGGER fail_gr5130_audit');
        $this->assertSame('applied', $this->service->run($options)['status']);
    }

    public function test_missing_authorization_configuration_and_stale_approval_fail_closed(): void
    {
        $valid = $this->correctionOptions();
        $before = $this->financialState();
        foreach (['confirm-owner', 'reason', 'actor-id', 'approve-fingerprint'] as $key) {
            $bad = $valid;
            unset($bad[$key]);
            $this->assertRejected($bad);
        }
        config(['reconciliation.allow_apply' => false]);
        $this->assertRejected($valid);
        config(['reconciliation.allow_apply' => true]);
        $this->assertRejected(array_replace($valid, ['approve-fingerprint' => str_repeat('0', 64)]));
        $this->assertSame($before, $this->financialState());
    }

    public function test_non_admin_and_inactive_admin_are_rejected(): void
    {
        $options = $this->correctionOptions();
        DB::table('users')->where('id', $this->actor)->update(['role' => 'viewer']);
        $this->assertRejected($options);
        DB::table('users')->where('id', $this->actor)->update(['role' => 'super_admin', 'is_active' => false]);
        $this->assertRejected($options);
        $this->assertDatabaseCount('qa_historical_settlements', 0);
    }

    public function test_duplicate_receipt_row_is_rejected(): void
    {
        $duplicate = (array) DB::table('qa_staged_collections')->first();
        $duplicate['id'] = 74219;
        DB::table('qa_staged_collections')->insert($duplicate);
        $this->expectException(RuntimeException::class);
        $this->service->run();
    }

    public function test_imported_portal_receipt_is_rejected_even_without_allocations(): void
    {
        $invoice = DB::table('invoices')->where('id', 9539)->first();
        DB::table('payments')->insert(['family_id' => $invoice->family_id, 'campus_id' => $invoice->campus_id, 'department_id' => $invoice->department_id, 'currency' => 'SAR', 'receipt_number' => 'REC-27223', 'amount_minor' => 27000, 'unallocated_minor' => 27000, 'received_on' => '2026-10-23', 'idempotency_key' => 'myskool-paid-slip-27223-row-1', 'request_hash' => str_repeat('a', 64), 'received_by' => $this->actor]);
        $this->expectExceptionMessage('Receipt already imported');
        $this->service->run();
    }

    public function test_collection_consumed_by_another_item_is_rejected(): void
    {
        $this->service->run($this->correctionOptions());
        $other = (array) DB::table('invoice_items')->where('id', 13378)->first();
        $other['id'] = 13379;
        $other['invoice_id'] = 16072;
        DB::table('invoice_items')->insert($other);
        DB::table('qa_historical_settlements')->update(['reconciliation_id' => 'OTHER-SETTLEMENT', 'invoice_item_id' => 13379, 'active_invoice_item_id' => 13379, 'invoice_id' => 16072]);
        DB::table('qa_historical_settlement_collections')->update(['invoice_item_id' => 13379]);
        DB::table('invoice_items')->where('id', 13378)->update(['discount_minor' => 27000, 'net_minor' => 6000, 'paid_minor' => 0, 'status' => 'active']);
        $this->expectExceptionMessage('already settled');
        $this->service->run();
    }

    public function test_database_unique_active_collection_blocks_second_allocation(): void
    {
        $this->service->run($this->correctionOptions());
        $duplicate = (array) DB::table('qa_historical_settlement_collections')->first();
        unset($duplicate['id']);
        $duplicate['qa_staged_collection_id'] = 74219;
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('qa_historical_settlement_collections')->insert($duplicate);
    }

    public function test_changed_snapshot_invalidates_approved_fingerprint(): void
    {
        $options = $this->correctionOptions();
        DB::table('invoices')->where('id', 16072)->update(['previous_dues_snapshot_minor' => 32000]);
        $before = $this->financialState();
        $this->assertRejected($options);
        $this->assertSame($before, $this->financialState());
    }

    public function test_other_students_and_invoices_are_untouched(): void
    {
        $family = DB::table('families')->insertGetId(['family_code' => 'UNRELATED', 'guardian_name' => 'Other']);
        $student = DB::table('students')->insertGetId(['family_id' => $family, 'gr_number' => 'OTHER', 'name' => 'Other', 'status' => 'present']);
        $invoice = (array) DB::table('invoices')->where('id', 16072)->first();
        $invoice['id'] = 16073;
        $invoice['family_id'] = $family;
        $invoice['invoice_number'] = 'OTHER-INVOICE';
        DB::table('invoices')->insert($invoice);
        $beforeStudent = DB::table('students')->where('id', $student)->first();
        $beforeInvoice = DB::table('invoices')->where('id', 16073)->first();
        $this->service->run($this->correctionOptions());
        $this->assertEquals($beforeStudent, DB::table('students')->where('id', $student)->first());
        $this->assertEquals($beforeInvoice, DB::table('invoices')->where('id', 16073)->first());
    }

    public function test_reversed_correction_is_never_automatically_reapplied(): void
    {
        $options = $this->correctionOptions();
        $this->service->run($options);
        DB::table('qa_historical_settlements')->update(['status' => 'reversed', 'active_invoice_item_id' => null, 'active_primary_collection_id' => null]);
        $before = $this->financialState();
        $this->assertRejected($options);
        $this->assertSame($before, $this->financialState());
    }

    public function test_source_lock_blocks_concurrent_reconciliation(): void
    {
        $lock = new BatchDispatchLock();
        $this->assertTrue($lock->acquire('hist_col_alloc_74218'));
        try {
            $this->expectExceptionMessage('locked by another operation');
            $this->service->run();
        } finally {
            $lock->release();
        }
    }

    public function test_live_activity_and_original_hold_rules_are_preserved(): void
    {
        $this->assertSame('LIVE_ACTIVITY_HOLD', $this->service->preview(['item_id' => 13378])['records'][0]['guard_class']);
        DB::table('invoice_items')->where('id', 13378)->update(['paid_minor' => 100]);
        $this->expectExceptionMessage('LIVE_ACTIVITY_HOLD');
        $this->service->run();
    }

    private function reversalOptions(): array
    {
        return ['apply' => true, 'confirm-owner' => true, 'actor-id' => $this->actor,
            'reason' => 'Separate owner approval to restore original classification',
            'approve-fingerprint' => app(Gr5130HistoricalPaymentReversal::class)->run()['fingerprint']];
    }

    public function test_reversal_without_correction_is_a_zero_write_noop(): void
    {
        $before = $this->financialState();
        $this->assertSame('not_applied', app(Gr5130HistoricalPaymentReversal::class)->run()['status']);
        $this->artisan('billing:reverse-gr5130')->assertExitCode(0);
        $this->assertSame($before, $this->financialState());
    }

    public function test_complete_authorized_reversal_via_command_preserves_all_history_and_replays_safely(): void
    {
        $originalAdjustment = DB::table('fee_adjustments')->where('id', 194)->first();
        $lineage = DB::table('qa_opening_balance_lineage')->get();
        $collection = DB::table('qa_staged_collections')->get();
        $this->service->run($this->correctionOptions());
        $reversal = app(Gr5130HistoricalPaymentReversal::class);
        $beforePreview = $this->financialState();
        $preview = $reversal->run();
        $this->assertSame('dry_run', $preview['status']);
        $this->assertSame(33000, $preview['snapshots'][0]['after_snapshot_minor']);
        $this->artisan('billing:reverse-gr5130')->assertExitCode(0);
        $this->assertSame($beforePreview, $this->financialState());
        $options = $this->reversalOptions();
        $cliOptions = [];
        foreach ($options as $key => $value) {
            $cliOptions['--'.$key] = $value;
        }
        $this->artisan('billing:reverse-gr5130', $cliOptions)->assertExitCode(0);
        $this->assertDatabaseHas('invoice_items', ['id' => 13378, 'amount_minor' => 33000, 'discount_minor' => 27000, 'net_minor' => 6000, 'paid_minor' => 0, 'status' => 'active']);
        $this->assertDatabaseHas('invoices', ['id' => 9539, 'total_minor' => 6000, 'paid_minor' => 0, 'status' => 'generated']);
        $this->assertDatabaseHas('invoices', ['id' => 16072, 'previous_dues_snapshot_minor' => 33000]);
        $this->assertDatabaseHas('qa_historical_settlements', ['reconciliation_id' => Gr5130HistoricalPaymentCorrection::RECONCILIATION_ID, 'status' => 'reversed', 'active_invoice_item_id' => null, 'active_primary_collection_id' => null, 'reversed_by' => $this->actor]);
        $this->assertDatabaseHas('qa_historical_settlement_collections', ['qa_staged_collection_id' => 74218, 'status' => 'reversed', 'active_staged_collection_id' => null, 'reversed_by' => $this->actor]);
        $this->assertDatabaseCount('fee_adjustments', 3);
        $this->assertDatabaseCount('audit_logs', 5);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertEquals($originalAdjustment, DB::table('fee_adjustments')->where('id', 194)->first());
        $this->assertEquals($lineage, DB::table('qa_opening_balance_lineage')->get());
        $this->assertEquals($collection, DB::table('qa_staged_collections')->get());
        $audit = json_decode(DB::table('audit_logs')->where('action', Gr5130HistoricalPaymentReversal::AUDIT_ACTION)->value('metadata'), true);
        $this->assertNotEmpty($audit['discount_removal_adjustment_id']);
        $this->assertNotEmpty($audit['discount_restoration_adjustment_id']);
        $beforeRepeat = $this->financialState();
        $this->assertSame('already_reversed', $reversal->run($options)['status']);
        $this->assertSame($beforeRepeat, $this->financialState());
        $this->assertRejected($this->correctionOptionsWithoutPreview());
    }

    private function correctionOptionsWithoutPreview(): array
    {
        return ['apply' => true, 'confirm-owner' => true, 'actor-id' => $this->actor, 'reason' => 'Do not reapply reversed correction', 'approve-fingerprint' => str_repeat('0', 64)];
    }

    public function test_reversal_failure_between_operations_or_at_final_audit_rolls_everything_back(): void
    {
        $this->service->run($this->correctionOptions());
        $options = $this->reversalOptions();
        $before = $this->financialState();
        foreach ([
            "CREATE TRIGGER fail_gr5130_reverse BEFORE INSERT ON fee_adjustments WHEN NEW.kind = 'discount' BEGIN SELECT RAISE(ABORT, 'injected reversal failure'); END",
            "CREATE TRIGGER fail_gr5130_reverse BEFORE INSERT ON audit_logs WHEN NEW.action = 'gr5130_correction_fully_reversed' BEGIN SELECT RAISE(ABORT, 'injected reversal failure'); END",
        ] as $sql) {
            DB::statement($sql);
            try {
                app(Gr5130HistoricalPaymentReversal::class)->run($options);
                $this->fail('Expected reversal failure.');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('injected reversal failure', $e->getMessage());
            }
            $this->assertSame($before, $this->financialState());
            DB::statement('DROP TRIGGER fail_gr5130_reverse');
        }
        $this->assertSame('reversed', app(Gr5130HistoricalPaymentReversal::class)->run($options)['status']);
    }

    public function test_reversal_requires_separate_owner_actor_reason_configuration_and_fingerprint(): void
    {
        $this->service->run($this->correctionOptions());
        $valid = $this->reversalOptions();
        $before = $this->financialState();
        foreach (['confirm-owner', 'actor-id', 'reason', 'approve-fingerprint'] as $key) {
            $bad = $valid;
            unset($bad[$key]);
            $this->assertReversalRejected($bad);
        }
        $this->assertReversalRejected(array_replace($valid, ['approve-fingerprint' => str_repeat('0', 64)]));
        config(['reconciliation.allow_apply' => false]);
        $this->assertReversalRejected($valid);
        config(['reconciliation.allow_apply' => true]);
        DB::table('users')->where('id', $this->actor)->update(['role' => 'cashier']);
        $this->assertReversalRejected($valid);
        DB::table('users')->where('id', $this->actor)->update(['role' => 'super_admin', 'is_active' => false]);
        $this->assertReversalRejected($valid);
        $this->assertSame($before, $this->financialState());
    }

    public function test_reversal_rejects_changed_snapshot_item_source_or_adjustment(): void
    {
        $this->service->run($this->correctionOptions());
        $valid = $this->reversalOptions();
        foreach ([['invoices', 16072, 'previous_dues_snapshot_minor', 7000],
            ['invoice_items', 13378, 'paid_minor', 28000], ['qa_staged_collections', 74218, 'amount_minor', 28000],
            ['fee_adjustments', 194, 'reason', 'Changed since correction']] as [$table, $id, $field, $value]) {
            $previous = DB::table($table)->where('id', $id)->value($field);
            DB::table($table)->where('id', $id)->update([$field => $value]);
            $before = $this->financialState();
            $this->assertReversalRejected($valid);
            $this->assertSame($before, $this->financialState());
            DB::table($table)->where('id', $id)->update([$field => $previous]);
        }
    }

    public function test_reversal_blocks_intervening_cashier_payment_without_erasing_it(): void
    {
        $this->service->run($this->correctionOptions());
        $valid = $this->reversalOptions();
        $invoice = DB::table('invoices')->where('id', 9539)->first();
        $payment = DB::table('payments')->insertGetId(['family_id' => $invoice->family_id, 'campus_id' => $invoice->campus_id, 'department_id' => $invoice->department_id, 'currency' => 'SAR', 'receipt_number' => 'NEW-CASH', 'amount_minor' => 100, 'unallocated_minor' => 0, 'received_on' => '2026-10-31', 'idempotency_key' => 'NEW-CASH-KEY', 'request_hash' => str_repeat('b', 64), 'received_by' => $this->actor]);
        DB::table('payment_allocations')->insert(['payment_id' => $payment, 'invoice_item_id' => 13378, 'amount_minor' => 100]);
        $before = $this->financialState();
        $this->assertReversalRejected($valid);
        $this->assertSame($before, $this->financialState());
    }

    public function test_reversal_preserves_source_lock_and_rejects_settlement_only_recovery(): void
    {
        $this->service->run($this->correctionOptions());
        $valid = $this->reversalOptions();
        $lock = new BatchDispatchLock();
        $this->assertTrue($lock->acquire('hist_col_alloc_74218'));
        try {
            $this->assertReversalRejected($valid);
        } finally {
            $lock->release();
        }
        app(HistoricalPaymentReconciliationService::class)->reverse(Gr5130HistoricalPaymentCorrection::RECONCILIATION_ID, $this->actor);
        $before = $this->financialState();
        $this->assertReversalRejected($valid);
        $this->assertSame($before, $this->financialState());
    }

    public function test_reversal_restores_exact_snapshot_even_if_correction_increased_it(): void
    {
        DB::table('invoices')->where('id', 16072)->update(['previous_dues_snapshot_minor' => 1000]);
        $this->service->run($this->correctionOptions());
        $this->assertDatabaseHas('invoices', ['id' => 16072, 'previous_dues_snapshot_minor' => 6000]);
        app(Gr5130HistoricalPaymentReversal::class)->run($this->reversalOptions());
        $this->assertDatabaseHas('invoices', ['id' => 16072, 'previous_dues_snapshot_minor' => 1000]);
    }

    public function test_reversal_accepts_database_json_key_reordering_without_weakening_state_checks(): void
    {
        $this->service->run($this->correctionOptions());
        $options = $this->reversalOptions();
        $reverseKeys = function (array $value) use (&$reverseKeys): array {
            foreach ($value as $key => $child) {
                if (is_array($child)) {
                    $value[$key] = $reverseKeys($child);
                }
            }
            if (!array_is_list($value)) {
                krsort($value, SORT_STRING);
            }
            return $value;
        };
        $audit = DB::table('audit_logs')->where('action', 'gr5130_discount_reclassified_as_historical_payment')->first();
        DB::table('audit_logs')->where('id', $audit->id)->update(['metadata' => json_encode($reverseKeys(json_decode($audit->metadata, true)))]);
        $reversal = app(Gr5130HistoricalPaymentReversal::class);
        $this->assertSame($options['approve-fingerprint'], $reversal->run()['fingerprint']);
        $this->assertSame('reversed', $reversal->run($options)['status']);
        $audit = DB::table('audit_logs')->where('action', Gr5130HistoricalPaymentReversal::AUDIT_ACTION)->first();
        DB::table('audit_logs')->where('id', $audit->id)->update(['metadata' => json_encode($reverseKeys(json_decode($audit->metadata, true)))]);
        $before = $this->financialState();
        $this->assertSame('already_reversed', $reversal->run($options)['status']);
        $this->assertSame($before, $this->financialState());
    }

    private function assertReversalRejected(array $options): void
    {
        try {
            app(Gr5130HistoricalPaymentReversal::class)->run($options);
            $this->fail('Expected reversal authorization or integrity rejection.');
        } catch (RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    private function assertRejected(array $options): void
    {
        try {
            $this->service->run($options);
            $this->fail('Expected authorization or integrity rejection.');
        } catch (RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }
}
