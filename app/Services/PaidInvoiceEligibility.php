<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PaidInvoiceEligibility
{
    public const RECEIVED_FROM = '2026-10-09';

    public static function constrain(Builder $query, string $alias = 'i'): Builder
    {
        return $query->where("{$alias}.status", 'paid')
            ->where("{$alias}.total_minor", '>', 0)
            ->whereColumn("{$alias}.paid_minor", '>=', "{$alias}.total_minor")
            ->whereExists(function (Builder $receipt) use ($alias) {
                self::receivedAllocation($receipt, $alias)->where(function (Builder $scope) use ($alias) {
                    $scope->where('receipt_p.received_on', '>=', self::RECEIVED_FROM)
                        ->orWhere(function (Builder $portal) use ($alias) {
                            self::portalGenerated($portal, $alias);
                            self::portalPayment($portal);
                        });
                });
            });
    }

    public static function eligible(int $invoiceId): bool
    {
        return self::constrain(DB::table('invoices as i'))->where('i.id', $invoiceId)->exists();
    }

    public static function portalPaid(int $invoiceId): bool
    {
        $query = self::constrain(DB::table('invoices as i'))->where('i.id', $invoiceId);
        self::portalGenerated($query, 'i');
        return $query->whereExists(function (Builder $receipt) {
            self::receivedAllocation($receipt, 'i');
            self::portalPayment($receipt);
        })->exists();
    }

    private static function receivedAllocation(Builder $receipt, string $alias): Builder
    {
        return $receipt->selectRaw('1')->from('invoice_items as receipt_ii')
            ->join('payment_allocations as receipt_pa', 'receipt_pa.invoice_item_id', '=', 'receipt_ii.id')
            ->join('payments as receipt_p', 'receipt_p.id', '=', 'receipt_pa.payment_id')
            ->whereColumn('receipt_ii.invoice_id', "{$alias}.id")
            ->where('receipt_p.status', 'received')->whereNull('receipt_p.reversed_at')
            ->where('receipt_pa.amount_minor', '>', 0);
    }

    private static function portalPayment(Builder $query): void
    {
        $query->whereExists(fn (Builder $audit) => $audit->selectRaw('1')->from('audit_logs as receipt_audit')
            ->where('receipt_audit.action', 'payment_received')->where('receipt_audit.subject_type', 'payment')
            ->whereNotNull('receipt_audit.actor_id')->whereColumn('receipt_audit.subject_id', 'receipt_p.id'));
    }

    private static function portalGenerated(Builder $query, string $alias): void
    {
        // Positive relational provenance: every item belongs to a matching fee
        // plan, and the invoice has neither opening-balance run nor import lineage.
        // Unknown/mixed provenance keeps the historical receipt cutoff.
        $query->whereExists(fn (Builder $run) => $run->selectRaw('1')->from('billing_runs as portal_run')
            ->whereColumn('portal_run.id', "{$alias}.billing_run_id")
            ->where('portal_run.frequency', '!=', 'opening_balance')->whereNotNull('portal_run.created_by'))
            ->whereNotExists(fn (Builder $lineage) => $lineage->selectRaw('1')->from('qa_opening_balance_lineage as portal_lineage')
                ->whereColumn('portal_lineage.invoice_id', "{$alias}.id"))
            ->whereExists(fn (Builder $items) => $items->selectRaw('1')->from('invoice_items as portal_any')
                ->whereColumn('portal_any.invoice_id', "{$alias}.id"))
            ->whereNotExists(function (Builder $items) use ($alias) {
                $items->selectRaw('1')->from('invoice_items as portal_item')->whereColumn('portal_item.invoice_id', "{$alias}.id")
                    ->whereNotExists(fn (Builder $plan) => $plan->selectRaw('1')->from('fee_plan_items as portal_plan')
                        ->whereColumn('portal_plan.id', 'portal_item.fee_plan_item_id')
                        ->whereColumn('portal_plan.enrollment_id', 'portal_item.enrollment_id')
                        ->whereColumn('portal_plan.fee_type_id', 'portal_item.fee_type_id')
                        ->whereColumn('portal_plan.currency', 'portal_item.currency'));
            });
    }
}
