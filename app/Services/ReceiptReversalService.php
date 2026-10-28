<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReceiptReversalService
{
    /**
     * Reverse an issued receipt without deleting the payment record.
     * Undoes all payment allocations, restores invoice item balances,
     * updates parent invoice statuses, and preserves full audit history.
     */
    public function reverseReceipt(int $paymentId, string $reason, int $userId): object
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A mandatory reason is required to reverse a receipt.']);
        }

        return DB::transaction(function () use ($paymentId, $reason, $userId) {
            $payment = DB::table('payments')->where('id', $paymentId)->lockForUpdate()->first();
            if (!$payment) {
                throw ValidationException::withMessages(['payment' => 'Payment receipt not found.']);
            }
            if ($payment->status === 'reversed') {
                throw ValidationException::withMessages(['payment' => 'This receipt has already been reversed.']);
            }

            // Fetch all allocations created by this payment (both immediate and prepaid)
            $allocations = DB::table('payment_allocations')->where('payment_id', $paymentId)->lockForUpdate()->get();
            $affectedInvoices = [];
            $reversedAllocationsData = [];

            foreach ($allocations as $alloc) {
                $item = DB::table('invoice_items')->where('id', $alloc->invoice_item_id)->lockForUpdate()->first();
                if ($item) {
                    $newPaid = max(0, (int) $item->paid_minor - (int) $alloc->amount_minor);
                    $restoredStatus = ($newPaid < (int) $item->net_minor && $item->status === 'paid')
                        ? ($newPaid > 0 ? 'partial' : 'active')
                        : ($newPaid === 0 && $item->status === 'partial' ? 'active' : $item->status);
                    DB::table('invoice_items')->where('id', $item->id)->update([
                        'paid_minor' => $newPaid,
                        'status' => $restoredStatus,
                        'updated_at' => now(),
                    ]);

                    // If this item has active fee installments, undo installment payments
                    $installments = DB::table('fee_installments')
                        ->where('invoice_item_id', $item->id)
                        ->where('paid_minor', '>', 0)
                        ->orderByDesc('installment_number')
                        ->lockForUpdate()->get();
                    $deductLeft = (int) $alloc->amount_minor;
                    foreach ($installments as $inst) {
                        if ($deductLeft <= 0) break;
                        $take = min($deductLeft, (int) $inst->paid_minor);
                        $instNewPaid = (int) $inst->paid_minor - $take;
                        DB::table('fee_installments')->where('id', $inst->id)->update([
                            'paid_minor' => $instNewPaid,
                            'status' => $instNewPaid <= 0 ? 'pending' : 'partial',
                            'updated_at' => now(),
                        ]);
                        $deductLeft -= $take;
                    }

                    $affectedInvoices[$item->invoice_id] = true;
                }

                $reversedAllocationsData[] = [
                    'invoice_item_id' => $alloc->invoice_item_id,
                    'amount_minor' => $alloc->amount_minor,
                ];
            }

            // Remove allocations now that item balances are restored
            DB::table('payment_allocations')->where('payment_id', $paymentId)->delete();

            // Mark payment as reversed and nullify any remaining advance pool
            DB::table('payments')->where('id', $paymentId)->update([
                'status' => 'reversed',
                'unallocated_minor' => 0,
                'reversed_at' => now(),
                'reversed_by' => $userId,
                'reversal_reason' => $reason,
                'updated_at' => now(),
            ]);

            // Refresh parent invoices
            $invoiceSvc = new InvoiceService();
            foreach (array_keys($affectedInvoices) as $invId) {
                $invoiceSvc->refreshInvoice($invId);
            }

            // Audit log
            DB::table('audit_logs')->insert([
                'actor_id' => $userId,
                'action' => 'payment_receipt_reversed',
                'subject_type' => 'payment',
                'subject_id' => $paymentId,
                'metadata' => json_encode([
                    'receipt_number' => $payment->receipt_number,
                    'amount_minor' => $payment->amount_minor,
                    'currency' => $payment->currency,
                    'family_id' => $payment->family_id,
                    'reason' => $reason,
                    'reversed_allocations' => $reversedAllocationsData,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('payments')->where('id', $paymentId)->first();
        }, 3);
    }
}
