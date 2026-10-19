<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReceivePaymentService
{
    /**
     * Receipts require an amount, not bank details, verification, or proof.
     * Supports unified or separate Monthly Fee and Registration Fee receiving,
     * one-time registration charge establishment, and specific-month targeting.
     */
    public function receive(array $input, int $userId): object
    {
        $input['currency'] = strtoupper(trim((string) ($input['currency'] ?? '')));
        $familyId = (int) $input['family_id'];
        $campusId = (int) $input['campus_id'];
        $departmentId = (int) $input['department_id'];
        $currency = $input['currency'];

        // Determine registration and monthly payment amounts
        $hasSeparateRegistration = !empty($input['receive_registration_fee']) || !empty($input['registration_amount']);
        $regPaymentMinor = 0;
        if (!empty($input['registration_amount'])) {
            $regPaymentMinor = Money::minor($input['registration_amount']);
        }

        $monthlyPaymentMinor = 0;
        if (isset($input['monthly_amount']) && $input['monthly_amount'] !== '') {
            $monthlyPaymentMinor = Money::minor($input['monthly_amount']);
        } elseif (isset($input['receiving_amount']) && $input['receiving_amount'] !== '') {
            $totalInputMinor = Money::minor($input['receiving_amount']);
            if ($hasSeparateRegistration) {
                // If receiving_amount was supplied alongside registration_amount, monthly is the remainder
                $monthlyPaymentMinor = max(0, $totalInputMinor - $regPaymentMinor);
            } else {
                $monthlyPaymentMinor = $totalInputMinor;
            }
        }

        $totalMinor = $regPaymentMinor + $monthlyPaymentMinor;
        if ($totalMinor <= 0) {
            throw ValidationException::withMessages(['receiving_amount' => 'Total receiving amount must be greater than zero.']);
        }

        $digest = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($input, $userId, $familyId, $campusId, $departmentId, $currency, $totalMinor, $regPaymentMinor, $monthlyPaymentMinor, $digest) {
            $existing = DB::table('payments')->where('idempotency_key', $input['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->request_hash !== $digest) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Key already used for a different payment.']);
                }
                return $existing;
            }

            // Validate that the family has an active enrollment or prior invoice in this ledger
            $hasEnrollment = DB::table('enrollments as e')
                ->join('students as s', 's.id', '=', 'e.student_id')
                ->where('s.family_id', $familyId)
                ->where('e.campus_id', $campusId)
                ->where('e.department_id', $departmentId)
                ->where('e.currency', $currency)
                ->where('e.status', 'active')->exists();

            $hasInvoice = DB::table('invoices')
                ->where('family_id', $familyId)
                ->where('campus_id', $campusId)
                ->where('department_id', $departmentId)
                ->where('currency', $currency)->exists();

            if (!$hasEnrollment && !$hasInvoice) {
                throw ValidationException::withMessages([
                    'currency' => 'This family has no enrollment or invoice for the selected campus, department and currency.',
                ]);
            }

            // Ensure registration fee type exists
            $regFeeType = DB::table('fee_types')->where('code', 'registration')->first();
            if (!$regFeeType) {
                $regFeeTypeId = DB::table('fee_types')->insertGetId([
                    'department_id' => null,
                    'name' => 'Registration Fee',
                    'code' => 'registration',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $regFeeTypeId = $regFeeType->id;
            }

            // Handle optional manual establishment of Registration Fee obligation
            if (!empty($input['create_registration_charge'])) {
                $regChargeMinor = Money::minor($input['create_registration_charge']);
                if ($regChargeMinor > 0) {
                    // Prevent duplicate registration charges for this family in this ledger
                    $existingRegItem = DB::table('invoice_items as ii')
                        ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
                        ->where('i.family_id', $familyId)->where('i.campus_id', $campusId)
                        ->where('i.department_id', $departmentId)->where('i.currency', $currency)
                        ->where('ii.fee_type_id', $regFeeTypeId)
                        ->where('ii.status', '!=', 'voided')
                        ->first();

                    if ($existingRegItem) {
                        throw ValidationException::withMessages([
                            'registration_amount' => 'A Registration Fee obligation is already established for this family ledger.',
                        ]);
                    }

                    // Find an existing invoice or create one to hold the registration fee
                    $targetInvoice = DB::table('invoices')
                        ->where('family_id', $familyId)->where('campus_id', $campusId)
                        ->where('department_id', $departmentId)->where('currency', $currency)
                        ->orderByDesc('id')->lockForUpdate()->first();

                    $student = DB::table('students as s')
                        ->join('enrollments as e', 'e.student_id', '=', 's.id')
                        ->where('s.family_id', $familyId)->where('e.campus_id', $campusId)
                        ->where('e.department_id', $departmentId)->where('e.currency', $currency)
                        ->select('s.id as student_id', 'e.id as enrollment_id')->first();

                    if (!$student) {
                        throw ValidationException::withMessages(['family_id' => 'Cannot create registration charge: no student enrollment found in this ledger.']);
                    }

                    if (!$targetInvoice) {
                        $deptCode = DB::table('departments')->where('id', $departmentId)->value('code') ?: 'FEE';
                        $runId = DB::table('billing_runs')->insertGetId([
                            'campus_id' => $campusId, 'department_id' => $departmentId,
                            'frequency' => 'one_time', 'period_key' => 'registration:' . $student->student_id . ':' . date('Ymd'),
                            'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
                            'due_date' => now()->toDateString(),
                            'fee_type_ids' => json_encode([$regFeeTypeId]),
                            'status' => 'generated', 'created_by' => $userId,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);

                        $invoiceId = DB::table('invoices')->insertGetId([
                            'billing_run_id' => $runId, 'family_id' => $familyId,
                            'campus_id' => $campusId, 'department_id' => $departmentId,
                            'currency' => $currency, 'invoice_number' => 'PENDING-' . Str::uuid(),
                            'issued_on' => now()->toDateString(), 'due_date' => now()->toDateString(),
                            'total_minor' => $regChargeMinor, 'paid_minor' => 0,
                            'previous_dues_snapshot_minor' => 0, 'status' => 'generated',
                            'approved_to_send' => false,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);

                        DB::table('invoices')->where('id', $invoiceId)->update([
                            'invoice_number' => strtoupper(substr($deptCode, 0, 3)) . '-' . date('Y') . '-' . str_pad((string) $invoiceId, 7, '0', STR_PAD_LEFT),
                        ]);
                    } else {
                        $invoiceId = $targetInvoice->id;
                    }

                    DB::table('invoice_items')->insert([
                        'invoice_id' => $invoiceId,
                        'student_id' => $student->student_id,
                        'enrollment_id' => $student->enrollment_id,
                        'fee_plan_item_id' => null,
                        'fee_type_id' => $regFeeTypeId,
                        'description' => 'Registration Fee',
                        'period_key' => 'one_time:reg:' . $student->student_id,
                        'period_start' => now()->toDateString(),
                        'period_end' => now()->toDateString(),
                        'currency' => $currency,
                        'amount_minor' => $regChargeMinor,
                        'discount_minor' => 0,
                        'exemption_minor' => 0,
                        'net_minor' => $regChargeMinor,
                        'paid_minor' => 0,
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    (new InvoiceService())->refreshInvoice($invoiceId);
                }
            }

            // Create primary payment record
            $paymentId = DB::table('payments')->insertGetId([
                'family_id' => $familyId,
                'campus_id' => $campusId,
                'department_id' => $departmentId,
                'currency' => $currency,
                'receipt_number' => 'PENDING-' . Str::uuid(),
                'amount_minor' => $totalMinor,
                'unallocated_minor' => $totalMinor,
                'received_on' => $input['receiving_date'] ?? now()->toDateString(),
                'selected_period' => $input['selected_period'] ?? null,
                'note' => $input['note'] ?? null,
                'status' => 'received',
                'received_by' => $userId,
                'idempotency_key' => $input['idempotency_key'],
                'request_hash' => $digest,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('payments')->where('id', $paymentId)->update([
                'receipt_number' => 'RCP-' . date('Y') . '-' . str_pad((string) $paymentId, 7, '0', STR_PAD_LEFT),
            ]);

            $affectedInvoices = [];
            $unallocatedMinor = $totalMinor;

            // 1. ALLOCATE REGISTRATION FEE (if specified)
            if ($regPaymentMinor > 0) {
                $regItems = DB::table('invoice_items as ii')
                    ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
                    ->where('i.family_id', $familyId)->where('i.campus_id', $campusId)
                    ->where('i.department_id', $departmentId)->where('i.currency', $currency)
                    ->where('ii.fee_type_id', $regFeeTypeId)
                    ->where('ii.status', 'active')
                    ->whereRaw('ii.paid_minor < ii.net_minor')
                    ->select('ii.*', 'i.id as parent_invoice_id')
                    ->orderBy('ii.id')->lockForUpdate()->get();

                if ($regItems->isEmpty()) {
                    throw ValidationException::withMessages([
                        'registration_amount' => 'No unpaid Registration Fee obligation found. Create the registration charge or deselect registration fee.',
                    ]);
                }

                $regLeft = $regPaymentMinor;
                foreach ($regItems as $regItem) {
                    if ($regLeft <= 0) break;
                    $due = max(0, (int) $regItem->net_minor - (int) $regItem->paid_minor);
                    $take = min($regLeft, $due);
                    if ($take <= 0) continue;

                    DB::table('invoice_items')->where('id', $regItem->id)->increment('paid_minor', $take);
                    DB::table('payment_allocations')->insert([
                        'payment_id' => $paymentId,
                        'invoice_item_id' => $regItem->id,
                        'amount_minor' => $take,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $regLeft -= $take;
                    $unallocatedMinor -= $take;
                    $affectedInvoices[$regItem->parent_invoice_id] = true;
                }
            }

            // 2. ALLOCATE MONTHLY / REGULAR FEES
            if ($monthlyPaymentMinor > 0) {
                $monthlyQuery = DB::table('invoice_items as ii')
                    ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
                    ->where('i.family_id', $familyId)->where('i.campus_id', $campusId)
                    ->where('i.department_id', $departmentId)->where('i.currency', $currency)
                    ->where('ii.fee_type_id', '!=', $regFeeTypeId)
                    ->where('ii.status', 'active')
                    ->whereRaw('ii.paid_minor < ii.net_minor');

                // If a specific month is selected, target only that month's obligations
                if (!empty($input['selected_period'])) {
                    $periodStart = $input['selected_period'] . '-01';
                    $periodEnd = Carbon::parse($periodStart)->addMonth()->toDateString();
                    $monthlyQuery->where('ii.period_start', '>=', $periodStart)
                                 ->where('ii.period_start', '<', $periodEnd);
                }

                $monthlyItems = $monthlyQuery->select('ii.*', 'i.due_date', 'i.id as parent_invoice_id')
                    ->orderBy('ii.period_start')->orderBy('i.due_date')->orderBy('ii.id')
                    ->lockForUpdate()->get();

                $monthlyLeft = $monthlyPaymentMinor;
                foreach ($monthlyItems as $item) {
                    if ($monthlyLeft <= 0) break;
                    $due = max(0, (int) $item->net_minor - (int) $item->paid_minor);
                    $take = min($monthlyLeft, $due);
                    if ($take <= 0) continue;

                    DB::table('invoice_items')->where('id', $item->id)->increment('paid_minor', $take);
                    DB::table('payment_allocations')->insert([
                        'payment_id' => $paymentId,
                        'invoice_item_id' => $item->id,
                        'amount_minor' => $take,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Sync installment status if item has scheduled installments
                    $installments = DB::table('fee_installments')
                        ->where('invoice_item_id', $item->id)
                        ->where('status', '!=', 'paid')
                        ->orderBy('installment_number')->lockForUpdate()->get();

                    $instTakeLeft = $take;
                    foreach ($installments as $inst) {
                        if ($instTakeLeft <= 0) break;
                        $instDue = max(0, (int) $inst->amount_minor - (int) $inst->paid_minor);
                        $applyToInst = min($instTakeLeft, $instDue);
                        if ($applyToInst <= 0) continue;
                        $newPaid = (int) $inst->paid_minor + $applyToInst;
                        DB::table('fee_installments')->where('id', $inst->id)->update([
                            'paid_minor' => $newPaid,
                            'status' => $newPaid >= (int) $inst->amount_minor ? 'paid' : 'partial',
                            'updated_at' => now(),
                        ]);
                        $instTakeLeft -= $applyToInst;
                    }

                    $monthlyLeft -= $take;
                    $unallocatedMinor -= $take;
                    $affectedInvoices[$item->parent_invoice_id] = true;
                }
            }

            // Update remaining unallocated advance amount
            DB::table('payments')->where('id', $paymentId)->update([
                'unallocated_minor' => $unallocatedMinor,
                'updated_at' => now(),
            ]);

            // Refresh parent invoices
            $invoiceSvc = new InvoiceService();
            foreach (array_keys($affectedInvoices) as $id) {
                $invoiceSvc->refreshInvoice($id);
            }

            // Audit log
            DB::table('audit_logs')->insert([
                'actor_id' => $userId,
                'action' => 'payment_received',
                'subject_type' => 'payment',
                'subject_id' => $paymentId,
                'metadata' => json_encode([
                    'total_amount_minor' => $totalMinor,
                    'registration_amount_minor' => $regPaymentMinor,
                    'monthly_amount_minor' => $monthlyPaymentMinor,
                    'unallocated_minor' => $unallocatedMinor,
                    'currency' => $currency,
                    'selected_period' => $input['selected_period'] ?? null,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('payments')->where('id', $paymentId)->first();
        }, 3);
    }
}
