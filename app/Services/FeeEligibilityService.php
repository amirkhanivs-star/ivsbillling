<?php

namespace App\Services;

use App\Services\Migration\HistoricalOpeningBalanceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FeeEligibilityService
{
    public const STATUS_ELIGIBLE = 'Eligible for creation';
    public const STATUS_ALREADY_INVOICED = 'Already invoiced';
    public const STATUS_PREVIOUSLY_VOIDED = 'Previously voided';
    public const STATUS_HISTORICAL_PAYMENT = 'Historical MySkool payment exists';
    public const STATUS_HISTORICAL_OB = 'Historical opening balance exists';
    public const STATUS_RESTORATION_REVIEW = 'Requires restoration review';
    public const STATUS_FINANCIAL_CONFLICT = 'Blocked by financial conflict';
    public const STATUS_INCOMPLETE_OBLIGATION = 'Incomplete imported obligation';

    /**
     * Evaluate fee eligibility across candidate students, fee types, months, and year.
     * Guaranteed consistent across both preview and transactional save operations.
     *
     * @param array{
     *     family_id: int,
     *     student_ids?: array<int>|null,
     *     fee_type_ids: array<int>,
     *     months: array<int>,
     *     year: int
     * } $params
     * @param string|null $conn
     * @param bool $lockForUpdate
     * @return array
     */
    public function evaluateEligibility(array $params, ?string $conn = null, bool $lockForUpdate = false): array
    {
        $conn = $conn ?: config('database.default', 'mysql');
        $db = DB::connection($conn);

        $familyId = !empty($params['family_id']) ? (int) $params['family_id'] : null;
        $familyCode = !empty($params['family_code']) ? trim((string) $params['family_code']) : null;
        $year = (int) $params['year'];
        $feeTypeIds = array_map('intval', $params['fee_type_ids']);
        $months = array_map('intval', $params['months']);
        $studentIds = !empty($params['student_ids']) ? array_map('intval', $params['student_ids']) : null;

        $family = $familyId
            ? $db->table('families')->where('id', $familyId)->first()
            : ($familyCode ? $db->table('families')->where('family_code', $familyCode)->first() : null);
        if (!$family) {
            return [
                'success' => false,
                'message' => 'Family not found.',
                'items' => [],
                'summary' => [
                    'total_evaluated' => 0,
                    'total_eligible' => 0,
                    'total_blocked' => 0,
                    'eligible_amount_minor' => 0,
                ],
            ];
        }

        // 1. Fetch eligible students
        $studentsQuery = $db->table('students as s')
            ->join('enrollments as e', 'e.student_id', '=', 's.id')
            ->where('s.family_id', $family->id)
            ->where('e.status', 'active');

        if (!empty($studentIds)) {
            $studentsQuery->whereIn('s.id', $studentIds);
        }

        $students = $studentsQuery->select(
            's.*',
            'e.id as enrollment_id',
            'e.campus_id',
            'e.department_id',
            'e.currency',
            'e.grade_label',
            'e.monthly_fee_minor'
        )->get();

        $feeTypes = $db->table('fee_types')->whereIn('id', $feeTypeIds)->get()->keyBy('id');

        // Preload historical obligation map for all involved GRs
        $grNumbers = $students->pluck('gr_number')->filter()->unique()->values()->all();
        $historicalMap = [];
        if (!empty($grNumbers) && class_exists(HistoricalOpeningBalanceService::class)) {
            $hobService = app(HistoricalOpeningBalanceService::class);
            $historicalMap = $hobService->buildHistoricalObligationMap($grNumbers, null, $conn);
        }

        $items = [];
        $totalEligible = 0;
        $totalBlocked = 0;
        $eligibleAmountMinor = 0;
        $statusCounts = [
            self::STATUS_ELIGIBLE => 0,
            self::STATUS_ALREADY_INVOICED => 0,
            self::STATUS_PREVIOUSLY_VOIDED => 0,
            self::STATUS_HISTORICAL_PAYMENT => 0,
            self::STATUS_HISTORICAL_OB => 0,
            self::STATUS_RESTORATION_REVIEW => 0,
            self::STATUS_FINANCIAL_CONFLICT => 0,
            self::STATUS_INCOMPLETE_OBLIGATION => 0,
        ];

        $monthNames = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        foreach ($students as $student) {
            $isStudentHeld = in_array($student->status, ['review_hold', 'historical_hold', 'suspended', 'left'], true);

            foreach ($feeTypeIds as $ftId) {
                $feeType = $feeTypes->get($ftId);
                if (!$feeType) {
                    continue;
                }

                $isOneTime = !in_array($feeType->code, ['monthly', 'tuition'], true);

                $monthsToEvaluate = $isOneTime ? [reset($months)] : $months;

                foreach ($monthsToEvaluate as $m) {
                    $month = (int) $m;
                    $periodStart = sprintf('%04d-%02d-01', $year, $month);
                    $periodEnd = date('Y-m-t', strtotime($periodStart));
                    $periodKey = $isOneTime ? "one_time:{$feeType->code}:{$periodStart}" : "monthly:{$periodStart}..{$periodEnd}";
                    $periodLabel = $isOneTime ? "One-Time ({$monthNames[$month]} {$year})" : "{$monthNames[$month]} {$year}";

                    // Rate and currency resolution consolidated via InvoiceService
                    $resolvedFee = InvoiceService::resolveFeeAndCurrency($student, $feeType, $conn);
                    $periodCurrency = $resolvedFee['currency'];
                    $amountMinor = $resolvedFee['amount_minor'];
                    $discountMinor = $resolvedFee['discount_minor'];
                    $netMinor = $resolvedFee['net_minor'];
                    $rateSource = $resolvedFee['rate_source'];
                    $planItem = $resolvedFee['plan_item'];

                    $itemResult = [
                        'student_id' => (int) $student->id,
                        'student_name' => (string) $student->name,
                        'student_gr' => (string) $student->gr_number,
                        'grade_label' => (string) ($student->grade_label ?? 'Standard'),
                        'currency' => (string) $periodCurrency,
                        'fee_type_id' => (int) $feeType->id,
                        'fee_type_name' => (string) $feeType->name,
                        'fee_type_code' => (string) $feeType->code,
                        'is_one_time' => $isOneTime,
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'period_key' => $periodKey,
                        'period_label' => $periodLabel,
                        'amount_minor' => $amountMinor,
                        'discount_minor' => $discountMinor,
                        'net_minor' => $netMinor,
                        'rate_source' => $rateSource,
                        'status' => self::STATUS_ELIGIBLE,
                        'status_code' => 'eligible',
                        'is_eligible' => true,
                        'reason' => 'Approved rate configured. Eligible for invoice generation.',
                        'voided_item_id' => null,
                        'existing_invoice_item_id' => null,
                        'existing_invoice_number' => null,
                        'plan_item_id' => $planItem?->id ? (int) $planItem->id : null,
                    ];

                    // Check Student Hold
                    if ($isStudentHeld) {
                        $itemResult['status'] = self::STATUS_FINANCIAL_CONFLICT;
                        $itemResult['status_code'] = 'financial_conflict';
                        $itemResult['is_eligible'] = false;
                        $itemResult['reason'] = $student->status === 'review_hold'
                            ? "Student GR {$student->gr_number} is on financial review hold."
                            : "Student GR {$student->gr_number} is marked as {$student->status}.";
                        $this->accumulateEvaluation($itemResult, $statusCounts, $totalEligible, $totalBlocked, $eligibleAmountMinor, $items);
                        continue;
                    }

                    // Check Missing Rate
                    if ($amountMinor <= 0) {
                        $itemResult['status'] = self::STATUS_FINANCIAL_CONFLICT;
                        $itemResult['status_code'] = 'financial_conflict';
                        $itemResult['is_eligible'] = false;
                        $itemResult['reason'] = "No approved catalog rate found for {$feeType->name} ({$student->grade_label}, {$periodCurrency}).";
                        $this->accumulateEvaluation($itemResult, $statusCounts, $totalEligible, $totalBlocked, $eligibleAmountMinor, $items);
                        continue;
                    }

                    // 2. Check Active Invoiced Items (Already invoiced)
                    $existsQuery = $db->table('invoice_items as ii')
                        ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
                        ->where('ii.student_id', $student->id)
                        ->where('ii.fee_type_id', $feeType->id)
                        ->where('ii.status', '!=', 'voided')
                        ->whereNotIn('inv.status', ['void', 'voided']);

                    if ($isOneTime) {
                        $existsQuery->where(function ($q) use ($periodStart, $periodKey) {
                            $q->where('ii.period_key', 'LIKE', 'one_time:%')
                              ->orWhere('ii.period_start', $periodStart);
                        });
                    } else {
                        $existsQuery->where('ii.period_start', $periodStart);
                    }

                    if ($lockForUpdate) {
                        $existsQuery->lockForUpdate();
                    }

                    $alreadyInvoiced = $existsQuery->select('ii.*', 'inv.invoice_number', 'inv.status as invoice_status', 'inv.billing_run_id')->first();

                    if ($alreadyInvoiced) {
                        $existingNet = (int) $alreadyInvoiced->net_minor;
                        $existingPaid = (int) $alreadyInvoiced->paid_minor;

                        $isImported = false;
                        if (!empty($alreadyInvoiced->billing_run_id)) {
                            $br = $db->table('billing_runs')->where('id', $alreadyInvoiced->billing_run_id)->first();
                            if ($br && $br->frequency === 'opening_balance') {
                                $isImported = true;
                            }
                        }
                        if (!$isImported) {
                            $hasHistLink = (Schema::connection($conn)->hasTable('qa_historical_settlements') && $db->table('qa_historical_settlements')->where('invoice_item_id', $alreadyInvoiced->id)->exists())
                                || (Schema::connection($conn)->hasTable('qa_myskool_payment_corrections') && $db->table('qa_myskool_payment_corrections')->where('invoice_item_id', $alreadyInvoiced->id)->exists())
                                || str_contains(strtolower((string) ($alreadyInvoiced->description ?? '')), 'opening balance')
                                || str_contains(strtolower((string) ($alreadyInvoiced->description ?? '')), 'myskool');
                            if ($hasHistLink) {
                                $isImported = true;
                            }
                        }

                        if ($existingNet < $netMinor && ($isImported || !empty($historicalMap))) {
                            $itemResult['status'] = self::STATUS_INCOMPLETE_OBLIGATION;
                            $itemResult['status_code'] = 'incomplete_obligation';
                            $itemResult['is_eligible'] = false;
                            $itemResult['existing_invoice_item_id'] = (int) $alreadyInvoiced->id;
                            $itemResult['existing_invoice_number'] = (string) $alreadyInvoiced->invoice_number;
                            $itemResult['is_incomplete_obligation'] = true;
                            $itemResult['approved_net_minor'] = $netMinor;
                            $itemResult['existing_net_minor'] = $existingNet;
                            $itemResult['existing_paid_minor'] = $existingPaid;
                            $diffFmt = number_format(($netMinor - $existingNet) / 100, 2);
                            $netFmt = number_format($netMinor / 100, 2);
                            $existFmt = number_format($existingNet / 100, 2);
                            $itemResult['reason'] = "Incomplete imported obligation: active invoice {$alreadyInvoiced->invoice_number} covers only {$periodCurrency} {$existFmt} of approved {$periodCurrency} {$netFmt} (understated by {$periodCurrency} {$diffFmt}).";
                            $this->accumulateEvaluation($itemResult, $statusCounts, $totalEligible, $totalBlocked, $eligibleAmountMinor, $items);
                            continue;
                        }

                        $itemResult['status'] = self::STATUS_ALREADY_INVOICED;
                        $itemResult['status_code'] = 'already_invoiced';
                        $itemResult['is_eligible'] = false;
                        $itemResult['existing_invoice_item_id'] = (int) $alreadyInvoiced->id;
                        $itemResult['existing_invoice_number'] = (string) $alreadyInvoiced->invoice_number;
                        $paid = (int) $alreadyInvoiced->paid_minor;
                        $net = (int) $alreadyInvoiced->net_minor;
                        if ($paid >= $net && $net > 0) {
                            $itemResult['reason'] = "Fee has already been invoiced on {$alreadyInvoiced->invoice_number} and paid in full.";
                        } elseif ($paid > 0) {
                            $remFmt = number_format(($net - $paid) / 100, 2);
                            $itemResult['reason'] = "An invoice ({$alreadyInvoiced->invoice_number}) has already been generated for this fee and period ({$periodCurrency} {$remFmt} outstanding; collect directly).";
                        } else {
                            $itemResult['reason'] = "An invoice ({$alreadyInvoiced->invoice_number}) has already been generated for this fee and period.";
                        }
                        $this->accumulateEvaluation($itemResult, $statusCounts, $totalEligible, $totalBlocked, $eligibleAmountMinor, $items);
                        continue;
                    }

                    // 3. Check Active Opening Balance Invoices (Historical opening balance exists)
                    $obQuery = $db->table('invoice_items as ii')
                        ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
                        ->join('billing_runs as br', 'br.id', '=', 'inv.billing_run_id')
                        ->where('br.frequency', 'opening_balance')
                        ->where('ii.enrollment_id', $student->enrollment_id)
                        ->where('ii.fee_type_id', $feeType->id)
                        ->where('ii.status', '!=', 'voided')
                        ->whereNotIn('inv.status', ['void', 'voided']);

                    if ($isOneTime) {
                        $obQuery->where(function ($q) use ($periodStart) {
                            $q->where('ii.period_key', 'LIKE', 'one_time:%')
                              ->orWhere('ii.period_start', $periodStart);
                        });
                    } else {
                        $obQuery->where('ii.period_start', $periodStart);
                    }

                    if ($lockForUpdate) {
                        $obQuery->lockForUpdate();
                    }

                    $activeOb = $obQuery->select('ii.*', 'inv.invoice_number', 'inv.status as invoice_status')->first();

                    if ($activeOb) {
                        $existingNet = (int) $activeOb->net_minor;
                        $existingPaid = (int) $activeOb->paid_minor;
                        if ($existingNet < $netMinor) {
                            $itemResult['status'] = self::STATUS_INCOMPLETE_OBLIGATION;
                            $itemResult['status_code'] = 'incomplete_obligation';
                            $itemResult['is_eligible'] = false;
                            $itemResult['existing_invoice_item_id'] = (int) $activeOb->id;
                            $itemResult['existing_invoice_number'] = (string) $activeOb->invoice_number;
                            $itemResult['is_incomplete_obligation'] = true;
                            $itemResult['approved_net_minor'] = $netMinor;
                            $itemResult['existing_net_minor'] = $existingNet;
                            $itemResult['existing_paid_minor'] = $existingPaid;
                            $diffFmt = number_format(($netMinor - $existingNet) / 100, 2);
                            $netFmt = number_format($netMinor / 100, 2);
                            $existFmt = number_format($existingNet / 100, 2);
                            $itemResult['reason'] = "Incomplete imported opening balance: active invoice {$activeOb->invoice_number} covers only {$periodCurrency} {$existFmt} of approved {$periodCurrency} {$netFmt} (understated by {$periodCurrency} {$diffFmt}).";
                            $this->accumulateEvaluation($itemResult, $statusCounts, $totalEligible, $totalBlocked, $eligibleAmountMinor, $items);
                            continue;
                        }

                        $itemResult['status'] = self::STATUS_HISTORICAL_OB;
                        $itemResult['status_code'] = 'historical_ob';
                        $itemResult['is_eligible'] = false;
                        $itemResult['existing_invoice_item_id'] = (int) $activeOb->id;
                        $itemResult['existing_invoice_number'] = (string) $activeOb->invoice_number;
                        $itemResult['reason'] = "Active opening balance obligation already exists on invoice {$activeOb->invoice_number}.";
                        $this->accumulateEvaluation($itemResult, $statusCounts, $totalEligible, $totalBlocked, $eligibleAmountMinor, $items);
                        continue;
                    }

                    // 4. Check Previously Voided Items on this student/period
                    $voidedQuery = $db->table('invoice_items as ii')
                        ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
                        ->where('ii.student_id', $student->id)
                        ->where('ii.fee_type_id', $feeType->id)
                        ->where('ii.status', 'voided');

                    if ($isOneTime) {
                        $voidedQuery->where(function ($q) use ($periodStart, $periodKey) {
                            $q->where('ii.period_key', $periodKey)
                              ->orWhere('ii.period_start', $periodStart);
                        });
                    } else {
                        $voidedQuery->where(function ($q) use ($periodStart, $periodKey) {
                            $q->where('ii.period_start', $periodStart)
                              ->orWhere('ii.period_key', $periodKey);
                        });
                    }

                    if ($lockForUpdate) {
                        $voidedQuery->lockForUpdate();
                    }

                    $voidedItem = $voidedQuery->orderByDesc('ii.id')->select('ii.*', 'inv.invoice_number')->first();

                    // Check Historical Protection via HistoricalOpeningBalanceService
                    $histCheck = null;
                    $voidedOrigCheck = null;
                    if (!empty($historicalMap) && class_exists(HistoricalOpeningBalanceService::class)) {
                        $histCheck = HistoricalOpeningBalanceService::checkHistoricalObligation(
                            $historicalMap,
                            (string) ($student->gr_number ?? ''),
                            (string) $feeType->code,
                            $periodStart,
                            (string) $periodCurrency,
                            $planItem?->subject_name ?? null,
                            $netMinor
                        );
                        if ($histCheck === null && $isOneTime) {
                            $histCheck = HistoricalOpeningBalanceService::checkHistoricalObligation(
                                $historicalMap,
                                (string) ($student->gr_number ?? ''),
                                (string) $feeType->code,
                                'ONE_TIME',
                                (string) $periodCurrency,
                                $planItem?->subject_name ?? null
                            );
                        }
                        if ($voidedItem && strtoupper(trim((string) $voidedItem->currency)) !== $periodCurrency) {
                            $voidedOrigCheck = HistoricalOpeningBalanceService::checkHistoricalObligation(
                                $historicalMap,
                                (string) ($student->gr_number ?? ''),
                                (string) $feeType->code,
                                $periodStart,
                                strtoupper(trim((string) $voidedItem->currency)),
                                $planItem?->subject_name ?? null
                            );
                        }
                    }

                    // Check Applied Historical Settlements in qa_historical_settlements
                    $hasHistoricalSettlement = false;
                    $historicalSettlementInfo = null;
                    if (Schema::connection($conn)->hasTable('qa_historical_settlements')) {
                        $settleQuery = $db->table('qa_historical_settlements')
                            ->where('student_gr', (string) $student->gr_number)
                            ->where('status', 'applied');
                        if ($voidedItem) {
                            $settleQuery->where(function ($q) use ($voidedItem, $periodStart, $periodKey) {
                                $q->where('invoice_item_id', $voidedItem->id)
                                  ->orWhere('period_key', $periodKey)
                                  ->orWhere('period_key', 'LIKE', "%{$periodStart}%");
                            });
                        } else {
                            $settleQuery->where('currency', (string) $periodCurrency)
                                ->where(function ($q) use ($periodStart, $periodKey) {
                                    $q->where('period_key', $periodKey)
                                      ->orWhere('period_key', 'LIKE', "%{$periodStart}%");
                                });
                        }
                        $historicalSettlementInfo = $settleQuery->first();
                        $hasHistoricalSettlement = (bool) $historicalSettlementInfo;
                    }

                    // Evaluate Voided Item vs Historical Payment / Restoration / Recreation with Current Settings
                    if ($voidedItem) {
                        $itemResult['voided_item_id'] = (int) $voidedItem->id;
                        $itemResult['existing_invoice_number'] = (string) $voidedItem->invoice_number;

                        $origCurrency = strtoupper(trim((string) ($voidedItem->currency ?: $periodCurrency)));
                        $origAmountMinor = (int) $voidedItem->amount_minor;
                        $origDiscountMinor = (int) $voidedItem->discount_minor;
                        $origExemptionMinor = (int) $voidedItem->exemption_minor;
                        $origNetMinor = max(0, $origAmountMinor - $origDiscountMinor - $origExemptionMinor);
                        if ($origNetMinor <= 0) {
                            $voidAdjPrev = (int) ($db->table('fee_adjustments')
                                ->where('invoice_item_id', $voidedItem->id)
                                ->where('kind', 'void')
                                ->orderByDesc('id')
                                ->value('previous_amount_minor') ?? 0);
                            if ($voidAdjPrev > 0) {
                                $origNetMinor = $voidAdjPrev;
                            }
                        }

                        $origDisplayAmt = fmod($origNetMinor / 100, 1.0) == 0.0
                            ? (string) intval($origNetMinor / 100)
                            : number_format($origNetMinor / 100, 2, '.', '');
                        $currDisplayAmt = fmod($netMinor / 100, 1.0) == 0.0
                            ? (string) intval($netMinor / 100)
                            : number_format($netMinor / 100, 2, '.', '');

                        $origFormatted = $origCurrency . ' ' . number_format($origNetMinor / 100, 2, '.', '');
                        $currFormatted = $periodCurrency . ' ' . number_format($netMinor / 100, 2, '.', '');
                        $hasSettingsChanged = (
                            $origCurrency !== $periodCurrency
                            || $origNetMinor !== $netMinor
                            || $origAmountMinor !== $amountMinor
                        );

                        $itemResult['original_voided_currency'] = $origCurrency;
                        $itemResult['original_voided_amount_minor'] = $origAmountMinor;
                        $itemResult['original_voided_net_minor'] = $origNetMinor;
                        $itemResult['original_voided_formatted'] = $origFormatted;
                        $itemResult['original_voided_summary'] = "Original: {$origCurrency} {$origDisplayAmt} (Voided)";
                        $itemResult['replacement_currency'] = $periodCurrency;
                        $itemResult['replacement_amount_minor'] = $amountMinor;
                        $itemResult['replacement_net_minor'] = $netMinor;
                        $itemResult['replacement_formatted'] = $currFormatted;
                        $itemResult['replacement_summary'] = "Replacement: {$periodCurrency} {$currDisplayAmt} (Current approved settings)";
                        $itemResult['has_settings_changed'] = $hasSettingsChanged;

                        $voidedEval = HistoricalOpeningBalanceService::evaluateVoidedImportedObligation(
                            $db,
                            $historicalMap,
                            $voidedItem,
                            (string) ($student->gr_number ?? ''),
                            (string) $feeType->code,
                            $periodStart,
                            (string) $periodCurrency,
                            $planItem?->subject_name ?? null
                        );

                        if (!$voidedEval['can_recreate']) {
                            if ($voidedEval['status_code'] === 'restoration_review') {
                                $itemResult['status'] = self::STATUS_RESTORATION_REVIEW;
                                $itemResult['status_code'] = 'restoration_review';
                            } else {
                                $itemResult['status'] = self::STATUS_FINANCIAL_CONFLICT;
                                $itemResult['status_code'] = 'financial_conflict';
                            }
                            $itemResult['is_eligible'] = false;
                            $itemResult['can_recreate_current'] = false;
                            $itemResult['recreate_block_reason'] = $voidedEval['recreate_block_reason'] ?? $voidedEval['reason'];
                            $itemResult['reason'] = $voidedEval['reason'] ?? $itemResult['recreate_block_reason'];
                        } else {
                            $itemResult['status'] = self::STATUS_PREVIOUSLY_VOIDED;
                            $itemResult['status_code'] = 'previously_voided';
                            $itemResult['is_eligible'] = false;
                            $itemResult['can_recreate_current'] = true;
                            $itemResult['recreate_block_reason'] = null;
                            $itemResult['reason'] = "Original: {$origCurrency} {$origDisplayAmt} (Voided) → Replacement: {$periodCurrency} {$currDisplayAmt} (Current approved settings). Recreation using current settings is permitted.";
                        }

                        $this->accumulateEvaluation($itemResult, $statusCounts, $totalEligible, $totalBlocked, $eligibleAmountMinor, $items);
                        continue;
                    }

                    // 5. Evaluate Historical Opening Balance or Staged Payments when no voided item exists
                    if ($histCheck !== null && ($histCheck['blocks_billing'] ?? true)) {
                        $itemResult['is_eligible'] = false;

                        if (!empty($histCheck['review_required']) || ($histCheck['status'] ?? '') === 'cross_currency_conflict') {
                            $itemResult['status'] = self::STATUS_FINANCIAL_CONFLICT;
                            $itemResult['status_code'] = 'financial_conflict';
                            $conflictCurrs = implode(', ', $histCheck['conflicting_currencies'] ?? ($histCheck['currencies'] ?? []));
                            $itemResult['reason'] = "Historical obligation exists in a different currency ({$conflictCurrs} vs {$student->currency}). Manual currency review required.";
                        } elseif (!empty($histCheck['is_settled']) || $hasHistoricalSettlement) {
                            $itemResult['status'] = self::STATUS_HISTORICAL_PAYMENT;
                            $itemResult['status_code'] = 'historical_payment';
                            $itemResult['reason'] = "Payment has already been received for this fee and period in historical MySkool records.";
                        } elseif (($histCheck['status'] ?? '') === 'partial_same_currency') {
                            $itemResult['status'] = self::STATUS_RESTORATION_REVIEW;
                            $itemResult['status_code'] = 'restoration_review';
                            $itemResult['reason'] = "Partial historical payment exists in MySkool records. Obligation requires reconciliation review.";
                        } else {
                            $itemResult['status'] = self::STATUS_HISTORICAL_OB;
                            $itemResult['status_code'] = 'historical_ob';
                            $itemResult['reason'] = "Historical opening balance obligation already exists for this period in MySkool staging records.";
                        }

                        $this->accumulateEvaluation($itemResult, $statusCounts, $totalEligible, $totalBlocked, $eligibleAmountMinor, $items);
                        continue;
                    }

                    // 6. Check active item on same fee_plan_item_id and period_key
                    if ($planItem) {
                        $keyRow = $db->table('invoice_items')
                            ->where('fee_plan_item_id', $planItem->id)
                            ->where('period_key', $periodKey)
                            ->where('status', '!=', 'voided')
                            ->first();
                        if ($keyRow) {
                            $itemResult['is_eligible'] = false;
                            $itemResult['status'] = self::STATUS_ALREADY_INVOICED;
                            $itemResult['status_code'] = 'already_invoiced';
                            $itemResult['existing_invoice_item_id'] = (int) $keyRow->id;
                            $itemResult['reason'] = "An active invoice item (#{$keyRow->id}) already occupies this fee plan slot.";
                            $this->accumulateEvaluation($itemResult, $statusCounts, $totalEligible, $totalBlocked, $eligibleAmountMinor, $items);
                            continue;
                        }
                    }

                    // All checks passed! Eligible for creation.
                    if ($histCheck !== null && !empty($histCheck['is_partial'])) {
                        $itemResult['historical_paid_minor'] = (int) $histCheck['paid_minor'];
                        $itemResult['expected_remaining_minor'] = (int) $histCheck['outstanding_minor'];
                        $itemResult['is_partial_historical'] = true;
                        $itemResult['reason'] = "Eligible with partial historical MySkool credit: {$periodCurrency} " . number_format($histCheck['paid_minor'] / 100, 2) . " paid, {$periodCurrency} " . number_format($histCheck['outstanding_minor'] / 100, 2) . " remaining.";
                    }
                    $this->accumulateEvaluation($itemResult, $statusCounts, $totalEligible, $totalBlocked, $eligibleAmountMinor, $items);
                }
            }
        }

        return [
            'success' => true,
            'family_id' => $familyId,
            'family_code' => (string) ($family->family_code ?? ''),
            'items' => $items,
            'summary' => [
                'total_evaluated' => count($items),
                'total_eligible' => $totalEligible,
                'total_blocked' => $totalBlocked,
                'eligible_amount_minor' => $eligibleAmountMinor,
                'status_counts' => $statusCounts,
            ],
        ];
    }

    private function accumulateEvaluation(
        array $itemResult,
        array &$statusCounts,
        int &$totalEligible,
        int &$totalBlocked,
        int &$eligibleAmountMinor,
        array &$items
    ): void {
        $st = $itemResult['status'];
        if (isset($statusCounts[$st])) {
            $statusCounts[$st]++;
        } else {
            $statusCounts[$st] = 1;
        }

        if ($itemResult['is_eligible']) {
            $totalEligible++;
            $eligibleAmountMinor += (int) $itemResult['net_minor'];
        } else {
            $totalBlocked++;
        }

        $items[] = $itemResult;
    }
}
