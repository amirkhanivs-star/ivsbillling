<?php

namespace App\Services\Migration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class HistoricalPaymentReconciliationService
{
    protected MySkoolReconciliationAuditService $auditService;

    public function __construct(?MySkoolReconciliationAuditService $auditService = null)
    {
        $this->auditService = $auditService ?? new MySkoolReconciliationAuditService();
    }

    /**
     * Preview proposed historical payment reconciliation changes without making any financial writes.
     */
    public function preview(
        array $filters = [],
        string $cutoffDate = MySkoolReconciliationAuditService::DEFAULT_CUTOFF_DATE,
        ?string $batchId = null,
        ?string $conn = null
    ): array {
        $auditResult = $this->auditService->audit($conn, $batchId, $cutoffDate);

        $filteredRecords = $this->filterAuditRecords($auditResult['records'], $filters);

        $previewPerCurrency = [];
        $classDistribution = [];
        $guardDistribution = [];

        foreach ($filteredRecords as $r) {
            $curr = $r['currency'];
            if (!isset($previewPerCurrency[$curr])) {
                $previewPerCurrency[$curr] = [
                    'currency' => $curr,
                    'items_count' => 0,
                    'original_obligation_minor' => 0,
                    'verified_settlement_minor' => 0,
                    'remaining_obligation_minor' => 0,
                    'approved_adjustments_minor' => 0,
                    'unmatched_overpayment_minor' => 0,
                    'pre_cutoff_cash_minor' => 0,
                    'post_cutoff_cash_minor' => 0,
                ];
            }

            $previewPerCurrency[$curr]['items_count']++;
            $previewPerCurrency[$curr]['original_obligation_minor'] += (int) $r['original_opening_balance_minor'];
            $previewPerCurrency[$curr]['verified_settlement_minor'] += (int) ($r['verified_settlement_minor'] ?? 0);
            $previewPerCurrency[$curr]['remaining_obligation_minor'] += (int) ($r['remaining_obligation_minor'] ?? $r['potential_remaining_balance_minor']);
            $previewPerCurrency[$curr]['approved_adjustments_minor'] += (int) ($r['approved_adjustments_minor'] ?? 0);
            $previewPerCurrency[$curr]['unmatched_overpayment_minor'] += (int) ($r['unmatched_overpayment_minor'] ?? 0);
            $previewPerCurrency[$curr]['pre_cutoff_cash_minor'] += (int) $r['historical_payments_before_cutoff_minor'];
            $previewPerCurrency[$curr]['post_cutoff_cash_minor'] += (int) $r['historical_payments_after_cutoff_minor'];

            $cls = $r['reconciliation_classification'];
            $classDistribution[$cls] = ($classDistribution[$cls] ?? 0) + 1;

            $grd = $r['guard_class'] ?? MySkoolReconciliationAuditService::mapToGuardClass($cls);
            $guardDistribution[$grd] = ($guardDistribution[$grd] ?? 0) + 1;
        }

        // Verify mathematical reconciliation across all currencies
        $invariantHolds = true;
        foreach ($previewPerCurrency as $curr => $data) {
            $expected = $data['verified_settlement_minor'] + $data['remaining_obligation_minor'] + $data['approved_adjustments_minor'];
            if ($data['original_obligation_minor'] !== $expected) {
                $invariantHolds = false;
            }
            $previewPerCurrency[$curr]['is_reconciled'] = ($data['original_obligation_minor'] === $expected);
        }

        return [
            'status' => 'preview',
            'cutoff_date' => $cutoffDate,
            'batch_id' => $auditResult['batch_id'],
            'total_scanned' => $auditResult['summary']['total_ob_items_scanned'],
            'filtered_count' => count($filteredRecords),
            'currency_breakdown' => $previewPerCurrency,
            'class_distribution' => $classDistribution,
            'guard_distribution' => $guardDistribution,
            'invariant_holds' => $invariantHolds,
            'records' => $filteredRecords,
        ];
    }

