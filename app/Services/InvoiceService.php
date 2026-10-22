<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InvoiceService {
    /** An obligation is charged at most once per fee plan item and period. */
    public function generate(int $runId): array {
        return DB::transaction(function () use ($runId) {
            $run = DB::table('billing_runs')->where('id', $runId)->lockForUpdate()->first();
            if (!$run) throw ValidationException::withMessages(['run' => 'Billing run not found']);

            $allowed = json_decode($run->fee_type_ids ?: '[]', true) ?: [];
            $items = DB::table('fee_plan_items as p')
                ->join('enrollments as e', 'e.id', '=', 'p.enrollment_id')
                ->join('students as s', 's.id', '=', 'e.student_id')
                ->where('e.campus_id', $run->campus_id)->where('e.department_id', $run->department_id)
                ->where('e.status', 'active')->where('p.is_active', true)->where('p.frequency', $run->frequency)
                ->whereNotIn('s.status', ['review_hold', 'historical_hold', 'suspended', 'left'])
                ->where(fn ($q) => $q->whereNull('e.started_on')->orWhere('e.started_on', '<=', $run->period_end))
                ->where(fn ($q) => $q->whereNull('e.ended_on')->orWhere('e.ended_on', '>=', $run->period_start))
                ->where(fn ($q) => $q->whereNull('p.effective_from')->orWhere('p.effective_from', '<=', $run->period_end))
                ->where(fn ($q) => $q->whereNull('p.effective_to')->orWhere('p.effective_to', '>=', $run->period_start))
                ->when(count($allowed) > 0, fn ($q) => $q->whereIn('p.fee_type_id', $allowed))
                ->select('p.*', 'e.student_id', 'e.campus_id', 'e.department_id', 's.family_id')->get();

            $created = 0; $skipped = 0; $invoices = [];
            foreach ($items->groupBy(fn ($p) => $p->family_id.'|'.$p->currency) as $group) {
                $first = $group->first();
                // Reuse the existing unsent family invoice for the same period,
                // even if another billing run was created later. The database's
                // original unique key protects only one run, not the calendar period.
                $invoice = DB::table('invoices as i')
                    ->join('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
                    ->where('b.period_key', $run->period_key)
                    ->where('i.family_id', $first->family_id)
                    ->where('i.campus_id', $run->campus_id)
                    ->where('i.department_id', $run->department_id)
                    ->where('i.currency', $first->currency)
                    ->select('i.*')->lockForUpdate()->first();

                foreach ($group as $plan) {
                    if (DB::table('invoice_items')->where('fee_plan_item_id', $plan->id)->where('period_key', $run->period_key)->exists()) {
                        $skipped++;
                        continue;
                    }
                    if ($invoice && $invoice->approved_to_send) {
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

    public function refreshInvoice(int $invoiceId): void {
        $totals = DB::table('invoice_items')->where('invoice_id', $invoiceId)
            ->selectRaw('COALESCE(SUM(net_minor),0) AS total, COALESCE(SUM(paid_minor),0) AS paid')->first();
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        if (!$invoice) return;
        $status = $totals->total <= 0 || $totals->paid >= $totals->total
            ? 'paid' : ($totals->paid > 0 ? 'partial' : 'generated');
        DB::table('invoices')->where('id', $invoiceId)->update([
            'total_minor' => $totals->total, 'paid_minor' => $totals->paid,
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
                DB::table('invoice_items')->where('id', $item->id)->increment('paid_minor', $amount);
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
