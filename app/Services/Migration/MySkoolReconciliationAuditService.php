<?php

namespace App\Services\Migration;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class MySkoolReconciliationAuditService
{
    public const DEFAULT_CUTOFF_DATE = '2026-10-22';

    // Granular reconciliation classifications
    public const CLASS_PRE_CUTOFF_RESIDUAL = 'PRE_CUTOFF_RESIDUAL_BALANCE';
    public const CLASS_POST_CUTOFF_FULL = 'POST_CUTOFF_FULL_SETTLEMENT';
    public const CLASS_POST_CUTOFF_PARTIAL = 'POST_CUTOFF_PARTIAL_PAYMENT';
    public const CLASS_POST_CUTOFF_CONCESSION = 'POST_CUTOFF_SIBLING_CONCESSION';
    public const CLASS_CONFLICT_PORTAL_ACTIVITY = 'CONFLICT_EXISTING_PORTAL_ACTIVITY';
    public const CLASS_REVIEW_CURRENCY_MISMATCH = 'MANUAL_REVIEW_CURRENCY_MISMATCH';
    public const CLASS_REVIEW_AMBIGUOUS_MATCH = 'MANUAL_REVIEW_AMBIGUOUS_MATCH';
    public const CLASS_REVIEW_MISSING_EVIDENCE = 'MANUAL_REVIEW_MISSING_EVIDENCE';
    public const CLASS_REVIEW_OVERPAYMENT = 'MANUAL_REVIEW_OVERPAYMENT';

    // Standard guarded reconciliation classes (Phase 2B specification)
    public const GUARD_KEEP_GENUINE_RESIDUAL = 'KEEP_GENUINE_RESIDUAL';
    public const GUARD_CANDIDATE_FULL_SETTLEMENT = 'CANDIDATE_FULL_SETTLEMENT';
    public const GUARD_CANDIDATE_PARTIAL_SETTLEMENT = 'CANDIDATE_PARTIAL_SETTLEMENT';
    public const GUARD_CONCESSION_REVIEW = 'CONCESSION_REVIEW';
    public const GUARD_CURRENCY_CONFLICT_HOLD = 'CURRENCY_CONFLICT_HOLD';
    public const GUARD_DUPLICATE_SOURCE_HOLD = 'DUPLICATE_SOURCE_HOLD';
    public const GUARD_LIVE_ACTIVITY_HOLD = 'LIVE_ACTIVITY_HOLD';
    public const GUARD_INSUFFICIENT_EVIDENCE_HOLD = 'INSUFFICIENT_EVIDENCE_HOLD';

    /**
     * Map granular reconciliation classification to guarded reconciliation class.
     */
    public static function mapToGuardClass(string $classification): string
    {
        return match ($classification) {
            self::CLASS_PRE_CUTOFF_RESIDUAL => self::GUARD_KEEP_GENUINE_RESIDUAL,
            self::CLASS_POST_CUTOFF_FULL => self::GUARD_CANDIDATE_FULL_SETTLEMENT,
            self::CLASS_POST_CUTOFF_PARTIAL => self::GUARD_CANDIDATE_PARTIAL_SETTLEMENT,
            self::CLASS_POST_CUTOFF_CONCESSION => self::GUARD_CONCESSION_REVIEW,
            self::CLASS_CONFLICT_PORTAL_ACTIVITY => self::GUARD_LIVE_ACTIVITY_HOLD,
            self::CLASS_REVIEW_CURRENCY_MISMATCH => self::GUARD_CURRENCY_CONFLICT_HOLD,
            self::CLASS_REVIEW_AMBIGUOUS_MATCH => self::GUARD_DUPLICATE_SOURCE_HOLD,
            self::CLASS_REVIEW_MISSING_EVIDENCE => self::GUARD_INSUFFICIENT_EVIDENCE_HOLD,
            self::CLASS_REVIEW_OVERPAYMENT => self::GUARD_CONCESSION_REVIEW,
            default => self::GUARD_INSUFFICIENT_EVIDENCE_HOLD,
        };
    }

    /**
     * Execute a strictly read-only reconciliation audit between unpaid/active opening-balance
     * invoice items and staged MySkool historical collections.
     */
    public function audit(
        ?string $conn = null,
        ?string $batchId = null,
        string $cutoffDate = self::DEFAULT_CUTOFF_DATE
    ): array {
        $conn = $conn ?: config('database.default', 'mysql');
        $db = DB::connection($conn);
        $schema = $db->getSchemaBuilder();

        // 1. Fail-closed verification of required schema tables
        $requiredTables = [
            'invoices',
            'invoice_items',
            'billing_runs',
            'students',
            'families',
            'qa_staged_collections',
            'payments',
            'payment_allocations',
            'fee_adjustments',
        ];

        foreach ($requiredTables as $table) {
            if (!$schema->hasTable($table)) {
                throw new RuntimeException(
                    "CRITICAL AUDIT ERROR: Required table '{$table}' does not exist on connection '{$conn}'. " .
                    "Cannot guess reconciliation results without complete schema evidence."
                );
            }
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoffDate)) {
            throw new RuntimeException("CRITICAL AUDIT ERROR: Invalid cutoff date format '{$cutoffDate}'. Expected YYYY-MM-DD.");
        }

        // Resolve batch ID from explicit argument, lineage table, or config
        $resolvedBatchId = $this->resolveBatchId($db, $schema, $batchId);

        // Verify staged collections exist for the resolved batch
        $totalStagedCollections = $db->table('qa_staged_collections')
            ->where('batch_id', $resolvedBatchId)
            ->count();

        if ($totalStagedCollections === 0) {
            throw new RuntimeException(
                "CRITICAL AUDIT ERROR: Zero staged collection records found in 'qa_staged_collections' for batch '{$resolvedBatchId}'. " .
                "Missing source collection evidence — refusing to produce guessed results."
            );
        }

        $hasLineageTable = $schema->hasTable('qa_opening_balance_lineage');
        $hasStagedDuesTable = $schema->hasTable('qa_staged_dues');
        $hasFeeTypesTable = $schema->hasTable('fee_types');
        $hasEnrollmentsTable = $schema->hasTable('enrollments');

        // 2. Fetch all opening-balance invoice items in a single bounded query
        $obQuery = $db->table('invoice_items as ii')
            ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
            ->join('billing_runs as br', 'br.id', '=', 'inv.billing_run_id')
            ->leftJoin('students as s', 's.id', '=', 'ii.student_id')
            ->leftJoin('families as f', 'f.id', '=', 'inv.family_id')
            ->where('br.frequency', 'opening_balance');

        $selectCols = [
            'ii.id as item_id',
            'ii.invoice_id',
            'ii.student_id',
            'ii.enrollment_id',
            'ii.fee_type_id',
            'ii.description',
            'ii.period_key',
            'ii.period_start',
            'ii.period_end',
            'ii.currency as item_currency',
            'ii.amount_minor',
            'ii.discount_minor',
            'ii.exemption_minor',
            'ii.net_minor',
            'ii.paid_minor as item_paid_minor',
            'ii.status as item_status',
            'inv.invoice_number',
            'inv.family_id as invoice_family_id',
            'inv.department_id',
            'inv.campus_id',
            'inv.currency as invoice_currency',
            'inv.total_minor as invoice_total_minor',
            'inv.paid_minor as invoice_paid_minor',
            'inv.status as invoice_status',
            'br.id as billing_run_id',
            'br.status as billing_run_status',
            's.gr_number as student_gr',
            's.name as student_name',
            'f.family_code as family_table_code',
            'f.is_provisional as family_is_provisional',
        ];

