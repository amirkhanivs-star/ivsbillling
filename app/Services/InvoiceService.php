<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InvoiceService {
    /** An obligation is charged at most once per fee plan item and period. */
    public function generate(int $runId): array {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(600);
        return DB::transaction(function () use ($runId) {
            $run = DB::table('billing_runs')->where('id', $runId)->lockForUpdate()->first();
            if (!$run) throw ValidationException::withMessages(['run' => 'Billing run not found']);

            $allowed = json_decode($run->fee_type_ids ?: '[]', true) ?: [];
            $items = DB::table('fee_plan_items as p')
                ->join('enrollments as e', 'e.id', '=', 'p.enrollment_id')
                ->join('students as s', 's.id', '=', 'e.student_id')
                ->leftJoin('fee_types as ft', 'ft.id', '=', 'p.fee_type_id')
                ->where('e.campus_id', $run->campus_id)->where('e.department_id', $run->department_id)
                ->where('e.status', 'active')->where('p.is_active', true)->where('p.frequency', $run->frequency)
                ->whereNotIn('s.status', ['review_hold', 'historical_hold', 'suspended', 'left'])
                ->whereNotNull('e.currency')->where('e.currency', '!=', 'PENDING')->where('e.currency', '!=', '')
                ->whereNotNull('p.currency')->where('p.currency', '!=', 'PENDING')->where('p.currency', '!=', '')
                ->whereColumn('p.currency', 'e.currency')
                ->where(fn ($q) => $q->whereNull('e.started_on')->orWhere('e.started_on', '<=', $run->period_end))
                ->where(fn ($q) => $q->whereNull('e.ended_on')->orWhere('e.ended_on', '>=', $run->period_start))
                ->where(fn ($q) => $q->whereNull('p.effective_from')->orWhere('p.effective_from', '<=', $run->period_end))
                ->where(fn ($q) => $q->whereNull('p.effective_to')->orWhere('p.effective_to', '>=', $run->period_start))
                ->when(count($allowed) > 0, fn ($q) => $q->whereIn('p.fee_type_id', $allowed))
                ->select('p.*', 'e.student_id', 'e.campus_id', 'e.department_id', 's.family_id', 's.gr_number', 'ft.code as fee_type_code')->get();

            $historicalMap = app(\App\Services\Migration\HistoricalOpeningBalanceService::class)
                ->buildHistoricalObligationMap(
                    $items->pluck('gr_number')->filter()->unique()->values()->all()
                );

            $created = 0; $skipped = 0; $invoices = [];
            foreach ($items->groupBy(fn ($p) => $p->family_id.'|'.$p->currency) as $group) {
                $first = $group->first();
                // Reuse the existing unsent family invoice for the same period and frequency,
                // even if another billing run was created later.
                // Critical: NEVER reuse historical opening-balance invoices for recurring monthly fees!
                $invoice = DB::table('invoices as i')
                    ->join('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
                    ->where('b.frequency', $run->frequency)
                    ->where('b.frequency', '!=', 'opening_balance')
                    ->where('b.period_key', $run->period_key)
                    ->where('i.family_id', $first->family_id)
                    ->where('i.campus_id', $run->campus_id)
                    ->where('i.department_id', $run->department_id)
                    ->where('i.currency', $first->currency)
                    ->select('i.*')->lockForUpdate()->first();

                foreach ($group as $plan) {
                    // 1. Regular billing check: Has THIS specific fee plan item already been invoiced for this period?
                    // Requirement 5: Do not automatically restore deleted or voided fees.
                    $existingRecurring = DB::table('invoice_items')
                        ->where('fee_plan_item_id', $plan->id)
                        ->where('period_key', $run->period_key)
                        ->lockForUpdate()
                        ->first();

                    if ($existingRecurring) {
                        $skipped++;
                        continue;
                    }

                    // 2. Opening-balance check: Is this exact fee obligation already represented in historical opening balances?
                    // Must match: same enrollment, same period, opening_balance frequency, AND exact fee_type_id!
                    $obQuery = DB::table('invoice_items as ii')
                        ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
                        ->join('billing_runs as br', 'br.id', '=', 'inv.billing_run_id')
                        ->where('br.frequency', 'opening_balance')
                        ->where('ii.enrollment_id', $plan->enrollment_id)
                        ->where('ii.period_key', $run->period_key)
                        ->where('ii.fee_type_id', $plan->fee_type_id)
                        ->where('ii.status', '!=', 'voided')
                        ->whereNotIn('inv.status', ['void', 'voided']);

                    // If plan item has a specific subject, require the opening balance description to match that subject
                    if (!empty($plan->subject_name)) {
                        $obQuery->where('ii.description', 'LIKE', '%' . $plan->subject_name . '%');
                    }

                    if ($obQuery->exists()) {
                        $skipped++;
                        continue;
                    }

                    // 3. Staged historical settlement & currency-aware conflict check:
                    // Block recurring fee generation when the exact (GR, fee_type, period_start, subject_tag, currency)
                    // is historically settled, has staged dues, is a verified currency transition, or is a cross-currency conflict.
                    $histCheck = \App\Services\Migration\HistoricalOpeningBalanceService::checkHistoricalObligation(
                        $historicalMap,
                        (string) ($plan->gr_number ?? ''),
                        (string) ($plan->fee_type_code ?? 'monthly'),
                        (string) $run->period_start,
                        (string) $plan->currency,
                        $plan->subject_name ?? null
                    );

                    if ($histCheck !== null && ($histCheck['blocks_billing'] ?? true)) {
                        $skipped++;
                        continue;
                    }
                    if ($invoice && $invoice->approved_to_send && !in_array($invoice->status, ['void', 'voided'], true)) {
                        throw ValidationException::withMessages([
                            'run' => 'This period has an invoice already approved for sending. Review a correction rather than silently changing the sent amount.',
                        ]);
                    }
                    if (!$invoice) {
                        $previous = (int) DB::table('invoices as i')
                            ->join('billing_runs as br', 'br.id', '=', 'i.billing_run_id')
                            ->where('i.family_id', $first->family_id)
                            ->where('i.department_id', $run->department_id)->where('i.campus_id', $run->campus_id)
                            ->where('i.currency', $first->currency)->where('br.period_start', '<', $run->period_start)
                            ->whereNotIn('i.status', ['void', 'voided'])
                            ->selectRaw('COALESCE(SUM(i.total_minor-i.paid_minor),0) AS outstanding')->value('outstanding');
                        $invoiceId = DB::table('invoices')->insertGetId([
                            'billing_run_id' => $runId, 'family_id' => $first->family_id,
                            'department_id' => $run->department_id, 'campus_id' => $run->campus_id,
                            'currency' => $first->currency, 'invoice_number' => 'PENDING-'.Str::uuid(),
                            'issued_on' => now()->toDateString(), 'due_date' => $run->due_date,
                            'previous_dues_snapshot_minor' => max(0, $previous),
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $dept = DB::table('departments')->where('id', $run->department_id)->value('code') ?: 'FEE';
                        DB::table('invoices')->where('id', $invoiceId)->update([
                            'invoice_number' => strtoupper(substr($dept, 0, 3)).'-'.date('Y').'-'.str_pad((string) $invoiceId, 7, '0', STR_PAD_LEFT),
                        ]);
                        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
                        $created++;
                        $invoices[] = $invoiceId;
                    }
                    $discount = Money::discount((int) $plan->amount_minor, $plan->discount_kind, (int) $plan->discount_value);
                    DB::table('invoice_items')->insert([
                        'invoice_id' => $invoice->id, 'student_id' => $plan->student_id,
                        'enrollment_id' => $plan->enrollment_id, 'fee_plan_item_id' => $plan->id,
                        'fee_type_id' => $plan->fee_type_id, 'description' => $plan->description,
                        'period_key' => $run->period_key, 'period_start' => $run->period_start,
                        'period_end' => $run->period_end, 'currency' => $plan->currency,
                        'amount_minor' => $plan->amount_minor, 'discount_minor' => $discount,
                        'net_minor' => $plan->amount_minor - $discount,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                if ($invoice) $this->refreshInvoice($invoice->id);
                // The credit pool is shared across all invoices for the ledger. Never
                // prefer a newly-created invoice over an older eligible outstanding one.
                $this->allocatePrepayments($first->family_id, $first->currency, $run->department_id, $run->campus_id);
            }
            DB::table('billing_runs')->where('id', $runId)->update(['status' => 'generated', 'updated_at' => now()]);
            return compact('created', 'skipped', 'invoices');
        }, 3);
    }

    public function refreshInvoice(int $invoiceId, ?string $conn = null): void {
        $db = $conn ? DB::connection($conn) : DB::connection();
        $db->table('invoice_items')
            ->where('invoice_id', $invoiceId)
            ->where('status', 'partial')
            ->where('net_minor', '>', 0)
            ->whereColumn('paid_minor', '>=', 'net_minor')
            ->update(['status' => 'paid', 'updated_at' => now()]);

        $totals = $db->table('invoice_items')->where('invoice_id', $invoiceId)
            ->selectRaw("
                COUNT(*) AS total_count,
                SUM(CASE WHEN status = 'voided' THEN 1 ELSE 0 END) AS voided_count,
                SUM(CASE WHEN status != 'voided' THEN 1 ELSE 0 END) AS valid_count,
                COALESCE(SUM(CASE WHEN status != 'voided' THEN net_minor ELSE 0 END), 0) AS total,
                COALESCE(SUM(paid_minor), 0) AS paid
            ")->first();
        $invoice = $db->table('invoices')->where('id', $invoiceId)->first();
        if (!$invoice) return;

        $totalCount = (int) ($totals->total_count ?? 0);
        $validCount = (int) ($totals->valid_count ?? 0);
        $totalMinor = (int) ($totals->total ?? 0);
        $paidMinor = (int) ($totals->paid ?? 0);

        if ($totalCount > 0 && $validCount === 0 && $paidMinor <= 0) {
            $status = 'voided';
        } elseif ($totalCount === 0) {
            $status = $paidMinor > 0 ? 'paid' : 'generated';
        } elseif ($totalMinor <= 0 || $paidMinor >= $totalMinor) {
            $status = 'paid';
        } elseif ($paidMinor > 0) {
            $status = 'partial';
        } else {
            $status = 'generated';
        }

        $db->table('invoices')->where('id', $invoiceId)->update([
            'total_minor' => $totalMinor, 'paid_minor' => $paidMinor,
            'status' => $status, 'updated_at' => now(),
        ]);
    }

    public function allocatePrepayments(int $familyId, string $currency, int $deptId, int $campusId): void {
        $credits = DB::table('payments')->where('family_id', $familyId)->where('currency', $currency)
            ->where('department_id', $deptId)->where('campus_id', $campusId)
            ->where('unallocated_minor', '>', 0)->orderBy('received_on')->orderBy('id')->lockForUpdate()->get();
        $affected = [];
        foreach ($credits as $credit) {
            $remaining = (int) $credit->unallocated_minor;
            $items = DB::table('invoice_items as ii')->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
                ->where('i.family_id', $familyId)->where('i.currency', $currency)
                ->where('i.department_id', $deptId)->where('i.campus_id', $campusId)
                ->whereIn('ii.status', ['active', 'partial'])
                ->whereNotIn('i.status', ['void', 'voided'])
                ->whereRaw('ii.paid_minor < ii.net_minor')
                ->when($credit->selected_period, fn ($q) => $q
                    ->where('ii.period_start', '>=', $credit->selected_period.'-01')
                    ->where('ii.period_start', '<', \Carbon\Carbon::parse($credit->selected_period.'-01')->addMonth()->toDateString()))
                ->select('ii.*', 'i.due_date', 'i.id as parent_invoice_id')
                ->orderBy('ii.period_start')->orderBy('i.due_date')->orderBy('ii.id')->lockForUpdate()->get();
            foreach ($items as $item) {
                if ($remaining <= 0) break;
                $amount = min($remaining, (int) $item->net_minor - (int) $item->paid_minor);
                if ($amount <= 0) continue;
                $newItemPaid = (int) $item->paid_minor + $amount;
                DB::table('invoice_items')->where('id', $item->id)->update([
                    'paid_minor' => $newItemPaid,
                    'status' => ($item->status === 'partial' && $newItemPaid >= (int) $item->net_minor) ? 'paid' : $item->status,
                    'updated_at' => now(),
                ]);
                DB::table('payment_allocations')->insert([
                    'payment_id' => $credit->id, 'invoice_item_id' => $item->id,
                    'amount_minor' => $amount, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $remaining -= $amount;
                $affected[$item->parent_invoice_id] = true;
            }
            DB::table('payments')->where('id', $credit->id)->update(['unallocated_minor' => $remaining, 'updated_at' => now()]);
        }
        foreach (array_keys($affected) as $id) $this->refreshInvoice($id);
    }
}
