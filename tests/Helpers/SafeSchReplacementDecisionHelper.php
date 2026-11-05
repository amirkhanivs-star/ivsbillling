<?php

namespace Tests\Helpers;

use App\Services\BatchDispatchLock;
use App\Services\ConsolidatedInvoiceBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Isolated decision helper for Phase B.2A Safe SCH Replacement Verification.
 *
 * NOTE: This is strictly a standalone non-production decision helper for testing.
 * It is NOT wired into invoice generation, controllers, delivery jobs,
 * notification processing, or batch dispatch.
 */
class SafeSchReplacementDecisionHelper
{
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
        return app(\App\Services\SafeSchReplacementService::class)->evaluateAndSupersede(
            $olderNotificationId,
            $candidateInvoiceId,
            $approver,
            $providedLock
        );
    }
}