    /**
     * Safely apply historical payment reconciliation to eligible opening-balance items.
     * Protected by multiple fail-closed safety guards.
     */
    public function apply(array $options, ?int $userId = null): array
    {
        $cutoffDate = $options['cutoff_date'] ?? MySkoolReconciliationAuditService::DEFAULT_CUTOFF_DATE;
        $batchId = $options['batch_id'] ?? null;
        $conn = $options['conn'] ?? null;
        $conn = $conn ?: config('database.default', 'mysql');
        $db = DB::connection($conn);

        // 1. Mandatory approval & cutoff gates
        if (empty($options['confirm_cutoff'])) {
            throw new RuntimeException(
                "CRITICAL RECONCILIATION GATE: Missing required confirmation of snapshot cutoff date ('--confirm-cutoff'). " .
                "Reconciliation cannot execute without explicit operator cutoff verification."
            );
        }

        if (empty($options['confirm_owner'])) {
            throw new RuntimeException(
                "CRITICAL RECONCILIATION GATE: Missing required owner sign-off confirmation ('--confirm-owner'). " .
                "Financial ledger mutations require explicit owner sign-off."
            );
        }

        // 2. Environment restrictions & database safety guards
        $allowApply = config('reconciliation.allow_apply', false)
            || (env('ALLOW_HISTORICAL_RECONCILIATION_APPLY') === true || env('ALLOW_HISTORICAL_RECONCILIATION_APPLY') === 'true');

        if (!$allowApply) {
            throw new RuntimeException(
                "CRITICAL ENVIRONMENT GUARD: Historical reconciliation apply is disabled by configuration. " .
                "Set ALLOW_HISTORICAL_RECONCILIATION_APPLY=true in an authorized environment to proceed."
            );
        }

        // Fail-closed against real production or live local database
        $dbName = (string) config("database.connections.{$conn}.database", '');
        $isTestingEnv = app()->environment('testing');

        if (($dbName === 'iqra_billing' || app()->environment('production')) && !$isTestingEnv && empty($options['force_authorized_live'])) {
            throw new RuntimeException(
                "CRITICAL PRODUCTION/DATABASE GUARD: Automated reconciliation mutation is strictly prohibited against " .
                "the real local database ('iqra_billing') or production per Phase 2B project instructions."
            );
        }

        // 3. Ensure schema table exists
        if (!$db->getSchemaBuilder()->hasTable('qa_historical_settlements')) {
            throw new RuntimeException(
                "CRITICAL SCHEMA ERROR: Table 'qa_historical_settlements' does not exist on connection '{$conn}'. " .
                "Run migrations on the authorized test environment before applying settlements."
            );
        }

        // 4. Resolve and verify explicitly authorized administrator
        $actorId = $this->resolveAuthorizedActor($db, $userId, $options);

        // 5. Retrieve candidate preview records
        $preview = $this->preview($options, $cutoffDate, $batchId, $conn);
        $records = $preview['records'];

        $allowedClasses = $options['classes'] ?? [
            MySkoolReconciliationAuditService::GUARD_CANDIDATE_FULL_SETTLEMENT,
            MySkoolReconciliationAuditService::CLASS_POST_CUTOFF_FULL,
        ];

        $appliedSettlements = [];
        $skippedHolds = [];
        $failedErrors = [];

        foreach ($records as $record) {
            $guardClass = $record['guard_class'] ?? MySkoolReconciliationAuditService::mapToGuardClass($record['reconciliation_classification']);
            $rawClass = $record['reconciliation_classification'];

            // Skip records not in requested apply classes
            if (!in_array($guardClass, $allowedClasses, true) && !in_array($rawClass, $allowedClasses, true)) {
                $skippedHolds[] = [
                    'invoice_item_id' => $record['invoice_item_id'],
                    'student_gr' => $record['student_gr'],
                    'reason' => "Class '{$guardClass}' not targeted for automated apply",
                ];
                continue;
            }

            $itemId = (int) $record['invoice_item_id'];
            $stagedCollectionIds = array_values(array_unique(array_map('intval', $record['staged_collection_ids'] ?? [])));
            sort($stagedCollectionIds);

            $acquiredLocks = [];
            try {
                foreach ($stagedCollectionIds as $cid) {
                    $lockKey = "hist_col_alloc_{$cid}";
                    $lock = new \App\Services\BatchDispatchLock();
                    if (!$lock->acquire($lockKey)) {
                        throw new RuntimeException("CRITICAL INTEGRITY VIOLATION: Historical source collection ID #{$cid} is currently being allocated by a concurrent request.");
                    }
                    $acquiredLocks[] = $lock;
                }

                $settlementResult = $db->transaction(function () use (
                    $db,
                    $itemId,
                    $record,
                    $guardClass,
                    $cutoffDate,
                    $preview,
                    $actorId,
                    $options,
                    $stagedCollectionIds
                ) {
                    // Lock target invoice item for update
                    $item = $db->table('invoice_items')
                        ->where('id', $itemId)
                        ->lockForUpdate()
                        ->first();

                    if (!$item) {
                        throw new RuntimeException("Target invoice_item #{$itemId} not found during locked re-read.");
                    }

                    // Lock target parent invoice for update
                    $invoice = $db->table('invoices')
                        ->where('id', $item->invoice_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$invoice) {
                        throw new RuntimeException("Target parent invoice #{$item->invoice_id} not found during locked re-read.");
                    }

                    // Lock candidate staged collection rows in deterministic order to serialize concurrent requests across different invoice items
                    if (!empty($stagedCollectionIds) && $db->getSchemaBuilder()->hasTable('qa_staged_collections')) {
                        $db->table('qa_staged_collections')
                            ->whereIn('id', $stagedCollectionIds)
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get();
                    }

                    // Idempotency constraint check first: never apply twice to the same item
                    $existingSettlement = $db->table('qa_historical_settlements')
                        ->where('invoice_item_id', $itemId)
                        ->where('status', 'applied')
                        ->lockForUpdate()
                        ->first();

                    if ($existingSettlement) {
                        return [
                            'status' => 'already_applied',
                            'reconciliation_id' => $existingSettlement->reconciliation_id,
                            'invoice_item_id' => $itemId,
                        ];
                    }

                    // Guard against consuming historical source collection multiple times across different invoice items
                    if (!empty($stagedCollectionIds)) {
                        if ($this->hasConsumedCollectionIds($db, $itemId, $stagedCollectionIds)) {
                            throw new RuntimeException("CRITICAL INTEGRITY VIOLATION: Historical source collection ID has already been consumed by another invoice item.");
                        }
                    }

                    // Pre-apply conflict detection with Phase 2C authorized void recovery path
                    $allowVoidRecovery = !empty($options['allow_void_recovery']);
                    $isVoided = in_array($item->status, ['void', 'voided'], true) || in_array($invoice->status, ['void', 'voided'], true);

                    $hasAllocs = $db->table('payment_allocations')->where('invoice_item_id', $itemId)->where('amount_minor', '>', 0)->exists();
                    if ($hasAllocs || (int) $item->paid_minor > 0 || in_array($item->status, ['paid', 'partial'], true)) {
                        throw new RuntimeException("LIVE_ACTIVITY_HOLD: Active payment allocations or paid balance exist on item #{$itemId}. Protected from modification.");
                    }

                    if ((int) $item->discount_minor > 0 || (int) $item->exemption_minor > 0 || $item->status === 'exempted') {
                        throw new RuntimeException("LIVE_ACTIVITY_HOLD: Discount or exemption exists on item #{$itemId}. Protected from modification.");
                    }

                    if ($isVoided) {
                        if (!$allowVoidRecovery) {
                            throw new RuntimeException("LIVE_ACTIVITY_HOLD: Item or invoice is currently voided. Aborting.");
                        }

                        // When authorized for void recovery, verify only void adjustments exist
                        $nonVoidAdjs = $db->table('fee_adjustments')
                            ->where('invoice_item_id', $itemId)
                            ->whereNotIn('kind', ['void'])
                            ->exists();
                        if ($nonVoidAdjs) {
                            throw new RuntimeException("LIVE_ACTIVITY_HOLD: Non-void fee adjustments exist on item #{$itemId}. Protected from automatic modification.");
                        }
                    } else {
                        $hasAdjs = $db->table('fee_adjustments')->where('invoice_item_id', $itemId)->exists();
                        if ($hasAdjs) {
                            throw new RuntimeException("LIVE_ACTIVITY_HOLD: Fee adjustments exist on item #{$itemId}. Protected from modification.");
                        }
                    }

                    // Calculate approved obligation and settlement allocation
                    if ($isVoided && $allowVoidRecovery) {
                        $approvedNetMinor = max(0, (int) $item->amount_minor - (int) $item->discount_minor - (int) $item->exemption_minor);
                        if ($approvedNetMinor <= 0) {
                            $approvedNetMinor = (int) ($record['original_opening_balance_minor'] ?? 0);
                        }
                        if ($approvedNetMinor <= 0) {
                            $approvedNetMinor = (int) $db->table('fee_adjustments')->where('invoice_item_id', $itemId)->where('kind', 'void')->value('previous_amount_minor');
                        }
                        $currentUnpaidMinor = $approvedNetMinor;
                    } else {
                        $approvedNetMinor = (int) $item->net_minor;
                        $currentUnpaidMinor = max(0, (int) $item->net_minor - (int) $item->paid_minor);
                    }

                    $postCutoffMinor = (int) $record['historical_payments_after_cutoff_minor'];
                    $settleAmountMinor = min($currentUnpaidMinor, $postCutoffMinor);
                    $overpaymentMinor = max(0, $postCutoffMinor - $currentUnpaidMinor);

                    if ($settleAmountMinor <= 0) {
                        throw new RuntimeException("No collectible balance or post-cutoff payment to settle on item #{$itemId}.");
                    }

                    $newItemPaidMinor = ($isVoided && $allowVoidRecovery) ? $settleAmountMinor : ((int) $item->paid_minor + $settleAmountMinor);
                    $netMinorForStatus = ($isVoided && $allowVoidRecovery) ? $approvedNetMinor : (int) $item->net_minor;
                    $newItemStatus = ($newItemPaidMinor >= $netMinorForStatus) ? 'paid' : 'partial';

                    // Update invoice item
                    $updateItemData = [
                        'paid_minor' => $newItemPaidMinor,
                        'status' => $newItemStatus,
                        'updated_at' => now(),
                    ];
                    if ($isVoided && $allowVoidRecovery) {
                        $updateItemData['net_minor'] = $approvedNetMinor;
                    }

                    $db->table('invoice_items')
                        ->where('id', $itemId)
                        ->update($updateItemData);

                    // Compensating restoration event if recovering voided item
                    if ($isVoided && $allowVoidRecovery) {
                        $db->table('fee_adjustments')->insert([
                            'invoice_item_id' => $itemId,
                            'user_id' => $actorId,
                            'kind' => 'restoration',
                            'amount_minor' => $approvedNetMinor,
                            'previous_amount_minor' => 0,
                            'new_amount_minor' => $approvedNetMinor,
                            'reason' => 'Phase 2C authorized atomic void recovery and historical settlement',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    // Recalculate parent invoice
                    $itemTotals = $db->table('invoice_items')
                        ->where('invoice_id', $invoice->id)
                        ->selectRaw("COALESCE(SUM(paid_minor), 0) as total_paid, COALESCE(SUM(CASE WHEN status != 'voided' THEN net_minor ELSE 0 END), 0) as total_net")
                        ->first();

                    $newInvPaidMinor = (int) $itemTotals->total_paid;
                    $newInvNetMinor = (int) $itemTotals->total_net;
                    $newInvStatus = ($newInvNetMinor > 0 && $newInvPaidMinor >= $newInvNetMinor)
                        ? 'paid'
                        : ($newInvPaidMinor > 0 ? 'partial' : ($newInvNetMinor === 0 ? 'voided' : 'unpaid'));

                    $db->table('invoices')
                        ->where('id', $invoice->id)
                        ->update([
                            'total_minor' => $newInvNetMinor,
                            'paid_minor' => $newInvPaidMinor,
                            'status' => $newInvStatus,
                            'updated_at' => now(),
                        ]);

                    // Generate unique reconciliation identifier
                    $reconId = sprintf(
                        'RECON-OB-%d-%s',
                        $itemId,
                        substr(md5($itemId . '-' . $settleAmountMinor . '-' . microtime()), 0, 8)
                    );

                    // Track affected subsequent invoice snapshots (e.g., November 2026) for audit and rollback
                    $affectedSnapshots = $this->updateSubsequentSnapshots(
                        $db,
                        $invoice->family_id,
                        (string) $item->currency,
                        $settleAmountMinor
                    );

                    try {
                        // Insert dedicated settlement lineage audit record with DB-level unique active locks
                        $settlementId = $db->table('qa_historical_settlements')->insertGetId([
                            'reconciliation_id' => $reconId,
                            'batch_id' => $preview['batch_id'],
                            'cutoff_date' => $cutoffDate,
                            'invoice_item_id' => $itemId,
                            'active_invoice_item_id' => $itemId,
                            'invoice_id' => (int) $item->invoice_id,
                            'student_id' => $item->student_id,
                            'family_id' => $invoice->family_id,
                            'student_gr' => (string) $record['student_gr'],
                            'family_code' => (string) $record['family_code'],
                            'currency' => (string) $item->currency,
                            'fee_description' => (string) $item->description,
                            'period_key' => (string) $item->period_key,
                            'original_obligation_minor' => $approvedNetMinor,
                            'settled_amount_minor' => $settleAmountMinor,
                            'remaining_obligation_minor' => max(0, $approvedNetMinor - $newItemPaidMinor),
                            'unmatched_overpayment_minor' => $overpaymentMinor,
                            'previous_item_paid_minor' => (int) $item->paid_minor,
                            'new_item_paid_minor' => $newItemPaidMinor,
                            'previous_item_status' => (string) $item->status,
                            'new_item_status' => $newItemStatus,
                            'previous_invoice_paid_minor' => (int) $invoice->paid_minor,
                            'new_invoice_paid_minor' => $newInvPaidMinor,
                            'previous_invoice_status' => (string) $invoice->status,
                            'new_invoice_status' => $newInvStatus,
                            'qa_staged_due_id' => $record['staged_due_id'] ?? null,
                            'active_primary_collection_id' => $stagedCollectionIds[0] ?? null,
                            'qa_staged_collection_ids' => json_encode($stagedCollectionIds),
                            'myskool_slip_numbers' => (string) $record['myskool_slip_references'],
                            'reconciliation_classification' => (string) $record['reconciliation_classification'],
                            'guard_class' => $guardClass,
                            'status' => 'applied',
                            'applied_by' => $actorId,
                            'applied_at' => now(),
                            'affected_snapshots_json' => !empty($affectedSnapshots) ? json_encode($affectedSnapshots) : null,
                            'notes' => ($isVoided && $allowVoidRecovery)
                                ? 'Atomically restored voided fee and settled via verified historical MySkool collection'
                                : 'Applied verified historical post-cutoff MySkool settlement',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        if (!empty($stagedCollectionIds) && $db->getSchemaBuilder()->hasTable('qa_historical_settlement_collections')) {
                            foreach ($stagedCollectionIds as $cid) {
                                $db->table('qa_historical_settlement_collections')->insert([
                                    'settlement_id' => $settlementId,
                                    'reconciliation_id' => $reconId,
                                    'invoice_item_id' => $itemId,
                                    'qa_staged_collection_id' => (int) $cid,
                                    'active_staged_collection_id' => (int) $cid,
                                    'status' => 'applied',
                                    'applied_by' => $actorId,
                                    'applied_at' => now(),
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }
                        }
                    } catch (\Illuminate\Database\QueryException $qe) {
                        if ($this->isUniqueConstraintViolation($qe)) {
                            throw new RuntimeException("CRITICAL INTEGRITY VIOLATION: Historical source collection ID has already been consumed by another invoice item.");
                        }
                        throw $qe;
                    }

                    // Write immutable audit_logs entry for every applied settlement
                    if ($db->getSchemaBuilder()->hasTable('audit_logs')) {
                        $db->table('audit_logs')->insert([
                            'actor_id' => $actorId,
                            'action' => ($isVoided && $allowVoidRecovery)
                                ? 'fee_restored_historically_settled'
                                : 'historical_payment_reconciled',
                            'subject_type' => 'invoice_item',
                            'subject_id' => $itemId,
                            'metadata' => json_encode([
                                'reconciliation_id' => $reconId,
                                'invoice_id' => (int) $item->invoice_id,
                                'student_gr' => (string) $record['student_gr'],
                                'currency' => (string) $item->currency,
                                'restored_net_minor' => $approvedNetMinor,
                                'settled_paid_minor' => $settleAmountMinor,
                                'unmatched_overpayment_minor' => $overpaymentMinor,
                                'item_status' => $newItemStatus,
                                'staged_collection_ids' => $stagedCollectionIds,
                            ]),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    return [
                        'status' => 'applied',
                        'reconciliation_id' => $reconId,
                        'invoice_item_id' => $itemId,
                        'settled_amount_minor' => $settleAmountMinor,
                        'unmatched_overpayment_minor' => $overpaymentMinor,
                        'currency' => (string) $item->currency,
                    ];
                });

                if ($settlementResult['status'] === 'applied') {
                    $appliedSettlements[] = $settlementResult;
                } else {
                    $skippedHolds[] = [
                        'invoice_item_id' => $itemId,
                        'reason' => 'Already reconciled (' . $settlementResult['reconciliation_id'] . ')',
                    ];
                }
            } catch (\Throwable $e) {
                $failedErrors[] = [
                    'invoice_item_id' => $itemId,
                    'student_gr' => $record['student_gr'],
                    'error' => $e->getMessage(),
                ];
            } finally {
                foreach (array_reverse($acquiredLocks) as $lock) {
                    $lock->release();
                }
            }
        }

        return [
            'status' => 'success',
            'cutoff_date' => $cutoffDate,
            'batch_id' => $preview['batch_id'],
            'actor_id' => $actorId,
            'total_candidates' => count($records),
            'applied_count' => count($appliedSettlements),
            'skipped_count' => count($skippedHolds),
            'failed_count' => count($failedErrors),
            'applied_settlements' => $appliedSettlements,
            'skipped_holds' => $skippedHolds,
            'failed_errors' => $failedErrors,
        ];
    }

    /**
     * Compensating action: revert an applied historical settlement without deleting audit evidence.
     */
    public function reverse(string $reconciliationId, ?int $userId = null, ?string $conn = null, array $options = []): array
    {
        $conn = $conn ?: config('database.default', 'mysql');
        $db = DB::connection($conn);

        $actorId = $this->resolveAuthorizedActor($db, $userId, $options);

        return $db->transaction(function () use ($db, $reconciliationId, $actorId) {
            $settlement = $db->table('qa_historical_settlements')
                ->where('reconciliation_id', $reconciliationId)
                ->where('status', 'applied')
                ->lockForUpdate()
                ->first();

            if (!$settlement) {
                throw new RuntimeException("Active applied settlement '{$reconciliationId}' not found for reversal.");
            }

            $itemId = (int) $settlement->invoice_item_id;
            $invoiceId = (int) $settlement->invoice_id;

            // Re-read and lock target records
            $item = $db->table('invoice_items')->where('id', $itemId)->lockForUpdate()->first();
            $invoice = $db->table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();

            if (!$item || !$invoice) {
                throw new RuntimeException("Target item #{$itemId} or invoice #{$invoiceId} not found during reversal.");
            }

            // Intervening cashier payment protection
            $hasInterveningAllocs = $db->table('payment_allocations')
                ->where('invoice_item_id', $itemId)
                ->where('amount_minor', '>', 0)
                ->exists();
            if ($hasInterveningAllocs) {
                throw new RuntimeException("SAFETY HOLD: Cannot reverse reconciliation '{$reconciliationId}' because active cashier payment allocations exist on item #{$itemId}. Intervening receipts must be reversed first.");
            }

            // Restore previous item state (including net_minor = 0 if item was recovered from voided state)
            $wasVoidRecovered = in_array((string) $settlement->previous_item_status, ['void', 'voided'], true);
            $itemUpdate = [
                'paid_minor' => (int) $settlement->previous_item_paid_minor,
                'status' => (string) $settlement->previous_item_status,
                'updated_at' => now(),
            ];
            if ($wasVoidRecovered) {
                $itemUpdate['net_minor'] = 0;
            }
            $db->table('invoice_items')->where('id', $itemId)->update($itemUpdate);

            if ($wasVoidRecovered && $db->getSchemaBuilder()->hasTable('fee_adjustments')) {
                $db->table('fee_adjustments')->insert([
                    'invoice_item_id' => $itemId,
                    'user_id' => $actorId,
                    'kind' => 'void',
                    'amount_minor' => (int) $settlement->original_obligation_minor,
                    'previous_amount_minor' => (int) $settlement->original_obligation_minor,
                    'new_amount_minor' => 0,
                    'reason' => "Compensating void on reversal of historical settlement {$reconciliationId}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Recalculate parent invoice state from live items so multi-item invoices remain accurate even on out-of-order reversal
            $itemTotals = $db->table('invoice_items')
                ->where('invoice_id', $invoiceId)
                ->selectRaw("COALESCE(SUM(paid_minor), 0) as total_paid, COALESCE(SUM(CASE WHEN status != 'voided' THEN net_minor ELSE 0 END), 0) as total_net")
                ->first();

            $restoredInvPaidMinor = (int) $itemTotals->total_paid;
            $restoredInvNetMinor = (int) $itemTotals->total_net;
            if ($restoredInvNetMinor === 0) {
                $restoredInvStatus = in_array((string) $settlement->previous_invoice_status, ['void', 'voided'], true)
                    ? (string) $settlement->previous_invoice_status
                    : 'voided';
            } elseif ($restoredInvPaidMinor >= $restoredInvNetMinor) {
                $restoredInvStatus = 'paid';
            } elseif ($restoredInvPaidMinor > 0) {
                $restoredInvStatus = 'partial';
            } else {
                $restoredInvStatus = ((string) $settlement->previous_invoice_status === 'unpaid')
                    ? 'unpaid'
                    : 'generated';
            }

            $db->table('invoices')->where('id', $invoiceId)->update([
                'total_minor' => $restoredInvNetMinor,
                'paid_minor' => $restoredInvPaidMinor,
                'status' => $restoredInvStatus,
                'updated_at' => now(),
            ]);

            // Revert subsequent snapshots if tracked
            if (!empty($settlement->affected_snapshots_json)) {
                $snapshots = json_decode((string) $settlement->affected_snapshots_json, true) ?: [];
                foreach ($snapshots as $snap) {
                    if (!empty($snap['invoice_id']) && isset($snap['before_snapshot_minor'])) {
                        $db->table('invoices')
                            ->where('id', $snap['invoice_id'])
                            ->update([
                                'previous_dues_snapshot_minor' => (int) $snap['before_snapshot_minor'],
                                'updated_at' => now(),
                            ]);
                    }
                }
            }

            // Mark settlement as reversed and release active unique locks, preserving original evidence
            $db->table('qa_historical_settlements')
                ->where('reconciliation_id', $reconciliationId)
                ->update([
                    'status' => 'reversed',
                    'active_invoice_item_id' => null,
                    'active_primary_collection_id' => null,
                    'reversed_by' => $actorId,
                    'reversed_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($db->getSchemaBuilder()->hasTable('qa_historical_settlement_collections')) {
                $db->table('qa_historical_settlement_collections')
                    ->where('settlement_id', $settlement->id)
                    ->update([
                        'status' => 'reversed',
                        'active_staged_collection_id' => null,
                        'reversed_by' => $actorId,
                        'reversed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            if ($db->getSchemaBuilder()->hasTable('audit_logs')) {
                $db->table('audit_logs')->insert([
                    'actor_id' => $actorId,
                    'action' => 'historical_payment_reconciliation_reversed',
                    'subject_type' => 'invoice_item',
                    'subject_id' => $itemId,
                    'metadata' => json_encode([
                        'reconciliation_id' => $reconciliationId,
                        'invoice_id' => $invoiceId,
                        'restored_item_paid_minor' => (int) $settlement->previous_item_paid_minor,
                        'restored_item_status' => (string) $settlement->previous_item_status,
                        'restored_invoice_paid_minor' => $restoredInvPaidMinor,
                        'restored_invoice_status' => $restoredInvStatus,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return [
                'status' => 'reversed',
                'reconciliation_id' => $reconciliationId,
                'invoice_item_id' => $itemId,
                'reversed_by' => $actorId,
                'restored_item_paid_minor' => (int) $settlement->previous_item_paid_minor,
                'restored_item_status' => (string) $settlement->previous_item_status,
            ];
        });
    }

    /**
     * Resolve and validate an explicitly authorized administrator account.
     * Never silently attributes mutations to user ID 1 or an arbitrary database row.
     */
    public function resolveAuthorizedActor($db, $userId = null, array $options = []): int
    {
        $candidate = $userId ?? ($options['actor_id'] ?? ($options['admin_user_id'] ?? auth()->id()));

        if ($candidate === null || trim((string) $candidate) === '') {
            throw new RuntimeException(
                "CRITICAL AUTHORIZATION GATE: An explicit, identifiable administrator user ID or email ('--actor-id') " .
                "is required for financial reconciliation mutations. Silent attribution to arbitrary accounts is prohibited."
            );
        }

        $query = $db->table('users');
        if (is_numeric($candidate)) {
            $actor = $query->where('id', (int) $candidate)->first();
        } else {
            $actor = $query->where('email', trim((string) $candidate))->first();
        }

        if (!$actor) {
            throw new RuntimeException(
                "CRITICAL AUTHORIZATION GATE: Administrator account '{$candidate}' does not exist in users table."
            );
        }

        if (isset($actor->is_active) && !$actor->is_active) {
            throw new RuntimeException(
                "CRITICAL AUTHORIZATION GATE: Administrator account #{$actor->id} ({$actor->email}) is inactive."
            );
        }

        $role = (string) ($actor->role ?? '');
        if (!in_array($role, ['super_admin', 'finance_admin', 'admin'], true)) {
            throw new RuntimeException(
                "CRITICAL AUTHORIZATION GATE: User #{$actor->id} ({$actor->email}, role: '{$role}') is not authorized. " .
                "Only super_admin, finance_admin, or admin may execute historical payment reconciliation mutations."
            );
        }

        return (int) $actor->id;
    }

    /**
     * Verify all accounting and downstream invariants across connection.
     */
    public function verifyInvariants(?string $conn = null, string $cutoffDate = MySkoolReconciliationAuditService::DEFAULT_CUTOFF_DATE): array
    {
        $conn = $conn ?: config('database.default', 'mysql');
        $db = DB::connection($conn);

        $preview = $this->preview([], $cutoffDate, null, $conn);

        // Invariant 1: Original obligation = verified settlement + remaining + approved adjustments
        $formulaHolds = $preview['invariant_holds'];

        // Invariant 2: Zero cashier cash impact (payments table has 0 historical settlement writes)
        $hasSettlements = $db->getSchemaBuilder()->hasTable('qa_historical_settlements');
        $paymentsTableUntouched = true;
        if ($hasSettlements) {
            $portalAllocationsOnHistorical = $db->table('payment_allocations as pa')
                ->join('qa_historical_settlements as qhs', 'qhs.invoice_item_id', '=', 'pa.invoice_item_id')
                ->count();
            $paymentsTableUntouched = ($portalAllocationsOnHistorical === 0);
        }

        // Invariant 3: Item status consistency (paid iff paid_minor >= net_minor)
        $inconsistentItems = $db->table('invoice_items')
            ->where(function ($q) {
                $q->where('status', 'paid')->whereColumn('paid_minor', '<', 'net_minor')
                    ->orWhere(function ($q2) {
                        $q2->where('status', '!=', 'paid')
                            ->whereColumn('paid_minor', '>=', 'net_minor')
                            ->where('net_minor', '>', 0);
                    });
            })
            ->count();

        $allPassed = $formulaHolds && $paymentsTableUntouched && ($inconsistentItems === 0);

        return [
            'all_passed' => $allPassed,
            'formula_reconciles' => $formulaHolds,
            'portal_payments_isolated' => $paymentsTableUntouched,
            'item_status_consistent' => ($inconsistentItems === 0),
            'currency_breakdown' => $preview['currency_breakdown'],
        ];
    }

    /**
     * Update downstream invoice previous_dues_snapshot_minor to reflect historical settlements.
     */
    protected function updateSubsequentSnapshots(
        $db,
        $familyId,
        string $currency,
        int $reductionMinor
    ): array {
        if (!$familyId || $reductionMinor <= 0) {
            return [];
        }

        $subsequentInvoices = $db->table('invoices as inv')
            ->join('billing_runs as br', 'br.id', '=', 'inv.billing_run_id')
            ->where('inv.family_id', $familyId)
            ->where('inv.currency', $currency)
            ->where('br.frequency', '!=', 'opening_balance')
            ->whereNotIn('inv.status', ['void', 'voided'])
            ->where('inv.previous_dues_snapshot_minor', '>', 0)
            ->select('inv.id', 'inv.invoice_number', 'inv.previous_dues_snapshot_minor')
            ->get();

        $tracked = [];
        foreach ($subsequentInvoices as $inv) {
            $before = (int) $inv->previous_dues_snapshot_minor;
            $after = max(0, $before - $reductionMinor);

            $db->table('invoices')->where('id', $inv->id)->update([
                'previous_dues_snapshot_minor' => $after,
                'updated_at' => now(),
            ]);

            $tracked[] = [
                'invoice_id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'before_snapshot_minor' => $before,
                'after_snapshot_minor' => $after,
                'reduction_minor' => $before - $after,
            ];
        }

        return $tracked;
    }

    /**
     * Filter audit records by gr, item_id, currency, or class.
     */
    protected function filterAuditRecords(array $records, array $filters): array
    {
        return array_values(array_filter($records, function ($r) use ($filters) {
            if (!empty($filters['gr'])) {
                $targetGr = trim((string) $filters['gr']);
                if (strcasecmp(trim((string) $r['student_gr']), $targetGr) !== 0) {
                    return false;
                }
            }

            if (!empty($filters['item_id'])) {
                if ((int) $r['invoice_item_id'] !== (int) $filters['item_id']) {
                    return false;
                }
            }

            if (!empty($filters['currency'])) {
                if (strcasecmp(trim((string) $r['currency']), trim((string) $filters['currency'])) !== 0) {
                    return false;
                }
            }

            if (!empty($filters['class'])) {
                $targetClass = trim((string) $filters['class']);
                $recordClass = trim((string) $r['reconciliation_classification']);
                $guardClass = trim((string) ($r['guard_class'] ?? ''));

                if (strcasecmp($recordClass, $targetClass) !== 0 && strcasecmp($guardClass, $targetClass) !== 0) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Portable check across MariaDB/MySQL and SQLite to verify if any candidate staged collection ID
     * has already been consumed by another active historical settlement.
     */
    protected function hasConsumedCollectionIds($db, int $currentItemId, array $candidateIds): bool
    {
        $targetIds = array_values(array_unique(array_map('intval', $candidateIds)));
        if (empty($targetIds)) {
            return false;
        }

        if ($db->getSchemaBuilder()->hasTable('qa_historical_settlement_collections')) {
            $existsInAllocTable = $db->table('qa_historical_settlement_collections')
                ->where('status', 'applied')
                ->where('invoice_item_id', '!=', $currentItemId)
                ->whereIn('qa_staged_collection_id', $targetIds)
                ->exists();
            if ($existsInAllocTable) {
                return true;
            }
        }

        $targetSet = array_flip($targetIds);
        $appliedRows = $db->table('qa_historical_settlements')
            ->where('status', 'applied')
            ->where('invoice_item_id', '!=', $currentItemId)
            ->whereNotNull('qa_staged_collection_ids')
            ->pluck('qa_staged_collection_ids');

        foreach ($appliedRows as $jsonIds) {
            $decoded = is_string($jsonIds) ? json_decode($jsonIds, true) : (array) $jsonIds;
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $consumedId) {
                if (isset($targetSet[(int) $consumedId])) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function isUniqueConstraintViolation(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return ($e instanceof \Illuminate\Database\UniqueConstraintViolationException)
            || (isset($e->errorInfo[1]) && in_array((int) $e->errorInfo[1], [1062, 19, 2067], true))
            || str_contains($msg, 'Duplicate entry')
            || str_contains($msg, 'UNIQUE constraint failed');
    }
}
