<?php

namespace App\Services;

use App\Services\BatchDispatchLock;
use App\Services\ConsolidatedInvoiceBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service for Phase B.2C Generation-Time Safe SCH Replacement.
 *
 * Automatically supersedes older, genuinely unsent SCH delivery notifications
 * when a newer authoritative SCH is generated for the same family, while preserving
 * financial integrity, Phase 3A cross-month protection, and worker concurrency safety.
 */
class SafeSchReplacementService
{
    /**
     * Process newly generated invoice identities after generation transaction commit.
     * Evaluates whether older, genuinely unsent SCH delivery notifications for the same
     * families can be safely superseded by these authoritative new invoices.
     *
     * @param array $newInvoiceIds Newly created invoice IDs from the billing run
     * @param object|null $user The actor who triggered the billing run
     * @return array Array of evaluation/replacement results
     */
    public function processNewInvoices(array $newInvoiceIds, ?object $user = null): array
    {
        if (empty($newInvoiceIds)) {
            return [];
        }

        $results = [];
        foreach ($newInvoiceIds as $invId) {
            $candidateInvoice = DB::table('invoices')->where('id', $invId)->first();
            if (!$candidateInvoice || in_array(strtolower($candidateInvoice->status ?? ''), ['void', 'voided'], true)) {
                continue;
            }

            // Find all eligible older queued notifications for the family with matching currency
            $olderNotifications = DB::table('notifications as n')
                ->join('invoices as i', 'i.id', '=', 'n.invoice_id')
                ->where('i.family_id', $candidateInvoice->family_id)
                ->where('i.currency', $candidateInvoice->currency)
                ->where(function ($q) {
                    $q->where('n.webhook_type', 'monthly')
                      ->orWhere('n.kind', 'monthly');
                })
                ->where('n.status', 'queued')
                ->where('n.attempts', 0)
                ->whereNull('n.last_attempt_at')
                ->whereNull('n.submitted_at')
                ->where('n.is_superseded', false)
                ->where('i.id', '!=', $candidateInvoice->id)
                ->select('n.id')
                ->get();

            foreach ($olderNotifications as $oldNotif) {
                $results[] = $this->evaluateAndSupersede((int) $oldNotif->id, (int) $invId, $user);
            }
        }

        return $results;
    }

