<?php

namespace App\Services;

use App\Services\Migration\HistoricalOpeningBalanceService;
use App\Services\Migration\MySkoolReconciliationAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class VoidedFeeRestorationService
{
    public const SAFETY_SAFE_NORMAL = 'SAFE_NORMAL_RESTORATION';
    public const SAFETY_SAFE_ATOMIC_SETTLED = 'SAFE_ATOMIC_HISTORICAL_SETTLEMENT';
    public const SAFETY_SAFE_PARTIAL_SETTLED = 'SAFE_PARTIAL_HISTORICAL_SETTLEMENT';
    public const SAFETY_BLOCKED_ALREADY_ACTIVE = 'BLOCKED_ALREADY_ACTIVE';
    public const SAFETY_BLOCKED_DUPLICATE_ACTIVE = 'BLOCKED_DUPLICATE_ACTIVE_ITEM';
    public const SAFETY_BLOCKED_INTERVENING_PAYMENTS = 'BLOCKED_INTERVENING_PAYMENTS';
    public const SAFETY_BLOCKED_INSUFFICIENT_EVIDENCE = 'BLOCKED_INSUFFICIENT_EVIDENCE';
    public const SAFETY_BLOCKED_CURRENCY_CONFLICT = 'BLOCKED_CURRENCY_CONFLICT';
    public const SAFETY_BLOCKED_CONSTRAINT_CONFLICT = 'BLOCKED_CONSTRAINT_CONFLICT';

    /**
     * Inspect a voided invoice item with comprehensive accounting forensics.
     * Admin-only read operation without making any financial changes.
     */
    public function inspect(int $invoiceItemId, ?string $conn = null, bool $lockForUpdate = false): array
    {
        $conn = $conn ?: config('database.default', 'mysql');
        $db = DB::connection($conn);

        $itemQuery = $db->table('invoice_items as ii')
            ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
            ->leftJoin('billing_runs as br', 'br.id', '=', 'inv.billing_run_id')
            ->leftJoin('families as f', 'f.id', '=', 'inv.family_id')
            ->leftJoin('students as s', 's.id', '=', 'ii.student_id')
            ->leftJoin('fee_types as ft', 'ft.id', '=', 'ii.fee_type_id')
            ->where('ii.id', $invoiceItemId)
            ->select(
                'ii.*',
                'inv.invoice_number',
                'inv.family_id',
                'inv.billing_run_id',
                'inv.status as invoice_status',
                'inv.total_minor as invoice_total_minor',
                'inv.paid_minor as invoice_paid_minor',
                'br.frequency as billing_run_frequency',
                'f.family_code',
                's.gr_number',
                's.name as student_name',
                'ft.name as fee_type_name',
                'ft.code as fee_type_code'
            );

        if ($lockForUpdate) {
            $itemQuery->lockForUpdate();
        }

        $item = $itemQuery->first();
        if (!$item) {
            return [
                'exists' => false,
                'is_safe_to_restore' => false,
                'safety_status' => self::SAFETY_BLOCKED_INSUFFICIENT_EVIDENCE,
                'error' => "Invoice item #{$invoiceItemId} not found.",
            ];
        }

        // 1. Verify item voided status
        if ($item->status !== 'voided') {
            return [
                'exists' => true,
                'invoice_item_id' => $invoiceItemId,
                'is_safe_to_restore' => false,
                'safety_status' => self::SAFETY_BLOCKED_ALREADY_ACTIVE,
                'error' => "Invoice item #{$invoiceItemId} is not in voided status (current status: '{$item->status}'). Only voided items can be restored.",
                'item' => $item,
            ];
        }

        // 2. Fetch original void adjustment record for audit evidence
        $voidAdjustment = $db->table('fee_adjustments')
            ->where('invoice_item_id', $invoiceItemId)
            ->where('kind', 'void')
            ->orderByDesc('id')
            ->first();

        // 3. Determine original approved financial values
        $amountMinor = (int) $item->amount_minor;
        $discountMinor = (int) $item->discount_minor;
        $exemptionMinor = (int) $item->exemption_minor;
        $calculatedNetMinor = max(0, $amountMinor - $discountMinor - $exemptionMinor);

        $voidPreviousAmountMinor = $voidAdjustment ? (int) $voidAdjustment->previous_amount_minor : 0;
        $approvedNetMinor = $calculatedNetMinor > 0 ? $calculatedNetMinor : $voidPreviousAmountMinor;

        // Check opening balance lineage if applicable
        $hasLineageTable = Schema::connection($conn)->hasTable('qa_opening_balance_lineage');
        $lineageRow = null;
        $stagedDueRow = null;
        if ($hasLineageTable) {
            $lineageRow = $db->table('qa_opening_balance_lineage')->where('invoice_item_id', $invoiceItemId)->first();
            if ($lineageRow && Schema::connection($conn)->hasTable('qa_staged_dues')) {
                $stagedDueRow = $db->table('qa_staged_dues')->where('id', $lineageRow->qa_staged_due_id)->first();
                if ($stagedDueRow && $approvedNetMinor <= 0) {
                    $approvedNetMinor = (int) ($stagedDueRow->balance_minor ?? $stagedDueRow->outstanding_amount_minor ?? 0);
                }
            }
        }

        if ($approvedNetMinor <= 0) {
            return [
                'exists' => true,
                'invoice_item_id' => $invoiceItemId,
                'is_safe_to_restore' => false,
                'safety_status' => self::SAFETY_BLOCKED_INSUFFICIENT_EVIDENCE,
                'error' => "Could not verify original approved financial value for item #{$invoiceItemId}. Refusing unsafe restoration.",
                'item' => $item,
            ];
        }

        // 4. Check for active duplicate invoice item for same student/fee_type/period
        $duplicateQuery = $db->table('invoice_items as ii')
            ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
            ->where('ii.id', '!=', $invoiceItemId)
            ->where('ii.student_id', $item->student_id)
            ->where('ii.fee_type_id', $item->fee_type_id)
            ->where('ii.status', '!=', 'voided')
            ->whereNotIn('inv.status', ['void', 'voided']);

        $isOneTime = !in_array($item->fee_type_code, ['monthly', 'tuition'], true);
        if ($isOneTime) {
            $duplicateQuery->where(function ($q) use ($item) {
                $q->where('ii.period_key', 'LIKE', 'one_time:%')
                  ->orWhere('ii.period_start', $item->period_start);
            });
        } else {
            $duplicateQuery->where('ii.period_start', $item->period_start);
        }

        $activeDuplicate = $duplicateQuery->select('ii.id', 'inv.invoice_number', 'ii.status', 'ii.net_minor', 'ii.paid_minor')->first();
        if ($activeDuplicate) {
            return [
                'exists' => true,
                'invoice_item_id' => $invoiceItemId,
                'is_safe_to_restore' => false,
                'safety_status' => self::SAFETY_BLOCKED_DUPLICATE_ACTIVE,
                'error' => "Cannot restore item #{$invoiceItemId}: an active invoice item (#{$activeDuplicate->id} on {$activeDuplicate->invoice_number}) already exists for this fee obligation and period.",
                'active_duplicate' => $activeDuplicate,
                'item' => $item,
            ];
        }

        // 5. Inspect historical MySkool settlements & intervening cashier payments on this item
        $hasSettlementsTable = Schema::connection($conn)->hasTable('qa_historical_settlements');
        $existingSettlement = null;
        if ($hasSettlementsTable) {
            $existingSettlement = $db->table('qa_historical_settlements')
                ->where('invoice_item_id', $invoiceItemId)
                ->where('status', 'applied')
                ->first();
        }

        $activeAllocations = $db->table('payment_allocations')
            ->where('invoice_item_id', $invoiceItemId)
            ->where('amount_minor', '>', 0)
            ->get();

        if ($activeAllocations->isNotEmpty() || (!$existingSettlement && (int) $item->paid_minor > 0)) {
            return [
                'exists' => true,
                'invoice_item_id' => $invoiceItemId,
                'is_safe_to_restore' => false,
                'safety_status' => self::SAFETY_BLOCKED_INTERVENING_PAYMENTS,
                'error' => "Cannot restore item #{$invoiceItemId}: active cashier payment allocations or paid balances exist on this item. Intervening cashier receipts must be resolved first.",
                'item' => $item,
            ];
        }

        // 6. Inspect historical MySkool collections across all currencies to detect cross-currency conflicts and settlements
        $historicalCollections = collect();
        $crossCurrencyCollections = collect();
        $hasStagedCurrencyConflict = false;

        if ($stagedDueRow) {
            $dueCurr = strtoupper(trim((string) ($stagedDueRow->currency ?? '')));
            if (($dueCurr !== '' && $dueCurr !== strtoupper((string) $item->currency))
                || !empty($stagedDueRow->is_currency_unresolved)
                || !empty($stagedDueRow->has_currency_conflict)
            ) {
                $hasStagedCurrencyConflict = true;
            }
        }

        if (Schema::connection($conn)->hasTable('qa_staged_collections') && !empty($item->gr_number)) {
            $activeBatch = (string) config('imports.active_batch_id', 'BATCH-20261025-FINAL-MYSKOOL');
            $colQuery = $db->table('qa_staged_collections')
                ->where('gr_number', (string) $item->gr_number);

            if ((clone $colQuery)->where('batch_id', $activeBatch)->exists()) {
                $colQuery->where('batch_id', $activeBatch);
            }

            $rawCols = MySkoolReconciliationAuditService::deduplicateCollections(
                $colQuery->orderBy('payment_date_parsed')->orderBy('source_row')->get()
            );

            $itemIdentity = MySkoolReconciliationAuditService::parseFeeIdentity((string) $item->description, (string) $item->period_key);

            // Filter collections matching this period and fee identity
            foreach ($rawCols as $col) {
                $parsed = HistoricalOpeningBalanceService::parseParticularPeriod((string) $col->fee_particular);
                $colIdentity = MySkoolReconciliationAuditService::parseFeeIdentity((string) $col->fee_particular);
                $typeMatches = ($colIdentity['type'] === $itemIdentity['type']) && ($colIdentity['subject'] === $itemIdentity['subject']);
                if ($parsed && !empty($parsed['period_start']) && $parsed['period_start'] === $item->period_start && $typeMatches) {
                    $colCurr = strtoupper(trim((string) ($col->currency ?? '')));
                    $itemCurr = strtoupper(trim((string) $item->currency));
                    if ($colCurr !== $itemCurr || !empty($col->is_currency_unresolved) || !empty($col->has_currency_conflict)) {
                        $crossCurrencyCollections->push($col);
                    } else {
                        $historicalCollections->push($col);
                    }
                }
            }
        }

        if ($hasStagedCurrencyConflict || $crossCurrencyCollections->isNotEmpty()) {
            $conflictCurr = $crossCurrencyCollections->pluck('currency')->unique()->implode(', ');
            return [
                'exists' => true,
                'invoice_item_id' => $invoiceItemId,
                'is_safe_to_restore' => false,
                'safety_status' => self::SAFETY_BLOCKED_CURRENCY_CONFLICT,
                'action_description' => 'Restoration blocked due to cross-currency conflict with historical MySkool records.',
                'error' => "Cannot restore item #{$invoiceItemId}: cross-currency conflict detected between invoice item ({$item->currency}) and historical MySkool records ({$conflictCurr}).",
                'original_approved_values' => [
                    'amount_minor' => $amountMinor,
                    'discount_minor' => $discountMinor,
                    'exemption_minor' => $exemptionMinor,
                    'net_minor' => $approvedNetMinor,
                    'net_formatted' => number_format($approvedNetMinor / 100, 2, '.', ''),
                    'currency' => (string) $item->currency,
                ],
                'proposed_restoration' => [
                    'target_status' => 'voided',
                    'restored_net_minor' => 0,
                    'settled_paid_minor' => 0,
                    'remaining_collectible_minor' => 0,
                    'requires_concession_review' => false,
                ],
                'item' => $item,
            ];
        }

        $cutoffDate = MySkoolReconciliationAuditService::DEFAULT_CUTOFF_DATE;
        $postCutoffCols = $historicalCollections->filter(fn($c) => !empty($c->payment_date_parsed) && $c->payment_date_parsed > $cutoffDate)->values();
        $preCutoffCols = $historicalCollections->filter(fn($c) => empty($c->payment_date_parsed) || $c->payment_date_parsed <= $cutoffDate)->values();
        $postCutoffPaidMinor = (int) $postCutoffCols->sum('amount_minor');
        $preCutoffPaidMinor = (int) $preCutoffCols->sum('amount_minor');
        $postCutoffCollectionIds = $postCutoffCols->pluck('id')->map(fn($id) => (int) $id)->all();

        // Check if any matched source collection row has already been consumed by another invoice item
        if ($hasSettlementsTable && !empty($postCutoffCollectionIds) && $this->hasConsumedCollectionIds($db, $invoiceItemId, $postCutoffCollectionIds)) {
            return [
                'exists' => true,
                'invoice_item_id' => $invoiceItemId,
                'is_safe_to_restore' => false,
                'safety_status' => self::SAFETY_BLOCKED_CONSTRAINT_CONFLICT,
                'error' => 'CRITICAL INTEGRITY VIOLATION: Historical source collection has already been consumed by another invoice item.',
                'item' => $item,
            ];
        }

        // Check if existing settlement already covered this
        $settledMinor = $existingSettlement ? (int) $existingSettlement->settled_amount_minor : $postCutoffPaidMinor;

        // Protect against bypassing historical obligation protections when restoring as unpaid
        $isOpeningBalanceItem = ($lineageRow !== null)
            || (($item->billing_run_frequency ?? '') === 'opening_balance')
            || str_starts_with((string) $item->invoice_number, 'SCH-OB-');

        if ($settledMinor === 0 && !$existingSettlement) {
            if ($preCutoffPaidMinor > 0 && (!$isOpeningBalanceItem || (!$stagedDueRow && $preCutoffPaidMinor >= $approvedNetMinor))) {
                return [
                    'exists' => true,
                    'invoice_item_id' => $invoiceItemId,
                    'is_safe_to_restore' => false,
                    'safety_status' => self::SAFETY_BLOCKED_CONSTRAINT_CONFLICT,
                    'error' => "Cannot restore item #{$invoiceItemId} as unpaid: this fee period already has pre-cutoff historical MySkool payment evidence ({$item->currency} " . number_format($preCutoffPaidMinor / 100, 2) . ").",
                    'item' => $item,
                ];
            }

            if (!$isOpeningBalanceItem && !empty($item->gr_number)) {
                $feeTypeCode = (string) ($item->fee_type_code ?: 'monthly');
                $subjectName = $item->fee_plan_item_id
                    ? $db->table('fee_plan_items')->where('id', $item->fee_plan_item_id)->value('subject_name')
                    : null;
                $historicalMap = app(HistoricalOpeningBalanceService::class)->buildHistoricalObligationMap([(string) $item->gr_number]);
                $histCheck = HistoricalOpeningBalanceService::checkHistoricalObligation(
                    $historicalMap,
                    (string) $item->gr_number,
                    $feeTypeCode,
                    (string) $item->period_start,
                    (string) $item->currency,
                    $subjectName
                );
                if ($histCheck !== null && ($histCheck['blocks_billing'] ?? true)) {
                    $isCurrConflict = ($histCheck['status'] ?? '') === 'cross_currency_conflict';
                    return [
                        'exists' => true,
                        'invoice_item_id' => $invoiceItemId,
                        'is_safe_to_restore' => false,
                        'safety_status' => $isCurrConflict ? self::SAFETY_BLOCKED_CURRENCY_CONFLICT : self::SAFETY_BLOCKED_CONSTRAINT_CONFLICT,
                        'error' => "Cannot restore item #{$invoiceItemId}: this fee period is already represented in historical MySkool records (" . ($histCheck['reason'] ?? 'historical obligation protection') . ").",
                        'item' => $item,
                    ];
                }
            }
        }

        // 7. Determine safety classification and restoration path
        $isSafe = true;
        $safetyStatus = self::SAFETY_SAFE_NORMAL;
        $proposedTargetStatus = 'active'; // unpaid
        $proposedPaidMinor = 0;
        $remainingCollectibleMinor = $approvedNetMinor;
        $requiresConcessionReview = false;
        $actionDescription = 'Normal safe restoration as unpaid active obligation.';

        if ($settledMinor >= $approvedNetMinor && $approvedNetMinor > 0) {
            $safetyStatus = self::SAFETY_SAFE_ATOMIC_SETTLED;
            $proposedTargetStatus = 'paid';
            $proposedPaidMinor = $approvedNetMinor;
            $remainingCollectibleMinor = 0;
            $actionDescription = "Atomic restoration with full historical settlement ({$item->currency} " . number_format($approvedNetMinor / 100, 2) . "). Directly marked as paid with zero collectible balance.";
        } elseif ($settledMinor > 0) {
            $safetyStatus = self::SAFETY_SAFE_PARTIAL_SETTLED;
            $proposedTargetStatus = 'partial';
            $proposedPaidMinor = $settledMinor;
            $remainingCollectibleMinor = max(0, $approvedNetMinor - $settledMinor);

            // Sibling concession review heuristic (e.g. QAR 50 on GR 5930)
            if ($remainingCollectibleMinor === 5000 || $remainingCollectibleMinor === 4200) {
                $requiresConcessionReview = true;
            }

            $actionDescription = "Atomic restoration with partial historical settlement ({$item->currency} " . number_format($settledMinor / 100, 2) . "). Remaining balance ({$item->currency} " . number_format($remainingCollectibleMinor / 100, 2) . ") preserved" . ($requiresConcessionReview ? ' under concession review.' : '.');
        }

        return [
            'exists' => true,
            'invoice_item_id' => $invoiceItemId,
            'is_safe_to_restore' => $isSafe,
            'safety_status' => $safetyStatus,
            'action_description' => $actionDescription,
            'original_approved_values' => [
                'amount_minor' => $amountMinor,
                'discount_minor' => $discountMinor,
                'exemption_minor' => $exemptionMinor,
                'net_minor' => $approvedNetMinor,
                'net_formatted' => number_format($approvedNetMinor / 100, 2, '.', ''),
                'currency' => (string) $item->currency,
            ],
            'proposed_restoration' => [
                'target_status' => $proposedTargetStatus,
                'restored_net_minor' => $approvedNetMinor,
                'settled_paid_minor' => $proposedPaidMinor,
                'remaining_collectible_minor' => $remainingCollectibleMinor,
                'requires_concession_review' => $requiresConcessionReview,
            ],
            'void_evidence' => [
                'adjustment_id' => $voidAdjustment?->id,
                'voided_by_user_id' => $voidAdjustment?->user_id,
                'void_reason' => $voidAdjustment?->reason,
                'voided_at' => $voidAdjustment?->created_at,
                'previous_amount_minor' => $voidPreviousAmountMinor,
            ],
            'historical_evidence' => [
                'collections_count' => $historicalCollections->count(),
                'post_cutoff_paid_minor' => $postCutoffPaidMinor,
                'slips' => $postCutoffCols->pluck('slip_number')->filter()->implode(', '),
                'collection_ids' => $postCutoffCollectionIds,
                'staged_collection_ids' => $postCutoffCollectionIds,
                'existing_settlement' => $existingSettlement,
            ],
            'item' => $item,
            'parent_invoice' => [
                'invoice_id' => (int) $item->invoice_id,
                'invoice_number' => (string) $item->invoice_number,
                'current_status' => (string) $item->invoice_status,
                'current_total_minor' => (int) $item->invoice_total_minor,
                'current_paid_minor' => (int) $item->invoice_paid_minor,
            ],
        ];
    }

    /**
     * Safely restore a voided invoice item with strict atomic validation.
     * Admin-only mutation operation.
     */
    public function restore(int $invoiceItemId, array $options, ?int $userId = null, ?string $conn = null): array
    {
        $conn = $conn ?: config('database.default', 'mysql');
        $db = DB::connection($conn);

        // 1. Validate mandatory reason
        $reason = trim((string) ($options['reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A mandatory administrative reason is required to restore a voided fee.',
            ]);
        }

        // 2. Role Gate & Explicit Identifiable Administrator Verification (Never fall back to an arbitrary user)
        $explicitRole = $options['user_role'] ?? (auth()->user()?->role ?? null);
        if ($explicitRole && !in_array($explicitRole, ['super_admin', 'finance_admin', 'admin'], true)) {
            abort(403, 'Unauthorized access: Only administrators can restore voided financial obligations.');
        }

        $candidateActor = $userId ?: ($options['actor_id'] ?? ($options['user_id'] ?? auth()->id()));
        if ($candidateActor === null || $candidateActor === '') {
            throw new RuntimeException(
                'CRITICAL AUTHORIZATION ERROR: Voided fee restoration requires an explicitly authenticated or specified administrator (actor_id / user_id).'
            );
        }

        $actorQuery = $db->table('users');
        if (is_numeric($candidateActor)) {
            $actorQuery->where('id', (int) $candidateActor);
        } else {
            $actorQuery->where('email', trim((string) $candidateActor));
        }
        $actorUser = $actorQuery->first();

        if (!$actorUser) {
            throw new RuntimeException("CRITICAL AUTHORIZATION ERROR: Specified administrator '{$candidateActor}' does not exist.");
        }
        if (property_exists($actorUser, 'is_active') && !$actorUser->is_active) {
            throw new RuntimeException("CRITICAL AUTHORIZATION ERROR: Administrator #{$actorUser->id} ({$actorUser->email}) is inactive.");
        }
        if (!in_array((string) ($actorUser->role ?? ''), ['super_admin', 'finance_admin', 'admin'], true)) {
            abort(403, 'Unauthorized access: Only administrators can restore voided financial obligations.');
        }

        $actorId = (int) $actorUser->id;

        return $db->transaction(function () use ($db, $invoiceItemId, $reason, $actorId, $conn, $options) {
            // Re-run inspection under locked rows to guarantee consistency
            $inspection = $this->inspect($invoiceItemId, $conn, true);

            if (!$inspection['exists']) {
                throw new RuntimeException($inspection['error'] ?? 'Invoice item not found.');
            }

            if (!$inspection['is_safe_to_restore']) {
                throw new RuntimeException("Restoration blocked: " . ($inspection['error'] ?? 'Safety checks failed.'));
            }

            $item = $inspection['item'];
            $invoiceId = (int) $item->invoice_id;
            $proposed = $inspection['proposed_restoration'];
            $netMinor = (int) $proposed['restored_net_minor'];
            $paidMinor = (int) $proposed['settled_paid_minor'];
            $targetStatus = (string) $proposed['target_status'];
            $safetyStatus = $inspection['safety_status'];

            $collectionIds = array_values(array_unique(array_map('intval', $inspection['historical_evidence']['collection_ids'] ?? [])));
            sort($collectionIds);
            $acquiredLocks = [];

            try {
                // If historical settlement applies, acquire deterministic locks on source collection rows first
                if ($paidMinor > 0 && !empty($collectionIds)) {
                    foreach ($collectionIds as $cid) {
                        $lockName = "hist_col_alloc_{$cid}";
                        $lock = new BatchDispatchLock();
                        if (!$lock->acquire($lockName)) {
                            throw new RuntimeException("Concurrent allocation lock timeout for historical collection #{$cid}.");
                        }
                        $acquiredLocks[] = $lock;
                    }

                    if (Schema::connection($conn)->hasTable('qa_staged_collections')) {
                        $lockedColCount = $db->table('qa_staged_collections')
                            ->whereIn('id', $collectionIds)
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->count();
                        if ($lockedColCount !== count($collectionIds)) {
                            throw new RuntimeException("Historical source collection row missing during locked verification.");
                        }
                    }

                    if (Schema::connection($conn)->hasTable('qa_historical_settlements')
                        && $this->hasConsumedCollectionIds($db, $invoiceItemId, $collectionIds)
                    ) {
                        throw new RuntimeException("CRITICAL INTEGRITY VIOLATION: Historical source collection has already been consumed by another invoice item.");
                    }
                }

                // 3. Atomically update invoice_items
                $db->table('invoice_items')
                    ->where('id', $invoiceItemId)
                    ->update([
                        'net_minor' => $netMinor,
                        'paid_minor' => $paidMinor,
                        'status' => $targetStatus,
                        'updated_at' => now(),
                    ]);

                // 4. Record compensating restoration event in fee_adjustments (never overwrite void)
                $adjustmentId = $db->table('fee_adjustments')->insertGetId([
                    'invoice_item_id' => $invoiceItemId,
                    'user_id' => $actorId,
                    'kind' => 'restoration',
                    'amount_minor' => $netMinor,
                    'previous_amount_minor' => 0,
                    'new_amount_minor' => $netMinor,
                    'reason' => $reason,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // 5. If historical settlement applies, atomically record in qa_historical_settlements & qa_historical_settlement_collections
                $reconciliationId = null;
                if ($paidMinor > 0 && Schema::connection($conn)->hasTable('qa_historical_settlements')) {
                    $reconciliationId = sprintf(
                        'RECON-RESTORE-%d-%s',
                        $invoiceItemId,
                        substr(md5($invoiceItemId . '-' . $paidMinor . '-' . microtime()), 0, 8)
                    );

                    $slipNums = $inspection['historical_evidence']['slips'] ?? '';
                    $primaryColId = !empty($collectionIds) ? (int) $collectionIds[0] : null;
                    $activeBatch = (string) config('imports.active_batch_id', 'BATCH-20261025-FINAL-MYSKOOL');

                    $insertPayload = [
                        'reconciliation_id' => $reconciliationId,
                        'batch_id' => $activeBatch,
                        'cutoff_date' => MySkoolReconciliationAuditService::DEFAULT_CUTOFF_DATE,
                        'invoice_item_id' => $invoiceItemId,
                        'invoice_id' => $invoiceId,
                        'student_id' => $item->student_id,
                        'family_id' => $item->family_id,
                        'student_gr' => (string) $item->gr_number,
                        'family_code' => (string) ($item->family_code ?? ''),
                        'currency' => (string) $item->currency,
                        'fee_description' => (string) $item->description,
                        'period_key' => (string) $item->period_key,
                        'original_obligation_minor' => $netMinor,
                        'settled_amount_minor' => $paidMinor,
                        'remaining_obligation_minor' => max(0, $netMinor - $paidMinor),
                        'unmatched_overpayment_minor' => 0,
                        'previous_item_paid_minor' => 0,
                        'new_item_paid_minor' => $paidMinor,
                        'previous_item_status' => 'voided',
                        'new_item_status' => $targetStatus,
                        'previous_invoice_paid_minor' => (int) $item->invoice_paid_minor,
                        'new_invoice_paid_minor' => (int) $item->invoice_paid_minor + $paidMinor,
                        'previous_invoice_status' => (string) $item->invoice_status,
                        'new_invoice_status' => ($paidMinor >= $netMinor) ? 'paid' : 'partial',
                        'qa_staged_due_id' => null,
                        'qa_staged_collection_ids' => json_encode($collectionIds),
                        'myskool_slip_numbers' => $slipNums,
                        'reconciliation_classification' => ($paidMinor >= $netMinor)
                            ? MySkoolReconciliationAuditService::CLASS_POST_CUTOFF_FULL
                            : MySkoolReconciliationAuditService::CLASS_POST_CUTOFF_PARTIAL,
                        'guard_class' => ($paidMinor >= $netMinor)
                            ? MySkoolReconciliationAuditService::GUARD_CANDIDATE_FULL_SETTLEMENT
                            : MySkoolReconciliationAuditService::GUARD_CANDIDATE_PARTIAL_SETTLEMENT,
                        'status' => 'applied',
                        'applied_by' => $actorId,
                        'applied_at' => now(),
                        'notes' => 'Atomically restored voided fee and settled via verified historical MySkool collection',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if (Schema::connection($conn)->hasColumn('qa_historical_settlements', 'active_invoice_item_id')) {
                        $insertPayload['active_invoice_item_id'] = $invoiceItemId;
                        $insertPayload['active_primary_collection_id'] = $primaryColId;
                    }

                    try {
                        $settlementId = $db->table('qa_historical_settlements')->insertGetId($insertPayload);

                        if (Schema::connection($conn)->hasTable('qa_historical_settlement_collections')) {
                            foreach ($collectionIds as $cid) {
                                $db->table('qa_historical_settlement_collections')->insert([
                                    'settlement_id' => $settlementId,
                                    'reconciliation_id' => $reconciliationId,
                                    'qa_staged_collection_id' => (int) $cid,
                                    'invoice_item_id' => $invoiceItemId,
                                    'active_staged_collection_id' => (int) $cid,
                                    'status' => 'applied',
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }
                        }
                    } catch (\Illuminate\Database\QueryException $qe) {
                        throw new RuntimeException(
                            "CRITICAL INTEGRITY VIOLATION: Historical source collection has already been consumed by another invoice item.",
                            0,
                            $qe
                        );
                    }
                }

                // 6. Recalculate parent invoice correctly
                $invoiceSvc = new InvoiceService();
                $invoiceSvc->refreshInvoice($invoiceId, $conn);

                $refreshedInvoice = $db->table('invoices')->where('id', $invoiceId)->first();

                // 7. Insert comprehensive audit log entry
                $db->table('audit_logs')->insert([
                    'actor_id' => $actorId,
                    'action' => 'fee_restored',
                    'subject_type' => 'invoice_item',
                    'subject_id' => $invoiceItemId,
                    'metadata' => json_encode([
                        'invoice_id' => $invoiceId,
                        'invoice_number' => $item->invoice_number,
                        'restored_net_minor' => $netMinor,
                        'restored_paid_minor' => $paidMinor,
                        'safety_status' => $safetyStatus,
                        'target_status' => $targetStatus,
                        'reconciliation_id' => $reconciliationId,
                        'reason' => $reason,
                        'parent_invoice_status' => $refreshedInvoice?->status,
                        'parent_invoice_total_minor' => $refreshedInvoice?->total_minor,
                        'parent_invoice_paid_minor' => $refreshedInvoice?->paid_minor,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return [
                    'success' => true,
                    'invoice_item_id' => $invoiceItemId,
                    'invoice_id' => $invoiceId,
                    'invoice_number' => (string) $item->invoice_number,
                    'restored_net_minor' => $netMinor,
                    'restored_paid_minor' => $paidMinor,
                    'status' => $targetStatus,
                    'safety_status' => $safetyStatus,
                    'reconciliation_id' => $reconciliationId,
                    'adjustment_id' => $adjustmentId,
                    'parent_invoice' => [
                        'id' => $invoiceId,
                        'total_minor' => (int) $refreshedInvoice?->total_minor,
                        'paid_minor' => (int) $refreshedInvoice?->paid_minor,
                        'status' => (string) $refreshedInvoice?->status,
                    ],
                    'message' => "Successfully restored invoice item #{$invoiceItemId} on invoice {$item->invoice_number}." .
                        ($paidMinor > 0 ? " Atomically settled historical payment of {$item->currency} " . number_format($paidMinor / 100, 2) . "." : ""),
                ];
            } finally {
                foreach (array_reverse($acquiredLocks) as $lock) {
                    $lock->release();
                }
            }
        }, 3);
    }

    /**
     * Check if any of the given qa_staged_collections IDs have already been consumed
     * by another applied settlement in qa_historical_settlement_collections or qa_historical_settlements.
     */
    private function hasConsumedCollectionIds($db, int $excludeInvoiceItemId, array $collectionIds): bool
    {
        $targetIds = array_values(array_unique(array_map('intval', $collectionIds)));
        if (empty($targetIds)) {
            return false;
        }

        $connName = $db->getName();
        if (Schema::connection($connName)->hasTable('qa_historical_settlement_collections')) {
            $existsInAllocTable = $db->table('qa_historical_settlement_collections')
                ->whereIn('active_staged_collection_id', $targetIds)
                ->where('invoice_item_id', '!=', $excludeInvoiceItemId)
                ->where('status', 'applied')
                ->exists();
            if ($existsInAllocTable) {
                return true;
            }
        }

        if (Schema::connection($connName)->hasColumn('qa_historical_settlements', 'active_primary_collection_id')) {
            $existsInPrimaryCol = $db->table('qa_historical_settlements')
                ->whereIn('active_primary_collection_id', $targetIds)
                ->where('invoice_item_id', '!=', $excludeInvoiceItemId)
                ->where('status', 'applied')
                ->exists();
            if ($existsInPrimaryCol) {
                return true;
            }
        }

        $activeRows = $db->table('qa_historical_settlements')
            ->where('status', 'applied')
            ->where('invoice_item_id', '!=', $excludeInvoiceItemId)
            ->whereNotNull('qa_staged_collection_ids')
            ->pluck('qa_staged_collection_ids');

        foreach ($activeRows as $jsonIds) {
            $decoded = json_decode((string) $jsonIds, true);
            if (!is_array($decoded)) {
                continue;
            }
            $consumed = array_map('intval', $decoded);
            if (!empty(array_intersect($targetIds, $consumed))) {
                return true;
            }
        }

        if (Schema::connection($connName)->hasTable('qa_myskool_payment_corrections')) {
            $corrRows = $db->table('qa_myskool_payment_corrections')
                ->whereIn('qa_staged_collection_id', $targetIds)
                ->orderBy('sequence_no')
                ->orderBy('id')
                ->get(['qa_staged_collection_id', 'resulting_status']);
            $latestByCol = [];
            foreach ($corrRows as $cr) {
                $latestByCol[(int) $cr->qa_staged_collection_id] = (string) $cr->resulting_status;
            }
            foreach ($latestByCol as $status) {
                if (in_array($status, ['reversed', 'disputed', 'under_review', 'corrected'], true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