        if ($hasEnrollmentsTable) {
            $obQuery->leftJoin('enrollments as e', 'e.id', '=', 'ii.enrollment_id');
            $selectCols[] = 'e.monthly_fee_minor as enrollment_monthly_fee_minor';
        }

        if ($hasFeeTypesTable) {
            $obQuery->leftJoin('fee_types as ft', 'ft.id', '=', 'ii.fee_type_id');
            $selectCols[] = 'ft.code as fee_type_code';
        }

        if ($hasLineageTable) {
            $obQuery->leftJoin('qa_opening_balance_lineage as obl', 'obl.invoice_item_id', '=', 'ii.id');
            $selectCols[] = 'obl.gr_number as lineage_gr';
            $selectCols[] = 'obl.qa_staged_due_id';
            $selectCols[] = 'obl.batch_id as lineage_batch_id';

            $hasBatchLineage = $db->table('qa_opening_balance_lineage')
                ->where('batch_id', $resolvedBatchId)
                ->exists();
            if ($hasBatchLineage) {
                $obQuery->where(function ($q) use ($resolvedBatchId) {
                    $q->where('obl.batch_id', $resolvedBatchId)
                        ->orWhereNull('obl.batch_id');
                });
            }

            if ($hasStagedDuesTable) {
                $obQuery->leftJoin('qa_staged_dues as qsd', 'qsd.id', '=', 'obl.qa_staged_due_id');
                $selectCols[] = 'qsd.family_code_final as staged_due_family_code';
            }
        }

        $obItems = $obQuery
            ->select($selectCols)
            ->orderBy('ii.id')
            ->get();

        if ($obItems->isEmpty()) {
            return $this->buildEmptyResult($conn, $resolvedBatchId, $cutoffDate, $totalStagedCollections);
        }

        // 3. Preload existing portal payment allocations and fee adjustments for these OB items (no N+1)
        $obItemIds = $obItems->pluck('item_id')->all();

        $allocationsByItem = collect();
        foreach (array_chunk($obItemIds, 1000) as $chunk) {
            $rows = $db->table('payment_allocations as pa')
                ->join('payments as p', 'p.id', '=', 'pa.payment_id')
                ->whereIn('pa.invoice_item_id', $chunk)
                ->select(
                    'pa.id',
                    'pa.invoice_item_id',
                    'pa.payment_id',
                    'pa.amount_minor',
                    'p.receipt_number',
                    'p.status as payment_status',
                    'p.received_on'
                )
                ->get();
            foreach ($rows as $r) {
                if (!$allocationsByItem->has($r->invoice_item_id)) {
                    $allocationsByItem->put($r->invoice_item_id, collect());
                }
                $allocationsByItem->get($r->invoice_item_id)->push($r);
            }
        }

        $adjustmentsByItem = collect();
        foreach (array_chunk($obItemIds, 1000) as $chunk) {
            $rows = $db->table('fee_adjustments')
                ->whereIn('invoice_item_id', $chunk)
                ->select('id', 'invoice_item_id', 'kind', 'amount_minor', 'reason')
                ->get();
            foreach ($rows as $r) {
                if (!$adjustmentsByItem->has($r->invoice_item_id)) {
                    $adjustmentsByItem->put($r->invoice_item_id, collect());
                }
                $adjustmentsByItem->get($r->invoice_item_id)->push($r);
            }
        }

        $hasSettlementsTable = $schema->hasTable('qa_historical_settlements');
        $settlementsByItem = collect();
        if ($hasSettlementsTable) {
            foreach (array_chunk($obItemIds, 1000) as $chunk) {
                $rows = $db->table('qa_historical_settlements')
                    ->whereIn('invoice_item_id', $chunk)
                    ->where('status', 'applied')
                    ->get();
                foreach ($rows as $r) {
                    if (!$settlementsByItem->has($r->invoice_item_id)) {
                        $settlementsByItem->put($r->invoice_item_id, collect());
                    }
                    $settlementsByItem->get($r->invoice_item_id)->push($r);
                }
            }
        }

        // 4. Preload staged collections for all relevant GR numbers in this batch
        $relevantGrs = $obItems->map(function ($it) {
            return trim((string) (($it->lineage_gr ?? '') !== '' ? $it->lineage_gr : ($it->student_gr ?? '')));
        })->filter()->unique()->values()->all();

        $stagedCollections = collect();
        foreach (array_chunk($relevantGrs, 1000) as $grChunk) {
            $rows = $db->table('qa_staged_collections')
                ->where('batch_id', $resolvedBatchId)
                ->whereIn('gr_number', $grChunk)
                ->orderBy('payment_date_parsed')
                ->orderBy('source_row')
                ->orderBy('id')
                ->get();
            $stagedCollections = $stagedCollections->concat($rows);
        }

        $collectionsByGr = $stagedCollections->groupBy(fn($c) => trim((string) $c->gr_number));

        // Preload slip sibling rows for post-cutoff slips to verify sibling concessions without N+1
        $postCutoffSlips = $stagedCollections
            ->filter(fn($c) => !empty($c->payment_date_parsed) && $c->payment_date_parsed > $cutoffDate && !empty($c->slip_number))
            ->pluck('slip_number')
            ->map(fn($s) => trim((string) $s))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $collectionsBySlip = collect();
        if (!empty($postCutoffSlips)) {
            foreach (array_chunk($postCutoffSlips, 500) as $slipChunk) {
                $rows = $db->table('qa_staged_collections')
                    ->where('batch_id', $resolvedBatchId)
                    ->whereIn('slip_number', $slipChunk)
                    ->get();
                foreach ($rows as $r) {
                    $sl = trim((string) $r->slip_number);
                    if (!$collectionsBySlip->has($sl)) {
                        $collectionsBySlip->put($sl, collect());
                    }
                    $collectionsBySlip->get($sl)->push($r);
                }
            }
        }

        // Preload student sibling counts by stable family_code (not auto-increment family_id)
        $familyStudentCountsByCode = $db->table('students as s')
            ->join('families as f', 'f.id', '=', 's.family_id')
            ->whereNotNull('f.family_code')
            ->where('f.family_code', '!=', '')
            ->select('f.family_code', DB::raw('COUNT(*) as cnt'))
            ->groupBy('f.family_code')
            ->pluck('cnt', 'family_code')
            ->all();

        // 5. Build obligation identity map for OB items to detect duplicate OB obligations vs genuine split residuals
        $obIdentityCounts = [];
        $obIdentityNetSums = [];
        foreach ($obItems as $item) {
            $gr = trim((string) (($item->lineage_gr ?? '') !== '' ? $item->lineage_gr : ($item->student_gr ?? '')));
            $identity = self::parseFeeIdentity((string) $item->description, $item->period_key ?? null, $item->fee_type_code ?? null);
            $identityKey = "{$gr}|{$identity['type']}|{$identity['period_key']}|{$identity['subject']}";
            $obIdentityCounts[$identityKey] = ($obIdentityCounts[$identityKey] ?? 0) + 1;
            $obIdentityNetSums[$identityKey] = ($obIdentityNetSums[$identityKey] ?? 0) + (int) $item->net_minor;
        }

