<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FeeAdjustmentService
{
    /**
     * Apply an ad-hoc discount to an existing invoice item.
     * Decreases net_minor and increases discount_minor without inflating cash received.
     */
    public function applyDiscount(int $invoiceItemId, int $discountMinor, string $reason, int $userId): object
    {
        if ($discountMinor <= 0) {
            throw ValidationException::withMessages(['amount' => 'Discount must be greater than zero.']);
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A mandatory reason is required to record a discount.']);
        }

        return DB::transaction(function () use ($invoiceItemId, $discountMinor, $reason, $userId) {
            $item = DB::table('invoice_items')->where('id', $invoiceItemId)->lockForUpdate()->first();
            if (!$item) {
                throw ValidationException::withMessages(['item' => 'Invoice item not found.']);
            }
            if ($item->status === 'voided') {
                throw ValidationException::withMessages(['item' => 'Cannot discount a voided fee obligation.']);
            }

            $currentBalance = max(0, (int) $item->net_minor - (int) $item->paid_minor);
            if ($discountMinor > $currentBalance) {
                throw ValidationException::withMessages([
                    'amount' => 'Discount cannot exceed the remaining balance (' . Money::format($currentBalance) . ' ' . $item->currency . ').',
                ]);
            }

            $newDiscount = (int) $item->discount_minor + $discountMinor;
            $newNet = max((int) $item->paid_minor, (int) $item->net_minor - $discountMinor);
            $newBalance = $currentBalance - $discountMinor;

            DB::table('invoice_items')->where('id', $invoiceItemId)->update([
                'discount_minor' => $newDiscount,
                'net_minor' => $newNet,
                'status' => ($newNet <= (int) $item->paid_minor && (int) $item->paid_minor > 0) ? 'paid' : $item->status,
                'updated_at' => now(),
            ]);

            DB::table('fee_adjustments')->insert([
                'invoice_item_id' => $invoiceItemId,
                'user_id' => $userId,
                'kind' => 'discount',
                'amount_minor' => $discountMinor,
                'previous_amount_minor' => $currentBalance,
                'new_amount_minor' => $newBalance,
                'reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            (new InvoiceService())->refreshInvoice($item->invoice_id);

            DB::table('audit_logs')->insert([
                'actor_id' => $userId,
                'action' => 'fee_discount_applied',
                'subject_type' => 'invoice_item',
                'subject_id' => $invoiceItemId,
                'metadata' => json_encode([
                    'discount_minor' => $discountMinor,
                    'previous_balance_minor' => $currentBalance,
                    'new_balance_minor' => $newBalance,
                    'currency' => $item->currency,
                    'reason' => $reason,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('invoice_items')->where('id', $invoiceItemId)->first();
        }, 3);
    }

    /**
     * Remove or revert an existing discount from an invoice item.
     * Restores net_minor back to original amount (less exemptions) and resets discount_minor to zero.
     */
    public function removeDiscount(int $invoiceItemId, string $reason, int $userId): object
    {
        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'Discount removed by user';
        }

        return DB::transaction(function () use ($invoiceItemId, $reason, $userId) {
            $item = DB::table('invoice_items')->where('id', $invoiceItemId)->lockForUpdate()->first();
            if (!$item) {
                throw ValidationException::withMessages(['item' => 'Invoice item not found.']);
            }
            if ((int) $item->discount_minor <= 0) {
                throw ValidationException::withMessages(['amount' => 'This fee obligation has no discount to remove.']);
            }

            $currentBalance = max(0, (int) $item->net_minor - (int) $item->paid_minor);
            $removedDiscount = (int) $item->discount_minor;
            $newNet = max((int) $item->paid_minor, (int) $item->amount_minor - (int) $item->exemption_minor);
            $newBalance = max(0, $newNet - (int) $item->paid_minor);

            DB::table('invoice_items')->where('id', $invoiceItemId)->update([
                'discount_minor' => 0,
                'net_minor' => $newNet,
                'status' => ($newNet <= (int) $item->paid_minor && (int) $item->paid_minor > 0) ? 'paid' : 'active',
                'updated_at' => now(),
            ]);

            DB::table('fee_adjustments')->insert([
                'invoice_item_id' => $invoiceItemId,
                'user_id' => $userId,
                'kind' => 'discount_removed',
                'amount_minor' => $removedDiscount,
                'previous_amount_minor' => $currentBalance,
                'new_amount_minor' => $newBalance,
                'reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            (new InvoiceService())->refreshInvoice($item->invoice_id);

            DB::table('audit_logs')->insert([
                'actor_id' => $userId,
                'action' => 'fee_discount_removed',
                'subject_type' => 'invoice_item',
                'subject_id' => $invoiceItemId,
                'metadata' => json_encode([
                    'removed_discount_minor' => $removedDiscount,
                    'previous_balance_minor' => $currentBalance,
                    'new_balance_minor' => $newBalance,
                    'currency' => $item->currency,
                    'reason' => $reason,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('invoice_items')->where('id', $invoiceItemId)->first();
        }, 3);
    }

    /**
     * Apply a full or partial exemption to an invoice item.
     */
    public function applyExemption(int $invoiceItemId, ?int $exemptionMinor, string $reason, int $userId): object
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A mandatory reason is required to record a fee exemption.']);
        }

        return DB::transaction(function () use ($invoiceItemId, $exemptionMinor, $reason, $userId) {
            $item = DB::table('invoice_items')->where('id', $invoiceItemId)->lockForUpdate()->first();
            if (!$item) {
                throw ValidationException::withMessages(['item' => 'Invoice item not found.']);
            }
            if ($item->status === 'voided') {
                throw ValidationException::withMessages(['item' => 'Cannot exempt a voided fee obligation.']);
            }

            $currentBalance = max(0, (int) $item->net_minor - (int) $item->paid_minor);
            if ($currentBalance <= 0) {
                throw ValidationException::withMessages(['item' => 'This fee obligation has no outstanding balance to exempt.']);
            }

            $exemptAmount = ($exemptionMinor === null || $exemptionMinor <= 0) ? $currentBalance : min($exemptionMinor, $currentBalance);
            $newExemption = (int) $item->exemption_minor + $exemptAmount;
            $newNet = (int) $item->net_minor - $exemptAmount;
            $newBalance = $currentBalance - $exemptAmount;
            $newStatus = ($newBalance === 0 && (int) $item->paid_minor === 0) ? 'exempted' : $item->status;

            DB::table('invoice_items')->where('id', $invoiceItemId)->update([
                'exemption_minor' => $newExemption,
                'net_minor' => $newNet,
                'status' => $newStatus,
                'updated_at' => now(),
            ]);

            DB::table('fee_adjustments')->insert([
                'invoice_item_id' => $invoiceItemId,
                'user_id' => $userId,
                'kind' => 'exemption',
                'amount_minor' => $exemptAmount,
                'previous_amount_minor' => $currentBalance,
                'new_amount_minor' => $newBalance,
                'reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            (new InvoiceService())->refreshInvoice($item->invoice_id);

            DB::table('audit_logs')->insert([
                'actor_id' => $userId,
                'action' => 'fee_exemption_applied',
                'subject_type' => 'invoice_item',
                'subject_id' => $invoiceItemId,
                'metadata' => json_encode([
                    'exemption_minor' => $exemptAmount,
                    'previous_balance_minor' => $currentBalance,
                    'new_balance_minor' => $newBalance,
                    'currency' => $item->currency,
                    'reason' => $reason,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('invoice_items')->where('id', $invoiceItemId)->first();
        }, 3);
    }

    /**
     * Void an incorrectly created fee item (only if it has zero payments).
     */
    public function voidFee(int $invoiceItemId, string $reason, int $userId): object
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A mandatory reason is required to void a fee.']);
        }

        return DB::transaction(function () use ($invoiceItemId, $reason, $userId) {
            $item = DB::table('invoice_items')->where('id', $invoiceItemId)->lockForUpdate()->first();
            if (!$item) {
                throw ValidationException::withMessages(['item' => 'Invoice item not found.']);
            }
            if ($item->status === 'voided') {
                throw ValidationException::withMessages(['item' => 'This fee obligation is already voided.']);
            }
            $hasAllocations = DB::table('payment_allocations')
                ->where('invoice_item_id', $invoiceItemId)
                ->where('amount_minor', '>', 0)
                ->exists();
            if ((int) $item->paid_minor > 0 || $hasAllocations) {
                throw ValidationException::withMessages([
                    'item' => 'Cannot void an obligation that has already received payments. Reverse payment receipt(s) first.',
                ]);
            }

            $currentBalance = (int) $item->net_minor;

            DB::table('invoice_items')->where('id', $invoiceItemId)->update([
                'status' => 'voided',
                'net_minor' => 0,
                'updated_at' => now(),
            ]);

            DB::table('fee_installments')
                ->where('invoice_item_id', $invoiceItemId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);

            DB::table('fee_adjustments')->insert([
                'invoice_item_id' => $invoiceItemId,
                'user_id' => $userId,
                'kind' => 'void',
                'amount_minor' => $currentBalance,
                'previous_amount_minor' => $currentBalance,
                'new_amount_minor' => 0,
                'reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            (new InvoiceService())->refreshInvoice($item->invoice_id);

            DB::table('audit_logs')->insert([
                'actor_id' => $userId,
                'action' => 'fee_voided',
                'subject_type' => 'invoice_item',
                'subject_id' => $invoiceItemId,
                'metadata' => json_encode([
                    'voided_amount_minor' => $currentBalance,
                    'currency' => $item->currency,
                    'reason' => $reason,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('invoice_items')->where('id', $invoiceItemId)->first();
        }, 3);
    }

    /**
     * Safely restore/recreate a previously voided, unpaid fee obligation in place,
     * preserving the (fee_plan_item_id, period_key) uniqueness constraint and full audit history.
     */
    public function recreateVoidedFee(
        int $invoiceItemId,
        array $attributes = [],
        ?int $userId = null,
        string $reason = 'Recreated previously voided fee obligation'
    ): object {
        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'Recreated previously voided fee obligation';
        }

        return DB::transaction(function () use ($invoiceItemId, $attributes, $userId, $reason) {
            $item = DB::table('invoice_items')->where('id', $invoiceItemId)->lockForUpdate()->first();
            if (!$item) {
                throw ValidationException::withMessages(['item' => 'Invoice item not found.']);
            }
            if ($item->status !== 'voided') {
                throw ValidationException::withMessages([
                    'item' => 'Only a voided fee obligation can be recreated.',
                ]);
            }

            $hasAllocations = DB::table('payment_allocations')
                ->where('invoice_item_id', $invoiceItemId)
                ->where('amount_minor', '>', 0)
                ->exists();
            if ((int) $item->paid_minor > 0 || $hasAllocations) {
                throw ValidationException::withMessages([
                    'item' => 'Cannot recreate or reactivate a fee obligation that has received payments or active payment allocations.',
                ]);
            }

            $previousInvoiceId = (int) $item->invoice_id;
            $targetInvoiceId = (int) ($attributes['invoice_id'] ?? $item->invoice_id);
            $studentId = (int) ($attributes['student_id'] ?? $item->student_id);
            $enrollmentId = array_key_exists('enrollment_id', $attributes) ? $attributes['enrollment_id'] : $item->enrollment_id;
            $feePlanItemId = array_key_exists('fee_plan_item_id', $attributes) ? $attributes['fee_plan_item_id'] : $item->fee_plan_item_id;
            $feeTypeId = array_key_exists('fee_type_id', $attributes) ? $attributes['fee_type_id'] : $item->fee_type_id;
            $description = (string) ($attributes['description'] ?? $item->description);
            $periodKey = (string) ($attributes['period_key'] ?? $item->period_key);
            $periodStart = (string) ($attributes['period_start'] ?? $item->period_start);
            $periodEnd = (string) ($attributes['period_end'] ?? $item->period_end);
            $currency = strtoupper(trim((string) ($attributes['currency'] ?? $item->currency)));
            $amountMinor = (int) ($attributes['amount_minor'] ?? $item->amount_minor);
            $discountMinor = (int) ($attributes['discount_minor'] ?? 0);
            $exemptionMinor = (int) ($attributes['exemption_minor'] ?? 0);
            $netMinor = isset($attributes['net_minor'])
                ? (int) $attributes['net_minor']
                : max(0, $amountMinor - $discountMinor - $exemptionMinor);

            // Historical settlement protection must be checked before restoring or recreating a fee.
            $grNumber = (string) (DB::table('students')->where('id', $studentId)->value('gr_number') ?? '');
            if ($grNumber !== '') {
                $feeTypeCode = $feeTypeId
                    ? (string) (DB::table('fee_types')->where('id', $feeTypeId)->value('code') ?? 'monthly')
                    : 'monthly';
                $subjectName = $feePlanItemId
                    ? DB::table('fee_plan_items')->where('id', $feePlanItemId)->value('subject_name')
                    : null;
                $historicalMap = app(\App\Services\Migration\HistoricalOpeningBalanceService::class)
                    ->buildHistoricalObligationMap([$grNumber]);
                $histCheck = \App\Services\Migration\HistoricalOpeningBalanceService::checkHistoricalObligation(
                    $historicalMap,
                    $grNumber,
                    $feeTypeCode,
                    $periodStart,
                    $currency,
                    $subjectName
                );
                if ($histCheck === null && !in_array($feeTypeCode, ['monthly', 'tuition'], true)) {
                    $histCheck = \App\Services\Migration\HistoricalOpeningBalanceService::checkHistoricalObligation(
                        $historicalMap,
                        $grNumber,
                        $feeTypeCode,
                        'ONE_TIME',
                        $currency,
                        $subjectName
                    );
                }
                if ($histCheck !== null && ($histCheck['blocks_billing'] ?? true)) {
                    $msg = !empty($histCheck['is_settled'])
                        ? 'Payment has already been received for this fee and period.'
                        : 'This fee period is already represented in historical MySkool records and cannot be recreated.';
                    throw ValidationException::withMessages([
                        'fee_type_ids' => $msg,
                        'item' => $msg,
                    ]);
                }
            }

            DB::table('invoice_items')->where('id', $invoiceItemId)->update([
                'invoice_id' => $targetInvoiceId,
                'student_id' => $studentId,
                'enrollment_id' => $enrollmentId,
                'fee_plan_item_id' => $feePlanItemId,
                'fee_type_id' => $feeTypeId,
                'description' => $description,
                'period_key' => $periodKey,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'currency' => $currency,
                'amount_minor' => $amountMinor,
                'discount_minor' => $discountMinor,
                'exemption_minor' => $exemptionMinor,
                'net_minor' => $netMinor,
                'paid_minor' => 0,
                'status' => 'active',
                'updated_at' => now(),
            ]);

            DB::table('fee_installments')
                ->where('invoice_item_id', $invoiceItemId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);

            $actorId = $userId ?: auth()->id();
            $adjustmentUserId = $actorId ?: DB::table('users')->orderBy('id')->value('id');
            if ($adjustmentUserId) {
                DB::table('fee_adjustments')->insert([
                    'invoice_item_id' => $invoiceItemId,
                    'user_id' => (int) $adjustmentUserId,
                    'kind' => 'recreated',
                    'amount_minor' => $netMinor,
                    'previous_amount_minor' => 0,
                    'new_amount_minor' => $netMinor,
                    'reason' => $reason,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $invoiceSvc = new InvoiceService();
            if ($previousInvoiceId !== $targetInvoiceId) {
                $invoiceSvc->refreshInvoice($previousInvoiceId);
            }
            $invoiceSvc->refreshInvoice($targetInvoiceId);

            DB::table('audit_logs')->insert([
                'actor_id' => $actorId,
                'action' => 'fee_recreated',
                'subject_type' => 'invoice_item',
                'subject_id' => $invoiceItemId,
                'metadata' => json_encode([
                    'invoice_id' => $targetInvoiceId,
                    'previous_invoice_id' => $previousInvoiceId,
                    'fee_plan_item_id' => $feePlanItemId,
                    'period_key' => $periodKey,
                    'amount_minor' => $amountMinor,
                    'discount_minor' => $discountMinor,
                    'net_minor' => $netMinor,
                    'currency' => $currency,
                    'reason' => $reason,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('invoice_items')->where('id', $invoiceItemId)->first();
        }, 3);
    }

    /**
     * Create an installment schedule for an unpaid balance.
     * @param list<array{amount_minor:int,due_date:string}> $schedule
     */
    public function createInstallmentPlan(int $invoiceItemId, array $schedule, int $userId): array
    {
        if (count($schedule) < 2) {
            throw ValidationException::withMessages(['installments' => 'An installment plan must have at least 2 installments.']);
        }

        return DB::transaction(function () use ($invoiceItemId, $schedule, $userId) {
            $item = DB::table('invoice_items')->where('id', $invoiceItemId)->lockForUpdate()->first();
            if (!$item) {
                throw ValidationException::withMessages(['item' => 'Invoice item not found.']);
            }
            if ($item->status === 'voided' || $item->status === 'exempted') {
                throw ValidationException::withMessages(['item' => 'Cannot schedule installments on a voided or exempted fee.']);
            }

            $currentBalance = max(0, (int) $item->net_minor - (int) $item->paid_minor);
            if ($currentBalance <= 0) {
                throw ValidationException::withMessages(['item' => 'This fee obligation has no outstanding balance to schedule.']);
            }

            $totalScheduled = 0;
            foreach ($schedule as $i => $row) {
                $amt = (int) ($row['amount_minor'] ?? 0);
                if ($amt <= 0) {
                    throw ValidationException::withMessages(["installments.{$i}.amount" => 'Each installment amount must be greater than zero.']);
                }
                if (empty($row['due_date'])) {
                    throw ValidationException::withMessages(["installments.{$i}.due_date" => 'Each installment must have a due date.']);
                }
                $totalScheduled += $amt;
            }

            if ($totalScheduled !== $currentBalance) {
                throw ValidationException::withMessages([
                    'installments' => 'Total installment amounts (' . Money::format($totalScheduled) . ') must exactly match the outstanding balance (' . Money::format($currentBalance) . ' ' . $item->currency . ').',
                ]);
            }

            // Cancel any previous pending installments for this item
            DB::table('fee_installments')->where('invoice_item_id', $invoiceItemId)->where('status', 'pending')->delete();

            $created = [];
            foreach ($schedule as $index => $row) {
                $id = DB::table('fee_installments')->insertGetId([
                    'invoice_item_id' => $invoiceItemId,
                    'installment_number' => $index + 1,
                    'amount_minor' => (int) $row['amount_minor'],
                    'paid_minor' => 0,
                    'due_date' => $row['due_date'],
                    'status' => 'pending',
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created[] = DB::table('fee_installments')->where('id', $id)->first();
            }

            DB::table('audit_logs')->insert([
                'actor_id' => $userId,
                'action' => 'installment_plan_created',
                'subject_type' => 'invoice_item',
                'subject_id' => $invoiceItemId,
                'metadata' => json_encode([
                    'count' => count($schedule),
                    'total_scheduled_minor' => $totalScheduled,
                    'currency' => $item->currency,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $created;
        }, 3);
    }
}
