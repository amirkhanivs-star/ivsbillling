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

                // Rate resolution
                $amountMinor = 0;
                $discountMinor = 0;
                $rateSource = 'Unknown';

                // Check student active fee plan
                $planItem = $db->table('fee_plan_items')
                    ->where('enrollment_id', $student->enrollment_id)
                    ->where('fee_type_id', $feeType->id)
                    ->where('is_active', true)
                    ->where('currency', $student->currency)
                    ->first();

                if ($planItem) {
                    $amountMinor = (int) $planItem->amount_minor;
                    $discountMinor = \App\Services\Money::discount($amountMinor, $planItem->discount_kind, (int) $planItem->discount_value);
                    $rateSource = 'Student Fee Plan';
                } elseif ($feeType->code === 'monthly' && !empty($student->monthly_fee_minor)) {
                    $amountMinor = (int) $student->monthly_fee_minor;
                    $rateSource = 'Enrollment Rate';
                } else {
                    $rate = $db->table('fee_catalog_rates')
                        ->where('fee_type_id', $feeType->id)
                        ->where('department_id', $student->department_id)
                        ->where('class_label', $student->grade_label)
                        ->where('currency', $student->currency)
                        ->first();
                    if ($rate) {
                        $amountMinor = (int) $rate->amount_minor;
                        $rateSource = 'Class Catalog';
                    }
                }

                $netMinor = max(0, $amountMinor - $discountMinor);

                $monthsToEvaluate = $isOneTime ? [reset($months)] : $months;

                foreach ($monthsToEvaluate as $m) {
                    $month = (int) $m;
                    $periodStart = sprintf('%04d-%02d-01', $year, $month);
                    $periodEnd = date('Y-m-t', strtotime($periodStart));
                    $periodKey = $isOneTime ? "one_time:{$feeType->code}:{$periodStart}" : "monthly:{$periodStart}..{$periodEnd}";
                    $periodLabel = $isOneTime ? "One-Time ({$monthNames[$month]} {$year})" : "{$monthNames[$month]} {$year}";

                    $itemResult = [
                        'student_id' => (int) $student->id,
                        'student_name' => (string) $student->name,
                        'student_gr' => (string) $student->gr_number,
                        'grade_label' => (string) ($student->grade_label ?? 'Standard'),
                        'currency' => (string) $student->currency,
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
                        $itemResult['reason'] = "No approved catalog rate found for {$feeType->name} ({$student->grade_label}, {$student->currency}).";
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

                    $alreadyInvoiced = $existsQuery->select('ii.*', 'inv.invoice_number', 'inv.status as invoice_status')->first();

                    if ($alreadyInvoiced) {
                        $itemResult['status'] = self::STATUS_ALREADY_INVOICED;
                        $itemResult['status_code'] = 'already_invoiced';
                        $itemResult['is_eligible'] = false;
                        $itemResult['existing_invoice_item_id'] = (int) $alreadyInvoiced->id;
                        $itemResult['existing_invoice_number'] = (string) $alreadyInvoiced->invoice_number;
                        $paid = (int) $alreadyInvoiced->paid_minor;
                        $net = (int) $alreadyInvoiced->net_minor;
                        $itemResult['reason'] = ($paid >= $net && $net > 0)
                            ? "Fee has already been invoiced on {$alreadyInvoiced->invoice_number} and paid in full."
                            : "An invoice ({$alreadyInvoiced->invoice_number}) has already been generated for this fee and period.";
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
                    if (!empty($historicalMap) && class_exists(HistoricalOpeningBalanceService::class)) {
                        $histCheck = HistoricalOpeningBalanceService::checkHistoricalObligation(
                            $historicalMap,
                            (string) ($student->gr_number ?? ''),
                            (string) $feeType->code,
                            $periodStart,
                            (string) $student->currency,
                            $planItem?->subject_name ?? null
                        );
                        if ($histCheck === null && $isOneTime) {
                            $histCheck = HistoricalOpeningBalanceService::checkHistoricalObligation(
                                $historicalMap,
                                (string) ($student->gr_number ?? ''),
                                (string) $feeType->code,
                                'ONE_TIME',
                                (string) $student->currency,
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
                            ->where('currency', (string) $student->currency)
                            ->where('status', 'applied');
                        if ($voidedItem) {
                            $settleQuery->where(function ($q) use ($voidedItem, $periodStart, $periodKey) {
                                $q->where('invoice_item_id', $voidedItem->id)
                                  ->orWhere('period_key', $periodKey)
                                  ->orWhere('period_key', 'LIKE', "%{$periodStart}%");
                            });
                        } else {
                            $settleQuery->where(function ($q) use ($periodStart, $periodKey) {
                                $q->where('period_key', $periodKey)
                                  ->orWhere('period_key', 'LIKE', "%{$periodStart}%");
                            });
                        }
                        $historicalSettlementInfo = $settleQuery->first();
                        $hasHistoricalSettlement = (bool) $historicalSettlementInfo;
                    }

                    // Evaluate Voided Item vs Historical Payment / Restoration
                    if ($voidedItem) {
                        $itemResult['voided_item_id'] = (int) $voidedItem->id;
                        $itemResult['existing_invoice_number'] = (string) $voidedItem->invoice_number;

                        // Check if this voided item has a historical payment or settlement
                        $hasHistoricalPayment = $hasHistoricalSettlement
                            || ($histCheck !== null && !empty($histCheck['is_settled']))
                            || ($histCheck !== null && !empty($histCheck['paid_minor']) && (int) $histCheck['paid_minor'] > 0);

                        if ($hasHistoricalPayment) {
                            $itemResult['status'] = self::STATUS_RESTORATION_REVIEW;
                            $itemResult['status_code'] = 'restoration_review';
                            $itemResult['is_eligible'] = false;
                            $paidAmt = $historicalSettlementInfo ? ((int) $historicalSettlementInfo->settled_amount_minor) : (int) ($histCheck['paid_minor'] ?? 0);
                            $paidFmt = number_format($paidAmt / 100, 2, '.', '');
                            $itemResult['reason'] = "This fee obligation was previously voided (Item #{$voidedItem->id} on {$voidedItem->invoice_number}) but possesses verified historical MySkool payment evidence ({$student->currency} {$paidFmt}). Requires administrative restoration review rather than recreation.";
                        } else {
                            $itemResult['status'] = self::STATUS_PREVIOUSLY_VOIDED;
                            $itemResult['status_code'] = 'previously_voided';
                            $itemResult['is_eligible'] = false;
                            $itemResult['reason'] = "This fee obligation was previously voided (Item #{$voidedItem->id} on {$voidedItem->invoice_number}). Restore the existing obligation using administrative restoration instead of creating a duplicate charge.";
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

                    // 6. Check unique constraint collision potential in invoice_items
                    if ($planItem) {
                        $keyRow = $db->table('invoice_items')
                            ->where('fee_plan_item_id', $planItem->id)
                            ->where('period_key', $periodKey)
                            ->first();
                        if ($keyRow) {
                            $itemResult['is_eligible'] = false;
                            if ($keyRow->status === 'voided') {
                                $itemResult['status'] = self::STATUS_PREVIOUSLY_VOIDED;
                                $itemResult['status_code'] = 'previously_voided';
                                $itemResult['voided_item_id'] = (int) $keyRow->id;
                                $itemResult['reason'] = "A voided invoice item (#{$keyRow->id}) already occupies this fee plan slot. Restore the voided item rather than recreating.";
                            } else {
                                $itemResult['status'] = self::STATUS_ALREADY_INVOICED;
                                $itemResult['status_code'] = 'already_invoiced';
                                $itemResult['existing_invoice_item_id'] = (int) $keyRow->id;
                                $itemResult['reason'] = "An active invoice item (#{$keyRow->id}) already occupies this fee plan slot.";
                            }
                            $this->accumulateEvaluation($itemResult, $statusCounts, $totalEligible, $totalBlocked, $eligibleAmountMinor, $items);
                            continue;
                        }
                    }

                    // All checks passed! Eligible for creation.
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