        // 6. Preload subsequent (non-opening-balance) invoices to report affected snapshots (including November 2026)
        $subsequentInvoicesByFamilyCurrency = $db->table('invoices as inv')
            ->join('billing_runs as br', 'br.id', '=', 'inv.billing_run_id')
            ->leftJoin('families as f', 'f.id', '=', 'inv.family_id')
            ->where('br.frequency', '!=', 'opening_balance')
            ->whereNotIn('inv.status', ['void', 'voided'])
            ->select(
                'inv.id',
                'inv.invoice_number',
                'inv.family_id',
                'f.family_code',
                'inv.currency',
                'inv.total_minor',
                'inv.paid_minor',
                'inv.previous_dues_snapshot_minor',
                'inv.status',
                'br.id as billing_run_id',
                'br.frequency',
                'br.period_key',
                'br.period_start'
            )
            ->orderBy('br.period_start')
            ->orderBy('inv.id')
            ->get()
            ->groupBy(function ($inv) {
                $famCode = trim((string) ($inv->family_code ?? ''));
                $famKey = $famCode !== '' ? "CODE:{$famCode}" : "ID:{$inv->family_id}";
                return $famKey . '|' . strtoupper(trim((string) $inv->currency));
            });

        // 7. Evaluate each opening-balance item against staged MySkool collections
        $overlappingRecords = [];
        $summaryCounts = [
            'total_ob_items_scanned' => $obItems->count(),
            'unmatched_ob_items' => 0,
            'total_overlapping_items' => 0,
            'pre_cutoff_residuals' => 0,
            'post_cutoff_full_settlements' => 0,
            'post_cutoff_partial_or_concessions' => 0,
            'post_cutoff_partial_payments' => 0,
            'post_cutoff_sibling_concessions' => 0,
            'anomalies_and_conflicts_requiring_review' => 0,
        ];

        $classificationBreakdown = [
            self::CLASS_PRE_CUTOFF_RESIDUAL => 0,
            self::CLASS_POST_CUTOFF_FULL => 0,
            self::CLASS_POST_CUTOFF_PARTIAL => 0,
            self::CLASS_POST_CUTOFF_CONCESSION => 0,
            self::CLASS_CONFLICT_PORTAL_ACTIVITY => 0,
            self::CLASS_REVIEW_CURRENCY_MISMATCH => 0,
            self::CLASS_REVIEW_AMBIGUOUS_MATCH => 0,
            self::CLASS_REVIEW_MISSING_EVIDENCE => 0,
            self::CLASS_REVIEW_OVERPAYMENT => 0,
        ];

        $guardBreakdown = [
            self::GUARD_KEEP_GENUINE_RESIDUAL => 0,
            self::GUARD_CANDIDATE_FULL_SETTLEMENT => 0,
            self::GUARD_CANDIDATE_PARTIAL_SETTLEMENT => 0,
            self::GUARD_CONCESSION_REVIEW => 0,
            self::GUARD_CURRENCY_CONFLICT_HOLD => 0,
            self::GUARD_DUPLICATE_SOURCE_HOLD => 0,
            self::GUARD_LIVE_ACTIVITY_HOLD => 0,
            self::GUARD_INSUFFICIENT_EVIDENCE_HOLD => 0,
        ];

        $currencyBreakdown = [];
        $familyCurrencySettlementReductions = [];
        $familyCurrencyAllOverlaps = [];

