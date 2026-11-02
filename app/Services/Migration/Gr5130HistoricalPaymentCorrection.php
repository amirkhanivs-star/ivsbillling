<?php

namespace App\Services\Migration;

use App\Services\BatchDispatchLock;
use App\Services\FeeAdjustmentService;
use App\Services\InvoiceService;
use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** An owner-reviewed exception for one misclassified payment; general HOLD rules remain intact. */
class Gr5130HistoricalPaymentCorrection extends HistoricalPaymentReconciliationService
{
    public const RECONCILIATION_ID = 'GR5130-13378-74218-DISCOUNT-TO-PAYMENT';

    public function run(array $options = []): array
    {
        DatabaseSafetyGuard::assertTargetDatabase();
        $apply = !empty($options['apply']);
        if ($apply) {
            $this->requireState(!empty($options['confirm-owner']) && trim((string) ($options['reason'] ?? '')) !== '', 'Explicit owner confirmation and reason required.');
            $this->requireState(config('reconciliation.allow_apply', false)
                || filter_var(env('ALLOW_HISTORICAL_RECONCILIATION_APPLY', false), FILTER_VALIDATE_BOOLEAN), 'Historical reconciliation apply is disabled by configuration.');
            $this->requireState(!empty($options['actor-id']), 'Explicit administrator --actor-id required.');
            $this->requireState(!empty($options['approve-fingerprint']), 'Approved dry-run fingerprint required.');
        }

        // Same source lock as both existing historical reconciliation/correction services.
        $lock = new BatchDispatchLock();
        $this->requireState($lock->acquire('hist_col_alloc_74218'), 'Collection 74218 is locked by another operation.');
        try {
            return DB::transaction(function () use ($options, $apply) {
                $db = DB::connection();
                $actorId = null;
                if ($apply) {
                    $actorId = $this->resolveAuthorizedActor($db, null, ['actor_id' => $options['actor-id']]);
                    $db->table('users')->where('id', $actorId)->lockForUpdate()->first();
                    $this->resolveAuthorizedActor($db, $actorId);
                }
                $item = $db->table('invoice_items')->where('id', 13378)->lockForUpdate()->first();
                $this->requireState($item !== null, 'Target item missing.');
                $student = $db->table('students')->where('id', $item->student_id)->lockForUpdate()->first();
                $this->requireState($student && $student->gr_number === '5130', 'Target student identity mismatch.');
                $invoice = $db->table('invoices')->where('id', $item->invoice_id)->lockForUpdate()->first();
                $this->requireState($invoice && (int) $invoice->family_id === (int) $student->family_id && $invoice->currency === 'SAR', 'Parent invoice identity mismatch.');
                $headers = $db->table('invoices')->where('family_id', $invoice->family_id)
                    ->where('currency', 'SAR')->where('campus_id', $invoice->campus_id)
                    ->where('department_id', $invoice->department_id)->orderBy('id')->lockForUpdate()->get();
                $items = $db->table('invoice_items')->whereIn('invoice_id', $headers->pluck('id'))->orderBy('id')->lockForUpdate()->get();
                // refreshInvoice also normalizes sibling statuses: prohibit any sibling in this exception.
                $this->requireState($items->where('invoice_id', $invoice->id)->count() === 1, 'Parent invoice gained sibling items; review required.');
                $collections = $db->table('qa_staged_collections')->where('slip_number', '27223')->orderBy('id')->lockForUpdate()->get();
                $collection = $collections->first();
                $this->requireState($collections->count() === 1 && (int) $collection->id === 74218
                    && $collection->gr_number === '5130' && $collection->currency === 'SAR'
                    && (int) $collection->amount_minor === 27000 && $collection->payment_date_parsed === '2026-10-23'
                    && $collection->fee_particular === 'Monthly Fee - Oct-2026'
                    && !$collection->is_currency_unresolved && !$collection->has_currency_conflict
                    && empty($collection->review_flags), 'Historical receipt identity, uniqueness, or verification mismatch.');
                $this->requireState($item->currency === 'SAR' && (int) $item->amount_minor === 33000
                    && (int) $item->exemption_minor === 0 && $item->period_start === '2026-10-01'
                    && $item->period_end === '2026-10-31'
                    && empty($item->replaced_by_invoice_item_id) && empty($item->replaces_invoice_item_id)
                    && empty($invoice->replaced_by_invoice_id) && empty($invoice->replaces_invoice_id)
                    && !in_array($invoice->status, ['void', 'voided'], true), 'LIVE_ACTIVITY_HOLD: Obligation or invoice lineage changed.');
                $adjustments = $db->table('fee_adjustments')->where('invoice_item_id', 13378)->orderBy('id')->lockForUpdate()->get();
                $lineage = $db->table('qa_opening_balance_lineage')->where('invoice_item_id', 13378)->lockForUpdate()->get();
                $this->requireState($lineage->count() === 1 && (int) $lineage[0]->invoice_id === (int) $invoice->id
                    && $lineage[0]->gr_number === '5130' && $lineage[0]->currency === 'SAR'
                    && (int) $lineage[0]->amount_minor === 33000, 'Opening balance lineage mismatch.');
                $this->requireState(!$db->table('payment_allocations')->where('invoice_item_id', 13378)->exists(), 'LIVE_ACTIVITY_HOLD: Cashier allocation exists.');
                $this->requireState(!$db->table('payments')->where(function ($query) {
                    $query->where('receipt_number', '27223')->orWhere('receipt_number', 'REC-27223')
                        ->orWhere('receipt_number', 'like', 'REC-27223-%')
                        ->orWhere('idempotency_key', 'like', 'myskool-paid-slip-27223-row-%')
                        ->orWhere('note', 'like', 'Collection slip #27223 (%');
                })->exists(), 'LIVE_ACTIVITY_HOLD: Receipt already imported as a portal payment.');
                $this->requireState(!$db->table('qa_myskool_payment_corrections')->where('qa_staged_collection_id', 74218)->exists(), 'LIVE_ACTIVITY_HOLD: Receipt correction history exists.');
                $existing = $db->table('qa_historical_settlements')->where('reconciliation_id', self::RECONCILIATION_ID)->lockForUpdate()->first();
                if ($existing) {
                    $allocation = $db->table('qa_historical_settlement_collections')->where('settlement_id', $existing->id)->get();
                    $this->requireState($existing->status === 'applied' && (int) $existing->invoice_item_id === 13378
                        && (int) $existing->settled_amount_minor === 27000 && (int) $existing->active_primary_collection_id === 74218
                        && $allocation->count() === 1 && (int) $allocation[0]->active_staged_collection_id === 74218
                        && $allocation[0]->status === 'applied' && (int) $invoice->total_minor === 33000
                        && (int) $invoice->paid_minor === 27000 && $invoice->status === 'partial' && (int) $item->paid_minor === 27000
                        && (int) $item->net_minor === 33000 && (int) $item->discount_minor === 0 && $item->status === 'partial'
                        && $adjustments->where('kind', 'discount_removed')->count() === 1,
                        'Existing correction was reversed or changed; manual review required, never reapply automatically.');
                    return ['status' => 'already_applied', 'reconciliation_id' => self::RECONCILIATION_ID, 'financial_writes' => false];
                }
                $this->requireState(!$db->table('qa_historical_settlements')->where('invoice_item_id', 13378)->exists()
                    && !$this->hasConsumedCollectionIds($db, 13378, [74218])
                    && !$db->table('qa_historical_settlement_collections')->where('active_staged_collection_id', 74218)->exists(), 'Historical collection or item already settled.');
                $this->requireState($item->status === 'active' && (int) $item->paid_minor === 0
                    && (int) $item->discount_minor === 27000 && (int) $item->net_minor === 6000
                    && (int) $invoice->total_minor === 6000 && (int) $invoice->paid_minor === 0 && $invoice->status === 'generated'
                    && $adjustments->count() === 1 && (int) $adjustments[0]->id === 194
                    && $adjustments[0]->kind === 'discount' && (int) $adjustments[0]->amount_minor === 27000
                    && (int) $adjustments[0]->previous_amount_minor === 33000 && (int) $adjustments[0]->new_amount_minor === 6000,
                    'LIVE_ACTIVITY_HOLD: Exact original discount or unpaid state changed.');

                // Recompute later snapshots using the same family/currency/campus/department and
                // strictly earlier billing-period semantics as InvoiceService, without double counting snapshots.
                $runs = $db->table('billing_runs')->whereIn('id', $headers->pluck('billing_run_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $this->requireState($runs[$invoice->billing_run_id]->frequency === 'opening_balance', 'Expected opening-balance parent.');
                $snapshots = [];
                foreach ($headers as $header) {
                    $period = $runs[$header->billing_run_id]->period_start;
                    if ($period <= $item->period_start || in_array($header->status, ['void', 'voided'], true)) {
                        continue;
                    }
                    $outstanding = 0;
                    foreach ($headers as $prior) {
                        if ($runs[$prior->billing_run_id]->period_start < $period && !in_array($prior->status, ['void', 'voided'], true)) {
                            $outstanding += (int) $prior->id === (int) $invoice->id ? 6000 : (int) $prior->total_minor - (int) $prior->paid_minor;
                        }
                    }
                    $snapshots[] = ['invoice_id' => (int) $header->id, 'before_snapshot_minor' => (int) $header->previous_dues_snapshot_minor, 'after_snapshot_minor' => max(0, $outstanding)];
                }
                $before = ['item' => $item, 'invoice' => $invoice, 'collection' => $collection, 'adjustments' => $adjustments, 'lineage' => $lineage, 'family_invoices' => $headers, 'family_items' => $items, 'billing_runs' => $runs];
                $fingerprint = hash('sha256', 'gr5130-correction-v2:'.json_encode($before, JSON_THROW_ON_ERROR));
                $result = ['status' => 'dry_run', 'financial_writes' => false, 'reconciliation_id' => self::RECONCILIATION_ID,
                    'fingerprint' => $fingerprint, 'before' => ['gross_minor' => 33000, 'discount_minor' => 27000, 'paid_minor' => 0, 'net_minor' => 6000, 'outstanding_minor' => 6000, 'status' => 'active'],
                    'after' => ['gross_minor' => 33000, 'discount_minor' => 0, 'paid_minor' => 27000, 'net_minor' => 33000, 'outstanding_minor' => 6000, 'status' => 'partial'],
                    'snapshots' => $snapshots, 'evidence' => $before];
                if (!$apply) {
                    return $result;
                }
                $this->requireState(hash_equals($fingerprint, (string) $options['approve-fingerprint']), 'Dry-run fingerprint changed; obtain fresh approval.');
                $reason = self::RECONCILIATION_ID.': '.$options['reason'];
                app(FeeAdjustmentService::class)->removeDiscount(13378, $reason, $actorId);
                $db->table('invoice_items')->where('id', 13378)->update(['paid_minor' => 27000, 'status' => 'partial', 'updated_at' => now()]);
                app(InvoiceService::class)->refreshInvoice((int) $invoice->id);
                foreach ($snapshots as $snapshot) {
                    $db->table('invoices')->where('id', $snapshot['invoice_id'])->update(['previous_dues_snapshot_minor' => $snapshot['after_snapshot_minor'], 'updated_at' => now()]);
                }
                $newInvoice = $db->table('invoices')->where('id', $invoice->id)->first();
                $settlementId = $db->table('qa_historical_settlements')->insertGetId([
                    'reconciliation_id' => self::RECONCILIATION_ID, 'batch_id' => $collection->batch_id, 'cutoff_date' => '2026-10-22',
                    'invoice_item_id' => 13378, 'active_invoice_item_id' => 13378, 'invoice_id' => $invoice->id,
                    'student_id' => $item->student_id, 'family_id' => $invoice->family_id, 'student_gr' => '5130',
                    'family_code' => $db->table('families')->where('id', $invoice->family_id)->value('family_code'),
                    'currency' => 'SAR', 'fee_description' => $item->description, 'period_key' => $item->period_key,
                    'original_obligation_minor' => 33000, 'settled_amount_minor' => 27000, 'remaining_obligation_minor' => 6000,
                    'unmatched_overpayment_minor' => 0, 'previous_item_paid_minor' => 0, 'new_item_paid_minor' => 27000,
                    'previous_item_status' => $item->status, 'new_item_status' => 'partial',
                    'previous_invoice_paid_minor' => $invoice->paid_minor, 'new_invoice_paid_minor' => $newInvoice->paid_minor,
                    'previous_invoice_status' => $invoice->status, 'new_invoice_status' => $newInvoice->status,
                    'qa_staged_due_id' => $lineage[0]->qa_staged_due_id, 'active_primary_collection_id' => 74218,
                    'qa_staged_collection_ids' => json_encode([74218]), 'myskool_slip_numbers' => '27223',
                    'reconciliation_classification' => 'owner_reviewed_discount_reclassification', 'guard_class' => 'LIVE_ACTIVITY_HOLD',
                    'status' => 'applied', 'applied_by' => $actorId, 'applied_at' => now(),
                    'affected_snapshots_json' => json_encode($snapshots), 'notes' => $reason, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $db->table('qa_historical_settlement_collections')->insert([
                    'settlement_id' => $settlementId, 'reconciliation_id' => self::RECONCILIATION_ID, 'invoice_item_id' => 13378,
                    'qa_staged_collection_id' => 74218, 'active_staged_collection_id' => 74218, 'status' => 'applied',
                    'applied_by' => $actorId, 'applied_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $db->table('audit_logs')->insert([
                    'actor_id' => $actorId, 'action' => 'gr5130_discount_reclassified_as_historical_payment',
                    'subject_type' => 'invoice_item', 'subject_id' => 13378,
                    'metadata' => json_encode(['reason' => $reason, 'approved_fingerprint' => $fingerprint, 'before' => $before, 'after' => $result['after'], 'snapshots' => $snapshots, 'settlement_id' => $settlementId, 'post_state' => $this->lockedCorrectionState()], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                return array_replace($result, ['status' => 'applied', 'financial_writes' => true]);
            }, 3);
        } finally {
            $lock->release();
        }
    }

    /** Exact, deterministic recovery evidence; caller must hold the shared source lock and transaction. */
    public function lockedCorrectionState(): array
    {
        $db = DB::connection();
        $item = $db->table('invoice_items')->where('id', 13378)->lockForUpdate()->first();
        $this->requireState($item !== null, 'Target item missing.');
        $student = $db->table('students')->where('id', $item->student_id)->lockForUpdate()->first();
        $invoice = $db->table('invoices')->where('id', $item->invoice_id)->lockForUpdate()->first();
        $this->requireState($student && $invoice && $student->gr_number === '5130', 'Recovery identity mismatch.');
        $headers = $db->table('invoices')->where('family_id', $invoice->family_id)->where('currency', 'SAR')
            ->where('campus_id', $invoice->campus_id)->where('department_id', $invoice->department_id)->orderBy('id')->lockForUpdate()->get();
        $items = $db->table('invoice_items')->whereIn('invoice_id', $headers->pluck('id'))->orderBy('id')->lockForUpdate()->get();

        return [
            'item' => $item, 'student' => $student, 'invoice' => $invoice,
            'family_invoices' => $headers, 'family_items' => $items,
            'collections' => $db->table('qa_staged_collections')->where('slip_number', '27223')->orderBy('id')->lockForUpdate()->get(),
            'adjustments' => $db->table('fee_adjustments')->where('invoice_item_id', 13378)->orderBy('id')->lockForUpdate()->get(),
            'lineage' => $db->table('qa_opening_balance_lineage')->where('invoice_item_id', 13378)->orderBy('id')->lockForUpdate()->get(),
            'billing_runs' => $db->table('billing_runs')->whereIn('id', $headers->pluck('billing_run_id'))->orderBy('id')->lockForUpdate()->get(),
            'settlements' => $db->table('qa_historical_settlements')->where(function ($q) {
                $q->where('invoice_item_id', 13378)->orWhere('active_primary_collection_id', 74218)->orWhere('reconciliation_id', self::RECONCILIATION_ID);
            })->orderBy('id')->lockForUpdate()->get(),
            'source_claims' => $db->table('qa_historical_settlement_collections')->where(function ($q) {
                $q->where('invoice_item_id', 13378)->orWhere('qa_staged_collection_id', 74218);
            })->orderBy('id')->lockForUpdate()->get(),
            'source_corrections' => $db->table('qa_myskool_payment_corrections')->where('qa_staged_collection_id', 74218)->orderBy('id')->lockForUpdate()->get(),
            'payments' => $db->table('payments')->where(function ($q) use ($invoice) {
                $q->where('family_id', $invoice->family_id)->orWhere('receipt_number', '27223')->orWhere('receipt_number', 'REC-27223')
                    ->orWhere('receipt_number', 'like', 'REC-27223-%')->orWhere('idempotency_key', 'like', 'myskool-paid-slip-27223-row-%')
                    ->orWhere('note', 'like', 'Collection slip #27223 (%');
            })->orderBy('id')->lockForUpdate()->get(),
            'cashier_allocations' => $db->table('payment_allocations')->whereIn('invoice_item_id', $items->pluck('id'))->orderBy('id')->lockForUpdate()->get(),
        ];
    }

    private function requireState(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
