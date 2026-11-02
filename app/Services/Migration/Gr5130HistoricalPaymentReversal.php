<?php

namespace App\Services\Migration;

use App\Services\BatchDispatchLock;
use App\Services\FeeAdjustmentService;
use App\Services\Staging\DatabaseSafetyGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Gr5130HistoricalPaymentReversal extends HistoricalPaymentReconciliationService
{
    public const AUDIT_ACTION = 'gr5130_correction_fully_reversed';

    /** Default is read-only; reversal approval is separate from correction approval. */
    public function run(array $options = []): array
    {
        DatabaseSafetyGuard::assertTargetDatabase();
        $apply = !empty($options['apply']);
        if ($apply) {
            $this->requireState(!empty($options['confirm-owner']) && trim((string) ($options['reason'] ?? '')) !== '', 'Explicit owner reversal confirmation and reason required.');
            $this->requireState(config('reconciliation.allow_apply', false)
                || filter_var(env('ALLOW_HISTORICAL_RECONCILIATION_APPLY', false), FILTER_VALIDATE_BOOLEAN), 'Historical reconciliation apply is disabled by configuration.');
            $this->requireState(!empty($options['actor-id']), 'Explicit administrator --actor-id required.');
            $this->requireState(!empty($options['approve-fingerprint']), 'Approved reversal dry-run fingerprint required.');
        }

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
                $reconciliationId = Gr5130HistoricalPaymentCorrection::RECONCILIATION_ID;
                $correction = app(Gr5130HistoricalPaymentCorrection::class);
                // Normalize database objects to arrays for exact comparison with JSON audit evidence.
                $state = $this->normalize($correction->lockedCorrectionState());
                $settlements = array_values(array_filter($state['settlements'], fn ($row) => $row['reconciliation_id'] === $reconciliationId));
                $correctionAudits = $db->table('audit_logs')->where('subject_type', 'invoice_item')->where('subject_id', 13378)
                    ->where('action', 'gr5130_discount_reclassified_as_historical_payment')->orderBy('id')->lockForUpdate()->get();
                $reversalAudits = $db->table('audit_logs')->where('subject_type', 'invoice_item')->where('subject_id', 13378)
                    ->where('action', self::AUDIT_ACTION)->orderBy('id')->lockForUpdate()->get();
                if (count($settlements) === 0) {
                    $this->requireState($correctionAudits->isEmpty() && $reversalAudits->isEmpty(), 'Incomplete correction evidence; manual review required.');
                    return ['status' => 'not_applied', 'financial_writes' => false, 'reconciliation_id' => $reconciliationId];
                }
                $this->requireState(count($settlements) === 1 && $correctionAudits->count() === 1, 'Correction audit or settlement is missing or ambiguous.');
                $settlement = $settlements[0];
                $audit = $correctionAudits[0];
                $metadata = $this->normalize(json_decode($audit->metadata, true, 512, JSON_THROW_ON_ERROR));
                $this->requireState((int) $settlement['invoice_item_id'] === 13378 && (int) $settlement['settled_amount_minor'] === 27000
                    && $settlement['student_gr'] === '5130' && $settlement['currency'] === 'SAR'
                    && (int) ($metadata['settlement_id'] ?? 0) === (int) $settlement['id'] && !empty($metadata['post_state']), 'Correction evidence identity mismatch.');

                if ($settlement['status'] === 'reversed') {
                    $this->requireState($reversalAudits->count() === 1, 'Settlement-only reversal detected; full recovery requires manual review.');
                    $reversed = $this->normalize(json_decode($reversalAudits[0]->metadata, true, 512, JSON_THROW_ON_ERROR));
                    $this->requireState((int) ($reversed['correction_audit_id'] ?? 0) === (int) $audit->id
                        && $state === ($reversed['post_state'] ?? null), 'Reversed correction changed; manual review required.');
                    return ['status' => 'already_reversed', 'financial_writes' => false, 'reconciliation_id' => $reconciliationId];
                }
                $this->requireState($settlement['status'] === 'applied' && $reversalAudits->isEmpty()
                    && $state === $metadata['post_state'], 'LIVE_ACTIVITY_HOLD: Post-correction state changed; reversal requires fresh administrative review.');
                $this->requireState(!$this->hasConsumedCollectionIds($db, 13378, [74218]), 'Historical source is also consumed by another item; manual review required.');
                $before = $metadata['before'];
                $this->requireState((int) $before['item']['amount_minor'] === 33000 && (int) $before['item']['discount_minor'] === 27000
                    && (int) $before['item']['net_minor'] === 6000 && (int) $before['item']['paid_minor'] === 0
                    && $before['item']['status'] === 'active' && count($state['adjustments']) === 2
                    && $state['adjustments'][1]['kind'] === 'discount_removed'
                    && (int) $state['adjustments'][1]['amount_minor'] === 27000
                    && $state['cashier_allocations'] === [] && $state['source_corrections'] === [], 'Recovery evidence does not represent the exact correction.');
                $fingerprint = hash('sha256', 'gr5130-reversal-v1:'.json_encode(['state' => $state, 'correction_audit_id' => $audit->id, 'metadata' => $metadata], JSON_THROW_ON_ERROR));
                $result = ['status' => 'dry_run', 'financial_writes' => false, 'reconciliation_id' => $reconciliationId,
                    'fingerprint' => $fingerprint, 'before' => ['gross_minor' => 33000, 'discount_minor' => 0, 'net_minor' => 33000, 'paid_minor' => 27000, 'outstanding_minor' => 6000, 'status' => 'partial'],
                    'after' => ['gross_minor' => 33000, 'discount_minor' => 27000, 'net_minor' => 6000, 'paid_minor' => 0, 'outstanding_minor' => 6000, 'status' => 'active'],
                    'snapshots' => array_map(fn ($row) => ['invoice_id' => $row['invoice_id'], 'before_snapshot_minor' => $row['after_snapshot_minor'], 'after_snapshot_minor' => $row['before_snapshot_minor']], $metadata['snapshots']),
                    'correction_audit_id' => $audit->id];
                if (!$apply) {
                    return $result;
                }
                $this->requireState(hash_equals($fingerprint, (string) $options['approve-fingerprint']), 'Reversal dry-run fingerprint changed; obtain fresh approval.');
                $reason = $reconciliationId.': complete reversal; '.$options['reason'];
                // Both reused services nest inside this single outer transaction. Any failure rolls back both.
                parent::reverse($reconciliationId, $actorId);
                app(FeeAdjustmentService::class)->applyDiscount(13378, 27000, $reason, $actorId);
                foreach ($metadata['snapshots'] as $snapshot) {
                    $db->table('invoices')->where('id', $snapshot['invoice_id'])->update([
                        'previous_dues_snapshot_minor' => $snapshot['before_snapshot_minor'], 'updated_at' => now(),
                    ]);
                }
                $postState = $this->normalize($correction->lockedCorrectionState());
                foreach (['amount_minor', 'discount_minor', 'exemption_minor', 'net_minor', 'paid_minor', 'status', 'invoice_id'] as $field) {
                    $this->requireState($postState['item'][$field] === $before['item'][$field], 'Recovery item invariant failed: '.$field);
                }
                foreach (['total_minor', 'paid_minor', 'status', 'previous_dues_snapshot_minor'] as $field) {
                    $this->requireState($postState['invoice'][$field] === $before['invoice'][$field], 'Recovery invoice invariant failed: '.$field);
                }
                $db->table('audit_logs')->insert([
                    'actor_id' => $actorId, 'action' => self::AUDIT_ACTION, 'subject_type' => 'invoice_item', 'subject_id' => 13378,
                    'metadata' => json_encode(['reconciliation_id' => $reconciliationId, 'correction_audit_id' => $audit->id,
                        'settlement_id' => $settlement['id'], 'reason' => $reason, 'approved_fingerprint' => $fingerprint,
                        'discount_removal_adjustment_id' => $state['adjustments'][1]['id'],
                        'discount_restoration_adjustment_id' => $postState['adjustments'][2]['id'],
                        'before_state' => $state, 'post_state' => $postState, 'snapshots' => $result['snapshots']], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                return array_replace($result, ['status' => 'reversed', 'financial_writes' => true]);
            }, 3);
        } finally {
            $lock->release();
        }
    }

    private function normalize(array $state): array
    {
        $state = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        // MySQL JSON columns may reorder object keys. Preserve ordered lists while canonicalizing maps.
        foreach ($state as $key => $value) {
            if (is_array($value)) {
                $state[$key] = $this->normalize($value);
            }
        }
        if (!array_is_list($state)) {
            ksort($state, SORT_STRING);
        }
        return $state;
    }

    private function requireState(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