        foreach ($obItems as $item) {
            $gr = trim((string) (($item->lineage_gr ?? '') !== '' ? $item->lineage_gr : ($item->student_gr ?? '')));
            $stableFamilyCode = $this->resolveStableFamilyCode($item);
            $itemIdentity = self::parseFeeIdentity((string) $item->description, $item->period_key ?? null, $item->fee_type_code ?? null);
            $identityKey = "{$gr}|{$itemIdentity['type']}|{$itemIdentity['period_key']}|{$itemIdentity['subject']}";

            $studentCols = $gr !== '' ? $collectionsByGr->get($gr, collect()) : collect();

            // Match by original student GR, fee identity (fee type + description), billing period, and subject
            $matchedCols = $studentCols->filter(function ($col) use ($item, $itemIdentity) {
                $colPart = trim((string) $col->fee_particular);
                $itemDesc = trim((string) $item->description);
                $colIdentity = self::parseFeeIdentity($colPart, null, null);

                if ($itemIdentity['period_key'] !== null || $colIdentity['period_key'] !== null) {
                    return $colIdentity['type'] === $itemIdentity['type']
                        && $colIdentity['period_key'] !== null
                        && $colIdentity['period_key'] === $itemIdentity['period_key']
                        && $colIdentity['subject'] === $itemIdentity['subject'];
                }

                return $colPart === $itemDesc && $colIdentity['type'] === $itemIdentity['type'];
            })->values();

            // Deduplicate exact duplicate staged collection rows to prevent double-counting
            [$dedupedCols, $hadDuplicateCollectionRows] = $this->deduplicateCollectionRows($matchedCols, (int) $item->net_minor);

            // Partition payments relative to cutoff date (<= cutoffDate vs > cutoffDate)
            $preCutoffCols = $dedupedCols->filter(fn($c) => !empty($c->payment_date_parsed) && $c->payment_date_parsed <= $cutoffDate);
            $postCutoffCols = $dedupedCols->filter(fn($c) => !empty($c->payment_date_parsed) && $c->payment_date_parsed > $cutoffDate);

            $preCutoffMinor = (int) $preCutoffCols->sum('amount_minor');
            $postCutoffMinor = (int) $postCutoffCols->sum('amount_minor');
            $netMinor = (int) $item->net_minor;

            $groupCount = (int) ($obIdentityCounts[$identityKey] ?? 1);
            $groupNetMinor = (int) ($obIdentityNetSums[$identityKey] ?? $netMinor);
            $enrollmentFeeMinor = (int) ($item->enrollment_monthly_fee_minor ?? 0);

            // A multi-line OB group is a genuine pre-cutoff split residual/fee (e.g., GR 4814 May-2026: AED 210 + AED 250 + AED 40 pre-cutoff paid = AED 500 monthly fee)
            // ONLY when no post-cutoff collections exist for the period AND the combined OB lines + pre-cutoff payment do not exceed the student's monthly fee.
            $isGenuineSplitResidual = ($groupCount > 1)
                && $itemIdentity['type'] === 'monthly'
                && $postCutoffCols->isEmpty()
                && $enrollmentFeeMinor > 0
                && ($groupNetMinor + $preCutoffMinor) <= $enrollmentFeeMinor;

            // Suspected duplicate source obligation (e.g., GR 6562 Oct-2026: AED 200 + AED 200 = AED 400 vs AED 200 monthly fee)
            // or ambiguous multi-item group where post-cutoff payments exist.
            $hasDuplicateObObligation = ($groupCount > 1) && !$isGenuineSplitResidual;

            $hasMissingStudentOrPeriod = ($gr === '' || $item->student_id === null || $itemIdentity['period_key'] === null);

            if ($matchedCols->isEmpty() && !$hasMissingStudentOrPeriod && !$hasDuplicateObObligation) {
                $summaryCounts['unmatched_ob_items']++;
                continue;
            }

            // Inspect existing portal payment / adjustment activity on the item and parent invoice
            $itemAllocs = $allocationsByItem->get($item->item_id, collect());
            $itemAdjs = $adjustmentsByItem->get($item->item_id, collect());
            $itemSettlements = $settlementsByItem->get($item->item_id, collect());

            $hasHistoricalSettlement = $itemSettlements->isNotEmpty();

            $hasPortalPayment = ($itemAllocs->isNotEmpty())
                || (!$hasHistoricalSettlement && ((int) $item->item_paid_minor > 0 || in_array($item->item_status, ['paid', 'partial'], true)));

            $hasPortalAdjustment = ((int) $item->discount_minor > 0)
                || ((int) $item->exemption_minor > 0)
                || $itemAdjs->isNotEmpty()
                || in_array($item->item_status, ['void', 'voided'], true)
                || in_array($item->invoice_status, ['void', 'voided'], true)
                || in_array($item->billing_run_status, ['void', 'voided'], true);

            $portalPaymentIndicator = $this->formatPortalPaymentIndicator($item, $itemAllocs, $hasPortalPayment);
            $portalAdjustmentIndicator = $this->formatPortalAdjustmentIndicator($item, $itemAdjs, $hasPortalAdjustment);
            $historicalSettlementIndicator = $hasHistoricalSettlement
                ? 'YES (' . $itemSettlements->pluck('reconciliation_id')->implode(', ') . ')'
                : 'NO';

            // Inspect evidence consistency: GR, stable family code, currency, payment dates
            $itemCurr = strtoupper(trim((string) $item->item_currency));
            $hasMissingPaymentDate = $dedupedCols->contains(fn($c) => empty($c->payment_date_parsed) || trim((string) $c->payment_date_parsed) === '');

            $hasCurrencyMismatch = $dedupedCols->contains(function ($c) use ($itemCurr) {
                $colCurr = strtoupper(trim((string) $c->currency));
                return $colCurr === ''
                    || $colCurr === 'UNRESOLVED'
                    || (bool) ($c->is_currency_unresolved ?? false)
                    || (bool) ($c->has_currency_conflict ?? false)
                    || $colCurr !== $itemCurr;
            });

            $hasFamilyCodeMismatch = $dedupedCols->contains(function ($c) use ($stableFamilyCode) {
                $colFam = trim((string) ($c->family_code ?? ''));
                if ($colFam === '' || $stableFamilyCode === '' || str_starts_with($stableFamilyCode, 'PROV-FAM-')) {
                    return false;
                }
                return strcasecmp($colFam, $stableFamilyCode) !== 0;
            });

            // Format slip references
            $slipRefs = $dedupedCols->map(function ($c) {
                $slip = trim((string) ($c->slip_number ?? '')) ?: 'NO-SLIP';
                $dt = $c->payment_date_parsed ?: ($c->payment_date_raw ?: 'UNKNOWN-DATE');
                $curr = strtoupper(trim((string) ($c->currency ?? '')));
                $amt = number_format(((int) $c->amount_minor) / 100, 2, '.', '');
                return "Slip {$slip} ({$dt}: {$curr} {$amt})";
            })->implode('; ');

            // Classification decision tree (strictly prioritizing conflict & safety checks first)
            $classification = '';
            $explanation = '';
            $potentialRemainingMinor = $netMinor;
            $safeReductionMinor = 0;

            if ($hasPortalPayment || $hasPortalAdjustment) {
                $classification = self::CLASS_CONFLICT_PORTAL_ACTIVITY;
                $reasons = [];
                if ($hasPortalPayment) {
                    $reasons[] = "existing portal payment activity ({$portalPaymentIndicator})";
                }
                if ($hasPortalAdjustment) {
                    $reasons[] = "existing portal fee adjustment/status activity ({$portalAdjustmentIndicator})";
                }
                $explanation = 'HOLD / CONFLICT: Item has ' . implode(' and ', $reasons) . '. Protected from automatic modification.';
                $summaryCounts['anomalies_and_conflicts_requiring_review']++;
            } elseif ($hasMissingStudentOrPeriod || $hasMissingPaymentDate) {
                $classification = self::CLASS_REVIEW_MISSING_EVIDENCE;
                $explanation = 'HOLD / MANUAL REVIEW: Missing or unverified student GR, billing period, or parsed payment date in source evidence.';
                $summaryCounts['anomalies_and_conflicts_requiring_review']++;
            } elseif ($hasCurrencyMismatch) {
                $classification = self::CLASS_REVIEW_CURRENCY_MISMATCH;
                $colCurrs = $dedupedCols->pluck('currency')->map(fn($c) => strtoupper(trim((string) $c)))->unique()->implode('/');
                $explanation = "HOLD / MANUAL REVIEW: Currency mismatch between opening balance item ({$itemCurr}) and staged MySkool collection ({$colCurrs}).";
                $summaryCounts['anomalies_and_conflicts_requiring_review']++;
            } elseif ($hasFamilyCodeMismatch || $hasDuplicateObObligation) {
                $classification = self::CLASS_REVIEW_AMBIGUOUS_MATCH;
                if ($hasFamilyCodeMismatch) {
                    $colFams = $dedupedCols->pluck('family_code')->filter()->unique()->implode('/');
                    $explanation = "HOLD / MANUAL REVIEW: Stable family code mismatch between opening balance ({$stableFamilyCode}) and staged collection ({$colFams}).";
                } elseif ($matchedCols->isEmpty()) {
                    $groupNetFormatted = number_format($groupNetMinor / 100, 2, '.', '');
                    $feeFormatted = $enrollmentFeeMinor > 0 ? number_format($enrollmentFeeMinor / 100, 2, '.', '') : 'N/A';
                    $explanation = "HOLD / MANUAL REVIEW: Suspected duplicate opening-balance items ({$groupCount} items totaling {$itemCurr} {$groupNetFormatted} vs {$itemCurr} {$feeFormatted} monthly fee) exist for GR {$gr} and '{$item->description}'. Held until actual duplication is confirmed.";
                } else {
                    $explanation = "HOLD / MANUAL REVIEW: Multiple opening-balance items ({$groupCount}) exist for GR {$gr} and '{$item->description}'. Held to prevent double-counting historical payment allocation across duplicate items.";
                }
                $summaryCounts['anomalies_and_conflicts_requiring_review']++;
            } elseif ($postCutoffCols->isEmpty()) {
                // Only pre-cutoff payments (<= cutoffDate) exist
                $classification = self::CLASS_PRE_CUTOFF_RESIDUAL;
                $preFormatted = number_format($preCutoffMinor / 100, 2, '.', '');
                $netFormatted = number_format($netMinor / 100, 2, '.', '');
                if ($isGenuineSplitResidual) {
                    $groupNetFormatted = number_format($groupNetMinor / 100, 2, '.', '');
                    $feeFormatted = number_format($enrollmentFeeMinor / 100, 2, '.', '');
                    $explanation = "PROTECTED RESIDUAL: Historical payment(s) of {$itemCurr} {$preFormatted} occurred on or before snapshot cutoff ({$cutoffDate}). Split opening-balance line of {$itemCurr} {$netFormatted} (part of {$itemCurr} {$groupNetFormatted} total residual against {$itemCurr} {$feeFormatted} monthly fee) is the genuine unpaid residual as of {$cutoffDate}.";
                } else {
                    $explanation = "PROTECTED RESIDUAL: Historical payment(s) of {$itemCurr} {$preFormatted} occurred on or before snapshot cutoff ({$cutoffDate}). Opening balance of {$itemCurr} {$netFormatted} is the genuine unpaid residual as of {$cutoffDate}.";
                }
                $potentialRemainingMinor = $netMinor;
                $summaryCounts['pre_cutoff_residuals']++;
            } elseif ($postCutoffMinor === $netMinor) {
                $classification = self::CLASS_POST_CUTOFF_FULL;
                $postFormatted = number_format($postCutoffMinor / 100, 2, '.', '');
                $explanation = "POST-CUTOFF FULL SETTLEMENT: Paid in full ({$itemCurr} {$postFormatted}) after {$cutoffDate} cutoff.";
                if ($preCutoffMinor > 0) {
                    $preFormatted = number_format($preCutoffMinor / 100, 2, '.', '');
                    $explanation .= " (After pre-cutoff partial payment of {$itemCurr} {$preFormatted}).";
                }
                if ($hadDuplicateCollectionRows) {
                    $explanation .= ' Duplicate staged collection line deduplicated.';
                }
                $potentialRemainingMinor = 0;
                $safeReductionMinor = $netMinor;
                $summaryCounts['post_cutoff_full_settlements']++;
            } elseif ($postCutoffMinor < $netMinor) {
                $potentialRemainingMinor = $netMinor - $postCutoffMinor;
                $safeReductionMinor = $postCutoffMinor;
                $isConcession = $this->isSiblingOrRecurringConcession(
                    $item,
                    $stableFamilyCode,
                    $studentCols,
                    $postCutoffCols,
                    $collectionsBySlip,
                    $familyStudentCountsByCode,
                    $netMinor,
                    $postCutoffMinor
                );

                $postFormatted = number_format($postCutoffMinor / 100, 2, '.', '');
                $remFormatted = number_format($potentialRemainingMinor / 100, 2, '.', '');

                if ($isConcession) {
                    $classification = self::CLASS_POST_CUTOFF_CONCESSION;
                    $explanation = "POST-CUTOFF POSSIBLE CONCESSION: Paid {$itemCurr} {$postFormatted} after {$cutoffDate} against {$itemCurr} " . number_format($netMinor / 100, 2, '.', '') . " opening balance (difference: {$itemCurr} {$remFormatted}). Matches recurring/sibling concession pattern; review whether remaining {$itemCurr} {$remFormatted} is a sibling discount/concession.";
                    $summaryCounts['post_cutoff_sibling_concessions']++;
                } else {
                    $classification = self::CLASS_POST_CUTOFF_PARTIAL;
                    $explanation = "POST-CUTOFF PARTIAL PAYMENT: Paid {$itemCurr} {$postFormatted} after {$cutoffDate}; potential remaining unpaid balance is {$itemCurr} {$remFormatted}.";
                    $summaryCounts['post_cutoff_partial_payments']++;
                }
                $summaryCounts['post_cutoff_partial_or_concessions']++;
            } else {
                // $postCutoffMinor > $netMinor
                $classification = self::CLASS_REVIEW_OVERPAYMENT;
                $postFormatted = number_format($postCutoffMinor / 100, 2, '.', '');
                $netFormatted = number_format($netMinor / 100, 2, '.', '');
                $explanation = "HOLD / MANUAL REVIEW: Post-cutoff historical payments ({$itemCurr} {$postFormatted}) exceed opening balance item amount ({$itemCurr} {$netFormatted}).";
                $summaryCounts['anomalies_and_conflicts_requiring_review']++;
            }

            $summaryCounts['total_overlapping_items']++;
            $classificationBreakdown[$classification] = ($classificationBreakdown[$classification] ?? 0) + 1;

            $guardClass = self::mapToGuardClass($classification);
            $guardBreakdown[$guardClass] = ($guardBreakdown[$guardClass] ?? 0) + 1;

            $originalObligationMinor = $netMinor;
            $verifiedSettlementMinor = 0;
            $remainingObligationMinor = $netMinor;
            $approvedAdjustmentsMinor = 0;
            $unmatchedOverpaymentMinor = 0;

            if ($classification === self::CLASS_POST_CUTOFF_FULL) {
                $verifiedSettlementMinor = min($netMinor, $postCutoffMinor);
                $remainingObligationMinor = max(0, $netMinor - $verifiedSettlementMinor);
                $unmatchedOverpaymentMinor = max(0, $postCutoffMinor - $netMinor);
            } elseif ($classification === self::CLASS_POST_CUTOFF_PARTIAL || $classification === self::CLASS_POST_CUTOFF_CONCESSION) {
                $verifiedSettlementMinor = min($netMinor, $postCutoffMinor);
                $remainingObligationMinor = max(0, $netMinor - $verifiedSettlementMinor);
                $unmatchedOverpaymentMinor = max(0, $postCutoffMinor - $netMinor);
            } elseif ($classification === self::CLASS_REVIEW_OVERPAYMENT) {
                $verifiedSettlementMinor = min($netMinor, $postCutoffMinor);
                $remainingObligationMinor = max(0, $netMinor - $verifiedSettlementMinor);
                $unmatchedOverpaymentMinor = max(0, $postCutoffMinor - $netMinor);
            } else {
                $verifiedSettlementMinor = 0;
                $remainingObligationMinor = $netMinor;
                $unmatchedOverpaymentMinor = 0;
            }

            $isReconciled = ($originalObligationMinor === ($verifiedSettlementMinor + $remainingObligationMinor + $approvedAdjustmentsMinor));

            // Track currency breakdown
            if (!isset($currencyBreakdown[$itemCurr])) {
                $currencyBreakdown[$itemCurr] = [
                    'currency' => $itemCurr,
                    'overlapping_items' => 0,
                    'original_obligation_minor' => 0,
                    'verified_settlement_minor' => 0,
                    'remaining_obligation_minor' => 0,
                    'approved_adjustments_minor' => 0,
                    'unmatched_overpayment_minor' => 0,
                    'is_reconciled' => true,
                    // Backwards compatible keys
                    'ob_net_minor' => 0,
                    'pre_cutoff_paid_minor' => 0,
                    'post_cutoff_paid_minor' => 0,
                    'potential_remaining_minor' => 0,
                ];
            }
            $currencyBreakdown[$itemCurr]['overlapping_items']++;
            $currencyBreakdown[$itemCurr]['original_obligation_minor'] += $originalObligationMinor;
            $currencyBreakdown[$itemCurr]['verified_settlement_minor'] += $verifiedSettlementMinor;
            $currencyBreakdown[$itemCurr]['remaining_obligation_minor'] += $remainingObligationMinor;
            $currencyBreakdown[$itemCurr]['approved_adjustments_minor'] += $approvedAdjustmentsMinor;
            $currencyBreakdown[$itemCurr]['unmatched_overpayment_minor'] += $unmatchedOverpaymentMinor;
            $currencyBreakdown[$itemCurr]['ob_net_minor'] += $netMinor;
            $currencyBreakdown[$itemCurr]['pre_cutoff_paid_minor'] += $preCutoffMinor;
            $currencyBreakdown[$itemCurr]['post_cutoff_paid_minor'] += $postCutoffMinor;
            $currencyBreakdown[$itemCurr]['potential_remaining_minor'] += $potentialRemainingMinor;
            $currencyBreakdown[$itemCurr]['is_reconciled'] = (
                $currencyBreakdown[$itemCurr]['original_obligation_minor'] ===
                ($currencyBreakdown[$itemCurr]['verified_settlement_minor'] +
                 $currencyBreakdown[$itemCurr]['remaining_obligation_minor'] +
                 $currencyBreakdown[$itemCurr]['approved_adjustments_minor'])
            );

            // Track family+currency key for subsequent invoice snapshot impact
            $famLookupKey = ($stableFamilyCode !== '' && !str_starts_with($stableFamilyCode, 'PROV-FAM-'))
                ? "CODE:{$stableFamilyCode}|{$itemCurr}"
                : "ID:{$item->invoice_family_id}|{$itemCurr}";

            $familyCurrencyAllOverlaps[$famLookupKey] = true;
            if ($safeReductionMinor > 0) {
                $familyCurrencySettlementReductions[$famLookupKey] = ($familyCurrencySettlementReductions[$famLookupKey] ?? 0) + $safeReductionMinor;
            }

            $subsequentInvs = $subsequentInvoicesByFamilyCurrency->get($famLookupKey, collect());
            $subsequentSummaryStr = $subsequentInvs->map(function ($si) {
                $snap = number_format(((int) $si->previous_dues_snapshot_minor) / 100, 2, '.', '');
                return "{$si->invoice_number} ({$si->period_key}, snapshot: {$si->currency} {$snap})";
            })->implode('; ');

            $overlappingRecords[] = [
                'student_gr' => $gr,
                'family_code' => $stableFamilyCode,
                'currency' => $itemCurr,
                'fee_description' => (string) $item->description,
                'billing_period' => (string) ($itemIdentity['period_label'] ?: ($item->period_key ?? '')),
                'original_opening_balance_minor' => $netMinor,
                'original_opening_balance' => number_format($netMinor / 100, 2, '.', ''),
                'historical_payments_before_cutoff_minor' => $preCutoffMinor,
                'historical_payments_before_cutoff' => number_format($preCutoffMinor / 100, 2, '.', ''),
                'historical_payments_after_cutoff_minor' => $postCutoffMinor,
                'historical_payments_after_cutoff' => number_format($postCutoffMinor / 100, 2, '.', ''),
                'potential_remaining_balance_minor' => $potentialRemainingMinor,
                'potential_remaining_balance' => number_format($potentialRemainingMinor / 100, 2, '.', ''),
                'original_obligation_minor' => $originalObligationMinor,
                'verified_settlement_minor' => $verifiedSettlementMinor,
                'remaining_obligation_minor' => $remainingObligationMinor,
                'approved_adjustments_minor' => $approvedAdjustmentsMinor,
                'unmatched_overpayment_minor' => $unmatchedOverpaymentMinor,
                'is_reconciled' => $isReconciled,
                'myskool_slip_references' => $slipRefs,
                'existing_portal_payment_indicator' => $portalPaymentIndicator,
                'existing_adjustment_indicator' => $portalAdjustmentIndicator,
                'existing_historical_settlement_indicator' => $historicalSettlementIndicator,
                'reconciliation_classification' => $classification,
                'guard_class' => $guardClass,
                'explanation_or_hold_reason' => $explanation,
                'invoice_item_id' => (int) $item->item_id,
                'invoice_id' => (int) $item->invoice_id,
                'invoice_number' => (string) $item->invoice_number,
                'student_name' => (string) ($item->student_name ?? ''),
                'subsequent_snapshot_invoices' => $subsequentSummaryStr,
                'staged_collection_ids' => $postCutoffCols->pluck('id')->map(fn($id) => (int) $id)->all(),
                'staged_due_id' => !empty($item->qa_staged_due_id) ? (int) $item->qa_staged_due_id : null,
            ];
        }

