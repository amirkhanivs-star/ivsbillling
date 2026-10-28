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
    public const ACTION_REAPPLY_SETTLEMENT = 'reapply_settlement';
    public const ACTION_MARK_UNDER_REVIEW = 'mark_under_review';
    public const ACTION_MARK_DISPUTED = 'mark_disputed';
    public const ACTION_MARK_CORRECTED = 'mark_corrected';
    public const ACTION_PROPOSE_REASSIGNMENT = 'propose_reassignment';
    public const ACTION_PROPOSE_PRE_CUTOFF_COMPENSATION = 'propose_pre_cutoff_compensation';

    // Resulting statuses
    public const STATUS_RECONCILED = 'reconciled';
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
        if ($hasCorrectionsTable) {
            $corrRows = $db->table('qa_myskool_payment_corrections as mc')
                ->leftJoin('users as u', 'u.id', '=', 'mc.acted_by')
                ->where('mc.qa_staged_collection_id', $stagedCollectionId)
                ->orderBy('mc.sequence_no')
                ->orderBy('mc.id')
                ->select('mc.*', 'u.name as actor_name', 'u.email as actor_email', 'u.role as actor_role')
                ->get();

            foreach ($corrRows as $cr) {
                $entry = [
                    'id' => (int) $cr->id,
                    'correction_id' => (string) $cr->correction_id,
                    'sequence_no' => (int) $cr->sequence_no,
                    'action_type' => (string) $cr->action_type,
                    'resulting_status' => (string) $cr->resulting_status,
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
                    'before_state' => json_decode((string) $cr->before_state_json, true) ?: [],
                    'after_state' => json_decode((string) $cr->after_state_json, true) ?: [],
                ];
                $correctionHistory[] = $entry;
                $latestCorrection = $entry;
            }
        }

        // 5. Inspect shared-slip sibling allocations (Case C: e.g. Slip 27328 for GR 5929 and GR 5930)
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
                $srStudentName = $srGr === $studentGr
                    ? $studentName
                    : ($schema->hasTable('students')
                        ? ((string) ($db->table('students')->where('gr_number', $srGr)->value('name') ?: ($sr->student_name ?? $srGr)))
                        : (string) ($sr->student_name ?? $srGr));

                $srSettlementStatus = 'unreconciled';
                $srReconId = null;
                $srItemId = null;
                if ($hasSettlementColsTable) {
                    $srAlloc = $db->table('qa_historical_settlement_collections')
                        ->where('qa_staged_collection_id', $srId)
                        ->orderByDesc('id')
                        ->first();
                    if ($srAlloc) {
                        $srSettlementStatus = (string) $srAlloc->status;
                        $srReconId = (string) $srAlloc->reconciliation_id;
                        $srItemId = (int) $srAlloc->invoice_item_id;
                    }
                } elseif ($hasSettlementsTable) {
                    $srSet = $db->table('qa_historical_settlements')
                        ->where('active_primary_collection_id', $srId)
                        ->first();
                    if ($srSet) {
                        $srSettlementStatus = (string) $srSet->status;
                        $srReconId = (string) $srSet->reconciliation_id;
                        $srItemId = (int) $srSet->invoice_item_id;
                    }
                }

                $srCorrStatus = null;
                if ($hasCorrectionsTable) {
                    $srCorrStatus = $db->table('qa_myskool_payment_corrections')
                        ->where('qa_staged_collection_id', $srId)
                        ->orderByDesc('sequence_no')
                        ->orderByDesc('id')
                        ->value('resulting_status');
                }

                $sharedSlipAllocations[] = [
                    'qa_staged_collection_id' => $srId,
                    'source_row' => $sr->source_row !== null ? (int) $sr->source_row : null,
                    'is_current_row' => $srId === $stagedCollectionId,
                    'student_gr' => $srGr,
                    'student_name' => $srStudentName,
                    'fee_particular' => (string) ($sr->fee_particular ?? ''),
                    'currency' => strtoupper(trim((string) ($sr->currency ?? $colCurrency))),
                    'amount_minor' => (int) ($sr->amount_minor ?? 0),
                    'amount_formatted' => number_format(((int) ($sr->amount_minor ?? 0)) / 100, 2, '.', ''),
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
            // Fallback to opening balance item if period is pre-cutoff or Oct-2026
            if (!$item && $studentItems->isNotEmpty()) {
                $obCandidate = $studentItems->first(fn($c) => ($c->billing_run_frequency ?? '') === 'opening_balance');
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

        // 7. Compute student & family total outstanding balances and currency expectations
        $studentExpectedCurrency = null;
        $studentTotalOutstandingMinor = 0;
        $familyTotalOutstandingMinor = 0;
        $portalReceiptsCount = 0;

        if ($studentId && $schema->hasTable('invoice_items') && $schema->hasTable('invoices')) {
            $stuActiveItems = $db->table('invoice_items as ii')
                ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
                ->where('ii.student_id', $studentId)
                ->where('ii.status', '!=', 'voided')
                ->whereNotIn('inv.status', ['void', 'voided'])
                ->get(['ii.currency', 'ii.net_minor', 'ii.paid_minor']);

            foreach ($stuActiveItems as $sai) {
                if ($studentExpectedCurrency === null && !empty($sai->currency)) {
                    $studentExpectedCurrency = strtoupper(trim((string) $sai->currency));
                }
                $studentTotalOutstandingMinor += max(0, (int) $sai->net_minor - (int) $sai->paid_minor);
            }
        }

        if ($studentExpectedCurrency === null && $studentId && $schema->hasTable('fee_plan_items')) {
            $fpCurr = $db->table('fee_plan_items')->where('student_id', $studentId)->value('currency');
            if ($fpCurr) {
                $studentExpectedCurrency = strtoupper(trim((string) $fpCurr));
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

        // 8. Check currency conflict (Rule 9 & Task 6 GR 8620)
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

        // 10. Determine current receipt status & case classification (Cases A - E)
        $isReconciled = ($activeSettlement !== null);
        $currentReceiptStatus = self::STATUS_UNRECONCILED_POST_CUTOFF;
        $currentStatusLabel = 'Unreconciled (Post-Cutoff)';

        if ($latestCorrection) {
            $corrResult = (string) $latestCorrection['resulting_status'];
            if ($corrResult === self::STATUS_REVERSED && !$isReconciled) {
                $currentReceiptStatus = self::STATUS_REVERSED;
                $currentStatusLabel = 'Reversed';
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
                $currentReceiptStatus = self::STATUS_RECONCILED;
                $currentStatusLabel = $corrResult === self::STATUS_REAPPLIED ? 'Reconciled (Reapplied)' : 'Reconciled';
            }
        } else {
            if ($isReconciled) {
                $currentReceiptStatus = self::STATUS_RECONCILED;
                $currentStatusLabel = 'Reconciled';
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

        // 11. Evaluate safety rules for reversal, reapplication, and review/dispute actions
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
            } else {
                $isSafeToReverse = true;
                $safetyStatus = self::SAFETY_SAFE_REVERSIBLE;
                $safetyExplanation = $isSharedSlip
                    ? "Safe to reverse this individual source allocation (Row #{$stagedCollectionId} for GR {$studentGr}) without affecting sibling allocations on shared slip #{$slipNumber}."
                    : "Safe to reverse historical settlement '{$activeSettlement->reconciliation_id}' and restore the unpaid invoice balance on {$invoice->invoice_number}.";
                $availableActions = [
                    self::ACTION_REVERSE_SETTLEMENT,
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
                    ? min($colAmountMinor, (int) $reversedSettlement->settled_amount_minor)
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
            // Case B (Pre-Cutoff): already accounted for in the opening-balance snapshot
            $safetyStatus = self::SAFETY_BLOCKED_PRE_CUTOFF_SNAPSHOT;
            $reasonMsg = "Pre-cutoff receipt ({$paymentDate} <= cutoff {$cutoffDate}): This payment was already netted into the opening-balance snapshot compilation before the migration cutoff. Direct settlement reversal or blind modification of invoice_items.paid_minor is blocked. Use the manual-review or pre-cutoff compensation proposal workflow after verifying original MySkool dues.";
            $blockedReasons[] = $reasonMsg;
            $safetyExplanation = $reasonMsg;
            $availableActions = [
                self::ACTION_MARK_UNDER_REVIEW,
                self::ACTION_MARK_DISPUTED,
                self::ACTION_PROPOSE_PRE_CUTOFF_COMPENSATION,
                self::ACTION_PROPOSE_REASSIGNMENT,
            ];
        } else {
            // Case B (Post-Cutoff Unreconciled) or Case D
            $safetyStatus = self::SAFETY_SAFE_REVIEW_ONLY;
            $reasonMsg = "This post-cutoff MySkool receipt is not currently reconciled to an active invoice settlement. Direct settlement reversal is not applicable; you may mark it Under Review, Disputed, Corrected, or propose reassignment.";
            $blockedReasons[] = $reasonMsg;
            $safetyExplanation = $reasonMsg;
            $availableActions = [
                self::ACTION_MARK_UNDER_REVIEW,
                self::ACTION_MARK_DISPUTED,
                self::ACTION_MARK_CORRECTED,
                self::ACTION_PROPOSE_REASSIGNMENT,
            ];
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

        // Reversal delta calculation (supports both single-collection and multi-collection settlements)
        $reversalDeltaMinor = 0;
        if ($activeSettlement) {
            $reversalDeltaMinor = $activeSettlementColCount > 1
                ? min($colAmountMinor, (int) $activeSettlement->settled_amount_minor)
                : (int) $activeSettlement->settled_amount_minor;
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
                (int) $reversedSettlement->settled_amount_minor,
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
            'fee_type_code' => $feeTypeCode,
            'fee_type_label' => $feeTypeLabel,
            'period_key' => $periodKey ?: 'N/A',
            'period_start' => $periodStart,
            'period_label' => $periodLabel,
            // 6. Currency
            'currency' => $colCurrency,
            'student_expected_currency' => $studentExpectedCurrency ?: $colCurrency,
            'has_currency_conflict' => $hasCurrencyConflict,
            // 7. Original receipt amount
            'amount_minor' => $colAmountMinor,
            'amount_formatted' => number_format($colAmountMinor / 100, 2, '.', ''),
            // 8. Historical payment date
            'payment_date' => $paymentDate,
            'payment_date_formatted' => !empty($col->payment_date_parsed)
                ? date('d M Y', strtotime((string) $col->payment_date_parsed))
                : $paymentDate,
            'cutoff_date' => $cutoffDate,
            'is_pre_cutoff' => $isPreCutoff,
            // 9. Whether the receipt is already reconciled to an invoice
            'is_reconciled' => $isReconciled,
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
     * Execute an authorized, audited correction or reversal on a MySkool staged collection row.
     * Enforces all 15 financial safety rules from Task 4.
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
                        $actorId
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
        int $actorId
    ): array {
        $stagedCollectionId = (int) $inspection['qa_staged_collection_id'];
        $reconId = (string) $inspection['reconciliation_id'];
        $itemId = (int) $inspection['invoice_item_id'];
        $invoiceId = (int) $inspection['invoice_id'];
        $effect = $inspection['proposed_effects'][self::ACTION_REVERSE_SETTLEMENT];

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

        if ($activeColCount <= 1) {
            // Standard Case A & Case C (where each student/item has its own settlement record):
            // Delegate directly to HistoricalPaymentReconciliationService::reverse()
            app(HistoricalPaymentReconciliationService::class)->reverse($reconId, $actorId, $conn);
        } else {
            // Partial multi-collection reversal on a single invoice item where multiple MySkool slips were combined
            $reversalDelta = (int) $effect['delta_minor'];
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
            $db->table('qa_historical_settlements')
                ->where('id', $settlement->id)
                ->update([
                    'settled_amount_minor' => $newSettledMinor,
                    'remaining_obligation_minor' => max(0, (int) $settlement->original_obligation_minor - $newItemPaidMinor),
                    'new_item_paid_minor' => $newItemPaidMinor,
                    'new_item_status' => $newItemStatus,
                    'new_invoice_paid_minor' => $newInvPaidMinor,
                    'new_invoice_status' => $newInvStatus,
                    'active_primary_collection_id' => $remainingActiveColId ? (int) $remainingActiveColId : null,
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
            $actorId
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
                if (!$targetCurrency && $schema->hasTable('fee_plan_items')) {
                    $targetCurrency = $db->table('fee_plan_items')
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
        ?int $proposedCompensationMinor = null
    ): string {
        $stagedCollectionId = (int) $inspection['qa_staged_collection_id'];
        $correctionId = sprintf(
            'MSCORR-%d-S%d-%s',
            $stagedCollectionId,
            $sequenceNo,
            substr(md5($stagedCollectionId . '-' . $sequenceNo . '-' . microtime()), 0, 8)
        );

        $now = now();
        $db->table('qa_myskool_payment_corrections')->insert([
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
        ]);

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
}
