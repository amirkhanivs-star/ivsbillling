<?php

namespace App\Services\Migration;

use App\Services\BatchDispatchLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MySkoolPaymentCorrectionService
{
    // Historical payment case classifications (Task 3)
    public const CASE_A_RECONCILED_SETTLEMENT = 'CASE_A_RECONCILED_SETTLEMENT';
    public const CASE_B_UNRECONCILED_PRE_CUTOFF = 'CASE_B_UNRECONCILED_PRE_CUTOFF';
    public const CASE_B_UNRECONCILED_POST_CUTOFF = 'CASE_B_UNRECONCILED_POST_CUTOFF';
    public const CASE_C_SHARED_SLIP_ALLOCATION = 'CASE_C_SHARED_SLIP_ALLOCATION';
    public const CASE_D_DISPUTED_OR_MISASSIGNED = 'CASE_D_DISPUTED_OR_MISASSIGNED';
    public const CASE_E_REVERSED_SETTLEMENT = 'CASE_E_REVERSED_SETTLEMENT';

    // Supported correction actions
    public const ACTION_REVERSE_SETTLEMENT = 'reverse_settlement';
    public const ACTION_RESTORE_PAYMENT = 'restore_payment';
    public const ACTION_RECONCILE_LINEAGE = 'reconcile_lineage';
    public const ACTION_REAPPLY_SETTLEMENT = 'reapply_settlement';
    public const ACTION_MARK_UNDER_REVIEW = 'mark_under_review';
    public const ACTION_MARK_DISPUTED = 'mark_disputed';
    public const ACTION_MARK_CORRECTED = 'mark_corrected';
    public const ACTION_PROPOSE_REASSIGNMENT = 'propose_reassignment';
    public const ACTION_PROPOSE_PRE_CUTOFF_COMPENSATION = 'propose_pre_cutoff_compensation';

    // Resulting statuses
    public const STATUS_RECONCILED = 'reconciled';
    public const STATUS_PARTIALLY_REVERSED = 'partially_reversed';
    public const STATUS_REVERSED = 'reversed';
    public const STATUS_REAPPLIED = 'reapplied';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_CORRECTED = 'corrected';
    public const STATUS_HISTORICAL_PRE_CUTOFF = 'historical_pre_cutoff';
    public const STATUS_UNRECONCILED_POST_CUTOFF = 'unreconciled_post_cutoff';

    // Safety statuses
    public const SAFETY_SAFE_REVERSIBLE = 'SAFE_TO_REVERSE';
    public const SAFETY_SAFE_REAPPLICABLE = 'SAFE_TO_REAPPLY';
    public const SAFETY_SAFE_REVIEW_ONLY = 'MANUAL_REVIEW_ONLY';
    public const SAFETY_REQUIRES_RECONCILIATION = 'REQUIRES_RECONCILIATION';
    public const SAFETY_BLOCKED_PRE_CUTOFF_SNAPSHOT = 'BLOCKED_PRE_CUTOFF_SNAPSHOT';
    public const SAFETY_BLOCKED_CURRENCY_CONFLICT = 'BLOCKED_CURRENCY_CONFLICT';
    public const SAFETY_BLOCKED_INTERVENING_PORTAL_PAYMENT = 'BLOCKED_INTERVENING_PORTAL_PAYMENT';
    public const SAFETY_BLOCKED_ALREADY_REVERSED = 'BLOCKED_ALREADY_REVERSED';
    public const SAFETY_BLOCKED_ALREADY_CONSUMED = 'BLOCKED_ALREADY_CONSUMED';
    public const SAFETY_BLOCKED_DISPUTED_SOURCE = 'BLOCKED_DISPUTED_SOURCE';
    public const SAFETY_BLOCKED_OVERPAYMENT = 'BLOCKED_OVERPAYMENT';

    /**
     * Read-only forensic inspection of an imported MySkool receipt row in qa_staged_collections.
     * Computes all 14 required inspection fields, shared-slip sibling allocations,
     * before/after financial impact previews, and safety determinations without mutating any data.
     */
    public function inspect(int $stagedCollectionId, ?string $conn = null, bool $lockForUpdate = false): array
    {
        $conn = $conn ?: config('database.default', 'mysql');
        $db = DB::connection($conn);
        $schema = $db->getSchemaBuilder();

        if (!$schema->hasTable('qa_staged_collections')) {
            return [
                'exists' => false,
                'is_safe_to_reverse' => false,
                'is_safe_to_reapply' => false,
                'safety_status' => self::SAFETY_SAFE_REVIEW_ONLY,
                'error' => "Table 'qa_staged_collections' does not exist on connection '{$conn}'.",
            ];
        }

        $colQuery = $db->table('qa_staged_collections')->where('id', $stagedCollectionId);
        if ($lockForUpdate) {
            $colQuery->lockForUpdate();
        }
        $col = $colQuery->first();

        if (!$col) {
            return [
                'exists' => false,
                'qa_staged_collection_id' => $stagedCollectionId,
                'is_safe_to_reverse' => false,
                'is_safe_to_reapply' => false,
                'safety_status' => self::SAFETY_SAFE_REVIEW_ONLY,
                'error' => "Imported MySkool collection row #{$stagedCollectionId} not found.",
            ];
        }

        $cutoffDate = MySkoolReconciliationAuditService::DEFAULT_CUTOFF_DATE;
        $studentGr = trim((string) ($col->gr_number ?? ''));
        $slipNumber = trim((string) ($col->slip_number ?? ''));
        $colCurrency = strtoupper(trim((string) ($col->currency ?? 'SAR')));
        $colAmountMinor = (int) ($col->amount_minor ?? 0);
        $paymentDate = !empty($col->payment_date_parsed)
            ? (string) $col->payment_date_parsed
            : trim((string) ($col->payment_date_raw ?? ''));
        $isPreCutoff = empty($col->payment_date_parsed) || ((string) $col->payment_date_parsed <= $cutoffDate);

        // 1. Resolve student and family identity
        $student = null;
        $family = null;
        if ($studentGr !== '' && $schema->hasTable('students')) {
            $student = $db->table('students')->where('gr_number', $studentGr)->first();
            if ($student && !empty($student->family_id) && $schema->hasTable('families')) {
                $family = $db->table('families')->where('id', $student->family_id)->first();
            }
        }
        $colFamilyCode = trim((string) ($col->family_code ?? ($col->family_code_raw ?? '')));
        if (!$family && $colFamilyCode !== '' && $schema->hasTable('families')) {
            $family = $db->table('families')->where('family_code', $colFamilyCode)->first();
        }

        $studentId = $student ? (int) $student->id : null;
        $familyId = $family ? (int) $family->id : ($student && !empty($student->family_id) ? (int) $student->family_id : null);
        $studentName = $student ? (string) $student->name : trim((string) ($col->student_name ?? 'Unknown Student'));
        $familyCode = $family ? (string) $family->family_code : ($colFamilyCode !== '' ? $colFamilyCode : 'N/A');
        $guardianName = $family ? (string) ($family->guardian_name ?? ($family->father_name ?? '')) : '';

        // 2. Parse fee type and billing period from fee_particular
        $feeParticular = trim((string) ($col->fee_particular ?? 'Monthly Fee'));
        $parsedPeriod = HistoricalOpeningBalanceService::parseParticularPeriod($feeParticular);
        $parsedIdentity = MySkoolReconciliationAuditService::parseFeeIdentity($feeParticular);
        $periodKey = $parsedPeriod['period_key'] ?? ($parsedPeriod['period_start'] ? substr((string) $parsedPeriod['period_start'], 0, 7) : null);
        $periodStart = $parsedPeriod['period_start'] ?? null;
        $periodLabel = $parsedPeriod['label'] ?? ($periodKey ?: 'Historical');
        $feeTypeCode = $parsedIdentity['type'] ?? 'monthly';
        $feeTypeLabel = ucfirst(str_replace('_', ' ', (string) $feeTypeCode))
            . (!empty($parsedIdentity['subject']) ? ' (' . ucfirst((string) $parsedIdentity['subject']) . ')' : '');

        // 3. Inspect historical settlement records for this source collection row
        $hasSettlementsTable = $schema->hasTable('qa_historical_settlements');
        $hasSettlementColsTable = $schema->hasTable('qa_historical_settlement_collections');
        $hasCorrectionsTable = $schema->hasTable('qa_myskool_payment_corrections');

        $activeSettlement = null;
        $reversedSettlement = null;
        $consumedElsewhere = null;
        $settlementHistory = [];
        $activeSettlementColCount = 0;

        if ($hasSettlementsTable) {
            $matchedSettlementIds = [];
            if ($hasSettlementColsTable) {
                $allocRows = $db->table('qa_historical_settlement_collections')
                    ->where('qa_staged_collection_id', $stagedCollectionId)
                    ->orderByDesc('id')
                    ->get();
                foreach ($allocRows as $ar) {
                    $matchedSettlementIds[] = (int) $ar->settlement_id;
                }
            }

            $directSettlements = $db->table('qa_historical_settlements')
                ->where(function ($q) use ($stagedCollectionId, $matchedSettlementIds) {
                    $q->where('active_primary_collection_id', $stagedCollectionId);
                    if (!empty($matchedSettlementIds)) {
                        $q->orWhereIn('id', $matchedSettlementIds);
                    }
                    $q->orWhere('qa_staged_collection_ids', 'LIKE', '%' . $stagedCollectionId . '%');
                })
                ->orderByDesc('id')
                ->get();

            foreach ($directSettlements as $st) {
                $ids = json_decode((string) ($st->qa_staged_collection_ids ?? '[]'), true) ?: [];
                $idsInt = array_map('intval', is_array($ids) ? $ids : []);
                $inAllocTable = in_array((int) $st->id, $matchedSettlementIds, true);
                if (!$inAllocTable && (int) ($st->active_primary_collection_id ?? 0) !== $stagedCollectionId && !in_array($stagedCollectionId, $idsInt, true)) {
                    continue;
                }

                // Check if this specific collection row is still active inside qa_historical_settlement_collections
                $colAllocStatus = (string) $st->status;
                if ($hasSettlementColsTable) {
                    $specificColRow = $db->table('qa_historical_settlement_collections')
                        ->where('settlement_id', $st->id)
                        ->where('qa_staged_collection_id', $stagedCollectionId)
                        ->first();
                    if ($specificColRow) {
                        $colAllocStatus = (string) $specificColRow->status;
                    }
                }

                $settlementHistory[] = [
                    'settlement_id' => (int) $st->id,
                    'reconciliation_id' => (string) $st->reconciliation_id,
                    'invoice_item_id' => (int) $st->invoice_item_id,
                    'invoice_id' => (int) $st->invoice_id,
                    'settled_amount_minor' => (int) $st->settled_amount_minor,
                    'settled_amount_formatted' => number_format(((int) $st->settled_amount_minor) / 100, 2, '.', ''),
                    'status' => $colAllocStatus,
                    'settlement_row_status' => (string) $st->status,
                    'guard_class' => (string) $st->guard_class,
                    'applied_at' => $st->applied_at ? (string) $st->applied_at : null,
                    'reversed_at' => $st->reversed_at ? (string) $st->reversed_at : null,
                ];

                if ($colAllocStatus === 'applied' && (string) $st->status === 'applied') {
                    if ($activeSettlement === null) {
                        $activeSettlement = $st;
                    } elseif ((int) $st->invoice_item_id !== (int) $activeSettlement->invoice_item_id) {
                        $consumedElsewhere = $st;
                    }
                } elseif ($colAllocStatus === 'reversed' || (string) $st->status === 'reversed') {
                    if ($reversedSettlement === null) {
                        $reversedSettlement = $st;
                    }
                }
            }

            if ($activeSettlement && $hasSettlementColsTable) {
                $activeSettlementColCount = $db->table('qa_historical_settlement_collections')
                    ->where('settlement_id', $activeSettlement->id)
                    ->where('status', 'applied')
                    ->count();
            } elseif ($activeSettlement) {
                $ids = json_decode((string) ($activeSettlement->qa_staged_collection_ids ?? '[]'), true) ?: [];
                $activeSettlementColCount = max(1, count($ids));
            }
        }

        // 4. Inspect immutable correction audit trail from qa_myskool_payment_corrections
        $correctionHistory = [];
        $latestCorrection = null;
        $trackedReversedMinorFromHistory = 0;
        if ($hasCorrectionsTable) {
            $corrRows = $db->table('qa_myskool_payment_corrections as mc')
                ->leftJoin('users as u', 'u.id', '=', 'mc.acted_by')
                ->where('mc.qa_staged_collection_id', $stagedCollectionId)
                ->orderBy('mc.sequence_no')
                ->orderBy('mc.id')
                ->select('mc.*', 'u.name as actor_name', 'u.email as actor_email', 'u.role as actor_role')
                ->get();

            foreach ($corrRows as $cr) {
                $beforeSt = json_decode((string) $cr->before_state_json, true) ?: [];
                $afterSt = json_decode((string) $cr->after_state_json, true) ?: [];
                $crReversedMinor = isset($cr->reversed_amount_minor) && (int) $cr->reversed_amount_minor > 0
                    ? (int) $cr->reversed_amount_minor
                    : 0;
                $crAct = (string) $cr->action_type;
                if (in_array($crAct, [self::ACTION_REVERSE_SETTLEMENT, self::ACTION_RESTORE_PAYMENT], true)) {
                    if ($crReversedMinor <= 0) {
                        $crReversedMinor = isset($beforeSt['item_paid_minor'], $afterSt['item_paid_minor'])
                            ? max(0, (int) $beforeSt['item_paid_minor'] - (int) $afterSt['item_paid_minor'])
                            : $colAmountMinor;
                    }
                    $trackedReversedMinorFromHistory = isset($cr->cumulative_reversed_minor) && (int) $cr->cumulative_reversed_minor > 0
                        ? (int) $cr->cumulative_reversed_minor
                        : min($colAmountMinor, $trackedReversedMinorFromHistory + $crReversedMinor);
                } elseif (in_array($crAct, [self::ACTION_REAPPLY_SETTLEMENT, self::ACTION_RECONCILE_LINEAGE], true)) {
                    $trackedReversedMinorFromHistory = 0;
                }

                $entry = [
                    'id' => (int) $cr->id,
                    'correction_id' => (string) $cr->correction_id,
                    'sequence_no' => (int) $cr->sequence_no,
                    'action_type' => $crAct,
                    'resulting_status' => (string) $cr->resulting_status,
                    'restoration_mode' => $cr->restoration_mode ?? null,
                    'reversed_amount_minor' => $crReversedMinor,
                    'reversed_amount_formatted' => number_format($crReversedMinor / 100, 2, '.', ''),
                    'cumulative_reversed_minor' => $trackedReversedMinorFromHistory,
                    'cumulative_reversed_formatted' => number_format($trackedReversedMinorFromHistory / 100, 2, '.', ''),
                    'remaining_paid_minor' => isset($cr->remaining_paid_minor) && $cr->remaining_paid_minor !== null
                        ? (int) $cr->remaining_paid_minor
                        : max(0, $colAmountMinor - $trackedReversedMinorFromHistory),
                    'idempotency_key' => $cr->idempotency_key ?? null,
                    'case_classification' => (string) $cr->case_classification,
                    'reconciliation_id' => $cr->reconciliation_id ? (string) $cr->reconciliation_id : null,
                    'invoice_item_id' => $cr->invoice_item_id ? (int) $cr->invoice_item_id : null,
                    'proposed_student_gr' => $cr->proposed_student_gr ? (string) $cr->proposed_student_gr : null,
                    'proposed_period_key' => $cr->proposed_period_key ? (string) $cr->proposed_period_key : null,
                    'proposed_fee_type' => $cr->proposed_fee_type ? (string) $cr->proposed_fee_type : null,
                    'proposed_compensation_minor' => $cr->proposed_compensation_minor !== null ? (int) $cr->proposed_compensation_minor : null,
                    'reason' => (string) $cr->reason,
                    'acted_by' => (int) $cr->acted_by,
                    'actor_name' => (string) ($cr->actor_name ?: ('User #' . $cr->acted_by)),
                    'actor_role' => (string) ($cr->actor_role ?: 'admin'),
                    'acted_at' => (string) $cr->acted_at,
                    'before_state' => $beforeSt,
                    'after_state' => $afterSt,
                ];
                $correctionHistory[] = $entry;
                $latestCorrection = $entry;
            }
        }

        // 5. Inspect shared-slip sibling allocations (Case C: e.g. Slip 27328 for GR 5929 and GR 5930, or Slip 24083)
        $sharedSlipAllocations = [];
        if ($slipNumber !== '' && $slipNumber !== '0') {
            $familyGrs = [];
            if ($familyId && $schema->hasTable('students')) {
                $familyGrs = $db->table('students')
                    ->where('family_id', $familyId)
                    ->pluck('gr_number')
                    ->map(fn($g) => trim((string) $g))
                    ->filter()
                    ->all();
            }
            if ($studentGr !== '' && !in_array($studentGr, $familyGrs, true)) {
                $familyGrs[] = $studentGr;
            }

            $slipQuery = $db->table('qa_staged_collections')
                ->where('slip_number', $slipNumber);
            if (!empty($col->batch_id)) {
                $slipQuery->where('batch_id', $col->batch_id);
            }
            if (!empty($familyGrs)) {
                $slipQuery->where(function ($q) use ($familyGrs, $col) {
                    $q->whereIn('gr_number', $familyGrs);
                    if (!empty($col->payment_date_parsed)) {
                        $q->orWhere('payment_date_parsed', $col->payment_date_parsed);
                    }
                });
            }

            $slipRows = $slipQuery->orderBy('source_row')->orderBy('id')->get();
            foreach ($slipRows as $sr) {
                $srId = (int) $sr->id;
                $srGr = trim((string) ($sr->gr_number ?? ''));
                $srOrigMinor = (int) ($sr->amount_minor ?? 0);
                $srCurrency = strtoupper(trim((string) ($sr->currency ?? $colCurrency)));
                $srStudentName = $srGr === $studentGr
                    ? $studentName
                    : ($schema->hasTable('students')
                        ? ((string) ($db->table('students')->where('gr_number', $srGr)->value('name') ?: ($sr->student_name ?? $srGr)))
                        : (string) ($sr->student_name ?? $srGr));

                $srSettlementStatus = 'unreconciled';
                $srReconId = null;
                $srItemId = null;
                $srSettledMinor = 0;
                if ($hasSettlementColsTable) {
                    $srAlloc = $db->table('qa_historical_settlement_collections')
                        ->where('qa_staged_collection_id', $srId)
                        ->orderByDesc('id')
                        ->first();
                    if ($srAlloc) {
                        $srSettlementStatus = (string) $srAlloc->status;
                        $srReconId = (string) $srAlloc->reconciliation_id;
                        $srItemId = (int) $srAlloc->invoice_item_id;
                        if ($hasSettlementsTable && $srAlloc->settlement_id) {
                            $srSettledMinor = (int) $db->table('qa_historical_settlements')
                                ->where('id', $srAlloc->settlement_id)
                                ->value('settled_amount_minor');
                        }
                    }
                } elseif ($hasSettlementsTable) {
                    $srSet = $db->table('qa_historical_settlements')
                        ->where('active_primary_collection_id', $srId)
                        ->first();
                    if ($srSet) {
                        $srSettlementStatus = (string) $srSet->status;
                        $srReconId = (string) $srSet->reconciliation_id;
                        $srItemId = (int) $srSet->invoice_item_id;
                        $srSettledMinor = (int) $srSet->settled_amount_minor;
                    }
                }

                $srCorrStatus = null;
                $srReversedMinor = 0;
                if ($hasCorrectionsTable) {
                    $srCorrs = $db->table('qa_myskool_payment_corrections')
                        ->where('qa_staged_collection_id', $srId)
                        ->orderBy('sequence_no')
                        ->orderBy('id')
                        ->get();
                    foreach ($srCorrs as $sc) {
                        $srCorrStatus = (string) $sc->resulting_status;
                        $scAct = (string) $sc->action_type;
                        if (in_array($scAct, [self::ACTION_REVERSE_SETTLEMENT, self::ACTION_RESTORE_PAYMENT], true)) {
                            $scRev = isset($sc->reversed_amount_minor) && (int) $sc->reversed_amount_minor > 0
                                ? (int) $sc->reversed_amount_minor
                                : $srOrigMinor;
                            $srReversedMinor = isset($sc->cumulative_reversed_minor) && (int) $sc->cumulative_reversed_minor > 0
                                ? (int) $sc->cumulative_reversed_minor
                                : min($srOrigMinor, $srReversedMinor + $scRev);
                        } elseif (in_array($scAct, [self::ACTION_REAPPLY_SETTLEMENT, self::ACTION_RECONCILE_LINEAGE], true)) {
                            $srReversedMinor = 0;
                        }
                    }
                }

                if ($srSettlementStatus === 'reversed' && $srReversedMinor === 0) {
                    $srReversedMinor = $srOrigMinor;
                } elseif ($srSettlementStatus === 'applied' && $srSettledMinor > 0 && $srSettledMinor < $srOrigMinor) {
                    $srReversedMinor = max($srReversedMinor, $srOrigMinor - $srSettledMinor);
                }

                $srEffectivePaidMinor = max(0, $srOrigMinor - $srReversedMinor);
                $srIsCredited = ($srSettlementStatus === 'applied' && $srEffectivePaidMinor > 0);
                $srHasConflict = !empty($sr->is_currency_unresolved) || !empty($sr->has_currency_conflict);

                if ($srReversedMinor >= $srOrigMinor && $srOrigMinor > 0) {
                    $srAllocStatus = 'fully_reversed';
                    $srAllocLabel = 'Fully Reversed';
                } elseif ($srReversedMinor > 0) {
                    $srAllocStatus = 'partially_reversed';
                    $srAllocLabel = 'Partially Reversed';
                } elseif ($srHasConflict || $srCorrStatus === self::STATUS_DISPUTED) {
                    $srAllocStatus = 'requires_review';
                    $srAllocLabel = 'Requires Review';
                } elseif ($srIsCredited) {
                    $srAllocStatus = 'paid';
                    $srAllocLabel = 'Paid';
                } else {
                    $srAllocStatus = 'requires_reconciliation';
                    $srAllocLabel = 'Requires Reconciliation';
                }

                $sharedSlipAllocations[] = [
                    'qa_staged_collection_id' => $srId,
                    'source_row' => $sr->source_row !== null ? (int) $sr->source_row : null,
                    'is_current_row' => $srId === $stagedCollectionId,
                    'student_gr' => $srGr,
                    'student_name' => $srStudentName,
                    'fee_particular' => (string) ($sr->fee_particular ?? ''),
                    'currency' => $srCurrency,
                    'amount_minor' => $srOrigMinor,
                    'amount_formatted' => number_format($srOrigMinor / 100, 2, '.', ''),
                    'original_paid_minor' => $srOrigMinor,
                    'original_paid_formatted' => number_format($srOrigMinor / 100, 2, '.', ''),
                    'reversed_amount_minor' => $srReversedMinor,
                    'reversed_amount_formatted' => number_format($srReversedMinor / 100, 2, '.', ''),
                    'effective_paid_minor' => $srEffectivePaidMinor,
                    'effective_paid_formatted' => number_format($srEffectivePaidMinor / 100, 2, '.', ''),
                    'remaining_reversible_minor' => $srIsCredited ? $srEffectivePaidMinor : 0,
                    'remaining_reversible_formatted' => number_format(($srIsCredited ? $srEffectivePaidMinor : 0) / 100, 2, '.', ''),
                    'is_credited_in_ledger' => $srIsCredited,
                    'allocation_status' => $srAllocStatus,
                    'allocation_status_label' => $srAllocLabel,
                    'settlement_status' => $srSettlementStatus,
                    'reconciliation_id' => $srReconId,
                    'invoice_item_id' => $srItemId,
                    'correction_status' => $srCorrStatus ? (string) $srCorrStatus : null,
                ];
            }
        }
        $isSharedSlip = count($sharedSlipAllocations) > 1;

        // 6. Resolve linked or candidate invoice_item and parent invoice
        $targetItemId = null;
        if ($activeSettlement) {
            $targetItemId = (int) $activeSettlement->invoice_item_id;
        } elseif ($reversedSettlement) {
            $targetItemId = (int) $reversedSettlement->invoice_item_id;
        } elseif ($latestCorrection && !empty($latestCorrection['invoice_item_id'])) {
            $targetItemId = (int) $latestCorrection['invoice_item_id'];
        }

        $item = null;
        $invoice = null;
        if ($targetItemId && $schema->hasTable('invoice_items') && $schema->hasTable('invoices')) {
            $itemQuery = $db->table('invoice_items')->where('id', $targetItemId);
            if ($lockForUpdate) {
                $itemQuery->lockForUpdate();
            }
            $item = $itemQuery->first();
            if ($item) {
                $invQuery = $db->table('invoices')->where('id', $item->invoice_id);
                if ($lockForUpdate) {
                    $invQuery->lockForUpdate();
                }
                $invoice = $invQuery->first();
            }
        }

        // If no direct settlement item, look for matching candidate invoice item for this student
        if (!$item && $studentId && $schema->hasTable('invoice_items') && $schema->hasTable('invoices')) {
            $candQuery = $db->table('invoice_items as ii')
                ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
                ->leftJoin('billing_runs as br', 'br.id', '=', 'inv.billing_run_id')
                ->where('ii.student_id', $studentId)
                ->where('ii.status', '!=', 'voided')
                ->whereNotIn('inv.status', ['void', 'voided'])
                ->select('ii.*', 'br.frequency as billing_run_frequency');

            $studentItems = $candQuery->orderBy('ii.id')->get();
            foreach ($studentItems as $cand) {
                $candIdentity = MySkoolReconciliationAuditService::parseFeeIdentity((string) $cand->description, (string) $cand->period_key);
                $typeMatch = ($candIdentity['type'] === $feeTypeCode) && ($candIdentity['subject'] === ($parsedIdentity['subject'] ?? null));
                $periodMatch = ($periodStart && (string) $cand->period_start === (string) $periodStart)
                    || ($periodKey && (string) $cand->period_key === (string) $periodKey);
                if ($typeMatch && $periodMatch) {
                    $item = $cand;
                    break;
                }
            }
            // Fallback to opening balance item only when the OB candidate matches the period (or has no specific period)
            if (!$item && $studentItems->isNotEmpty()) {
                $obCandidate = $studentItems->first(function ($c) use ($periodStart) {
                    if (($c->billing_run_frequency ?? '') !== 'opening_balance') {
                        return false;
                    }
                    return empty($periodStart) || empty($c->period_start) || (string) $c->period_start === (string) $periodStart;
                });
                if ($obCandidate && ($isPreCutoff || $periodStart === '2026-10-01')) {
                    $item = $obCandidate;
                }
            }
            if ($item) {
                $invQuery = $db->table('invoices')->where('id', $item->invoice_id);
                if ($lockForUpdate) {
                    $invQuery->lockForUpdate();
                }
                $invoice = $invQuery->first();
            }
        }

        // 7. Compute student & family total outstanding balances and period-aware currency expectations
        $studentExpectedCurrency = null;
        $periodHistoricalCurrency = null;
        $studentTotalOutstandingMinor = 0;
        $familyTotalOutstandingMinor = 0;
        $portalReceiptsCount = 0;

        // Check period-specific historical fee plan currency first (e.g. when enrollment currency changed in a later month)
        if ($studentId && $schema->hasTable('fee_plan_items') && $schema->hasTable('enrollments')) {
            $fpRows = $db->table('fee_plan_items as fpi')
                ->join('enrollments as e', 'e.id', '=', 'fpi.enrollment_id')
                ->where('e.student_id', $studentId)
                ->orderBy('fpi.id')
                ->select('fpi.currency', 'fpi.effective_from', 'fpi.effective_to')
                ->get();
            foreach ($fpRows as $fpr) {
                $fpCurr = strtoupper(trim((string) ($fpr->currency ?? '')));
                if ($fpCurr === '') {
                    continue;
                }
                if ($periodStart) {
                    $fromOk = empty($fpr->effective_from) || ((string) $fpr->effective_from <= (string) $periodStart);
                    $toOk = empty($fpr->effective_to) || ((string) $fpr->effective_to >= (string) $periodStart);
                    if ($fromOk && $toOk) {
                        $periodHistoricalCurrency = $fpCurr;
                        if ($studentExpectedCurrency === null) {
                            $studentExpectedCurrency = $fpCurr;
                        }
                        if ($fpCurr === $colCurrency) {
                            break;
                        }
                    }
                }
                if ($studentExpectedCurrency === null) {
                    $studentExpectedCurrency = $fpCurr;
                }
            }
        }

        if ($studentId && $schema->hasTable('invoice_items') && $schema->hasTable('invoices')) {
            $stuActiveItems = $db->table('invoice_items as ii')
                ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
                ->where('ii.student_id', $studentId)
                ->where('ii.status', '!=', 'voided')
                ->whereNotIn('inv.status', ['void', 'voided'])
                ->get(['ii.currency', 'ii.net_minor', 'ii.paid_minor']);

            foreach ($stuActiveItems as $sai) {
                $saiCurr = strtoupper(trim((string) ($sai->currency ?? '')));
                if ($studentExpectedCurrency === null && $saiCurr !== '') {
                    $studentExpectedCurrency = $saiCurr;
                }
                if ($saiCurr === $colCurrency) {
                    $studentTotalOutstandingMinor += max(0, (int) $sai->net_minor - (int) $sai->paid_minor);
                }
            }
        }

        if ($periodHistoricalCurrency !== null && empty($col->has_currency_conflict) && empty($col->is_currency_unresolved)) {
            $studentExpectedCurrency = $periodHistoricalCurrency;
        }

        if ($studentExpectedCurrency === null && $studentId && $schema->hasTable('enrollments')) {
            $enrCurr = $db->table('enrollments')->where('student_id', $studentId)->value('currency');
            if ($enrCurr) {
                $studentExpectedCurrency = strtoupper(trim((string) $enrCurr));
            }
        }

        if ($studentExpectedCurrency === null && $studentGr !== '' && $schema->hasTable('qa_staged_dues')) {
            $sdCurr = $db->table('qa_staged_dues')->where('gr_number', $studentGr)->value('currency');
            if ($sdCurr) {
                $studentExpectedCurrency = strtoupper(trim((string) $sdCurr));
            }
        }

        if ($familyId && $schema->hasTable('invoice_items') && $schema->hasTable('invoices')) {
            $famItems = $db->table('invoice_items as ii')
                ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
                ->where('inv.family_id', $familyId)
                ->where('ii.currency', $colCurrency)
                ->where('ii.status', '!=', 'voided')
                ->whereNotIn('inv.status', ['void', 'voided'])
                ->get(['ii.net_minor', 'ii.paid_minor']);
            foreach ($famItems as $fi) {
                $familyTotalOutstandingMinor += max(0, (int) $fi->net_minor - (int) $fi->paid_minor);
            }

            if ($schema->hasTable('payments')) {
                $portalReceiptsCount = $db->table('payments')
                    ->where('family_id', $familyId)
                    ->where('status', '!=', 'reversed')
                    ->count();
            }
        }

        // 8. Check currency conflict (Rule 9 & Task 6 GR 8620 / GR 8240)
        $itemCurrency = $item ? strtoupper(trim((string) $item->currency)) : $studentExpectedCurrency;
        $hasCurrencyConflict = !empty($col->is_currency_unresolved)
            || !empty($col->has_currency_conflict)
            || ($itemCurrency !== null && $itemCurrency !== '' && $itemCurrency !== $colCurrency)
            || ($studentExpectedCurrency !== null && $studentExpectedCurrency !== '' && $studentExpectedCurrency !== $colCurrency);

        // 9. Check existing adjustments and intervening Portal cashier payments on the target item
        $feeAdjustments = [];
        $hasInterveningPortalPayments = false;
        $interveningPortalAmountMinor = 0;

        if ($item) {
            if ($schema->hasTable('fee_adjustments')) {
                $adjRows = $db->table('fee_adjustments')
                    ->where('invoice_item_id', $item->id)
                    ->orderBy('id')
                    ->get();
                foreach ($adjRows as $adj) {
                    $feeAdjustments[] = [
                        'id' => (int) $adj->id,
                        'kind' => (string) $adj->kind,
                        'amount_minor' => (int) $adj->amount_minor,
                        'amount_formatted' => number_format(((int) $adj->amount_minor) / 100, 2, '.', ''),
                        'reason' => (string) ($adj->reason ?? ''),
                        'created_at' => (string) ($adj->created_at ?? ''),
                    ];
                }
            }

            if ($schema->hasTable('payment_allocations')) {
                $interveningPortalAmountMinor = (int) $db->table('payment_allocations')
                    ->where('invoice_item_id', $item->id)
                    ->where('amount_minor', '>', 0)
                    ->sum('amount_minor');
                $hasInterveningPortalPayments = $interveningPortalAmountMinor > 0;
            }
        }

        // 10. Determine current receipt status, partial/full reversal amounts & case classification (Cases A - E)
        $isReconciled = ($activeSettlement !== null);
        $currentReceiptStatus = self::STATUS_UNRECONCILED_POST_CUTOFF;
        $currentStatusLabel = 'Unreconciled (Post-Cutoff)';

        $previouslyReversedMinor = $trackedReversedMinorFromHistory;
        if (!$isReconciled && ($reversedSettlement !== null || ($latestCorrection && (string) $latestCorrection['resulting_status'] === self::STATUS_REVERSED))) {
            $previouslyReversedMinor = max($previouslyReversedMinor, $colAmountMinor);
        } elseif ($isReconciled && $activeSettlementColCount <= 1) {
            $settledMinorNow = (int) $activeSettlement->settled_amount_minor;
            if ($settledMinorNow < $colAmountMinor && $trackedReversedMinorFromHistory > 0) {
                $previouslyReversedMinor = max($trackedReversedMinorFromHistory, $colAmountMinor - $settledMinorNow);
            }
        }
        $previouslyReversedMinor = min($colAmountMinor, max(0, $previouslyReversedMinor));
        $effectivePaidMinor = max(0, $colAmountMinor - $previouslyReversedMinor);

        $isCreditedInLedger = $isReconciled
            && $item !== null
            && !$hasCurrencyConflict
            && (int) $item->paid_minor > 0
            && $effectivePaidMinor > 0;

        $remainingReversibleMinor = 0;
        if ($isCreditedInLedger && $activeSettlement) {
            $activeSettledCap = (int) $activeSettlement->settled_amount_minor;
            $itemPaidCap = $item ? max(0, (int) $item->paid_minor - $interveningPortalAmountMinor) : 0;
            $remainingReversibleMinor = max(0, min($effectivePaidMinor, $activeSettledCap, $itemPaidCap));
        }

        if ($latestCorrection) {
            $corrResult = (string) $latestCorrection['resulting_status'];
            if ($corrResult === self::STATUS_REVERSED && !$isReconciled) {
                $currentReceiptStatus = self::STATUS_REVERSED;
                $currentStatusLabel = 'Reversed';
            } elseif ($corrResult === self::STATUS_PARTIALLY_REVERSED && $isReconciled) {
                $currentReceiptStatus = self::STATUS_PARTIALLY_REVERSED;
                $currentStatusLabel = 'Partially Reversed';
            } elseif ($corrResult === self::STATUS_DISPUTED) {
                $currentReceiptStatus = self::STATUS_DISPUTED;
                $currentStatusLabel = 'Disputed';
            } elseif ($corrResult === self::STATUS_UNDER_REVIEW) {
                $currentReceiptStatus = self::STATUS_UNDER_REVIEW;
                $currentStatusLabel = 'Under Review';
            } elseif ($corrResult === self::STATUS_CORRECTED) {
                $currentReceiptStatus = self::STATUS_CORRECTED;
                $currentStatusLabel = 'Corrected';
            } elseif ($isReconciled) {
                $currentReceiptStatus = $previouslyReversedMinor > 0 ? self::STATUS_PARTIALLY_REVERSED : self::STATUS_RECONCILED;
                $currentStatusLabel = $previouslyReversedMinor > 0
                    ? 'Partially Reversed'
                    : ($corrResult === self::STATUS_REAPPLIED ? 'Reconciled (Reapplied)' : 'Reconciled');
            }
        } else {
            if ($isReconciled) {
                $currentReceiptStatus = $previouslyReversedMinor > 0 ? self::STATUS_PARTIALLY_REVERSED : self::STATUS_RECONCILED;
                $currentStatusLabel = $previouslyReversedMinor > 0 ? 'Partially Reversed' : 'Reconciled';
            } elseif ($reversedSettlement) {
                $currentReceiptStatus = self::STATUS_REVERSED;
                $currentStatusLabel = 'Reversed';
            } elseif ($hasCurrencyConflict) {
                $currentReceiptStatus = self::STATUS_UNDER_REVIEW;
                $currentStatusLabel = 'Under Review (Currency Conflict)';
            } elseif ($isPreCutoff) {
                $currentReceiptStatus = self::STATUS_HISTORICAL_PRE_CUTOFF;
                $currentStatusLabel = 'Pre-Cutoff Historical (Reflected in OB)';
            }
        }

        // Individual allocation status (Task 4: Paid, Partially Reversed, Fully Reversed, Requires Review, or Requires Reconciliation)
        if ($previouslyReversedMinor >= $colAmountMinor && $colAmountMinor > 0) {
            $allocationStatus = 'fully_reversed';
            $allocationStatusLabel = 'Fully Reversed';
        } elseif ($previouslyReversedMinor > 0 && $previouslyReversedMinor < $colAmountMinor) {
            $allocationStatus = 'partially_reversed';
            $allocationStatusLabel = 'Partially Reversed';
        } elseif ($hasCurrencyConflict || $currentReceiptStatus === self::STATUS_DISPUTED) {
            $allocationStatus = 'requires_review';
            $allocationStatusLabel = 'Requires Review';
        } elseif ($isCreditedInLedger) {
            $allocationStatus = 'paid';
            $allocationStatusLabel = 'Paid';
        } else {
            $allocationStatus = 'requires_reconciliation';
            $allocationStatusLabel = 'Requires Reconciliation';
        }

        $requiresReconciliation = ($allocationStatus === 'requires_reconciliation');
        $canReconcileLineage = $requiresReconciliation
            && !$hasCurrencyConflict
            && $studentId !== null
            && $familyId !== null
            && !empty($periodKey)
            && !empty($periodStart)
            && $colAmountMinor > 0
            && !$consumedElsewhere;

        // Case classification
        if ($isReconciled && $isSharedSlip) {
            $caseClassification = self::CASE_C_SHARED_SLIP_ALLOCATION;
        } elseif ($isReconciled) {
            $caseClassification = self::CASE_A_RECONCILED_SETTLEMENT;
        } elseif ($reversedSettlement !== null || $currentReceiptStatus === self::STATUS_REVERSED) {
            $caseClassification = self::CASE_E_REVERSED_SETTLEMENT;
        } elseif ($hasCurrencyConflict || in_array($currentReceiptStatus, [self::STATUS_DISPUTED, self::STATUS_CORRECTED], true)) {
            $caseClassification = self::CASE_D_DISPUTED_OR_MISASSIGNED;
        } elseif ($isPreCutoff) {
            $caseClassification = self::CASE_B_UNRECONCILED_PRE_CUTOFF;
        } else {
            $caseClassification = self::CASE_B_UNRECONCILED_POST_CUTOFF;
        }

        // 11. Evaluate safety rules for reversal, restoration, reapplication, and review/dispute actions
        $blockedReasons = [];
        $availableActions = [];
        $isSafeToReverse = false;
        $isSafeToReapply = false;
        $safetyStatus = self::SAFETY_SAFE_REVIEW_ONLY;
        $safetyExplanation = '';

        if ($hasCurrencyConflict) {
            $safetyStatus = self::SAFETY_BLOCKED_CURRENCY_CONFLICT;
            $expectedCurrStr = $itemCurrency ?: ($studentExpectedCurrency ?: 'UNKNOWN');
            $reasonMsg = "Cross-currency conflict detected: MySkool collection row is in {$colCurrency}, whereas student/invoice obligation currency is {$expectedCurrStr}. Automatic or manual financial balance mutation is blocked to preserve currency isolation.";
            $blockedReasons[] = $reasonMsg;
            $safetyExplanation = $reasonMsg;
            $availableActions = [
                self::ACTION_MARK_UNDER_REVIEW,
                self::ACTION_MARK_DISPUTED,
            ];
        } elseif ($isReconciled) {
            if ($hasInterveningPortalPayments) {
                $safetyStatus = self::SAFETY_BLOCKED_INTERVENING_PORTAL_PAYMENT;
                $reasonMsg = "Cannot reverse historical settlement '{$activeSettlement->reconciliation_id}' because active Portal cashier payment allocations ({$colCurrency} " . number_format($interveningPortalAmountMinor / 100, 2) . ") exist on invoice item #{$item->id}. Genuine Portal receipts are protected.";
                $blockedReasons[] = $reasonMsg;
                $safetyExplanation = $reasonMsg;
                $availableActions = [self::ACTION_MARK_UNDER_REVIEW];
            } elseif ($remainingReversibleMinor <= 0) {
                $safetyStatus = self::SAFETY_BLOCKED_ALREADY_REVERSED;
                $reasonMsg = "No remaining reversible amount on MySkool collection row #{$stagedCollectionId}.";
                $blockedReasons[] = $reasonMsg;
                $safetyExplanation = $reasonMsg;
                $availableActions = [self::ACTION_MARK_UNDER_REVIEW];
            } else {
                $isSafeToReverse = true;
                $safetyStatus = self::SAFETY_SAFE_REVERSIBLE;
                $safetyExplanation = $isSharedSlip
                    ? "Safe to restore up to {$colCurrency} " . number_format($remainingReversibleMinor / 100, 2) . " on this individual source allocation (Row #{$stagedCollectionId} for GR {$studentGr}) without affecting sibling allocations on shared slip #{$slipNumber}."
                    : "Safe to restore up to {$colCurrency} " . number_format($remainingReversibleMinor / 100, 2) . " from historical settlement '{$activeSettlement->reconciliation_id}' to outstanding dues on {$invoice->invoice_number}.";
                $availableActions = [
                    self::ACTION_REVERSE_SETTLEMENT,
                    self::ACTION_RESTORE_PAYMENT,
                    self::ACTION_MARK_DISPUTED,
                    self::ACTION_MARK_UNDER_REVIEW,
                ];
            }
        } elseif ($reversedSettlement !== null || $currentReceiptStatus === self::STATUS_REVERSED) {
            // Case E: Reversed settlement — check if safe to reapply
            if ($consumedElsewhere) {
                $safetyStatus = self::SAFETY_BLOCKED_ALREADY_CONSUMED;
                $reasonMsg = "Cannot reapply: source collection row #{$stagedCollectionId} has already been consumed by settlement '{$consumedElsewhere->reconciliation_id}' on item #{$consumedElsewhere->invoice_item_id}.";
                $blockedReasons[] = $reasonMsg;
                $safetyExplanation = $reasonMsg;
            } elseif ($currentReceiptStatus === self::STATUS_DISPUTED) {
                $safetyStatus = self::SAFETY_BLOCKED_DISPUTED_SOURCE;
                $reasonMsg = "Cannot reapply: source collection row #{$stagedCollectionId} is currently marked as Disputed.";
                $blockedReasons[] = $reasonMsg;
                $safetyExplanation = $reasonMsg;
            } elseif ($hasInterveningPortalPayments) {
                $safetyStatus = self::SAFETY_BLOCKED_INTERVENING_PORTAL_PAYMENT;
                $reasonMsg = "Cannot reapply: intervening Portal cashier payment allocations exist on target invoice item #{$item->id}. Reapplying would cause duplicate payment recognition.";
                $blockedReasons[] = $reasonMsg;
                $safetyExplanation = $reasonMsg;
            } elseif (!$item) {
                $safetyStatus = self::SAFETY_SAFE_REVIEW_ONLY;
                $reasonMsg = "Cannot reapply: no target invoice item found for this reversed settlement.";
                $blockedReasons[] = $reasonMsg;
                $safetyExplanation = $reasonMsg;
            } else {
                $itemUnpaid = max(0, (int) $item->net_minor - (int) $item->paid_minor);
                $reapplyCandidateMinor = $reversedSettlement
                    ? min($colAmountMinor, max((int) $reversedSettlement->settled_amount_minor, $previouslyReversedMinor))
                    : min($colAmountMinor, $itemUnpaid);
                if ($itemUnpaid <= 0 || $reapplyCandidateMinor <= 0 || ((int) $item->paid_minor + $reapplyCandidateMinor > (int) $item->net_minor)) {
                    $safetyStatus = self::SAFETY_BLOCKED_OVERPAYMENT;
                    $reasonMsg = "Cannot reapply: target invoice item #{$item->id} does not have sufficient unpaid balance ({$colCurrency} " . number_format($itemUnpaid / 100, 2) . "). Reapplication would cause duplicate recognition.";
                    $blockedReasons[] = $reasonMsg;
                    $safetyExplanation = $reasonMsg;
                } else {
                    $isSafeToReapply = true;
                    $safetyStatus = self::SAFETY_SAFE_REAPPLICABLE;
                    $safetyExplanation = "Settlement '{$reversedSettlement->reconciliation_id}' is currently Reversed. Safe to reapply {$colCurrency} " . number_format($reapplyCandidateMinor / 100, 2) . " to invoice item #{$item->id} or record a dispute/reassignment.";
                    $blockedActionsMsg = "Already reversed — double reversal is blocked.";
                    $blockedReasons[] = $blockedActionsMsg;
                }
            }

            $availableActions = array_values(array_filter([
                $isSafeToReapply ? self::ACTION_REAPPLY_SETTLEMENT : null,
                self::ACTION_MARK_DISPUTED,
                self::ACTION_MARK_CORRECTED,
                self::ACTION_PROPOSE_REASSIGNMENT,
                self::ACTION_MARK_UNDER_REVIEW,
            ]));
        } elseif ($isPreCutoff) {
            // Case B (Pre-Cutoff): not yet reconciled as an active ledger credit
            $safetyStatus = self::SAFETY_BLOCKED_PRE_CUTOFF_SNAPSHOT;
            $reasonMsg = "Requires Reconciliation: Pre-cutoff receipt ({$paymentDate} <= cutoff {$cutoffDate}) has not been posted as an active paid credit in the ledger. Reconcile its original fee obligation and source lineage first before restoring any balance, or use the manual-review workflow.";
            $blockedReasons[] = $reasonMsg;
            $safetyExplanation = $reasonMsg;
            $availableActions = array_values(array_filter([
                $canReconcileLineage ? self::ACTION_RECONCILE_LINEAGE : null,
                self::ACTION_MARK_UNDER_REVIEW,
                self::ACTION_MARK_DISPUTED,
                self::ACTION_PROPOSE_PRE_CUTOFF_COMPENSATION,
                self::ACTION_PROPOSE_REASSIGNMENT,
            ]));
        } else {
            // Case B (Post-Cutoff Unreconciled) or Case D
            $safetyStatus = self::SAFETY_SAFE_REVIEW_ONLY;
            $reasonMsg = "Requires Reconciliation: This MySkool receipt is not currently reconciled to an active invoice settlement in the ledger. Reconcile its fee obligation first before executing a balance-changing restoration.";
            $blockedReasons[] = $reasonMsg;
            $safetyExplanation = $reasonMsg;
            $availableActions = array_values(array_filter([
                $canReconcileLineage ? self::ACTION_RECONCILE_LINEAGE : null,
                self::ACTION_MARK_UNDER_REVIEW,
                self::ACTION_MARK_DISPUTED,
                self::ACTION_MARK_CORRECTED,
                self::ACTION_PROPOSE_REASSIGNMENT,
            ]));
        }

        // 12. Build current financial balances & before/after effect previews
        $itemNetMinor = $item ? (int) $item->net_minor : 0;
        $itemPaidMinor = $item ? (int) $item->paid_minor : 0;
        $itemBalanceMinor = max(0, $itemNetMinor - $itemPaidMinor);
        $itemStatus = $item ? (string) $item->status : 'N/A';

        $invTotalMinor = $invoice ? (int) $invoice->total_minor : 0;
        $invPaidMinor = $invoice ? (int) $invoice->paid_minor : 0;
        $invBalanceMinor = max(0, $invTotalMinor - $invPaidMinor);
        $invStatus = $invoice ? (string) $invoice->status : 'N/A';

        // Reversal delta calculation (supports full or remaining reversible amount on single/multi-collection settlements)
        $reversalDeltaMinor = 0;
        if ($activeSettlement) {
            $reversalDeltaMinor = $remainingReversibleMinor > 0
                ? $remainingReversibleMinor
                : ($activeSettlementColCount > 1
                    ? min($colAmountMinor, (int) $activeSettlement->settled_amount_minor)
                    : (int) $activeSettlement->settled_amount_minor);
        }

        $afterRevItemPaidMinor = max(0, $itemPaidMinor - $reversalDeltaMinor);
        $afterRevItemBalanceMinor = max(0, $itemNetMinor - $afterRevItemPaidMinor);
        $afterRevItemStatus = $item
            ? ($afterRevItemPaidMinor >= $itemNetMinor && $itemNetMinor > 0
                ? 'paid'
                : ($afterRevItemPaidMinor > 0 ? 'partial' : (string) ($activeSettlement->previous_item_status ?? 'active')))
            : 'N/A';

        $afterRevInvPaidMinor = max(0, $invPaidMinor - $reversalDeltaMinor);
        $afterRevInvBalanceMinor = max(0, $invTotalMinor - $afterRevInvPaidMinor);
        $afterRevInvStatus = $invoice
            ? ($afterRevInvPaidMinor >= $invTotalMinor && $invTotalMinor > 0
                ? 'paid'
                : ($afterRevInvPaidMinor > 0 ? 'partial' : (string) ($activeSettlement->previous_invoice_status ?? 'unpaid')))
            : 'N/A';

        // Reapply delta calculation
        $reapplyDeltaMinor = 0;
        if ($reversedSettlement && $item) {
            $reapplyDeltaMinor = min(
                $colAmountMinor,
                max((int) $reversedSettlement->settled_amount_minor, $previouslyReversedMinor),
                max(0, $itemNetMinor - $itemPaidMinor)
            );
        } elseif ($item) {
            $reapplyDeltaMinor = min($colAmountMinor, max(0, $itemNetMinor - $itemPaidMinor));
        }

        $afterReapplyItemPaidMinor = $itemPaidMinor + $reapplyDeltaMinor;
        $afterReapplyItemBalanceMinor = max(0, $itemNetMinor - $afterReapplyItemPaidMinor);
        $afterReapplyItemStatus = $item
            ? ($afterReapplyItemPaidMinor >= $itemNetMinor && $itemNetMinor > 0 ? 'paid' : ($afterReapplyItemPaidMinor > 0 ? 'partial' : $itemStatus))
            : 'N/A';

        $afterReapplyInvPaidMinor = $invPaidMinor + $reapplyDeltaMinor;
        $afterReapplyInvBalanceMinor = max(0, $invTotalMinor - $afterReapplyInvPaidMinor);
        $afterReapplyInvStatus = $invoice
            ? ($afterReapplyInvPaidMinor >= $invTotalMinor && $invTotalMinor > 0 ? 'paid' : ($afterReapplyInvPaidMinor > 0 ? 'partial' : $invStatus))
            : 'N/A';

        $proposedEffects = [
            self::ACTION_REVERSE_SETTLEMENT => [
                'action_label' => 'Reverse Reconciled MySkool Settlement',
                'is_permitted' => $isSafeToReverse,
                'delta_minor' => $reversalDeltaMinor,
                'delta_formatted' => number_format($reversalDeltaMinor / 100, 2, '.', ''),
                'before' => [
                    'receipt_status' => $currentStatusLabel,
                    'allocation_effective_paid_minor' => $effectivePaidMinor,
                    'allocation_effective_paid_formatted' => number_format($effectivePaidMinor / 100, 2, '.', ''),
                    'item_paid_minor' => $itemPaidMinor,
                    'item_paid_formatted' => number_format($itemPaidMinor / 100, 2, '.', ''),
                    'item_balance_minor' => $itemBalanceMinor,
                    'item_balance_formatted' => number_format($itemBalanceMinor / 100, 2, '.', ''),
                    'item_status' => $itemStatus,
                    'invoice_paid_minor' => $invPaidMinor,
                    'invoice_paid_formatted' => number_format($invPaidMinor / 100, 2, '.', ''),
                    'invoice_balance_minor' => $invBalanceMinor,
                    'invoice_balance_formatted' => number_format($invBalanceMinor / 100, 2, '.', ''),
                    'invoice_status' => $invStatus,
                ],
                'after' => [
                    'receipt_status' => 'Reversed',
                    'allocation_effective_paid_minor' => 0,
                    'allocation_effective_paid_formatted' => '0.00',
                    'item_paid_minor' => $afterRevItemPaidMinor,
                    'item_paid_formatted' => number_format($afterRevItemPaidMinor / 100, 2, '.', ''),
                    'item_balance_minor' => $afterRevItemBalanceMinor,
                    'item_balance_formatted' => number_format($afterRevItemBalanceMinor / 100, 2, '.', ''),
                    'item_status' => $afterRevItemStatus,
                    'invoice_paid_minor' => $afterRevInvPaidMinor,
                    'invoice_paid_formatted' => number_format($afterRevInvPaidMinor / 100, 2, '.', ''),
                    'invoice_balance_minor' => $afterRevInvBalanceMinor,
                    'invoice_balance_formatted' => number_format($afterRevInvBalanceMinor / 100, 2, '.', ''),
                    'invoice_status' => $afterRevInvStatus,
                ],
                'preserves_staged_collection' => true,
                'preserves_sibling_allocations' => true,
                'zero_cashier_impact' => true,
            ],
            self::ACTION_RESTORE_PAYMENT => [
                'action_label' => 'Restore Selected Amount to Outstanding Dues',
                'is_permitted' => $isSafeToReverse,
                'delta_minor' => $reversalDeltaMinor,
                'delta_formatted' => number_format($reversalDeltaMinor / 100, 2, '.', ''),
                'max_reversible_minor' => $remainingReversibleMinor,
                'max_reversible_formatted' => number_format($remainingReversibleMinor / 100, 2, '.', ''),
                'before' => [
                    'receipt_status' => $currentStatusLabel,
                    'allocation_effective_paid_minor' => $effectivePaidMinor,
                    'allocation_effective_paid_formatted' => number_format($effectivePaidMinor / 100, 2, '.', ''),
                    'item_paid_minor' => $itemPaidMinor,
                    'item_paid_formatted' => number_format($itemPaidMinor / 100, 2, '.', ''),
                    'item_balance_minor' => $itemBalanceMinor,
                    'item_balance_formatted' => number_format($itemBalanceMinor / 100, 2, '.', ''),
                    'item_status' => $itemStatus,
                    'invoice_paid_minor' => $invPaidMinor,
                    'invoice_paid_formatted' => number_format($invPaidMinor / 100, 2, '.', ''),
                    'invoice_balance_minor' => $invBalanceMinor,
                    'invoice_balance_formatted' => number_format($invBalanceMinor / 100, 2, '.', ''),
                    'invoice_status' => $invStatus,
                ],
                'after' => [
                    'receipt_status' => 'Fully Reversed',
                    'allocation_effective_paid_minor' => 0,
                    'allocation_effective_paid_formatted' => '0.00',
                    'item_paid_minor' => $afterRevItemPaidMinor,
                    'item_paid_formatted' => number_format($afterRevItemPaidMinor / 100, 2, '.', ''),
                    'item_balance_minor' => $afterRevItemBalanceMinor,
                    'item_balance_formatted' => number_format($afterRevItemBalanceMinor / 100, 2, '.', ''),
                    'item_status' => $afterRevItemStatus,
                    'invoice_paid_minor' => $afterRevInvPaidMinor,
                    'invoice_paid_formatted' => number_format($afterRevInvPaidMinor / 100, 2, '.', ''),
                    'invoice_balance_minor' => $afterRevInvBalanceMinor,
                    'invoice_balance_formatted' => number_format($afterRevInvBalanceMinor / 100, 2, '.', ''),
                    'invoice_status' => $afterRevInvStatus,
                ],
                'preserves_staged_collection' => true,
                'preserves_sibling_allocations' => true,
                'zero_cashier_impact' => true,
            ],
            self::ACTION_RECONCILE_LINEAGE => [
                'action_label' => 'Reconcile Original Fee Obligation & Lineage (Zero Debt Impact)',
                'is_permitted' => $canReconcileLineage,
                'delta_minor' => 0,
                'delta_formatted' => '0.00',
                'before' => [
                    'receipt_status' => $currentStatusLabel,
                    'item_paid_minor' => $itemPaidMinor,
                    'item_paid_formatted' => number_format($itemPaidMinor / 100, 2, '.', ''),
                    'item_balance_minor' => $itemBalanceMinor,
                    'item_balance_formatted' => number_format($itemBalanceMinor / 100, 2, '.', ''),
                    'item_status' => $itemStatus,
                    'invoice_paid_minor' => $invPaidMinor,
                    'invoice_paid_formatted' => number_format($invPaidMinor / 100, 2, '.', ''),
                    'invoice_balance_minor' => $invBalanceMinor,
                    'invoice_balance_formatted' => number_format($invBalanceMinor / 100, 2, '.', ''),
                    'invoice_status' => $invStatus,
                ],
                'after' => [
                    'receipt_status' => 'Reconciled (Paid)',
                    'item_paid_minor' => $colAmountMinor,
                    'item_paid_formatted' => number_format($colAmountMinor / 100, 2, '.', ''),
                    'item_balance_minor' => 0,
                    'item_balance_formatted' => '0.00',
                    'item_status' => 'paid',
                    'invoice_paid_minor' => $invPaidMinor + $colAmountMinor,
                    'invoice_paid_formatted' => number_format(($invPaidMinor + $colAmountMinor) / 100, 2, '.', ''),
                    'invoice_balance_minor' => $invBalanceMinor,
                    'invoice_balance_formatted' => number_format($invBalanceMinor / 100, 2, '.', ''),
                    'invoice_status' => $invBalanceMinor > 0 ? 'partial' : 'paid',
                ],
                'preserves_staged_collection' => true,
                'preserves_sibling_allocations' => true,
                'zero_cashier_impact' => true,
            ],
            self::ACTION_REAPPLY_SETTLEMENT => [
                'action_label' => 'Reapply Reversed Historical Settlement',
                'is_permitted' => $isSafeToReapply,
                'delta_minor' => $reapplyDeltaMinor,
                'delta_formatted' => number_format($reapplyDeltaMinor / 100, 2, '.', ''),
                'before' => [
                    'receipt_status' => $currentStatusLabel,
                    'item_paid_minor' => $itemPaidMinor,
                    'item_paid_formatted' => number_format($itemPaidMinor / 100, 2, '.', ''),
                    'item_balance_minor' => $itemBalanceMinor,
                    'item_balance_formatted' => number_format($itemBalanceMinor / 100, 2, '.', ''),
                    'item_status' => $itemStatus,
                    'invoice_paid_minor' => $invPaidMinor,
                    'invoice_paid_formatted' => number_format($invPaidMinor / 100, 2, '.', ''),
                    'invoice_balance_minor' => $invBalanceMinor,
                    'invoice_balance_formatted' => number_format($invBalanceMinor / 100, 2, '.', ''),
                    'invoice_status' => $invStatus,
                ],
                'after' => [
                    'receipt_status' => 'Reconciled (Reapplied)',
                    'item_paid_minor' => $afterReapplyItemPaidMinor,
                    'item_paid_formatted' => number_format($afterReapplyItemPaidMinor / 100, 2, '.', ''),
                    'item_balance_minor' => $afterReapplyItemBalanceMinor,
                    'item_balance_formatted' => number_format($afterReapplyItemBalanceMinor / 100, 2, '.', ''),
                    'item_status' => $afterReapplyItemStatus,
                    'invoice_paid_minor' => $afterReapplyInvPaidMinor,
                    'invoice_paid_formatted' => number_format($afterReapplyInvPaidMinor / 100, 2, '.', ''),
                    'invoice_balance_minor' => $afterReapplyInvBalanceMinor,
                    'invoice_balance_formatted' => number_format($afterReapplyInvBalanceMinor / 100, 2, '.', ''),
                    'invoice_status' => $afterReapplyInvStatus,
                ],
                'preserves_staged_collection' => true,
                'preserves_sibling_allocations' => true,
                'zero_cashier_impact' => true,
            ],
            self::ACTION_MARK_UNDER_REVIEW => [
                'action_label' => 'Place Under Finance Review (Audit Hold)',
                'is_permitted' => in_array(self::ACTION_MARK_UNDER_REVIEW, $availableActions, true),
                'delta_minor' => 0,
                'delta_formatted' => '0.00',
                'before' => [
                    'receipt_status' => $currentStatusLabel,
                    'item_paid_minor' => $itemPaidMinor,
                    'item_paid_formatted' => number_format($itemPaidMinor / 100, 2, '.', ''),
                    'item_balance_minor' => $itemBalanceMinor,
                    'item_balance_formatted' => number_format($itemBalanceMinor / 100, 2, '.', ''),
                    'item_status' => $itemStatus,
                    'invoice_paid_minor' => $invPaidMinor,
                    'invoice_paid_formatted' => number_format($invPaidMinor / 100, 2, '.', ''),
                    'invoice_balance_minor' => $invBalanceMinor,
                    'invoice_balance_formatted' => number_format($invBalanceMinor / 100, 2, '.', ''),
                    'invoice_status' => $invStatus,
                ],
                'after' => [
                    'receipt_status' => 'Under Review',
                    'item_paid_minor' => $itemPaidMinor,
                    'item_paid_formatted' => number_format($itemPaidMinor / 100, 2, '.', ''),
                    'item_balance_minor' => $itemBalanceMinor,
                    'item_balance_formatted' => number_format($itemBalanceMinor / 100, 2, '.', ''),
                    'item_status' => $itemStatus,
                    'invoice_paid_minor' => $invPaidMinor,
                    'invoice_paid_formatted' => number_format($invPaidMinor / 100, 2, '.', ''),
                    'invoice_balance_minor' => $invBalanceMinor,
                    'invoice_balance_formatted' => number_format($invBalanceMinor / 100, 2, '.', ''),
                    'invoice_status' => $invStatus,
                ],
                'preserves_staged_collection' => true,
                'preserves_sibling_allocations' => true,
                'zero_cashier_impact' => true,
            ],
            self::ACTION_MARK_DISPUTED => [
                'action_label' => 'Mark Receipt Disputed (Block Auto-Application)',
                'is_permitted' => in_array(self::ACTION_MARK_DISPUTED, $availableActions, true),
                'delta_minor' => 0,
                'delta_formatted' => '0.00',
                'before' => [
                    'receipt_status' => $currentStatusLabel,
                    'item_paid_minor' => $itemPaidMinor,
                    'item_paid_formatted' => number_format($itemPaidMinor / 100, 2, '.', ''),
                    'item_balance_minor' => $itemBalanceMinor,
                    'item_balance_formatted' => number_format($itemBalanceMinor / 100, 2, '.', ''),
                    'item_status' => $itemStatus,
                    'invoice_paid_minor' => $invPaidMinor,
                    'invoice_paid_formatted' => number_format($invPaidMinor / 100, 2, '.', ''),
                    'invoice_balance_minor' => $invBalanceMinor,
                    'invoice_balance_formatted' => number_format($invBalanceMinor / 100, 2, '.', ''),
                    'invoice_status' => $invStatus,
                ],
                'after' => [
                    'receipt_status' => 'Disputed',
                    'item_paid_minor' => $itemPaidMinor,
                    'item_paid_formatted' => number_format($itemPaidMinor / 100, 2, '.', ''),
                    'item_balance_minor' => $itemBalanceMinor,
                    'item_balance_formatted' => number_format($itemBalanceMinor / 100, 2, '.', ''),
                    'item_status' => $itemStatus,
                    'invoice_paid_minor' => $invPaidMinor,
                    'invoice_paid_formatted' => number_format($invPaidMinor / 100, 2, '.', ''),
                    'invoice_balance_minor' => $invBalanceMinor,
                    'invoice_balance_formatted' => number_format($invBalanceMinor / 100, 2, '.', ''),
                    'invoice_status' => $invStatus,
                ],
                'preserves_staged_collection' => true,
                'preserves_sibling_allocations' => true,
                'zero_cashier_impact' => true,
            ],
            self::ACTION_MARK_CORRECTED => [
                'action_label' => 'Mark Receipt Corrected (Audit Notation)',
                'is_permitted' => in_array(self::ACTION_MARK_CORRECTED, $availableActions, true),
                'delta_minor' => 0,
                'delta_formatted' => '0.00',
                'before' => [
                    'receipt_status' => $currentStatusLabel,
                    'item_paid_minor' => $itemPaidMinor,
                    'item_paid_formatted' => number_format($itemPaidMinor / 100, 2, '.', ''),
                    'item_balance_minor' => $itemBalanceMinor,
                    'item_balance_formatted' => number_format($itemBalanceMinor / 100, 2, '.', ''),
                    'item_status' => $itemStatus,
                    'invoice_paid_minor' => $invPaidMinor,
                    'invoice_paid_formatted' => number_format($invPaidMinor / 100, 2, '.', ''),
                    'invoice_balance_minor' => $invBalanceMinor,
                    'invoice_balance_formatted' => number_format($invBalanceMinor / 100, 2, '.', ''),
                    'invoice_status' => $invStatus,
                ],
                'after' => [
                    'receipt_status' => 'Corrected',
                    'item_paid_minor' => $itemPaidMinor,
                    'item_paid_formatted' => number_format($itemPaidMinor / 100, 2, '.', ''),
                    'item_balance_minor' => $itemBalanceMinor,
                    'item_balance_formatted' => number_format($itemBalanceMinor / 100, 2, '.', ''),
                    'item_status' => $itemStatus,
                    'invoice_paid_minor' => $invPaidMinor,
                    'invoice_paid_formatted' => number_format($invPaidMinor / 100, 2, '.', ''),
                    'invoice_balance_minor' => $invBalanceMinor,
                    'invoice_balance_formatted' => number_format($invBalanceMinor / 100, 2, '.', ''),
                    'invoice_status' => $invStatus,
                ],
                'preserves_staged_collection' => true,
                'preserves_sibling_allocations' => true,
                'zero_cashier_impact' => true,
            ],
            self::ACTION_PROPOSE_REASSIGNMENT => [
                'action_label' => 'Propose Reassignment (Student / Period / Fee Type)',
                'is_permitted' => in_array(self::ACTION_PROPOSE_REASSIGNMENT, $availableActions, true),
                'delta_minor' => 0,
                'delta_formatted' => '0.00',
                'before' => [
                    'receipt_status' => $currentStatusLabel,
                    'item_paid_minor' => $itemPaidMinor,
                    'item_paid_formatted' => number_format($itemPaidMinor / 100, 2, '.', ''),
                    'item_balance_minor' => $itemBalanceMinor,
                    'item_balance_formatted' => number_format($itemBalanceMinor / 100, 2, '.', ''),
                    'item_status' => $itemStatus,
                    'invoice_paid_minor' => $invPaidMinor,
                    'invoice_paid_formatted' => number_format($invPaidMinor / 100, 2, '.', ''),
                    'invoice_balance_minor' => $invBalanceMinor,
                    'invoice_balance_formatted' => number_format($invBalanceMinor / 100, 2, '.', ''),
                    'invoice_status' => $invStatus,
                ],
                'after' => [
                    'receipt_status' => 'Corrected',
                    'item_paid_minor' => $itemPaidMinor,
                    'item_paid_formatted' => number_format($itemPaidMinor / 100, 2, '.', ''),
                    'item_balance_minor' => $itemBalanceMinor,
                    'item_balance_formatted' => number_format($itemBalanceMinor / 100, 2, '.', ''),
                    'item_status' => $itemStatus,
                    'invoice_paid_minor' => $invPaidMinor,
                    'invoice_paid_formatted' => number_format($invPaidMinor / 100, 2, '.', ''),
                    'invoice_balance_minor' => $invBalanceMinor,
                    'invoice_balance_formatted' => number_format($invBalanceMinor / 100, 2, '.', ''),
                    'invoice_status' => $invStatus,
                ],
                'preserves_staged_collection' => true,
                'preserves_sibling_allocations' => true,
                'zero_cashier_impact' => true,
            ],
            self::ACTION_PROPOSE_PRE_CUTOFF_COMPENSATION => [
                'action_label' => 'Log Pre-Cutoff Snapshot Compensation Review',
                'is_permitted' => in_array(self::ACTION_PROPOSE_PRE_CUTOFF_COMPENSATION, $availableActions, true),
                'delta_minor' => 0,
                'delta_formatted' => '0.00',
                'before' => [
                    'receipt_status' => $currentStatusLabel,
                    'item_paid_minor' => $itemPaidMinor,
                    'item_paid_formatted' => number_format($itemPaidMinor / 100, 2, '.', ''),
                    'item_balance_minor' => $itemBalanceMinor,
                    'item_balance_formatted' => number_format($itemBalanceMinor / 100, 2, '.', ''),
                    'item_status' => $itemStatus,
                    'invoice_paid_minor' => $invPaidMinor,
                    'invoice_paid_formatted' => number_format($invPaidMinor / 100, 2, '.', ''),
                    'invoice_balance_minor' => $invBalanceMinor,
                    'invoice_balance_formatted' => number_format($invBalanceMinor / 100, 2, '.', ''),
                    'invoice_status' => $invStatus,
                ],
                'after' => [
                    'receipt_status' => 'Under Review',
                    'item_paid_minor' => $itemPaidMinor,
                    'item_paid_formatted' => number_format($itemPaidMinor / 100, 2, '.', ''),
                    'item_balance_minor' => $itemBalanceMinor,
                    'item_balance_formatted' => number_format($itemBalanceMinor / 100, 2, '.', ''),
                    'item_status' => $itemStatus,
                    'invoice_paid_minor' => $invPaidMinor,
                    'invoice_paid_formatted' => number_format($invPaidMinor / 100, 2, '.', ''),
                    'invoice_balance_minor' => $invBalanceMinor,
                    'invoice_balance_formatted' => number_format($invBalanceMinor / 100, 2, '.', ''),
                    'invoice_status' => $invStatus,
                ],
                'preserves_staged_collection' => true,
                'preserves_sibling_allocations' => true,
                'zero_cashier_impact' => true,
            ],
        ];

        $alreadyUnpaidInvoiceExists = !$isCreditedInLedger
            && $previouslyReversedMinor === 0
            && $item !== null
            && (int) $item->net_minor > 0
            && (int) $item->paid_minor === 0;

        $canAutoReconcileOnRestore = !$isCreditedInLedger
            && $canReconcileLineage
            && !$alreadyUnpaidInvoiceExists
            && !$hasInterveningPortalPayments
            && $previouslyReversedMinor === 0;

        $restorableAmountMinor = $isCreditedInLedger
            ? $remainingReversibleMinor
            : ($canAutoReconcileOnRestore ? $colAmountMinor : 0);

        $requiresFinanceReview = $hasCurrencyConflict
            || ($isReconciled && $hasInterveningPortalPayments)
            || (!$isCreditedInLedger && !$canAutoReconcileOnRestore && !$alreadyUnpaidInvoiceExists && $previouslyReversedMinor === 0);

        $cleanFeeTypeLabel = str_ends_with(strtolower($feeTypeLabel), 'fee')
            ? $feeTypeLabel
            : ($feeTypeLabel . ' Fee');
        $cleanBillingMonth = $periodStart
            ? date('F Y', strtotime($periodStart))
            : ($periodLabel ?: 'N/A');

        return [
            'exists' => true,
            // 1. Original MySkool slip number
            'slip_number' => $slipNumber !== '' ? $slipNumber : 'N/A',
            // 2. Imported source collection row ID
            'qa_staged_collection_id' => $stagedCollectionId,
            'source_row' => $col->source_row !== null ? (int) $col->source_row : null,
            'batch_id' => (string) ($col->batch_id ?? ''),
            // 3. Student name and GR
            'student_id' => $studentId,
            'student_name' => $studentName,
            'student_gr' => $studentGr,
            // 4. Family code
            'family_id' => $familyId,
            'family_code' => $familyCode,
            'guardian_name' => $guardianName,
            // 5. Fee type and billing period
            'fee_particular' => $feeParticular,
            'fee_type' => $cleanFeeTypeLabel,
            'fee_type_code' => $feeTypeCode,
            'fee_type_label' => $feeTypeLabel,
            'billing_month' => $cleanBillingMonth,
            'period_key' => $periodKey ?: 'N/A',
            'period_start' => $periodStart,
            'period_label' => $periodLabel,
            // 6. Currency
            'currency' => $colCurrency,
            'student_expected_currency' => $studentExpectedCurrency ?: $colCurrency,
            'has_currency_conflict' => $hasCurrencyConflict,
            // 7. Original receipt amount & Phase 3 partial/full restoration breakdown
            'amount_minor' => $colAmountMinor,
            'amount_formatted' => number_format($colAmountMinor / 100, 2, '.', ''),
            'original_paid_minor' => $colAmountMinor,
            'original_paid_formatted' => number_format($colAmountMinor / 100, 2, '.', ''),
            'previously_reversed_minor' => $previouslyReversedMinor,
            'previously_reversed_formatted' => number_format($previouslyReversedMinor / 100, 2, '.', ''),
            'remaining_reversible_minor' => $remainingReversibleMinor,
            'remaining_reversible_formatted' => number_format($remainingReversibleMinor / 100, 2, '.', ''),
            'restorable_amount_minor' => $restorableAmountMinor,
            'restorable_amount_formatted' => number_format($restorableAmountMinor / 100, 2, '.', ''),
            'effective_paid_minor' => $effectivePaidMinor,
            'effective_paid_formatted' => number_format($effectivePaidMinor / 100, 2, '.', ''),
            // 8. Historical payment date
            'payment_date' => $paymentDate,
            'payment_date_formatted' => !empty($col->payment_date_parsed)
                ? date('d M Y', strtotime((string) $col->payment_date_parsed))
                : $paymentDate,
            'cutoff_date' => $cutoffDate,
            'is_pre_cutoff' => $isPreCutoff,
            // 9. Whether the receipt is already reconciled and actively credited in the ledger
            'is_reconciled' => $isReconciled,
            'is_credited_in_ledger' => $isCreditedInLedger,
            'requires_reconciliation' => $requiresReconciliation,
            'can_reconcile_lineage' => $canReconcileLineage,
            'can_auto_reconcile_on_restore' => $canAutoReconcileOnRestore,
            'already_unpaid_invoice_exists' => $alreadyUnpaidInvoiceExists,
            'requires_finance_review' => $requiresFinanceReview,
            'finance_review_message' => 'Finance review required. This payment needs verification before restoration.',
            'allocation_status' => $allocationStatus,
            'allocation_status_label' => $allocationStatusLabel,
            'reconciliation_id' => $activeSettlement
                ? (string) $activeSettlement->reconciliation_id
                : ($reversedSettlement ? (string) $reversedSettlement->reconciliation_id : null),
            'settlement_status' => $activeSettlement
                ? 'applied'
                : ($reversedSettlement ? 'reversed' : 'unreconciled'),
            'active_settlement_col_count' => $activeSettlementColCount,
            // 10. Invoice item and invoice number where available
            'invoice_item_id' => $item ? (int) $item->id : null,
            'invoice_id' => $invoice ? (int) $invoice->id : null,
            'invoice_number' => $invoice ? (string) $invoice->invoice_number : null,
            'item_description' => $item ? (string) $item->description : null,
            'item_status' => $itemStatus,
            'invoice_status' => $invStatus,
            // 11. Current outstanding balance
            'item_net_minor' => $itemNetMinor,
            'item_net_formatted' => number_format($itemNetMinor / 100, 2, '.', ''),
            'item_paid_minor' => $itemPaidMinor,
            'item_paid_formatted' => number_format($itemPaidMinor / 100, 2, '.', ''),
            'item_balance_minor' => $itemBalanceMinor,
            'item_balance_formatted' => number_format($itemBalanceMinor / 100, 2, '.', ''),
            'invoice_total_minor' => $invTotalMinor,
            'invoice_total_formatted' => number_format($invTotalMinor / 100, 2, '.', ''),
            'invoice_paid_minor' => $invPaidMinor,
            'invoice_paid_formatted' => number_format($invPaidMinor / 100, 2, '.', ''),
            'invoice_balance_minor' => $invBalanceMinor,
            'invoice_balance_formatted' => number_format($invBalanceMinor / 100, 2, '.', ''),
            'student_total_outstanding_minor' => $studentTotalOutstandingMinor,
            'student_total_outstanding_formatted' => number_format($studentTotalOutstandingMinor / 100, 2, '.', ''),
            'family_total_outstanding_minor' => $familyTotalOutstandingMinor,
            'family_total_outstanding_formatted' => number_format($familyTotalOutstandingMinor / 100, 2, '.', ''),
            // 12. Existing adjustments and corrections
            'current_receipt_status' => $currentReceiptStatus,
            'current_status_label' => $currentStatusLabel,
            'fee_adjustments' => $feeAdjustments,
            'settlement_history' => $settlementHistory,
            'correction_history' => $correctionHistory,
            'latest_correction' => $latestCorrection,
            'has_intervening_portal_payments' => $hasInterveningPortalPayments,
            'intervening_portal_amount_minor' => $interveningPortalAmountMinor,
            'family_portal_receipts_count' => $portalReceiptsCount,
            // Shared slip details (Case C)
            'is_shared_slip' => $isSharedSlip,
            'shared_slip_allocations' => $sharedSlipAllocations,
            // 13. Effect of the proposed correction
            'proposed_effects' => $proposedEffects,
            // 14. Whether reversal is safe
            'case_classification' => $caseClassification,
            'is_safe_to_reverse' => $isSafeToReverse,
            'is_safe_to_reapply' => $isSafeToReapply,
            'safety_status' => $safetyStatus,
            'safety_explanation' => $safetyExplanation,
            'blocked_reasons' => $blockedReasons,
            'available_actions' => $availableActions,
        ];
    }

    /**
     * Phase 3: Restore full or partial paid amount of a single MySkool receipt allocation to outstanding dues.
     */
    public function restorePayment(
        int $stagedCollectionId,
        array $input,
        ?int $userId = null,
        ?string $conn = null
    ): array {
        $input['action_type'] = self::ACTION_RESTORE_PAYMENT;
        return $this->executeCorrection($stagedCollectionId, $input, $userId, $conn);
    }

    /**
     * Phase 3: Reconcile the original fee obligation and source lineage for an unreconciled historical collection
     * without creating artificial debt (net_minor = paid_minor = verified historical payment amount).
     */
    public function reconcileHistoricalAllocation(
        int $stagedCollectionId,
        array $input,
        ?int $userId = null,
        ?string $conn = null
    ): array {
        $input['action_type'] = self::ACTION_RECONCILE_LINEAGE;
        if (empty($input['reason'])) {
            $input['reason'] = 'Reconciling original MySkool fee obligation and payment lineage from verified historical source.';
        }
        return $this->executeCorrection($stagedCollectionId, $input, $userId, $conn);
    }

    /**
     * Execute an authorized, audited correction or reversal on a MySkool staged collection row.
     * Enforces all 15 financial safety rules from Task 4 + Phase 3 partial/full restoration rules.
     */
    public function executeCorrection(
        int $stagedCollectionId,
        array $input,
        ?int $userId = null,
        ?string $conn = null
    ): array {
        $conn = $conn ?: config('database.default', 'mysql');
        $db = DB::connection($conn);
        $schema = $db->getSchemaBuilder();

        // Rule 3: Require valid authenticated finance administrator or super administrator
        try {
            $actorId = app(HistoricalPaymentReconciliationService::class)->resolveAuthorizedActor($db, $userId);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'authorization' => [$e->getMessage()],
            ]);
        }

        // Rule 4: Require a mandatory reason
        $reason = trim((string) ($input['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => ['A mandatory audit reason (at least 5 characters) is required for MySkool payment corrections.'],
            ]);
        }

        $actionType = trim((string) ($input['action_type'] ?? self::ACTION_REVERSE_SETTLEMENT));
        $validActions = [
            self::ACTION_REVERSE_SETTLEMENT,
            self::ACTION_RESTORE_PAYMENT,
            self::ACTION_RECONCILE_LINEAGE,
            self::ACTION_REAPPLY_SETTLEMENT,
            self::ACTION_MARK_UNDER_REVIEW,
            self::ACTION_MARK_DISPUTED,
            self::ACTION_MARK_CORRECTED,
            self::ACTION_PROPOSE_REASSIGNMENT,
            self::ACTION_PROPOSE_PRE_CUTOFF_COMPENSATION,
        ];

        if (!in_array($actionType, $validActions, true)) {
            throw ValidationException::withMessages([
                'action_type' => ["Unsupported MySkool correction action '{$actionType}'."],
            ]);
        }

        // Rule 9: Block any attempt to manually reassign or override currency
        if (!empty($input['proposed_currency'])) {
            throw ValidationException::withMessages([
                'currency' => ['Unsafe manual currency reassignment is strictly prohibited. Currency isolation must be preserved.'],
            ]);
        }

        if (!$schema->hasTable('qa_myskool_payment_corrections')) {
            throw new RuntimeException(
                "CRITICAL SAFETY GUARD: Table 'qa_myskool_payment_corrections' has not been migrated on connection '{$conn}'. " .
                "Owner approval is required before migrating and executing corrections on the live database."
            );
        }

        // Rule 8: Application-level concurrency lock per staged collection row
        $colLock = new BatchDispatchLock();
        if (!$colLock->acquire("hist_col_alloc_{$stagedCollectionId}")) {
            throw ValidationException::withMessages([
                'concurrency' => ["Concurrent correction request detected for MySkool collection row #{$stagedCollectionId}. Please wait and retry."],
            ]);
        }

        try {
            return $db->transaction(function () use ($db, $schema, $conn, $stagedCollectionId, $actionType, $reason, $input, $actorId) {
                // Idempotency protection check inside locked transaction
                $idempotencyKey = isset($input['idempotency_key']) && trim((string) $input['idempotency_key']) !== ''
                    ? trim((string) $input['idempotency_key'])
                    : null;
                if ($idempotencyKey !== null && $schema->hasColumn('qa_myskool_payment_corrections', 'idempotency_key')) {
                    $existingIdem = $db->table('qa_myskool_payment_corrections')
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($existingIdem) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => ['Duplicate restoration request blocked by idempotency protection.'],
                        ]);
                    }
                }

                // Re-inspect under row-level FOR UPDATE locks
                $inspection = $this->inspect($stagedCollectionId, $conn, true);
                if (empty($inspection['exists'])) {
                    throw ValidationException::withMessages([
                        'qa_staged_collection_id' => [$inspection['error'] ?? "MySkool collection row #{$stagedCollectionId} not found."],
                    ]);
                }

                // Determine next immutable sequence number for this staged collection row
                $lastCorr = $db->table('qa_myskool_payment_corrections')
                    ->where('qa_staged_collection_id', $stagedCollectionId)
                    ->lockForUpdate()
                    ->orderByDesc('sequence_no')
                    ->orderByDesc('id')
                    ->first();

                $nextSeq = $lastCorr ? ((int) $lastCorr->sequence_no + 1) : 1;
                $prevCorrectionId = $lastCorr ? (string) $lastCorr->correction_id : null;

                // Validate action-specific financial safety rules
                if ($actionType === self::ACTION_RESTORE_PAYMENT) {
                    return $this->performRestorePayment(
                        $db,
                        $schema,
                        $conn,
                        $inspection,
                        $input,
                        $nextSeq,
                        $prevCorrectionId,
                        $reason,
                        $actorId,
                        $idempotencyKey
                    );
                }

                if ($actionType === self::ACTION_RECONCILE_LINEAGE) {
                    return $this->performReconcileLineage(
                        $db,
                        $schema,
                        $inspection,
                        $nextSeq,
                        $prevCorrectionId,
                        $reason,
                        $actorId,
                        $idempotencyKey
                    );
                }

                if ($actionType === self::ACTION_REVERSE_SETTLEMENT) {
                    if (!$inspection['is_safe_to_reverse']) {
                        $blockMsg = !empty($inspection['blocked_reasons'])
                            ? implode(' ', $inspection['blocked_reasons'])
                            : ($inspection['safety_explanation'] ?: 'Reversal is blocked by financial safety rules.');
                        throw ValidationException::withMessages([
                            'action_type' => [$blockMsg],
                        ]);
                    }
                    return $this->performReverseSettlement(
                        $db,
                        $schema,
                        $conn,
                        $inspection,
                        $nextSeq,
                        $prevCorrectionId,
                        $reason,
                        $actorId,
                        $idempotencyKey
                    );
                }

                if ($actionType === self::ACTION_REAPPLY_SETTLEMENT) {
                    if (!$inspection['is_safe_to_reapply']) {
                        $blockMsg = !empty($inspection['blocked_reasons'])
                            ? implode(' ', $inspection['blocked_reasons'])
                            : ($inspection['safety_explanation'] ?: 'Reapplication is blocked by financial safety rules.');
                        throw ValidationException::withMessages([
                            'action_type' => [$blockMsg],
                        ]);
                    }
                    return $this->performReapplySettlement(
                        $db,
                        $schema,
                        $inspection,
                        $nextSeq,
                        $prevCorrectionId,
                        $reason,
                        $actorId
                    );
                }

                // Non-settlement-mutating or review/dispute/reassignment actions (Cases B & D)
                if (!in_array($actionType, $inspection['available_actions'], true)) {
                    $blockMsg = !empty($inspection['blocked_reasons'])
                        ? implode(' ', $inspection['blocked_reasons'])
                        : "Action '{$actionType}' is not permitted in the current state ({$inspection['current_status_label']}).";
                    throw ValidationException::withMessages([
                        'action_type' => [$blockMsg],
                    ]);
                }

                return $this->performStatusOrProposalAction(
                    $db,
                    $schema,
                    $inspection,
                    $actionType,
                    $input,
                    $nextSeq,
                    $prevCorrectionId,
                    $reason,
                    $actorId
                );
            });
        } catch (\Illuminate\Database\QueryException $qe) {
            $msg = $qe->getMessage();
            if (str_contains($msg, 'Duplicate entry') || str_contains($msg, 'UNIQUE constraint failed')) {
                throw ValidationException::withMessages([
                    'concurrency' => ['Concurrent or duplicate correction request blocked by database uniqueness constraint.'],
                ]);
            }
            throw $qe;
        } finally {
            $colLock->release();
        }
    }

    /**
     * Phase 3 (Tasks 2, 3, 4 & 5): Restore full or partial amount of a verified MySkool payment allocation
     * back to the student's outstanding obligation in its original currency.
     */
    protected function performRestorePayment(
        $db,
        $schema,
        string $conn,
        array $inspection,
        array $input,
        int $nextSeq,
        ?string $prevCorrectionId,
        string $reason,
        int $actorId,
        ?string $idempotencyKey = null
    ): array {
        $stagedCollectionId = (int) $inspection['qa_staged_collection_id'];
        $colCurrency = (string) $inspection['currency'];
        $origPaidMinor = (int) $inspection['original_paid_minor'];
        $prevReversedMinor = (int) $inspection['previously_reversed_minor'];
        $remReversibleMinor = (int) $inspection['remaining_reversible_minor'];

        // 1. Check currency conflict first
        if (!empty($inspection['has_currency_conflict'])) {
            throw ValidationException::withMessages([
                'currency' => [
                    "Finance review required. This payment needs verification before restoration. Cross-currency conflict detected between receipt currency ({$colCurrency}) and student obligation currency ({$inspection['student_expected_currency']}). Restoration is blocked to prevent cross-currency mixing."
                ],
            ]);
        }

        // 2. Check if already fully reversed
        if ($prevReversedMinor >= $origPaidMinor && $origPaidMinor > 0) {
            throw ValidationException::withMessages([
                'restore_amount' => [
                    "This allocation (Row #{$stagedCollectionId}) has already been fully reversed ({$colCurrency} {$inspection['original_paid_formatted']}). Double reversal is blocked."
                ],
            ]);
        }

        // 2b. For already unpaid fees, Restore must not create another outstanding charge
        if (!empty($inspection['already_unpaid_invoice_exists'])) {
            $invNum = $inspection['invoice_number'] ?: ('#' . $inspection['invoice_id']);
            throw ValidationException::withMessages([
                'restore_amount' => [
                    "An outstanding invoice ({$invNum}) already exists for this fee ({$colCurrency} {$inspection['item_balance_formatted']} unpaid). Restore must not create another outstanding charge."
                ],
            ]);
        }

        // 3. Internally reconcile historical links when verifiably safe and requested by the Restore workflow
        if (
            empty($inspection['is_credited_in_ledger'])
            && !empty($input['auto_reconcile'])
            && !empty($inspection['can_reconcile_lineage'])
            && empty($inspection['already_unpaid_invoice_exists'])
        ) {
            $reconRes = $this->performReconcileLineage(
                $db,
                $schema,
                $inspection,
                $nextSeq,
                $prevCorrectionId,
                'Internal safe lineage reconciliation prior to payment restoration: ' . $reason,
                $actorId,
                null
            );
            $nextSeq++;
            $prevCorrectionId = (string) $reconRes['correction_id'];
            $inspection = $this->inspect($stagedCollectionId, $conn, true);
            $origPaidMinor = (int) $inspection['original_paid_minor'];
            $prevReversedMinor = (int) $inspection['previously_reversed_minor'];
            $remReversibleMinor = (int) $inspection['remaining_reversible_minor'];
        }

        // 3b. Check if still unreconciled / not safe to restore
        if (empty($inspection['is_credited_in_ledger']) || empty($inspection['is_safe_to_reverse'])) {
            $blockMsg = !empty($inspection['requires_reconciliation'])
                ? "Requires Reconciliation: Historical payment row #{$stagedCollectionId} has not been credited in the authoritative ledger. Cannot execute a balance-changing restoration or create artificial debt before reconciling its original fee obligation."
                : (!empty($inspection['blocked_reasons']) ? implode(' ', $inspection['blocked_reasons']) : 'Finance review required. This payment needs verification before restoration.');
            throw ValidationException::withMessages([
                'reconciliation' => [$blockMsg],
            ]);
        }

        // 4. Parse and validate restoration mode and amount
        $mode = strtolower(trim((string) ($input['restoration_mode'] ?? 'full')));
        if (!in_array($mode, ['full', 'partial'], true)) {
            throw ValidationException::withMessages([
                'restoration_mode' => ["Invalid restoration mode '{$mode}'. Must be 'full' or 'partial'."],
            ]);
        }

        $requestedMinor = null;
        if (isset($input['restore_amount_minor']) && $input['restore_amount_minor'] !== '' && $input['restore_amount_minor'] !== null) {
            if (!is_numeric($input['restore_amount_minor'])) {
                throw ValidationException::withMessages([
                    'restore_amount' => ['Restoration amount must be a valid positive number.'],
                ]);
            }
            $requestedMinor = (int) round((float) $input['restore_amount_minor']);
        } elseif (isset($input['restore_amount']) && $input['restore_amount'] !== '' && $input['restore_amount'] !== null) {
            if (!is_numeric($input['restore_amount'])) {
                throw ValidationException::withMessages([
                    'restore_amount' => ['Restoration amount must be a valid positive number.'],
                ]);
            }
            $requestedMinor = (int) round(((float) $input['restore_amount']) * 100);
        }

        if ($mode === 'full') {
            if ($requestedMinor !== null && $requestedMinor !== $remReversibleMinor) {
                throw ValidationException::withMessages([
                    'restore_amount' => [
                        "Full restoration amount must equal the remaining reversible amount ({$colCurrency} " . number_format($remReversibleMinor / 100, 2) . ")."
                    ],
                ]);
            }
            $restoreMinor = $remReversibleMinor;
        } else {
            if ($requestedMinor === null) {
                throw ValidationException::withMessages([
                    'restore_amount' => ['Please enter a valid partial restoration amount greater than zero.'],
                ]);
            }
            $restoreMinor = $requestedMinor;
        }

        if ($restoreMinor <= 0) {
            throw ValidationException::withMessages([
                'restore_amount' => ['Restoration amount must be greater than zero.'],
            ]);
        }

        if ($restoreMinor > $remReversibleMinor) {
            throw ValidationException::withMessages([
                'restore_amount' => [
                    "Restoration amount ({$colCurrency} " . number_format($restoreMinor / 100, 2) . ") cannot exceed the remaining reversible allocation ({$colCurrency} " . number_format($remReversibleMinor / 100, 2) . ")."
                ],
            ]);
        }

        $reconId = (string) $inspection['reconciliation_id'];
        $itemId = (int) $inspection['invoice_item_id'];
        $invoiceId = (int) $inspection['invoice_id'];

        $settlement = $db->table('qa_historical_settlements')
            ->where('reconciliation_id', $reconId)
            ->where('status', 'applied')
            ->lockForUpdate()
            ->first();

        if (!$settlement) {
            throw ValidationException::withMessages([
                'reconciliation_id' => ["Active applied settlement '{$reconId}' not found or already reversed."],
            ]);
        }

        $item = $db->table('invoice_items')->where('id', $itemId)->lockForUpdate()->first();
        $invoice = $db->table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();

        if (!$item || !$invoice) {
            throw ValidationException::withMessages([
                'invoice_item_id' => ["Target invoice item #{$itemId} or invoice #{$invoiceId} not found."],
            ]);
        }

        // Verify strict currency match on item and invoice
        if (strtoupper(trim((string) $item->currency)) !== $colCurrency || strtoupper(trim((string) $invoice->currency)) !== $colCurrency) {
            throw ValidationException::withMessages([
                'currency' => ['Cross-currency mismatch between MySkool allocation and target invoice obligation.'],
            ]);
        }

        $beforeState = [
            'receipt_status' => $inspection['allocation_status_label'],
            'allocation_effective_paid_minor' => (int) $inspection['effective_paid_minor'],
            'allocation_effective_paid_formatted' => $inspection['effective_paid_formatted'],
            'previously_reversed_minor' => $prevReversedMinor,
            'previously_reversed_formatted' => $inspection['previously_reversed_formatted'],
            'remaining_reversible_minor' => $remReversibleMinor,
            'remaining_reversible_formatted' => $inspection['remaining_reversible_formatted'],
            'item_paid_minor' => (int) $item->paid_minor,
            'item_paid_formatted' => number_format(((int) $item->paid_minor) / 100, 2, '.', ''),
            'item_balance_minor' => max(0, (int) $item->net_minor - (int) $item->paid_minor),
            'item_balance_formatted' => number_format(max(0, (int) $item->net_minor - (int) $item->paid_minor) / 100, 2, '.', ''),
            'item_status' => (string) $item->status,
            'invoice_paid_minor' => (int) $invoice->paid_minor,
            'invoice_paid_formatted' => number_format(((int) $invoice->paid_minor) / 100, 2, '.', ''),
            'invoice_balance_minor' => max(0, (int) $invoice->total_minor - (int) $invoice->paid_minor),
            'invoice_balance_formatted' => number_format(max(0, (int) $invoice->total_minor - (int) $invoice->paid_minor) / 100, 2, '.', ''),
            'invoice_status' => (string) $invoice->status,
        ];

        $newCumulativeReversedMinor = $prevReversedMinor + $restoreMinor;
        $newRemainingForCol = max(0, $origPaidMinor - $newCumulativeReversedMinor);
        $isNowFullyReversedForCol = ($newRemainingForCol === 0);
        $activeColCount = (int) ($inspection['active_settlement_col_count'] ?? 1);

        if ($isNowFullyReversedForCol && $activeColCount <= 1 && $restoreMinor === (int) $settlement->settled_amount_minor) {
            // Complete reversal of a single-collection settlement: delegate to HistoricalPaymentReconciliationService::reverse()
            app(HistoricalPaymentReconciliationService::class)->reverse($reconId, $actorId, $conn);
        } else {
            // Partial restoration or multi-collection restoration
            $newItemPaidMinor = max(0, (int) $item->paid_minor - $restoreMinor);
            $newItemStatus = ($newItemPaidMinor >= (int) $item->net_minor && (int) $item->net_minor > 0)
                ? 'paid'
                : ($newItemPaidMinor > 0 ? 'partial' : (string) ($settlement->previous_item_status ?: 'active'));

            $db->table('invoice_items')->where('id', $itemId)->update([
                'paid_minor' => $newItemPaidMinor,
                'status' => $newItemStatus,
                'updated_at' => now(),
            ]);

            $itemTotals = $db->table('invoice_items')
                ->where('invoice_id', $invoiceId)
                ->selectRaw("COALESCE(SUM(paid_minor), 0) as total_paid, COALESCE(SUM(CASE WHEN status != 'voided' THEN net_minor ELSE 0 END), 0) as total_net")
                ->first();

            $newInvPaidMinor = (int) $itemTotals->total_paid;
            $newInvNetMinor = (int) $itemTotals->total_net;
            $newInvStatus = ($newInvNetMinor > 0 && $newInvPaidMinor >= $newInvNetMinor)
                ? 'paid'
                : ($newInvPaidMinor > 0 ? 'partial' : (string) ($settlement->previous_invoice_status ?: 'unpaid'));

            $db->table('invoices')->where('id', $invoiceId)->update([
                'total_minor' => $newInvNetMinor,
                'paid_minor' => $newInvPaidMinor,
                'status' => $newInvStatus,
                'updated_at' => now(),
            ]);

            if ($isNowFullyReversedForCol && $schema->hasTable('qa_historical_settlement_collections')) {
                $db->table('qa_historical_settlement_collections')
                    ->where('settlement_id', $settlement->id)
                    ->where('qa_staged_collection_id', $stagedCollectionId)
                    ->update([
                        'status' => 'reversed',
                        'active_staged_collection_id' => null,
                        'reversed_by' => $actorId,
                        'reversed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $newSettledMinor = max(0, (int) $settlement->settled_amount_minor - $restoreMinor);
            $settlementNowFullyReversed = ($newSettledMinor === 0);

            $remainingActiveColId = $settlement->active_primary_collection_id;
            if ($settlementNowFullyReversed) {
                $remainingActiveColId = null;
            } elseif ($isNowFullyReversedForCol && $schema->hasTable('qa_historical_settlement_collections')) {
                $remainingActiveColId = $db->table('qa_historical_settlement_collections')
                    ->where('settlement_id', $settlement->id)
                    ->where('status', 'applied')
                    ->value('qa_staged_collection_id');
            }

            $db->table('qa_historical_settlements')
                ->where('id', $settlement->id)
                ->update([
                    'status' => $settlementNowFullyReversed ? 'reversed' : 'applied',
                    'active_invoice_item_id' => $settlementNowFullyReversed ? null : $itemId,
                    'active_primary_collection_id' => $remainingActiveColId ? (int) $remainingActiveColId : null,
                    'settled_amount_minor' => $newSettledMinor,
                    'remaining_obligation_minor' => max(0, (int) $item->net_minor - $newItemPaidMinor),
                    'new_item_paid_minor' => $newItemPaidMinor,
                    'new_item_status' => $newItemStatus,
                    'new_invoice_paid_minor' => $newInvPaidMinor,
                    'new_invoice_status' => $newInvStatus,
                    'reversed_by' => $settlementNowFullyReversed ? $actorId : $settlement->reversed_by,
                    'reversed_at' => $settlementNowFullyReversed ? now() : $settlement->reversed_at,
                    'updated_at' => now(),
                ]);

            if (!empty($settlement->affected_snapshots_json)) {
                $snapshots = json_decode((string) $settlement->affected_snapshots_json, true) ?: [];
                foreach ($snapshots as $snap) {
                    if (!empty($snap['invoice_id'])) {
                        $subInv = $db->table('invoices')->where('id', (int) $snap['invoice_id'])->lockForUpdate()->first();
                        if ($subInv) {
                            $db->table('invoices')
                                ->where('id', (int) $snap['invoice_id'])
                                ->update([
                                    'previous_dues_snapshot_minor' => (int) $subInv->previous_dues_snapshot_minor + $restoreMinor,
                                    'updated_at' => now(),
                                ]);
                        }
                    }
                }
            }
        }

        $updatedItem = $db->table('invoice_items')->where('id', $itemId)->first();
        $updatedInvoice = $db->table('invoices')->where('id', $invoiceId)->first();

        $resultingStatus = $isNowFullyReversedForCol ? self::STATUS_REVERSED : self::STATUS_PARTIALLY_REVERSED;
        $effectiveMode = $isNowFullyReversedForCol && $prevReversedMinor === 0 ? 'full' : $mode;

        $afterState = [
            'receipt_status' => $isNowFullyReversedForCol ? 'Fully Reversed' : 'Partially Reversed',
            'restoration_mode' => $effectiveMode,
            'restored_amount_minor' => $restoreMinor,
            'restored_amount_formatted' => number_format($restoreMinor / 100, 2, '.', ''),
            'reversed_amount_minor' => $restoreMinor,
            'reversed_amount_formatted' => number_format($restoreMinor / 100, 2, '.', ''),
            'cumulative_reversed_minor' => $newCumulativeReversedMinor,
            'cumulative_reversed_formatted' => number_format($newCumulativeReversedMinor / 100, 2, '.', ''),
            'allocation_effective_paid_minor' => $newRemainingForCol,
            'allocation_effective_paid_formatted' => number_format($newRemainingForCol / 100, 2, '.', ''),
            'item_paid_minor' => $updatedItem ? (int) $updatedItem->paid_minor : 0,
            'item_paid_formatted' => number_format(($updatedItem ? (int) $updatedItem->paid_minor : 0) / 100, 2, '.', ''),
            'item_balance_minor' => $updatedItem ? max(0, (int) $updatedItem->net_minor - (int) $updatedItem->paid_minor) : 0,
            'item_balance_formatted' => number_format(($updatedItem ? max(0, (int) $updatedItem->net_minor - (int) $updatedItem->paid_minor) : 0) / 100, 2, '.', ''),
            'item_status' => $updatedItem ? (string) $updatedItem->status : 'active',
            'invoice_paid_minor' => $updatedInvoice ? (int) $updatedInvoice->paid_minor : 0,
            'invoice_paid_formatted' => number_format(($updatedInvoice ? (int) $updatedInvoice->paid_minor : 0) / 100, 2, '.', ''),
            'invoice_balance_minor' => $updatedInvoice ? max(0, (int) $updatedInvoice->total_minor - (int) $updatedInvoice->paid_minor) : 0,
            'invoice_balance_formatted' => number_format(($updatedInvoice ? max(0, (int) $updatedInvoice->total_minor - (int) $updatedInvoice->paid_minor) : 0) / 100, 2, '.', ''),
            'invoice_status' => $updatedInvoice ? (string) $updatedInvoice->status : 'unpaid',
        ];

        $correctionId = $this->insertImmutableCorrectionRecord(
            $db,
            $schema,
            $inspection,
            $nextSeq,
            $prevCorrectionId,
            self::ACTION_RESTORE_PAYMENT,
            $resultingStatus,
            (int) $settlement->id,
            $beforeState,
            $afterState,
            $reason,
            $actorId,
            null,
            null,
            null,
            null,
            $effectiveMode,
            $restoreMinor,
            $newCumulativeReversedMinor,
            $newRemainingForCol,
            $idempotencyKey
        );

        return [
            'status' => 'success',
            'correction_id' => $correctionId,
            'qa_staged_collection_id' => $stagedCollectionId,
            'action_type' => self::ACTION_RESTORE_PAYMENT,
            'restoration_mode' => $effectiveMode,
            'resulting_status' => $resultingStatus,
            'allocation_status' => $isNowFullyReversedForCol ? 'fully_reversed' : 'partially_reversed',
            'allocation_status_label' => $isNowFullyReversedForCol ? 'Fully Reversed' : 'Partially Reversed',
            'currency' => $colCurrency,
            'original_paid_minor' => $origPaidMinor,
            'original_paid_formatted' => number_format($origPaidMinor / 100, 2, '.', ''),
            'restored_amount_minor' => $restoreMinor,
            'restored_amount_formatted' => number_format($restoreMinor / 100, 2, '.', ''),
            'reversed_amount_minor' => $restoreMinor,
            'reversed_amount_formatted' => number_format($restoreMinor / 100, 2, '.', ''),
            'cumulative_reversed_minor' => $newCumulativeReversedMinor,
            'cumulative_reversed_formatted' => number_format($newCumulativeReversedMinor / 100, 2, '.', ''),
            'remaining_paid_minor' => $newRemainingForCol,
            'remaining_paid_formatted' => number_format($newRemainingForCol / 100, 2, '.', ''),
            'remaining_reversible_minor' => $newRemainingForCol,
            'remaining_reversible_formatted' => number_format($newRemainingForCol / 100, 2, '.', ''),
            'reconciliation_id' => $reconId,
            'invoice_item_id' => $itemId,
            'invoice_id' => $invoiceId,
            'before_state' => $beforeState,
            'after_state' => $afterState,
        ];
    }

    /**
     * Phase 3 (Task 3): Reconcile the original fee obligation and source lineage for a verified MySkool collection
     * whose obligation was missing (e.g. netted prior to export) or unreconciled, without posting duplicate credits
     * or creating artificial unpaid debt.
     */
    protected function performReconcileLineage(
        $db,
        $schema,
        array $inspection,
        int $nextSeq,
        ?string $prevCorrectionId,
        string $reason,
        int $actorId,
        ?string $idempotencyKey = null
    ): array {
        $stagedCollectionId = (int) $inspection['qa_staged_collection_id'];
        $colCurrency = (string) $inspection['currency'];
        $colAmountMinor = (int) $inspection['amount_minor'];
        $studentId = $inspection['student_id'] ? (int) $inspection['student_id'] : null;
        $familyId = $inspection['family_id'] ? (int) $inspection['family_id'] : null;
        $studentGr = (string) $inspection['student_gr'];
        $periodKey = (string) $inspection['period_key'];
        $periodStart = $inspection['period_start'] ? (string) $inspection['period_start'] : null;

        if (!empty($inspection['has_currency_conflict'])) {
            throw ValidationException::withMessages([
                'currency' => ['Cannot reconcile lineage: cross-currency conflict detected on this allocation.'],
            ]);
        }

        if (!empty($inspection['is_credited_in_ledger']) || empty($inspection['can_reconcile_lineage'])) {
            throw ValidationException::withMessages([
                'reconciliation' => ['This allocation is either already reconciled in the ledger or not eligible for lineage reconciliation.'],
            ]);
        }

        if (!$studentId || !$familyId || !$periodStart || $periodKey === 'N/A') {
            throw ValidationException::withMessages([
                'reconciliation' => ['Cannot reconcile lineage: student, family, or billing period could not be resolved.'],
            ]);
        }

        if ($schema->hasTable('qa_historical_settlement_collections')) {
            $alreadyConsumed = $db->table('qa_historical_settlement_collections')
                ->where('active_staged_collection_id', $stagedCollectionId)
                ->exists();
            if ($alreadyConsumed) {
                throw ValidationException::withMessages([
                    'reconciliation' => ["Collection row #{$stagedCollectionId} is already consumed by an active settlement."],
                ]);
            }
        }

        $enrollment = $db->table('enrollments')
            ->where('student_id', $studentId)
            ->orderByDesc('id')
            ->first();
        if (!$enrollment) {
            throw ValidationException::withMessages([
                'reconciliation' => ["No enrollment record found for student GR {$studentGr}."],
            ]);
        }

        $enrollmentId = (int) $enrollment->id;
        $campusId = (int) $enrollment->campus_id;
        $deptId = (int) $enrollment->department_id;
        $feeTypeId = (int) ($db->table('fee_types')->where('code', $inspection['fee_type_code'] ?: 'monthly')->value('id')
            ?: $db->table('fee_types')->value('id'));
        $periodEnd = date('Y-m-t', strtotime($periodStart));

        // Check if an exact matching invoice_items row in $colCurrency already exists for this student, fee type & period
        $existingItem = $db->table('invoice_items as ii')
            ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
            ->where('ii.student_id', $studentId)
            ->where('ii.fee_type_id', $feeTypeId)
            ->where('ii.currency', $colCurrency)
            ->where('ii.status', '!=', 'voided')
            ->whereNotIn('inv.status', ['void', 'voided'])
            ->where(function ($q) use ($periodKey, $periodStart) {
                $q->where('ii.period_key', $periodKey)
                    ->orWhere('ii.period_start', $periodStart);
            })
            ->select('ii.*')
            ->lockForUpdate()
            ->first();

        $beforeState = [
            'receipt_status' => $inspection['allocation_status_label'],
            'item_paid_minor' => $existingItem ? (int) $existingItem->paid_minor : 0,
            'item_balance_minor' => $existingItem ? max(0, (int) $existingItem->net_minor - (int) $existingItem->paid_minor) : 0,
        ];

        $now = now();
        if ($existingItem) {
            $itemId = (int) $existingItem->id;
            $invoiceId = (int) $existingItem->invoice_id;
            $invoice = $db->table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            $prevItemPaid = (int) $existingItem->paid_minor;
            $prevItemStatus = (string) $existingItem->status;
            $prevInvPaid = (int) $invoice->paid_minor;
            $prevInvStatus = (string) $invoice->status;

            $newItemNet = max((int) $existingItem->net_minor, $prevItemPaid + $colAmountMinor);
            $newItemPaid = $prevItemPaid + $colAmountMinor;
            $newItemStatus = ($newItemPaid >= $newItemNet && $newItemNet > 0) ? 'paid' : 'partial';

            $db->table('invoice_items')->where('id', $itemId)->update([
                'amount_minor' => max((int) $existingItem->amount_minor, $newItemNet),
                'net_minor' => $newItemNet,
                'paid_minor' => $newItemPaid,
                'status' => $newItemStatus,
                'updated_at' => $now,
            ]);
        } else {
            // Reconcile missing historical obligation onto the family's Opening Balance invoice in $colCurrency
            $obRun = $db->table('billing_runs')
                ->where('frequency', 'opening_balance')
                ->where(function ($q) use ($periodStart, $periodKey) {
                    $q->where('period_start', $periodStart)
                        ->orWhere('period_key', $periodKey);
                })
                ->orderBy('id')
                ->first();

            if (!$obRun) {
                $existingFamObInv = $db->table('invoices as inv')
                    ->join('billing_runs as br', 'br.id', '=', 'inv.billing_run_id')
                    ->where('inv.family_id', $familyId)
                    ->where('inv.currency', $colCurrency)
                    ->where('br.frequency', 'opening_balance')
                    ->whereNotIn('inv.status', ['void', 'voided'])
                    ->orderBy('inv.id')
                    ->select('br.*')
                    ->first();
                $obRun = $existingFamObInv ?: $db->table('billing_runs')
                    ->where('frequency', 'opening_balance')
                    ->orderBy('id')
                    ->first();
            }

            $obRunId = $obRun ? (int) $obRun->id : (int) $db->table('billing_runs')->insertGetId([
                'campus_id' => $campusId,
                'department_id' => $deptId,
                'frequency' => 'opening_balance',
                'period_key' => $periodKey ?: ('monthly:' . $periodStart . '..' . $periodEnd),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'due_date' => date('Y-m-25', strtotime($periodStart)),
                'status' => 'generated',
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $invoice = $db->table('invoices')
                ->where('family_id', $familyId)
                ->where('currency', $colCurrency)
                ->where('billing_run_id', $obRunId)
                ->whereNotIn('status', ['void', 'voided'])
                ->lockForUpdate()
                ->first();

            if (!$invoice) {
                $invoice = $db->table('invoices as inv')
                    ->join('billing_runs as br', 'br.id', '=', 'inv.billing_run_id')
                    ->where('inv.family_id', $familyId)
                    ->where('inv.currency', $colCurrency)
                    ->where('br.frequency', 'opening_balance')
                    ->whereNotIn('inv.status', ['void', 'voided'])
                    ->select('inv.*')
                    ->lockForUpdate()
                    ->first();
            }

            if ($invoice) {
                $invoiceId = (int) $invoice->id;
                $prevInvPaid = (int) $invoice->paid_minor;
                $prevInvStatus = (string) $invoice->status;
            } else {
                $prevInvPaid = 0;
                $prevInvStatus = 'unpaid';
                $invoiceId = (int) $db->table('invoices')->insertGetId([
                    'billing_run_id' => $obRunId,
                    'family_id' => $familyId,
                    'campus_id' => $campusId,
                    'department_id' => $deptId,
                    'currency' => $colCurrency,
                    'invoice_number' => 'SCH-OB-' . $colCurrency . '-' . $familyId . '-' . substr(md5((string) $stagedCollectionId), 0, 6),
                    'issued_on' => $inspection['payment_date'] ?: '2026-10-01',
                    'due_date' => '2026-10-25',
                    'total_minor' => 0,
                    'paid_minor' => 0,
                    'previous_dues_snapshot_minor' => 0,
                    'status' => 'paid',
                    'approved_to_send' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $invoice = $db->table('invoices')->where('id', $invoiceId)->first();
            }

            $prevItemPaid = 0;
            $prevItemStatus = 'active';

            $resolvedAmountMinor = $colAmountMinor;
            $resolvedDiscountMinor = 0;
            if ($studentId && $enrollmentId && $feeTypeId && class_exists(\App\Services\InvoiceService::class)) {
                $studentRec = $db->table('students as s')
                    ->join('enrollments as e', 'e.student_id', '=', 's.id')
                    ->where('s.id', $studentId)
                    ->where('e.id', $enrollmentId)
                    ->select('s.*', 'e.id as enrollment_id', 'e.campus_id', 'e.department_id', 'e.currency', 'e.grade_label', 'e.monthly_fee_minor')
                    ->first();
                if ($studentRec) {
                    $resolved = \App\Services\InvoiceService::resolveFeeAndCurrency($studentRec, $feeTypeId);
                    if (!empty($resolved['net_minor']) && (int) $resolved['net_minor'] > 0 && strtoupper(trim((string) $resolved['currency'])) === strtoupper(trim($colCurrency))) {
                        $resolvedAmountMinor = (int) $resolved['amount_minor'];
                        $resolvedDiscountMinor = (int) $resolved['discount_minor'];
                    }
                }
            }

            $newItemNet = max(0, $resolvedAmountMinor - $resolvedDiscountMinor);
            if ($newItemNet <= 0) {
                $newItemNet = $colAmountMinor;
                $resolvedAmountMinor = $colAmountMinor;
                $resolvedDiscountMinor = 0;
            }
            $newItemPaid = min($newItemNet, $colAmountMinor);
            $newItemStatus = ($newItemPaid >= $newItemNet && $newItemNet > 0) ? 'paid' : 'partial';

            $itemId = (int) $db->table('invoice_items')->insertGetId([
                'invoice_id' => $invoiceId,
                'student_id' => $studentId,
                'enrollment_id' => $enrollmentId,
                'fee_type_id' => $feeTypeId,
                'description' => $inspection['fee_particular'] ?: ('Monthly Fee - ' . $inspection['period_label']),
                'period_key' => $periodKey,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'currency' => $colCurrency,
                'amount_minor' => $resolvedAmountMinor,
                'discount_minor' => $resolvedDiscountMinor,
                'exemption_minor' => 0,
                'net_minor' => $newItemNet,
                'paid_minor' => $newItemPaid,
                'status' => $newItemStatus,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Recalculate parent invoice totals (since both net_minor and paid_minor increased by $colAmountMinor, unpaid balance delta = 0!)
        $itemTotals = $db->table('invoice_items')
            ->where('invoice_id', $invoiceId)
            ->selectRaw("COALESCE(SUM(paid_minor), 0) as total_paid, COALESCE(SUM(CASE WHEN status != 'voided' THEN net_minor ELSE 0 END), 0) as total_net")
            ->first();

        $newInvPaidMinor = (int) $itemTotals->total_paid;
        $newInvNetMinor = (int) $itemTotals->total_net;
        $newInvStatus = ($newInvNetMinor > 0 && $newInvPaidMinor >= $newInvNetMinor)
            ? 'paid'
            : ($newInvPaidMinor > 0 ? 'partial' : 'unpaid');

        $db->table('invoices')->where('id', $invoiceId)->update([
            'total_minor' => $newInvNetMinor,
            'paid_minor' => $newInvPaidMinor,
            'status' => $newInvStatus,
            'updated_at' => $now,
        ]);

        $reconId = sprintf('HRECON-LIN-I%d-C%d', $itemId, $stagedCollectionId);
        $batchId = (string) ($inspection['batch_id'] ?: config('imports.active_batch_id', 'BATCH-20261025-FINAL-MYSKOOL'));

        $settlementId = (int) $db->table('qa_historical_settlements')->insertGetId([
            'reconciliation_id' => $reconId,
            'batch_id' => $batchId,
            'cutoff_date' => $inspection['cutoff_date'],
            'invoice_item_id' => $itemId,
            'active_invoice_item_id' => $itemId,
            'invoice_id' => $invoiceId,
            'student_id' => $studentId,
            'family_id' => $familyId,
            'student_gr' => $studentGr,
            'family_code' => (string) $inspection['family_code'],
            'currency' => $colCurrency,
            'fee_description' => (string) $inspection['fee_particular'],
            'period_key' => $periodKey,
            'original_obligation_minor' => $newItemNet,
            'settled_amount_minor' => $colAmountMinor,
            'remaining_obligation_minor' => max(0, $newItemNet - $newItemPaid),
            'unmatched_overpayment_minor' => 0,
            'previous_item_paid_minor' => $prevItemPaid,
            'new_item_paid_minor' => $newItemPaid,
            'previous_item_status' => $prevItemStatus,
            'new_item_status' => $newItemStatus,
            'previous_invoice_paid_minor' => $prevInvPaid,
            'new_invoice_paid_minor' => $newInvPaidMinor,
            'previous_invoice_status' => $prevInvStatus,
            'new_invoice_status' => $newInvStatus,
            'qa_staged_due_id' => null,
            'active_primary_collection_id' => $stagedCollectionId,
            'qa_staged_collection_ids' => json_encode([$stagedCollectionId]),
            'myskool_slip_numbers' => (string) $inspection['slip_number'],
            'reconciliation_classification' => ($newItemPaid >= $newItemNet ? 'A1_SAFE_EXACT' : 'PARTIAL_SETTLEMENT'),
            'guard_class' => ($newItemPaid >= $newItemNet ? 'A1_SAFE_EXACT' : 'CANDIDATE_PARTIAL_SETTLEMENT'),
            'status' => 'applied',
            'applied_by' => $actorId,
            'applied_at' => $now,
            'affected_snapshots_json' => null,
            'notes' => 'Reconciled historical obligation and settlement lineage via MySkoolPaymentCorrectionService.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($schema->hasTable('qa_historical_settlement_collections')) {
            $db->table('qa_historical_settlement_collections')->insert([
                'settlement_id' => $settlementId,
                'reconciliation_id' => $reconId,
                'invoice_item_id' => $itemId,
                'qa_staged_collection_id' => $stagedCollectionId,
                'active_staged_collection_id' => $stagedCollectionId,
                'status' => 'applied',
                'applied_by' => $actorId,
                'applied_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $afterState = [
            'receipt_status' => 'Paid',
            'reconciliation_id' => $reconId,
            'invoice_item_id' => $itemId,
            'invoice_id' => $invoiceId,
            'item_paid_minor' => $newItemPaid,
            'item_paid_formatted' => number_format($newItemPaid / 100, 2, '.', ''),
            'item_balance_minor' => max(0, $newItemNet - $newItemPaid),
            'item_balance_formatted' => number_format(max(0, $newItemNet - $newItemPaid) / 100, 2, '.', ''),
            'item_status' => $newItemStatus,
        ];

        $refreshedInspection = array_merge($inspection, [
            'reconciliation_id' => $reconId,
            'invoice_item_id' => $itemId,
            'invoice_id' => $invoiceId,
        ]);

        $correctionId = $this->insertImmutableCorrectionRecord(
            $db,
            $schema,
            $refreshedInspection,
            $nextSeq,
            $prevCorrectionId,
            self::ACTION_RECONCILE_LINEAGE,
            self::STATUS_RECONCILED,
            $settlementId,
            $beforeState,
            $afterState,
            $reason,
            $actorId,
            null,
            null,
            null,
            null,
            'reconcile',
            0,
            0,
            $colAmountMinor,
            $idempotencyKey
        );

        return [
            'status' => 'success',
            'correction_id' => $correctionId,
            'qa_staged_collection_id' => $stagedCollectionId,
            'action_type' => self::ACTION_RECONCILE_LINEAGE,
            'resulting_status' => self::STATUS_RECONCILED,
            'allocation_status' => 'paid',
            'allocation_status_label' => 'Paid',
            'reconciliation_id' => $reconId,
            'invoice_item_id' => $itemId,
            'invoice_id' => $invoiceId,
            'before_state' => $beforeState,
            'after_state' => $afterState,
        ];
    }

    /**
     * Case A & Case C: Reverse a reconciled historical settlement (either full settlement or single row of a multi-row settlement).
     */
    protected function performReverseSettlement(
        $db,
        $schema,
        string $conn,
        array $inspection,
        int $nextSeq,
        ?string $prevCorrectionId,
        string $reason,
        int $actorId,
        ?string $idempotencyKey = null
    ): array {
        $stagedCollectionId = (int) $inspection['qa_staged_collection_id'];
        $reconId = (string) $inspection['reconciliation_id'];
        $itemId = (int) $inspection['invoice_item_id'];
        $invoiceId = (int) $inspection['invoice_id'];
        $effect = $inspection['proposed_effects'][self::ACTION_REVERSE_SETTLEMENT];
        $reversalDelta = (int) $effect['delta_minor'];
        $origPaidMinor = (int) ($inspection['original_paid_minor'] ?? $inspection['amount_minor']);

        $settlement = $db->table('qa_historical_settlements')
            ->where('reconciliation_id', $reconId)
            ->where('status', 'applied')
            ->lockForUpdate()
            ->first();

        if (!$settlement) {
            throw ValidationException::withMessages([
                'reconciliation_id' => ["Active applied settlement '{$reconId}' not found or already reversed."],
            ]);
        }

        $activeColCount = (int) ($inspection['active_settlement_col_count'] ?? 1);

        if ($activeColCount <= 1 && $reversalDelta === (int) $settlement->settled_amount_minor) {
            // Standard Case A & Case C (where each student/item has its own settlement record):
            // Delegate directly to HistoricalPaymentReconciliationService::reverse()
            app(HistoricalPaymentReconciliationService::class)->reverse($reconId, $actorId, $conn);
        } else {
            // Partial multi-collection reversal on a single invoice item where multiple MySkool slips were combined
            $item = $db->table('invoice_items')->where('id', $itemId)->lockForUpdate()->first();
            $invoice = $db->table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();

            $newItemPaidMinor = max(0, (int) $item->paid_minor - $reversalDelta);
            $newItemStatus = ($newItemPaidMinor >= (int) $item->net_minor && (int) $item->net_minor > 0)
                ? 'paid'
                : ($newItemPaidMinor > 0 ? 'partial' : (string) $settlement->previous_item_status);

            $db->table('invoice_items')->where('id', $itemId)->update([
                'paid_minor' => $newItemPaidMinor,
                'status' => $newItemStatus,
                'updated_at' => now(),
            ]);

            $itemTotals = $db->table('invoice_items')
                ->where('invoice_id', $invoiceId)
                ->selectRaw("COALESCE(SUM(paid_minor), 0) as total_paid, COALESCE(SUM(CASE WHEN status != 'voided' THEN net_minor ELSE 0 END), 0) as total_net")
                ->first();

            $newInvPaidMinor = (int) $itemTotals->total_paid;
            $newInvNetMinor = (int) $itemTotals->total_net;
            $newInvStatus = ($newInvNetMinor > 0 && $newInvPaidMinor >= $newInvNetMinor)
                ? 'paid'
                : ($newInvPaidMinor > 0 ? 'partial' : (string) $settlement->previous_invoice_status);

            $db->table('invoices')->where('id', $invoiceId)->update([
                'total_minor' => $newInvNetMinor,
                'paid_minor' => $newInvPaidMinor,
                'status' => $newInvStatus,
                'updated_at' => now(),
            ]);

            // Release only the targeted collection row in qa_historical_settlement_collections
            if ($schema->hasTable('qa_historical_settlement_collections')) {
                $db->table('qa_historical_settlement_collections')
                    ->where('settlement_id', $settlement->id)
                    ->where('qa_staged_collection_id', $stagedCollectionId)
                    ->update([
                        'status' => 'reversed',
                        'active_staged_collection_id' => null,
                        'reversed_by' => $actorId,
                        'reversed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $remainingActiveColId = $schema->hasTable('qa_historical_settlement_collections')
                ? $db->table('qa_historical_settlement_collections')
                    ->where('settlement_id', $settlement->id)
                    ->where('status', 'applied')
                    ->value('qa_staged_collection_id')
                : null;

            $newSettledMinor = max(0, (int) $settlement->settled_amount_minor - $reversalDelta);
            $settlementNowFullyReversed = ($newSettledMinor === 0);
            $db->table('qa_historical_settlements')
                ->where('id', $settlement->id)
                ->update([
                    'status' => $settlementNowFullyReversed ? 'reversed' : 'applied',
                    'active_invoice_item_id' => $settlementNowFullyReversed ? null : $itemId,
                    'settled_amount_minor' => $newSettledMinor,
                    'remaining_obligation_minor' => max(0, (int) $settlement->original_obligation_minor - $newItemPaidMinor),
                    'new_item_paid_minor' => $newItemPaidMinor,
                    'new_item_status' => $newItemStatus,
                    'new_invoice_paid_minor' => $newInvPaidMinor,
                    'new_invoice_status' => $newInvStatus,
                    'active_primary_collection_id' => $remainingActiveColId ? (int) $remainingActiveColId : null,
                    'reversed_by' => $settlementNowFullyReversed ? $actorId : $settlement->reversed_by,
                    'reversed_at' => $settlementNowFullyReversed ? now() : $settlement->reversed_at,
                    'updated_at' => now(),
                ]);

            if (!empty($settlement->affected_snapshots_json)) {
                $snapshots = json_decode((string) $settlement->affected_snapshots_json, true) ?: [];
                foreach ($snapshots as $snap) {
                    if (!empty($snap['invoice_id'])) {
                        $subInv = $db->table('invoices')->where('id', (int) $snap['invoice_id'])->lockForUpdate()->first();
                        if ($subInv) {
                            $db->table('invoices')
                                ->where('id', (int) $snap['invoice_id'])
                                ->update([
                                    'previous_dues_snapshot_minor' => (int) $subInv->previous_dues_snapshot_minor + $reversalDelta,
                                    'updated_at' => now(),
                                ]);
                        }
                    }
                }
            }
        }

        // Refresh live item and invoice after reversal for accurate after_state
        $updatedItem = $db->table('invoice_items')->where('id', $itemId)->first();
        $updatedInvoice = $db->table('invoices')->where('id', $invoiceId)->first();

        $afterState = [
            'receipt_status' => 'Reversed',
            'item_paid_minor' => $updatedItem ? (int) $updatedItem->paid_minor : (int) $effect['after']['item_paid_minor'],
            'item_paid_formatted' => number_format(($updatedItem ? (int) $updatedItem->paid_minor : (int) $effect['after']['item_paid_minor']) / 100, 2, '.', ''),
            'item_balance_minor' => $updatedItem ? max(0, (int) $updatedItem->net_minor - (int) $updatedItem->paid_minor) : (int) $effect['after']['item_balance_minor'],
            'item_balance_formatted' => number_format(($updatedItem ? max(0, (int) $updatedItem->net_minor - (int) $updatedItem->paid_minor) : (int) $effect['after']['item_balance_minor']) / 100, 2, '.', ''),
            'item_status' => $updatedItem ? (string) $updatedItem->status : (string) $effect['after']['item_status'],
            'invoice_paid_minor' => $updatedInvoice ? (int) $updatedInvoice->paid_minor : (int) $effect['after']['invoice_paid_minor'],
            'invoice_paid_formatted' => number_format(($updatedInvoice ? (int) $updatedInvoice->paid_minor : (int) $effect['after']['invoice_paid_minor']) / 100, 2, '.', ''),
            'invoice_balance_minor' => $updatedInvoice ? max(0, (int) $updatedInvoice->total_minor - (int) $updatedInvoice->paid_minor) : (int) $effect['after']['invoice_balance_minor'],
            'invoice_balance_formatted' => number_format(($updatedInvoice ? max(0, (int) $updatedInvoice->total_minor - (int) $updatedInvoice->paid_minor) : (int) $effect['after']['invoice_balance_minor']) / 100, 2, '.', ''),
            'invoice_status' => $updatedInvoice ? (string) $updatedInvoice->status : (string) $effect['after']['invoice_status'],
        ];

        $correctionId = $this->insertImmutableCorrectionRecord(
            $db,
            $schema,
            $inspection,
            $nextSeq,
            $prevCorrectionId,
            self::ACTION_REVERSE_SETTLEMENT,
            self::STATUS_REVERSED,
            (int) $settlement->id,
            $effect['before'],
            $afterState,
            $reason,
            $actorId,
            null,
            null,
            null,
            null,
            'full',
            $reversalDelta,
            $origPaidMinor,
            0,
            $idempotencyKey
        );

        return [
            'status' => 'success',
            'correction_id' => $correctionId,
            'qa_staged_collection_id' => $stagedCollectionId,
            'action_type' => self::ACTION_REVERSE_SETTLEMENT,
            'resulting_status' => self::STATUS_REVERSED,
            'reconciliation_id' => $reconId,
            'invoice_item_id' => $itemId,
            'invoice_id' => $invoiceId,
            'before_state' => $effect['before'],
            'after_state' => $afterState,
        ];
    }

    /**
     * Case E: Safely reapply a previously reversed historical settlement when valid and unconsumed.
     */
    protected function performReapplySettlement(
        $db,
        $schema,
        array $inspection,
        int $nextSeq,
        ?string $prevCorrectionId,
        string $reason,
        int $actorId
    ): array {
        $stagedCollectionId = (int) $inspection['qa_staged_collection_id'];
        $reconId = (string) $inspection['reconciliation_id'];
        $itemId = (int) $inspection['invoice_item_id'];
        $invoiceId = (int) $inspection['invoice_id'];
        $effect = $inspection['proposed_effects'][self::ACTION_REAPPLY_SETTLEMENT];
        $reapplyDelta = (int) $effect['delta_minor'];

        $settlement = $db->table('qa_historical_settlements')
            ->where('reconciliation_id', $reconId)
            ->lockForUpdate()
            ->first();

        if (!$settlement) {
            throw ValidationException::withMessages([
                'reconciliation_id' => ["Settlement '{$reconId}' not found for reapplication."],
            ]);
        }

        $item = $db->table('invoice_items')->where('id', $itemId)->lockForUpdate()->first();
        $invoice = $db->table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();

        if (!$item || !$invoice) {
            throw ValidationException::withMessages([
                'invoice_item_id' => ["Target invoice item #{$itemId} or invoice #{$invoiceId} not found."],
            ]);
        }

        // Verify source collection is not consumed elsewhere
        if ($schema->hasTable('qa_historical_settlement_collections')) {
            $alreadyConsumed = $db->table('qa_historical_settlement_collections')
                ->where('active_staged_collection_id', $stagedCollectionId)
                ->exists();
            if ($alreadyConsumed) {
                throw ValidationException::withMessages([
                    'qa_staged_collection_id' => ["Source collection row #{$stagedCollectionId} is already actively consumed."],
                ]);
            }
        }

        // Verify no intervening portal payments and no overpayment
        $hasAllocs = $db->table('payment_allocations')
            ->where('invoice_item_id', $itemId)
            ->where('amount_minor', '>', 0)
            ->exists();
        if ($hasAllocs || ((int) $item->paid_minor + $reapplyDelta > (int) $item->net_minor) || $reapplyDelta <= 0) {
            throw ValidationException::withMessages([
                'action_type' => ["Cannot reapply settlement: would create duplicate payment recognition on item #{$itemId}."],
            ]);
        }

        $newItemPaidMinor = (int) $item->paid_minor + $reapplyDelta;
        $newItemStatus = ($newItemPaidMinor >= (int) $item->net_minor && (int) $item->net_minor > 0) ? 'paid' : 'partial';

        $db->table('invoice_items')->where('id', $itemId)->update([
            'paid_minor' => $newItemPaidMinor,
            'status' => $newItemStatus,
            'updated_at' => now(),
        ]);

        // Recalculate parent invoice totals from live items
        $itemTotals = $db->table('invoice_items')
            ->where('invoice_id', $invoiceId)
            ->selectRaw("COALESCE(SUM(paid_minor), 0) as total_paid, COALESCE(SUM(CASE WHEN status != 'voided' THEN net_minor ELSE 0 END), 0) as total_net")
            ->first();

        $newInvPaidMinor = (int) $itemTotals->total_paid;
        $newInvNetMinor = (int) $itemTotals->total_net;
        $newInvStatus = ($newInvNetMinor > 0 && $newInvPaidMinor >= $newInvNetMinor)
            ? 'paid'
            : ($newInvPaidMinor > 0 ? 'partial' : 'unpaid');

        $db->table('invoices')->where('id', $invoiceId)->update([
            'total_minor' => $newInvNetMinor,
            'paid_minor' => $newInvPaidMinor,
            'status' => $newInvStatus,
            'updated_at' => now(),
        ]);

        // Reduce downstream previous_dues_snapshot_minor by the reapplied amount
        if (!empty($settlement->affected_snapshots_json)) {
            $snapshots = json_decode((string) $settlement->affected_snapshots_json, true) ?: [];
            foreach ($snapshots as $snap) {
                if (!empty($snap['invoice_id'])) {
                    $subInv = $db->table('invoices')->where('id', (int) $snap['invoice_id'])->lockForUpdate()->first();
                    if ($subInv) {
                        $db->table('invoices')
                            ->where('id', (int) $snap['invoice_id'])
                            ->update([
                                'previous_dues_snapshot_minor' => max(0, (int) $subInv->previous_dues_snapshot_minor - $reapplyDelta),
                                'updated_at' => now(),
                            ]);
                    }
                }
            }
        }

        // Restore settlement and collection lock rows
        $newSettledMinor = ((string) $settlement->status === 'applied')
            ? ((int) $settlement->settled_amount_minor + $reapplyDelta)
            : $reapplyDelta;

        $db->table('qa_historical_settlements')
            ->where('id', $settlement->id)
            ->update([
                'status' => 'applied',
                'active_invoice_item_id' => $itemId,
                'active_primary_collection_id' => $settlement->active_primary_collection_id ?: $stagedCollectionId,
                'settled_amount_minor' => $newSettledMinor,
                'remaining_obligation_minor' => max(0, (int) $settlement->original_obligation_minor - $newItemPaidMinor),
                'new_item_paid_minor' => $newItemPaidMinor,
                'new_item_status' => $newItemStatus,
                'new_invoice_paid_minor' => $newInvPaidMinor,
                'new_invoice_status' => $newInvStatus,
                'applied_by' => $actorId,
                'applied_at' => now(),
                'reversed_by' => null,
                'reversed_at' => null,
                'updated_at' => now(),
            ]);

        if ($schema->hasTable('qa_historical_settlement_collections')) {
            $existingAlloc = $db->table('qa_historical_settlement_collections')
                ->where('settlement_id', $settlement->id)
                ->where('qa_staged_collection_id', $stagedCollectionId)
                ->first();

            if ($existingAlloc) {
                $db->table('qa_historical_settlement_collections')
                    ->where('id', $existingAlloc->id)
                    ->update([
                        'status' => 'applied',
                        'active_staged_collection_id' => $stagedCollectionId,
                        'applied_by' => $actorId,
                        'applied_at' => now(),
                        'reversed_by' => null,
                        'reversed_at' => null,
                        'updated_at' => now(),
                    ]);
            } else {
                $db->table('qa_historical_settlement_collections')->insert([
                    'settlement_id' => $settlement->id,
                    'reconciliation_id' => $reconId,
                    'invoice_item_id' => $itemId,
                    'qa_staged_collection_id' => $stagedCollectionId,
                    'active_staged_collection_id' => $stagedCollectionId,
                    'status' => 'applied',
                    'applied_by' => $actorId,
                    'applied_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $afterState = [
            'receipt_status' => 'Reconciled (Reapplied)',
            'item_paid_minor' => $newItemPaidMinor,
            'item_paid_formatted' => number_format($newItemPaidMinor / 100, 2, '.', ''),
            'item_balance_minor' => max(0, (int) $item->net_minor - $newItemPaidMinor),
            'item_balance_formatted' => number_format(max(0, (int) $item->net_minor - $newItemPaidMinor) / 100, 2, '.', ''),
            'item_status' => $newItemStatus,
            'invoice_paid_minor' => $newInvPaidMinor,
            'invoice_paid_formatted' => number_format($newInvPaidMinor / 100, 2, '.', ''),
            'invoice_balance_minor' => max(0, $newInvNetMinor - $newInvPaidMinor),
            'invoice_balance_formatted' => number_format(max(0, $newInvNetMinor - $newInvPaidMinor) / 100, 2, '.', ''),
            'invoice_status' => $newInvStatus,
        ];

        $correctionId = $this->insertImmutableCorrectionRecord(
            $db,
            $schema,
            $inspection,
            $nextSeq,
            $prevCorrectionId,
            self::ACTION_REAPPLY_SETTLEMENT,
            self::STATUS_REAPPLIED,
            (int) $settlement->id,
            $effect['before'],
            $afterState,
            $reason,
            $actorId
        );

        return [
            'status' => 'success',
            'correction_id' => $correctionId,
            'qa_staged_collection_id' => $stagedCollectionId,
            'action_type' => self::ACTION_REAPPLY_SETTLEMENT,
            'resulting_status' => self::STATUS_REAPPLIED,
            'reconciliation_id' => $reconId,
            'invoice_item_id' => $itemId,
            'invoice_id' => $invoiceId,
            'before_state' => $effect['before'],
            'after_state' => $afterState,
        ];
    }

    /**
     * Cases B & D: Record auditable manual review, dispute, correction, reassignment proposal,
     * or pre-cutoff compensation proposal without mutating qa_staged_collections or blindly altering paid_minor.
     */
    protected function performStatusOrProposalAction(
        $db,
        $schema,
        array $inspection,
        string $actionType,
        array $input,
        int $nextSeq,
        ?string $prevCorrectionId,
        string $reason,
        int $actorId
    ): array {
        $stagedCollectionId = (int) $inspection['qa_staged_collection_id'];
        $colCurrency = (string) $inspection['currency'];

        $proposedStudentGr = isset($input['proposed_student_gr']) && trim((string) $input['proposed_student_gr']) !== ''
            ? trim((string) $input['proposed_student_gr'])
            : null;
        $proposedPeriodKey = isset($input['proposed_period_key']) && trim((string) $input['proposed_period_key']) !== ''
            ? trim((string) $input['proposed_period_key'])
            : null;
        $proposedFeeType = isset($input['proposed_fee_type']) && trim((string) $input['proposed_fee_type']) !== ''
            ? trim((string) $input['proposed_fee_type'])
            : null;
        $proposedCompensationMinor = isset($input['proposed_compensation_minor']) && $input['proposed_compensation_minor'] !== '' && $input['proposed_compensation_minor'] !== null
            ? max(0, (int) $input['proposed_compensation_minor'])
            : null;

        if ($actionType === self::ACTION_PROPOSE_REASSIGNMENT) {
            if ($proposedStudentGr === null && $proposedPeriodKey === null && $proposedFeeType === null) {
                throw ValidationException::withMessages([
                    'proposed_reassignment' => ['Please specify at least one proposed reassignment target (Student GR, Period, or Fee Type).'],
                ]);
            }

            if ($proposedStudentGr !== null && $schema->hasTable('students')) {
                $targetStudent = $db->table('students')->where('gr_number', $proposedStudentGr)->first();
                if (!$targetStudent) {
                    throw ValidationException::withMessages([
                        'proposed_student_gr' => ["Target student GR '{$proposedStudentGr}' does not exist."],
                    ]);
                }

                // Enforce currency isolation on proposed reassignment target
                $targetCurrency = null;
                if ($schema->hasTable('invoice_items')) {
                    $targetCurrency = $db->table('invoice_items')
                        ->where('student_id', $targetStudent->id)
                        ->where('status', '!=', 'voided')
                        ->value('currency');
                }
                if (!$targetCurrency && $schema->hasTable('fee_plan_items') && $schema->hasTable('enrollments')) {
                    $targetCurrency = $db->table('fee_plan_items as fpi')
                        ->join('enrollments as e', 'e.id', '=', 'fpi.enrollment_id')
                        ->where('e.student_id', $targetStudent->id)
                        ->value('fpi.currency');
                }
                if (!$targetCurrency && $schema->hasTable('enrollments')) {
                    $targetCurrency = $db->table('enrollments')
                        ->where('student_id', $targetStudent->id)
                        ->value('currency');
                }
                if ($targetCurrency && strtoupper(trim((string) $targetCurrency)) !== $colCurrency) {
                    throw ValidationException::withMessages([
                        'proposed_student_gr' => [
                            "Cross-currency reassignment blocked: collection row #{$stagedCollectionId} is in {$colCurrency}, " .
                            "whereas target student GR {$proposedStudentGr} operates in " . strtoupper(trim((string) $targetCurrency)) . "."
                        ],
                    ]);
                }
            }
        }

        if ($actionType === self::ACTION_PROPOSE_PRE_CUTOFF_COMPENSATION && empty($inspection['is_pre_cutoff'])) {
            throw ValidationException::withMessages([
                'action_type' => ['Pre-cutoff compensation proposals only apply to pre-cutoff historical receipts.'],
            ]);
        }

        $resultingStatus = match ($actionType) {
            self::ACTION_MARK_DISPUTED => self::STATUS_DISPUTED,
            self::ACTION_MARK_CORRECTED, self::ACTION_PROPOSE_REASSIGNMENT => self::STATUS_CORRECTED,
            default => self::STATUS_UNDER_REVIEW,
        };

        $effect = $inspection['proposed_effects'][$actionType] ?? $inspection['proposed_effects'][self::ACTION_MARK_UNDER_REVIEW];
        $afterState = $effect['after'];
        if ($proposedStudentGr) {
            $afterState['proposed_student_gr'] = $proposedStudentGr;
        }
        if ($proposedPeriodKey) {
            $afterState['proposed_period_key'] = $proposedPeriodKey;
        }
        if ($proposedFeeType) {
            $afterState['proposed_fee_type'] = $proposedFeeType;
        }
        if ($proposedCompensationMinor !== null) {
            $afterState['proposed_compensation_minor'] = $proposedCompensationMinor;
        }

        $correctionId = $this->insertImmutableCorrectionRecord(
            $db,
            $schema,
            $inspection,
            $nextSeq,
            $prevCorrectionId,
            $actionType,
            $resultingStatus,
            null,
            $effect['before'],
            $afterState,
            $reason,
            $actorId,
            $proposedStudentGr,
            $proposedPeriodKey,
            $proposedFeeType,
            $proposedCompensationMinor
        );

        return [
            'status' => 'success',
            'correction_id' => $correctionId,
            'qa_staged_collection_id' => $stagedCollectionId,
            'action_type' => $actionType,
            'resulting_status' => $resultingStatus,
            'reconciliation_id' => $inspection['reconciliation_id'],
            'invoice_item_id' => $inspection['invoice_item_id'],
            'invoice_id' => $inspection['invoice_id'],
            'before_state' => $effect['before'],
            'after_state' => $afterState,
        ];
    }

    /**
     * Insert an immutable record into qa_myskool_payment_corrections and audit_logs.
     */
    protected function insertImmutableCorrectionRecord(
        $db,
        $schema,
        array $inspection,
        int $sequenceNo,
        ?string $prevCorrectionId,
        string $actionType,
        string $resultingStatus,
        ?int $settlementId,
        array $beforeState,
        array $afterState,
        string $reason,
        int $actorId,
        ?string $proposedStudentGr = null,
        ?string $proposedPeriodKey = null,
        ?string $proposedFeeType = null,
        ?int $proposedCompensationMinor = null,
        ?string $restorationMode = null,
        int $reversedAmountMinor = 0,
        int $cumulativeReversedMinor = 0,
        ?int $remainingPaidMinor = null,
        ?string $idempotencyKey = null
    ): string {
        $stagedCollectionId = (int) $inspection['qa_staged_collection_id'];
        $correctionId = sprintf(
            'MSCORR-%d-S%d-%s',
            $stagedCollectionId,
            $sequenceNo,
            substr(md5($stagedCollectionId . '-' . $sequenceNo . '-' . microtime()), 0, 8)
        );

        $now = now();
        $insertPayload = [
            'correction_id' => $correctionId,
            'batch_id' => (string) ($inspection['batch_id'] ?: config('imports.active_batch_id', 'BATCH-20261025-FINAL-MYSKOOL')),
            'qa_staged_collection_id' => $stagedCollectionId,
            'sequence_no' => $sequenceNo,
            'previous_correction_id' => $prevCorrectionId,
            'slip_number' => (string) ($inspection['slip_number'] ?? ''),
            'source_row' => $inspection['source_row'],
            'student_id' => $inspection['student_id'],
            'family_id' => $inspection['family_id'],
            'student_gr' => (string) ($inspection['student_gr'] ?? ''),
            'family_code' => (string) ($inspection['family_code'] ?? ''),
            'currency' => (string) ($inspection['currency'] ?? 'SAR'),
            'original_amount_minor' => (int) ($inspection['amount_minor'] ?? 0),
            'payment_date' => (string) ($inspection['payment_date'] ?? ''),
            'is_pre_cutoff' => !empty($inspection['is_pre_cutoff']),
            'case_classification' => (string) ($inspection['case_classification'] ?? self::CASE_A_RECONCILED_SETTLEMENT),
            'action_type' => $actionType,
            'resulting_status' => $resultingStatus,
            'reconciliation_id' => $inspection['reconciliation_id'],
            'settlement_id' => $settlementId,
            'invoice_item_id' => $inspection['invoice_item_id'],
            'invoice_id' => $inspection['invoice_id'],
            'proposed_student_gr' => $proposedStudentGr,
            'proposed_period_key' => $proposedPeriodKey,
            'proposed_fee_type' => $proposedFeeType,
            'proposed_compensation_minor' => $proposedCompensationMinor,
            'before_state_json' => json_encode($beforeState),
            'after_state_json' => json_encode($afterState),
            'reason' => $reason,
            'acted_by' => $actorId,
            'acted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($schema->hasColumn('qa_myskool_payment_corrections', 'restoration_mode')) {
            $insertPayload['restoration_mode'] = $restorationMode;
            $insertPayload['reversed_amount_minor'] = $reversedAmountMinor;
            $insertPayload['cumulative_reversed_minor'] = $cumulativeReversedMinor;
            $insertPayload['remaining_paid_minor'] = $remainingPaidMinor;
            $insertPayload['idempotency_key'] = $idempotencyKey;
        }

        $db->table('qa_myskool_payment_corrections')->insert($insertPayload);

        if ($schema->hasTable('audit_logs')) {
            $db->table('audit_logs')->insert([
                'actor_id' => $actorId,
                'action' => 'myskool_payment_' . $actionType,
                'subject_type' => 'qa_staged_collection',
                'subject_id' => $stagedCollectionId,
                'metadata' => json_encode([
                    'correction_id' => $correctionId,
                    'sequence_no' => $sequenceNo,
                    'slip_number' => $inspection['slip_number'],
                    'student_gr' => $inspection['student_gr'],
                    'family_code' => $inspection['family_code'],
                    'currency' => $inspection['currency'],
                    'original_amount_minor' => $inspection['amount_minor'],
                    'action_type' => $actionType,
                    'resulting_status' => $resultingStatus,
                    'restoration_mode' => $restorationMode,
                    'reversed_amount_minor' => $reversedAmountMinor,
                    'cumulative_reversed_minor' => $cumulativeReversedMinor,
                    'remaining_paid_minor' => $remainingPaidMinor,
                    'reconciliation_id' => $inspection['reconciliation_id'],
                    'invoice_item_id' => $inspection['invoice_item_id'],
                    'invoice_id' => $inspection['invoice_id'],
                    'proposed_student_gr' => $proposedStudentGr,
                    'proposed_period_key' => $proposedPeriodKey,
                    'proposed_fee_type' => $proposedFeeType,
                    'proposed_compensation_minor' => $proposedCompensationMinor,
                    'before_state' => $beforeState,
                    'after_state' => $afterState,
                    'reason' => $reason,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $correctionId;
    }

    /**
     * Phase 3E: Inspect an imported MySkool invoice_items entry for Dispute or Restore.
     * For already unpaid fees, returns already_unpaid_invoice_exists = true with the existing
     * outstanding invoice details rather than generating a duplicate charge.
     */
    public function inspectInvoiceItemForMySkool(int $invoiceItemId, ?string $conn = null): array
    {
        $conn = $conn ?: config('database.default', 'mysql');
        $db = DB::connection($conn);
        $schema = $db->getSchemaBuilder();

        if (!$schema->hasTable('invoice_items') || !$schema->hasTable('invoices')) {
            return ['exists' => false, 'error' => 'Billing ledger tables not found.'];
        }

        $item = $db->table('invoice_items as ii')
            ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
            ->leftJoin('students as s', 's.id', '=', 'ii.student_id')
            ->leftJoin('families as f', 'f.id', '=', 'inv.family_id')
            ->leftJoin('fee_types as ft', 'ft.id', '=', 'ii.fee_type_id')
            ->where('ii.id', $invoiceItemId)
            ->select(
                'ii.*',
                'inv.invoice_number',
                'inv.family_id',
                'inv.total_minor as invoice_total_minor',
                'inv.paid_minor as invoice_paid_minor',
                'inv.status as invoice_status',
                's.name as student_name',
                's.gr_number as student_gr',
                'f.family_code',
                'f.guardian_name',
                'ft.name as fee_type_name',
                'ft.code as fee_type_code'
            )
            ->first();

        if (!$item) {
            return ['exists' => false, 'error' => "Invoice item #{$invoiceItemId} not found."];
        }

        // Check if this item is linked to a MySkool staged collection via qa_historical_settlements
        if ($schema->hasTable('qa_historical_settlements')) {
            $settlement = $db->table('qa_historical_settlements')
                ->where('invoice_item_id', $invoiceItemId)
                ->orderByDesc('id')
                ->first();
            if ($settlement && !empty($settlement->active_primary_collection_id)) {
                return $this->inspect((int) $settlement->active_primary_collection_id, $conn);
            }
        }

        $curr = strtoupper(trim((string) ($item->currency ?: 'SAR')));
        $netMinor = (int) $item->net_minor;
        $paidMinor = (int) $item->paid_minor;
        $balMinor = max(0, $netMinor - $paidMinor);
        $invTotalMinor = (int) $item->invoice_total_minor;
        $invPaidMinor = (int) $item->invoice_paid_minor;
        $invBalMinor = max(0, $invTotalMinor - $invPaidMinor);
        $periodStart = !empty($item->period_start) ? (string) $item->period_start : null;
        $billingMonth = $periodStart ? date('F Y', strtotime($periodStart)) : ((string) ($item->period_key ?: 'N/A'));
        $feeType = (string) ($item->fee_type_name ?: ($item->description ?: 'Monthly Fee'));

        $alreadyUnpaid = ($paidMinor === 0 && $netMinor > 0);

        return [
            'exists' => true,
            'source_type' => 'myskool_invoice_item',
            'qa_staged_collection_id' => null,
            'invoice_item_id' => (int) $item->id,
            'invoice_id' => (int) $item->invoice_id,
            'invoice_number' => (string) $item->invoice_number,
            'slip_number' => (string) $item->invoice_number,
            'student_id' => (int) $item->student_id,
            'student_name' => (string) ($item->student_name ?: 'Unknown Student'),
            'student_gr' => (string) ($item->student_gr ?: ''),
            'family_id' => (int) $item->family_id,
            'family_code' => (string) ($item->family_code ?: ''),
            'guardian_name' => (string) ($item->guardian_name ?: ''),
            'fee_particular' => (string) ($item->description ?: $feeType),
            'fee_type' => $feeType,
            'fee_type_code' => (string) ($item->fee_type_code ?: 'monthly'),
            'fee_type_label' => $feeType,
            'billing_month' => $billingMonth,
            'period_key' => (string) ($item->period_key ?: 'N/A'),
            'period_start' => $periodStart,
            'period_label' => $billingMonth,
            'currency' => $curr,
            'student_expected_currency' => $curr,
            'has_currency_conflict' => false,
            'amount_minor' => $netMinor,
            'amount_formatted' => number_format($netMinor / 100, 2, '.', ''),
            'original_paid_minor' => $paidMinor,
            'original_paid_formatted' => number_format($paidMinor / 100, 2, '.', ''),
            'previously_reversed_minor' => 0,
            'previously_reversed_formatted' => '0.00',
            'remaining_reversible_minor' => 0,
            'remaining_reversible_formatted' => '0.00',
            'restorable_amount_minor' => 0,
            'restorable_amount_formatted' => '0.00',
            'effective_paid_minor' => $paidMinor,
            'effective_paid_formatted' => number_format($paidMinor / 100, 2, '.', ''),
            'is_reconciled' => false,
            'is_credited_in_ledger' => $paidMinor > 0,
            'requires_reconciliation' => false,
            'can_reconcile_lineage' => false,
            'can_auto_reconcile_on_restore' => false,
            'already_unpaid_invoice_exists' => $alreadyUnpaid,
            'requires_finance_review' => !$alreadyUnpaid,
            'finance_review_message' => 'Finance review required. This payment needs verification before restoration.',
            'allocation_status' => $alreadyUnpaid ? 'unpaid' : 'requires_review',
            'allocation_status_label' => $alreadyUnpaid ? 'Already Unpaid' : 'Requires Review',
            'item_net_minor' => $netMinor,
            'item_net_formatted' => number_format($netMinor / 100, 2, '.', ''),
            'item_paid_minor' => $paidMinor,
            'item_paid_formatted' => number_format($paidMinor / 100, 2, '.', ''),
            'item_balance_minor' => $balMinor,
            'item_balance_formatted' => number_format($balMinor / 100, 2, '.', ''),
            'invoice_total_minor' => $invTotalMinor,
            'invoice_total_formatted' => number_format($invTotalMinor / 100, 2, '.', ''),
            'invoice_paid_minor' => $invPaidMinor,
            'invoice_paid_formatted' => number_format($invPaidMinor / 100, 2, '.', ''),
            'invoice_balance_minor' => $invBalMinor,
            'invoice_balance_formatted' => number_format($invBalMinor / 100, 2, '.', ''),
            'item_status' => (string) $item->status,
            'invoice_status' => (string) $item->invoice_status,
            'is_safe_to_reverse' => false,
            'safety_status' => $alreadyUnpaid ? 'ALREADY_UNPAID_INVOICE_EXISTS' : self::SAFETY_SAFE_REVIEW_ONLY,
            'safety_explanation' => $alreadyUnpaid
                ? "An outstanding invoice ({$item->invoice_number}) already exists for this fee ({$curr} " . number_format($balMinor / 100, 2) . " unpaid). Restore will not generate a duplicate charge."
                : 'Finance review required. This payment needs verification before restoration.',
            'blocked_reasons' => [],
        ];
    }

    /**
     * Phase 3E (Task 3): Create a persistent MySkool dispute case for a historical payment allocation
     * or imported MySkool invoice fee entry. Preserves original records and makes ZERO financial balance changes.
     */
    public function submitDispute(array $input, ?int $userId = null, ?string $conn = null): array
    {
        $conn = $conn ?: config('database.default', 'mysql');
        $db = DB::connection($conn);
        $schema = $db->getSchemaBuilder();

        try {
            $actorId = app(HistoricalPaymentReconciliationService::class)->resolveAuthorizedActor($db, $userId);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'authorization' => [$e->getMessage()],
            ]);
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => ['A dispute reason (at least 5 characters) is required.'],
            ]);
        }

        $stagedColId = !empty($input['qa_staged_collection_id']) ? (int) $input['qa_staged_collection_id'] : null;
        $invoiceItemId = !empty($input['invoice_item_id']) ? (int) $input['invoice_item_id'] : null;

        if (!$stagedColId && !$invoiceItemId) {
            throw ValidationException::withMessages([
                'record' => ['Either a MySkool payment allocation ID or invoice item ID is required to submit a dispute.'],
            ]);
        }

        return $db->transaction(function () use ($db, $schema, $conn, $stagedColId, $invoiceItemId, $reason, $actorId) {
            $correctionId = null;
            if ($stagedColId) {
                $inspection = $this->inspect($stagedColId, $conn, true);
                if (empty($inspection['exists'])) {
                    throw ValidationException::withMessages([
                        'qa_staged_collection_id' => [$inspection['error'] ?? "MySkool collection row #{$stagedColId} not found."],
                    ]);
                }

                // Also record immutable mark_disputed entry in qa_myskool_payment_corrections if table exists
                if ($schema->hasTable('qa_myskool_payment_corrections')) {
                    $lastCorr = $db->table('qa_myskool_payment_corrections')
                        ->where('qa_staged_collection_id', $stagedColId)
                        ->lockForUpdate()
                        ->orderByDesc('sequence_no')
                        ->orderByDesc('id')
                        ->first();
                    $nextSeq = $lastCorr ? ((int) $lastCorr->sequence_no + 1) : 1;
                    $prevCorrId = $lastCorr ? (string) $lastCorr->correction_id : null;

                    $stateSnapshot = [
                        'receipt_status' => $inspection['current_status_label'] ?? 'Paid',
                        'item_paid_minor' => (int) ($inspection['item_paid_minor'] ?? 0),
                        'item_paid_formatted' => (string) ($inspection['item_paid_formatted'] ?? '0.00'),
                        'item_balance_minor' => (int) ($inspection['item_balance_minor'] ?? 0),
                        'item_balance_formatted' => (string) ($inspection['item_balance_formatted'] ?? '0.00'),
                        'item_status' => (string) ($inspection['item_status'] ?? 'N/A'),
                        'invoice_paid_minor' => (int) ($inspection['invoice_paid_minor'] ?? 0),
                        'invoice_paid_formatted' => (string) ($inspection['invoice_paid_formatted'] ?? '0.00'),
                        'invoice_balance_minor' => (int) ($inspection['invoice_balance_minor'] ?? 0),
                        'invoice_balance_formatted' => (string) ($inspection['invoice_balance_formatted'] ?? '0.00'),
                        'invoice_status' => (string) ($inspection['invoice_status'] ?? 'N/A'),
                    ];
                    $afterSnapshot = array_merge($stateSnapshot, ['receipt_status' => 'Disputed']);

                    $correctionId = $this->insertImmutableCorrectionRecord(
                        $db,
                        $schema,
                        $inspection,
                        $nextSeq,
                        $prevCorrId,
                        self::ACTION_MARK_DISPUTED,
                        self::STATUS_DISPUTED,
                        null,
                        $stateSnapshot,
                        $afterSnapshot,
                        $reason,
                        $actorId,
                        null,
                        null,
                        null,
                        null,
                        null,
                        0,
                        (int) ($inspection['previously_reversed_minor'] ?? 0),
                        (int) ($inspection['effective_paid_minor'] ?? $inspection['amount_minor'])
                    );
                }

                $sourceType = 'myskool_collection';
                $slipNo = (string) ($inspection['slip_number'] ?? '');
                $sourceRow = $inspection['source_row'] ?? null;
                $studentId = $inspection['student_id'] ?? null;
                $studentGr = (string) ($inspection['student_gr'] ?? '');
                $studentName = (string) ($inspection['student_name'] ?? '');
                $familyId = $inspection['family_id'] ?? null;
                $familyCode = (string) ($inspection['family_code'] ?? '');
                $feeType = (string) ($inspection['fee_type'] ?? ($inspection['fee_type_label'] ?? 'Monthly Fee'));
                $feeParticular = (string) ($inspection['fee_particular'] ?? $feeType);
                $billingMonth = (string) ($inspection['billing_month'] ?? ($inspection['period_label'] ?? 'N/A'));
                $periodKey = (string) ($inspection['period_key'] ?? '');
                $currency = (string) ($inspection['currency'] ?? 'SAR');
                $amountMinor = (int) ($inspection['amount_minor'] ?? 0);
                $linkedItemId = $inspection['invoice_item_id'] ?? $invoiceItemId;
                $linkedInvId = $inspection['invoice_id'] ?? null;
                $batchId = (string) ($inspection['batch_id'] ?: config('imports.active_batch_id', 'BATCH-20261025-FINAL-MYSKOOL'));
                $origLabel = "MySkool Slip #{$slipNo} (Row #{$stagedColId})";
            } else {
                $itemInspect = $this->inspectInvoiceItemForMySkool((int) $invoiceItemId, $conn);
                if (empty($itemInspect['exists'])) {
                    throw ValidationException::withMessages([
                        'invoice_item_id' => [$itemInspect['error'] ?? "Invoice item #{$invoiceItemId} not found."],
                    ]);
                }
                $sourceType = 'myskool_invoice_item';
                $slipNo = (string) ($itemInspect['invoice_number'] ?? '');
                $sourceRow = null;
                $studentId = $itemInspect['student_id'] ?? null;
                $studentGr = (string) ($itemInspect['student_gr'] ?? '');
                $studentName = (string) ($itemInspect['student_name'] ?? '');
                $familyId = $itemInspect['family_id'] ?? null;
                $familyCode = (string) ($itemInspect['family_code'] ?? '');
                $feeType = (string) ($itemInspect['fee_type'] ?? 'Monthly Fee');
                $feeParticular = (string) ($itemInspect['fee_particular'] ?? $feeType);
                $billingMonth = (string) ($itemInspect['billing_month'] ?? 'N/A');
                $periodKey = (string) ($itemInspect['period_key'] ?? '');
                $currency = (string) ($itemInspect['currency'] ?? 'SAR');
                $amountMinor = (int) ($itemInspect['amount_minor'] ?? 0);
                $linkedItemId = (int) $invoiceItemId;
                $linkedInvId = $itemInspect['invoice_id'] ?? null;
                $batchId = (string) config('imports.active_batch_id', 'BATCH-20261025-FINAL-MYSKOOL');
                $origLabel = "Invoice #{$slipNo} (Fee Item #{$invoiceItemId})";
            }

            $now = now();
            $disputeCode = sprintf(
                'MSDISP-%s-%s',
                $stagedColId ? ('C' . $stagedColId) : ('I' . $invoiceItemId),
                strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 6))
            );

            $disputeId = null;
            if ($schema->hasTable('myskool_disputes')) {
                $disputeId = (int) $db->table('myskool_disputes')->insertGetId([
                    'dispute_code' => $disputeCode,
                    'batch_id' => $batchId,
                    'source_type' => $sourceType,
                    'qa_staged_collection_id' => $stagedColId,
                    'invoice_item_id' => $linkedItemId ? (int) $linkedItemId : null,
                    'invoice_id' => $linkedInvId ? (int) $linkedInvId : null,
                    'slip_number' => $slipNo,
                    'source_row' => $sourceRow ? (int) $sourceRow : null,
                    'student_id' => $studentId ? (int) $studentId : null,
                    'student_gr' => $studentGr,
                    'student_name' => $studentName,
                    'family_id' => $familyId ? (int) $familyId : null,
                    'family_code' => $familyCode,
                    'fee_type' => $feeType,
                    'fee_particular' => $feeParticular,
                    'billing_month' => $billingMonth,
                    'period_key' => $periodKey,
                    'currency' => $currency,
                    'amount_minor' => $amountMinor,
                    'original_record_label' => $origLabel,
                    'reason' => $reason,
                    'status' => 'open',
                    'correction_id' => $correctionId,
                    'submitted_by' => $actorId,
                    'submitted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if ($schema->hasTable('audit_logs')) {
                $db->table('audit_logs')->insert([
                    'actor_id' => $actorId,
                    'action' => 'myskool_dispute_submitted',
                    'subject_type' => $stagedColId ? 'qa_staged_collection' : 'invoice_item',
                    'subject_id' => $stagedColId ?: $invoiceItemId,
                    'metadata' => json_encode([
                        'dispute_id' => $disputeId,
                        'dispute_code' => $disputeCode,
                        'student_gr' => $studentGr,
                        'student_name' => $studentName,
                        'fee_type' => $feeType,
                        'billing_month' => $billingMonth,
                        'currency' => $currency,
                        'amount_minor' => $amountMinor,
                        'original_record_label' => $origLabel,
                        'reason' => $reason,
                        'financial_balance_change' => 0,
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return [
                'status' => 'success',
                'message' => "Dispute case {$disputeCode} created for {$origLabel} (Records preserved, no balance change).",
                'dispute_id' => $disputeId,
                'dispute_code' => $disputeCode,
                'correction_id' => $correctionId,
                'source_type' => $sourceType,
                'qa_staged_collection_id' => $stagedColId,
                'invoice_item_id' => $linkedItemId,
                'student_gr' => $studentGr,
                'student_name' => $studentName,
                'family_code' => $familyCode,
                'fee_type' => $feeType,
                'billing_month' => $billingMonth,
                'currency' => $currency,
                'amount_minor' => $amountMinor,
                'amount_formatted' => number_format($amountMinor / 100, 2, '.', ''),
                'original_record_label' => $origLabel,
                'reason' => $reason,
                'dispute_status' => 'open',
            ];
        });
    }

    /**
     * Phase 3E (Task 3): Review, approve, reject, or resolve a MySkool dispute case.
     * Makes ZERO automatic financial balance changes (any financial restoration following approval
     * requires its own explicit authorized Restore action).
     */
    public function updateDisputeStatus(int $disputeId, array $input, ?int $userId = null, ?string $conn = null): array
    {
        $conn = $conn ?: config('database.default', 'mysql');
        $db = DB::connection($conn);
        $schema = $db->getSchemaBuilder();

        try {
            $actorId = app(HistoricalPaymentReconciliationService::class)->resolveAuthorizedActor($db, $userId);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'authorization' => [$e->getMessage()],
            ]);
        }

        $newStatus = strtolower(trim((string) ($input['status'] ?? '')));
        $validStatuses = ['under_review', 'approved', 'rejected', 'resolved'];
        if (!in_array($newStatus, $validStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => ["Invalid dispute status '{$newStatus}'. Must be one of: " . implode(', ', $validStatuses)],
            ]);
        }

        $notes = trim((string) ($input['review_notes'] ?? ($input['notes'] ?? '')));

        if (!$schema->hasTable('myskool_disputes')) {
            throw ValidationException::withMessages([
                'dispute' => ['Disputes table does not exist.'],
            ]);
        }

        return $db->transaction(function () use ($db, $schema, $disputeId, $newStatus, $notes, $actorId) {
            $dispute = $db->table('myskool_disputes')->where('id', $disputeId)->lockForUpdate()->first();
            if (!$dispute) {
                throw ValidationException::withMessages([
                    'dispute_id' => ["Dispute case #{$disputeId} not found."],
                ]);
            }

            $now = now();
            $updateData = [
                'status' => $newStatus,
                'reviewed_by' => $actorId,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ];
            if ($notes !== '') {
                $updateData['review_notes'] = $notes;
            }
            if ($newStatus === 'resolved') {
                $updateData['resolved_by'] = $actorId;
                $updateData['resolved_at'] = $now;
            }

            $db->table('myskool_disputes')->where('id', $disputeId)->update($updateData);

            if ($schema->hasTable('audit_logs')) {
                $db->table('audit_logs')->insert([
                    'actor_id' => $actorId,
                    'action' => 'myskool_dispute_' . $newStatus,
                    'subject_type' => 'myskool_dispute',
                    'subject_id' => $disputeId,
                    'metadata' => json_encode([
                        'dispute_code' => $dispute->dispute_code,
                        'previous_status' => $dispute->status,
                        'new_status' => $newStatus,
                        'review_notes' => $notes,
                        'financial_balance_change' => 0,
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return [
                'status' => 'success',
                'message' => "Dispute case {$dispute->dispute_code} updated to status '" . ucfirst(str_replace('_', ' ', $newStatus)) . "'. No automatic balance changes made.",
                'dispute_id' => $disputeId,
                'dispute_code' => (string) $dispute->dispute_code,
                'new_status' => $newStatus,
                'review_notes' => $notes,
            ];
        });
    }
}