        // 8. Summarize potentially affected subsequent invoice snapshots (including November 2026)
        $affectedSubsequentInvoices = [];
        $november2026AffectedCount = 0;
        $totalSnapshotOverstatementMinorByCurrency = [];

        foreach ($familyCurrencySettlementReductions as $famCurrKey => $reductionMinor) {
            $subInvs = $subsequentInvoicesByFamilyCurrency->get($famCurrKey, collect());
            foreach ($subInvs as $si) {
                $currentSnap = (int) $si->previous_dues_snapshot_minor;
                $projectedSnap = max(0, $currentSnap - $reductionMinor);
                $deltaMinor = $currentSnap - $projectedSnap;

                $isNov2026 = str_contains((string) $si->period_key, '2026-11') || str_starts_with((string) $si->period_start, '2026-11');
                if ($isNov2026) {
                    $november2026AffectedCount++;
                }

                $curr = strtoupper(trim((string) $si->currency));
                $totalSnapshotOverstatementMinorByCurrency[$curr] = ($totalSnapshotOverstatementMinorByCurrency[$curr] ?? 0) + $deltaMinor;

                $affectedSubsequentInvoices[] = [
                    'invoice_id' => (int) $si->id,
                    'invoice_number' => (string) $si->invoice_number,
                    'family_code' => (string) ($si->family_code ?? ''),
                    'period_key' => (string) $si->period_key,
                    'currency' => $curr,
                    'current_snapshot_minor' => $currentSnap,
                    'current_snapshot' => number_format($currentSnap / 100, 2, '.', ''),
                    'post_cutoff_reduction_minor' => $reductionMinor,
                    'post_cutoff_reduction' => number_format($reductionMinor / 100, 2, '.', ''),
                    'projected_snapshot_minor' => $projectedSnap,
                    'projected_snapshot' => number_format($projectedSnap / 100, 2, '.', ''),
                ];
            }
        }