    /**
     * Evaluate whether an older queued notification can be safely superseded
     * by a newer authoritative SCH invoice, and if eligible, execute the atomic transition.
     *
     * Returns an array with:
     * - 'decision': 'REPLACE' or 'NO_REPLACE'
     * - 'reason': Detailed explanation of pass/fail condition
     * - 'lock_acquired': bool
     * - 'replaced': bool
     * - 'authoritative_invoice_id': ?int
     * - 'superseded_notification_id': ?int
     */
    public function evaluateAndSupersede(
        int $olderNotificationId,
        int $candidateInvoiceId,
        ?object $approver = null,
        ?BatchDispatchLock $providedLock = null
    ): array {
        // 1. Initial pre-lock inspection of candidate invoice and older notification
        $olderNotif = DB::table('notifications')->where('id', $olderNotificationId)->first();
        if (!$olderNotif) {
            return [
                'decision' => 'NO_REPLACE',
                'reason' => 'Older notification does not exist.',
                'lock_acquired' => false,
                'replaced' => false,
                'authoritative_invoice_id' => null,
                'superseded_notification_id' => null,
            ];
        }

        $olderInvoice = DB::table('invoices')->where('id', $olderNotif->invoice_id)->first();
        if (!$olderInvoice) {
            return [
                'decision' => 'NO_REPLACE',
                'reason' => 'Invoice for older notification does not exist.',
                'lock_acquired' => false,
                'replaced' => false,
                'authoritative_invoice_id' => null,
                'superseded_notification_id' => null,
            ];
        }

        $candidateInvoice = DB::table('invoices')->where('id', $candidateInvoiceId)->first();
        if (!$candidateInvoice) {
            return [
                'decision' => 'NO_REPLACE',
                'reason' => 'Candidate invoice does not exist.',
                'lock_acquired' => false,
                'replaced' => false,
                'authoritative_invoice_id' => null,
                'superseded_notification_id' => null,
            ];
        }

        // Section 3: Authoritative SCH Verification
        // Condition: Same family
        if ((int) $candidateInvoice->family_id !== (int) $olderInvoice->family_id) {
            return [
                'decision' => 'NO_REPLACE',
                'reason' => 'Candidate invoice family does not match older invoice family.',
                'lock_acquired' => false,
                'replaced' => false,
                'authoritative_invoice_id' => null,
                'superseded_notification_id' => null,
            ];
        }

        // Condition: Matching financial currency
        if ($candidateInvoice->currency !== $olderInvoice->currency) {
            return [
                'decision' => 'NO_REPLACE',
                'reason' => 'Financial currency mismatch between candidate and older invoice.',
                'lock_acquired' => false,
                'replaced' => false,
                'authoritative_invoice_id' => null,
                'superseded_notification_id' => null,
            ];
        }

        // Condition: Non-void status
        if (in_array(strtolower($candidateInvoice->status ?? ''), ['void', 'voided'], true)) {
            return [
                'decision' => 'NO_REPLACE',
                'reason' => 'Candidate invoice is voided.',
                'lock_acquired' => false,
                'replaced' => false,
                'authoritative_invoice_id' => null,
                'superseded_notification_id' => null,
            ];
        }

        // Condition: The replacement candidate must be the actual newest valid SCH record,
        // ordered by invoices.created_at DESC, invoices.id DESC (NOT independently calculated MAX values)
        $newestValidSch = DB::table('invoices')
            ->where('family_id', $candidateInvoice->family_id)
            ->where('currency', $candidateInvoice->currency)
            ->whereNotIn('status', ['void', 'voided'])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if (!$newestValidSch || (int) $newestValidSch->id !== (int) $candidateInvoice->id) {
            return [
                'decision' => 'NO_REPLACE',
                'reason' => 'Candidate invoice is not the authoritative newest valid SCH record ordered by (created_at DESC, id DESC).',
                'lock_acquired' => false,
                'replaced' => false,
                'authoritative_invoice_id' => $newestValidSch ? (int) $newestValidSch->id : null,
                'superseded_notification_id' => null,
            ];
        }

        // Ensure candidate invoice is strictly newer in canonical ordering than older invoice
        if ($candidateInvoice->created_at < $olderInvoice->created_at ||
            ($candidateInvoice->created_at === $olderInvoice->created_at && (int) $candidateInvoice->id <= (int) $olderInvoice->id)) {
            return [
                'decision' => 'NO_REPLACE',
                'reason' => 'Candidate invoice is not strictly newer than older invoice in canonical order.',
                'lock_acquired' => false,
                'replaced' => false,
                'authoritative_invoice_id' => (int) $candidateInvoice->id,
                'superseded_notification_id' => null,
            ];
        }

        // Condition: Department and campus authorization scope
        $builder = app(ConsolidatedInvoiceBuilder::class);
        if ($approver && !$builder->canDispatchFullFamilyStatement($approver)) {
            return [
                'decision' => 'NO_REPLACE',
                'reason' => 'Unauthorized campus/department scope: approver lacks permission to dispatch full family statement.',
                'lock_acquired' => false,
                'replaced' => false,
                'authoritative_invoice_id' => (int) $candidateInvoice->id,
                'superseded_notification_id' => null,
            ];
        }

        // Condition: Financial integrity and verified outstanding obligations
        $familyEligibility = $builder->evaluateFamilyDispatchEligibility(
            (int) $candidateInvoice->family_id,
            null,
            $approver
        );

        if (!$familyEligibility['can_dispatch'] || !empty($familyEligibility['has_data_integrity_issue']) || empty($familyEligibility['is_payable_verified'])) {
            return [
                'decision' => 'NO_REPLACE',
                'reason' => 'Financial integrity check failed on candidate invoice: ' . ($familyEligibility['block_reason'] ?? 'Unverified obligations or data integrity issue.'),
                'lock_acquired' => false,
                'replaced' => false,
                'authoritative_invoice_id' => (int) $candidateInvoice->id,
                'superseded_notification_id' => null,
            ];
        }

        // Condition: Valid consolidated financial totals
        // Verify that candidate invoice has positive remaining/total obligations
        if ((int) $candidateInvoice->total_minor <= 0 || (int) $candidateInvoice->paid_minor >= (int) $candidateInvoice->total_minor) {
            return [
                'decision' => 'NO_REPLACE',
                'reason' => 'Invalid consolidated financial totals: candidate invoice has no outstanding obligations.',
                'lock_acquired' => false,
                'replaced' => false,
                'authoritative_invoice_id' => (int) $candidateInvoice->id,
                'superseded_notification_id' => null,
            ];
        }

        // Condition: New consolidated document includes all applicable outstanding obligations of older document
        // All active unpaid items from older invoice must be represented or accounted for in family outstanding balance
        $olderUnpaidItemsCount = DB::table('invoice_items')
            ->where('invoice_id', $olderInvoice->id)
            ->whereNotIn('status', ['void', 'voided'])
            ->whereColumn('paid_minor', '<', 'amount_minor')
            ->count();

        if ($olderUnpaidItemsCount > 0) {
            // Verify candidate invoice items cover older period or carry-forward balance
            $candidateTotalMinor = (int) $candidateInvoice->total_minor;
            $olderOutstandingMinor = (int) ($olderInvoice->total_minor - $olderInvoice->paid_minor);
            if ($candidateTotalMinor < $olderOutstandingMinor) {
                return [
                    'decision' => 'NO_REPLACE',
                    'reason' => 'Candidate invoice does not encompass older outstanding obligations (candidate total minor < older outstanding minor).',
                    'lock_acquired' => false,
                    'replaced' => false,
                    'authoritative_invoice_id' => (int) $candidateInvoice->id,
                    'superseded_notification_id' => null,
                ];
            }
        }

        // Section 4: Replacement Eligibility of Older Notification
        // Must be monthly SCH delivery notification
        if (!in_array($olderNotif->webhook_type ?? $olderNotif->kind, ['monthly'], true) && $olderNotif->kind !== 'invoice') {
            return [
                'decision' => 'NO_REPLACE',
                'reason' => 'Older notification is not a monthly SCH invoice delivery notification.',
                'lock_acquired' => false,
                'replaced' => false,
                'authoritative_invoice_id' => (int) $candidateInvoice->id,
                'superseded_notification_id' => null,
            ];
        }

        // Initial pre-lock check of notification status
        if ($olderNotif->status !== 'queued') {
            return [
                'decision' => 'NO_REPLACE',
                'reason' => "Older notification status is '{$olderNotif->status}', expected exactly 'queued'.",
                'lock_acquired' => false,
                'replaced' => false,
                'authoritative_invoice_id' => (int) $candidateInvoice->id,
                'superseded_notification_id' => null,
            ];
        }

        if ((int) $olderNotif->attempts !== 0) {
            return [
                'decision' => 'NO_REPLACE',
                'reason' => "Older notification attempts count is {$olderNotif->attempts}, expected exactly 0.",
                'lock_acquired' => false,
                'replaced' => false,
                'authoritative_invoice_id' => (int) $candidateInvoice->id,
                'superseded_notification_id' => null,
            ];
        }

        if ($olderNotif->last_attempt_at !== null) {
            return [
                'decision' => 'NO_REPLACE',
                'reason' => 'Older notification last_attempt_at is not null.',
                'lock_acquired' => false,
                'replaced' => false,
                'authoritative_invoice_id' => (int) $candidateInvoice->id,
                'superseded_notification_id' => null,
            ];
        }

        if ($olderNotif->submitted_at !== null) {
            return [
                'decision' => 'NO_REPLACE',
                'reason' => 'Older notification submitted_at is not null.',
                'lock_acquired' => false,
                'replaced' => false,
                'authoritative_invoice_id' => (int) $candidateInvoice->id,
                'superseded_notification_id' => null,
            ];
        }

        if ((bool) $olderNotif->is_superseded) {
            return [
                'decision' => 'NO_REPLACE',
                'reason' => 'Older notification is already marked superseded.',
                'lock_acquired' => false,
                'replaced' => false,
                'authoritative_invoice_id' => (int) $candidateInvoice->id,
                'superseded_notification_id' => null,
            ];
        }

        // Batch lock acquisition
        $batchId = $olderNotif->batch_id;
        $lock = $providedLock ?: app(BatchDispatchLock::class);
        $lockKey = $batchId ?: 'single-notif-' . $olderNotificationId;

        if (!$lock->acquire($lockKey)) {
            return [
                'decision' => 'NO_REPLACE',
                'reason' => 'Could not acquire BatchDispatchLock (batch is currently locked by active worker or concurrent replacement).',
                'lock_acquired' => false,
                'replaced' => false,
                'authoritative_invoice_id' => (int) $candidateInvoice->id,
                'superseded_notification_id' => null,
            ];
        }

        try {
            // Revalidation INSIDE the lock
            $revalidatedNotif = DB::table('notifications')->where('id', $olderNotificationId)->first();
            if (!$revalidatedNotif) {
                return [
                    'decision' => 'NO_REPLACE',
                    'reason' => 'Older notification disappeared after lock acquisition.',
                    'lock_acquired' => true,
                    'replaced' => false,
                    'authoritative_invoice_id' => (int) $candidateInvoice->id,
                    'superseded_notification_id' => null,
                ];
            }

            if ($revalidatedNotif->status !== 'queued' ||
                (int) $revalidatedNotif->attempts !== 0 ||
                $revalidatedNotif->last_attempt_at !== null ||
                $revalidatedNotif->submitted_at !== null ||
                (bool) $revalidatedNotif->is_superseded) {
                return [
                    'decision' => 'NO_REPLACE',
                    'reason' => 'Older notification state changed before lock acquisition and is no longer eligible.',
                    'lock_acquired' => true,
                    'replaced' => false,
                    'authoritative_invoice_id' => (int) $candidateInvoice->id,
                    'superseded_notification_id' => null,
                ];
            }

            // Check if batch is currently processing
            if ($batchId) {
                $batch = DB::table('notification_batches')->where('batch_id', $batchId)->first();
                if ($batch && in_array($batch->status, ['processing', 'submitted', 'completed'], true)) {
                    return [
                        'decision' => 'NO_REPLACE',
                        'reason' => "Parent batch '{$batchId}' status is '{$batch->status}', active worker has started transmitting.",
                        'lock_acquired' => true,
                        'replaced' => false,
                        'authoritative_invoice_id' => (int) $candidateInvoice->id,
                        'superseded_notification_id' => null,
                    ];
                }
            }

            // Conditional atomic database update requiring original queued and unattempted state
            $updated = false;
            DB::transaction(function () use ($olderNotificationId, $candidateInvoiceId, $approver, &$updated) {
                $affected = DB::table('notifications')
                    ->where('id', $olderNotificationId)
                    ->where('status', 'queued')
                    ->where('attempts', 0)
                    ->whereNull('last_attempt_at')
                    ->whereNull('submitted_at')
                    ->where('is_superseded', false)
                    ->update([
                        'status' => 'superseded',
                        'is_superseded' => true,
                        'superseded_by_invoice_id' => $candidateInvoiceId,
                        'error' => 'Superseded by newer authoritative consolidated invoice ID ' . $candidateInvoiceId,
                        'updated_at' => now(),
                    ]);

                if ($affected > 0) {
                    $updated = true;
                    if (Schema::hasTable('audit_logs')) {
                        DB::table('audit_logs')->insert([
                            'actor_id' => $approver?->id ?? 1,
                            'action' => 'notification.superseded_by_sch_generation',
                            'subject_type' => 'notification',
                            'subject_id' => $olderNotificationId,
                            'metadata' => json_encode([
                                'older_notification_id' => $olderNotificationId,
                                'superseded_by_invoice_id' => $candidateInvoiceId,
                                'reason' => 'Automatically superseded by newly generated authoritative SCH invoice',
                            ]),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });

            if (!$updated) {
                return [
                    'decision' => 'NO_REPLACE',
                    'reason' => 'Conditional update failed: older notification was modified concurrently.',
                    'lock_acquired' => true,
                    'replaced' => false,
                    'authoritative_invoice_id' => (int) $candidateInvoiceId,
                    'superseded_notification_id' => null,
                ];
            }

            return [
                'decision' => 'REPLACE',
                'reason' => 'Eligible: older notification safely superseded by newer authoritative SCH invoice.',
                'lock_acquired' => true,
                'replaced' => true,
                'authoritative_invoice_id' => (int) $candidateInvoice->id,
                'superseded_notification_id' => $olderNotificationId,
            ];
        } finally {
            $lock->release();
        }
    }
}