        $allOverlapSubsequentInvoicesCount = 0;
        foreach (array_keys($familyCurrencyAllOverlaps) as $famCurrKey) {
            $allOverlapSubsequentInvoicesCount += $subsequentInvoicesByFamilyCurrency->get($famCurrKey, collect())->count();
        }

        return [
            'status' => 'success',
            'connection' => $conn,
            'database' => config("database.connections.{$conn}.database"),
            'batch_id' => $resolvedBatchId,
            'cutoff_date' => $cutoffDate,
            'total_staged_collections_in_batch' => $totalStagedCollections,
            'summary' => $summaryCounts,
            'classifications' => $classificationBreakdown,
            'guard_distribution' => $guardBreakdown,
            'currency_breakdown' => $currencyBreakdown,
            'subsequent_snapshots' => [
                'all_overlap_families_subsequent_invoices_count' => $allOverlapSubsequentInvoicesCount,
                'post_cutoff_affected_subsequent_invoices_count' => count($affectedSubsequentInvoices),
                'november_2026_affected_invoices_count' => $november2026AffectedCount,
                'overstatement_minor_by_currency' => $totalSnapshotOverstatementMinorByCurrency,
                'invoices' => $affectedSubsequentInvoices,
            ],
            'records' => $overlappingRecords,
        ];
    }

    /**
     * Write the audit records to a reviewable CSV file.
     */
    public function exportCsv(array $auditResult, ?string $customPath = null): string
    {
        $defaultRelative = 'reconciliation/myskool_opening_balance_reconciliation_audit.csv';
        $targetPath = $customPath && trim($customPath) !== '' && strtolower(trim($customPath)) !== 'csv'
            ? $customPath
            : storage_path('app/' . $defaultRelative);

        $dir = dirname($targetPath);
        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $handle = fopen($targetPath, 'w');
        if ($handle === false) {
            throw new RuntimeException("Unable to open CSV export path for writing: {$targetPath}");
        }

        // Required reviewable CSV headers + traceability columns
        $headers = [
            'Student GR',
            'Stable family code',
            'Currency',
            'Fee description',
            'Billing period',
            'Original opening balance',
            'Historical payments before cutoff',
            'Historical payments after cutoff',
            'Potential remaining balance',
            'MySkool receipt/slip references',
            'Existing portal payment indicator',
            'Existing adjustment indicator',
            'Reconciliation classification',
            'Explanation or hold reason',
            'Invoice Item ID',
            'Invoice Number',
            'Student Name',
            'Potentially Affected Subsequent Invoice Snapshots',
            'Guard class',
            'Verified historical settlement',
            'Remaining obligation',
            'Approved adjustments',
            'Unmatched overpayment',
            'Existing historical settlement indicator',
        ];

        fputcsv($handle, $headers);

        foreach ($auditResult['records'] as $r) {
            fputcsv($handle, [
                $r['student_gr'],
                $r['family_code'],
                $r['currency'],
                $r['fee_description'],
                $r['billing_period'],
                $r['original_opening_balance'],
                $r['historical_payments_before_cutoff'],
                $r['historical_payments_after_cutoff'],
                $r['potential_remaining_balance'],
                $r['myskool_slip_references'],
                $r['existing_portal_payment_indicator'],
                $r['existing_adjustment_indicator'],
                $r['reconciliation_classification'],
                $r['explanation_or_hold_reason'],
                $r['invoice_item_id'],
                $r['invoice_number'],
                $r['student_name'],
                $r['subsequent_snapshot_invoices'],
                $r['guard_class'] ?? '',
                number_format(((int) ($r['verified_settlement_minor'] ?? 0)) / 100, 2, '.', ''),
                number_format(((int) ($r['remaining_obligation_minor'] ?? 0)) / 100, 2, '.', ''),
                number_format(((int) ($r['approved_adjustments_minor'] ?? 0)) / 100, 2, '.', ''),
                number_format(((int) ($r['unmatched_overpayment_minor'] ?? 0)) / 100, 2, '.', ''),
                $r['existing_historical_settlement_indicator'] ?? 'NO',
            ]);
        }

        fclose($handle);

        return $targetPath;
    }

    /**
     * Resolve the batch ID to audit without guessing.
     */
    protected function resolveBatchId($db, $schema, ?string $explicitBatchId): string
    {
        if ($explicitBatchId !== null && trim($explicitBatchId) !== '') {
            return trim($explicitBatchId);
        }

        if ($schema->hasTable('qa_opening_balance_lineage')) {
            $lineageBatches = $db->table('qa_opening_balance_lineage')
                ->distinct()
                ->pluck('batch_id')
                ->filter()
                ->values()
                ->all();

            if (count($lineageBatches) === 1) {
                return (string) $lineageBatches[0];
            }
        }

        return (string) config('imports.active_batch_id', 'BATCH-20261025-FINAL-MYSKOOL');
    }

    /**
     * Resolve the stable family code for an opening balance item (never using local auto-increment ID).
     */
    protected function resolveStableFamilyCode(object $item): string
    {
        $stagedCode = trim((string) ($item->staged_due_family_code ?? ''));
        if ($stagedCode !== '') {
            return $stagedCode;
        }

        $familyTableCode = trim((string) ($item->family_table_code ?? ''));
        if ($familyTableCode !== '') {
            return $familyTableCode;
        }

        return '';
    }

    /**
     * Normalize fee description into fee identity type, standard period_key, period_label, and subject.
     */
    public static function parseFeeIdentity(string $description, ?string $fallbackPeriodKey = null, ?string $feeTypeCode = null): array
    {
        $descTrim = trim($description);
        $parsedPeriod = HistoricalOpeningBalanceService::parseParticularPeriod($descTrim);
        $periodKey = $parsedPeriod['period_key'] ?? ($fallbackPeriodKey ? trim($fallbackPeriodKey) : null);

        $periodLabel = '';
        if (preg_match('/([A-Za-z]{3})[-–\s]+(202[0-9])/', $descTrim, $m)) {
            $periodLabel = ucfirst(strtolower($m[1])) . '-' . $m[2];
        } elseif ($periodKey !== null && preg_match('/(\d{4}-\d{2})-\d{2}/', $periodKey, $m)) {
            $periodLabel = $m[1];
        }

        $lower = strtolower($descTrim);
        $codeLower = strtolower(trim((string) $feeTypeCode));

        if ($codeLower !== '' && in_array($codeLower, ['monthly', 'admission', 'annual', 'exam', 'tuition'], true)) {
            $type = $codeLower;
        } elseif (str_contains($lower, 'admission') || str_contains($lower, 'registration')) {
            $type = 'admission';
        } elseif (str_contains($lower, 'tuition') || str_contains($lower, 'tution')) {
            $type = 'tuition';
        } elseif (str_contains($lower, 'monthly')) {
            $type = 'monthly';
        } elseif (str_contains($lower, 'annual')) {
            $type = 'annual';
        } elseif (str_contains($lower, 'exam')) {
            $type = 'exam';
        } else {
            $type = 'other:' . $lower;
        }

        $subject = '';
        if (preg_match('/\((.*?)\)/', $descTrim, $sm)) {
            $subject = strtolower(trim($sm[1]));
        }

        return [
            'type' => $type,
            'period_key' => $periodKey,
            'period_label' => $periodLabel,
            'subject' => $subject,
            'raw' => $descTrim,
        ];
    }

    /**
     * Deduplicate reused or exact-duplicate historical collection rows for an item.
     * Preserves legitimate multi-installment rows while preventing duplicate row double-counting.
     *
     * @return array{0: Collection, 1: bool}
     */
    protected function deduplicateCollectionRows(Collection $rows, int $itemNetMinor): array
    {
        $deduped = collect();
        $seenSourceRows = [];
        $seenExactTuples = [];
        $hadDuplicates = false;

        foreach ($rows as $row) {
            $sourceRow = (int) ($row->source_row ?? 0);
            $batch = trim((string) ($row->batch_id ?? ''));

            // 1. Exact same (batch_id, source_row) when source_row > 0 is a duplicate staging row
            if ($sourceRow > 0) {
                $sourceKey = "{$batch}|{$sourceRow}";
                if (isset($seenSourceRows[$sourceKey])) {
                    $hadDuplicates = true;
                    continue;
                }
                $seenSourceRows[$sourceKey] = true;
            }

            // 2. Check if identical (slip, gr, particular, date, amount_minor) appears multiple times
            // when a single row already equals or exceeds itemNetMinor (or when source_row is 0/duplicate)
            $slip = trim((string) ($row->slip_number ?? ''));
            $gr = trim((string) ($row->gr_number ?? ''));
            $part = strtolower(trim((string) ($row->fee_particular ?? '')));
            $date = trim((string) ($row->payment_date_parsed ?? $row->payment_date_raw ?? ''));
            $amtMinor = (int) ($row->amount_minor ?? 0);

            $tupleKey = "{$slip}|{$gr}|{$part}|{$date}|{$amtMinor}";
            if (isset($seenExactTuples[$tupleKey])) {
                // If single row already settles the full item net amount (or source_row == 0), repeating it is a duplicate allocation
                if ($amtMinor >= $itemNetMinor || $sourceRow === 0) {
                    $hadDuplicates = true;
                    continue;
                }
            }
            $seenExactTuples[$tupleKey] = true;

            $deduped->push($row);
        }

        return [$deduped, $hadDuplicates];
    }

    /**
     * Static wrapper to deduplicate staged collection rows for a single obligation.
     */
    public static function deduplicateCollections(Collection $rows, int $itemNetMinor = PHP_INT_MAX): Collection
    {
        $instance = new self();
        [$deduped] = $instance->deduplicateCollectionRows($rows, $itemNetMinor);
        return $deduped;
    }

    /**
     * Determine whether a post-cutoff underpayment (< net_minor) matches a sibling or recurring concession pattern
     * rather than an ad-hoc partial installment payment.
     */
    protected function isSiblingOrRecurringConcession(
        object $item,
        string $stableFamilyCode,
        Collection $studentAllCols,
        Collection $postCutoffCols,
        Collection $collectionsBySlip,
        array $familyStudentCountsByCode,
        int $netMinor,
        int $postCutoffMinor
    ): bool {
        // 1. Did this student pay the exact same reduced amount in prior monthly fee collections?
        $priorSameAmountCount = $studentAllCols->filter(function ($c) use ($item, $postCutoffMinor) {
            return trim((string) $c->fee_particular) !== trim((string) $item->description)
                && str_starts_with(strtolower(trim((string) $c->fee_particular)), 'monthly fee')
                && (int) $c->amount_minor === $postCutoffMinor;
        })->count();

        if ($priorSameAmountCount >= 1) {
            return true;
        }

        // 2. Sibling concession pattern on a single post-cutoff payment line:
        //    - Single post-cutoff collection line for this period
        //    - Family has > 1 sibling OR the same receipt/slip settles another student for the same period/family
        //    - Reduced payment is at least 50% of net_minor and the discount is a clean round multiple of 5 major units (500 minor units)
        if ($postCutoffCols->count() !== 1) {
            return false;
        }

        $diffMinor = $netMinor - $postCutoffMinor;
        $isRoundDiscount = ($postCutoffMinor >= (int) ($netMinor * 0.5)) && ($diffMinor % 500 === 0);
        if (!$isRoundDiscount) {
            return false;
        }

        $hasMultipleSiblings = ($stableFamilyCode !== '' && ($familyStudentCountsByCode[$stableFamilyCode] ?? 1) > 1);
        $postSlip = trim((string) ($postCutoffCols->first()->slip_number ?? ''));
        $siblingSamePeriodOnSlip = false;

        if ($postSlip !== '' && $collectionsBySlip->has($postSlip)) {
            foreach ($collectionsBySlip->get($postSlip) as $slipRow) {
                if (trim((string) $slipRow->gr_number) !== trim((string) ($item->student_gr ?? ''))
                    && trim((string) $slipRow->fee_particular) === trim((string) $item->description)) {
                    $siblingSamePeriodOnSlip = true;
                    break;
                }
            }
        }

        return ($hasMultipleSiblings && $siblingSamePeriodOnSlip) || ($hasMultipleSiblings && $diffMinor <= 10000 && $postCutoffMinor >= (int) ($netMinor * 0.7));
    }

    protected function formatPortalPaymentIndicator(object $item, Collection $allocs, bool $hasPortalPayment): string
    {
        if (!$hasPortalPayment) {
            return 'NO';
        }

        $parts = [];
        if ((int) $item->item_paid_minor > 0) {
            $parts[] = 'paid_minor=' . number_format(((int) $item->item_paid_minor) / 100, 2, '.', '');
        }
        if ($allocs->isNotEmpty()) {
            $rcps = $allocs->pluck('receipt_number')->filter()->unique()->implode('|');
            $parts[] = 'allocations=' . $allocs->count() . ($rcps !== '' ? " ({$rcps})" : '');
        }
        if ($item->item_status !== 'active') {
            $parts[] = "item_status={$item->item_status}";
        }

        return 'YES (' . implode(', ', $parts) . ')';
    }

    protected function formatPortalAdjustmentIndicator(object $item, Collection $adjs, bool $hasPortalAdjustment): string
    {
        if (!$hasPortalAdjustment) {
            return 'NO';
        }

        $parts = [];
        if ((int) $item->discount_minor > 0) {
            $parts[] = 'discount=' . number_format(((int) $item->discount_minor) / 100, 2, '.', '');
        }
        if ((int) $item->exemption_minor > 0) {
            $parts[] = 'exemption=' . number_format(((int) $item->exemption_minor) / 100, 2, '.', '');
        }
        if ($adjs->isNotEmpty()) {
            $kinds = $adjs->pluck('kind')->unique()->implode('|');
            $parts[] = 'adjustments=' . $adjs->count() . " ({$kinds})";
        }
        if (in_array($item->item_status, ['void', 'voided'], true)) {
            $parts[] = "item_status={$item->item_status}";
        }
        if (in_array($item->invoice_status, ['void', 'voided'], true)) {
            $parts[] = "invoice_status={$item->invoice_status}";
        }

        return 'YES (' . implode(', ', $parts) . ')';
    }

    protected function buildEmptyResult(string $conn, string $batchId, string $cutoffDate, int $totalStagedCollections): array
    {
        return [
            'status' => 'empty',
            'connection' => $conn,
            'database' => config("database.connections.{$conn}.database"),
            'batch_id' => $batchId,
            'cutoff_date' => $cutoffDate,
            'total_staged_collections_in_batch' => $totalStagedCollections,
            'summary' => [
                'total_ob_items_scanned' => 0,
                'unmatched_ob_items' => 0,
                'total_overlapping_items' => 0,
                'pre_cutoff_residuals' => 0,
                'post_cutoff_full_settlements' => 0,
                'post_cutoff_partial_or_concessions' => 0,
                'post_cutoff_partial_payments' => 0,
                'post_cutoff_sibling_concessions' => 0,
                'anomalies_and_conflicts_requiring_review' => 0,
            ],
            'classifications' => [],
            'currency_breakdown' => [],
            'subsequent_snapshots' => [
                'all_overlap_families_subsequent_invoices_count' => 0,
                'post_cutoff_affected_subsequent_invoices_count' => 0,
                'november_2026_affected_invoices_count' => 0,
                'overstatement_minor_by_currency' => [],
                'invoices' => [],
            ],
            'records' => [],
        ];
    }
}
