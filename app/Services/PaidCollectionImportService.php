<?php

namespace App\Services;

use App\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaidCollectionImportService
{
    public const BATCH_ID = 'BATCH-20261023-FINAL-MYSKOOL';

    public function import(string $filePath, bool $commit = false): array
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("File not found at: {$filePath}");
        }

        $handle = fopen($filePath, 'r');
        $header = fgetcsv($handle);

        $students = DB::table('students')->select('id', 'gr_number', 'family_id', 'name')->get()->keyBy('gr_number');
        $enrollments = DB::table('enrollments')->select('id', 'student_id', 'department_id', 'campus_id', 'currency')->get()->groupBy('student_id');
        $families = DB::table('families')->select('id', 'family_code', 'guardian_name')->get()->keyBy('id');

        $feeTypes = DB::table('fee_types')->get()->keyBy('code');
        $monthlyFeeType = $feeTypes['monthly'] ?? DB::table('fee_types')->where('name', 'like', '%Monthly%')->first();
        $admissionFeeType = $feeTypes['admission'] ?? $feeTypes['registration'] ?? DB::table('fee_types')->where('name', 'like', '%Admission%')->orWhere('name', 'like', '%Registration%')->first();

        $maxStagedRow = (int) DB::table('qa_staged_collections')->where('batch_id', self::BATCH_ID)->max('source_row');

        $line = 0;
        $slipCounts = [];
        $csvTupleCounts = [];
        $stats = [
            'total_rows' => 0,
            'staged_created' => 0,
            'payments_created' => 0,
            'allocations_created' => 0,
            'existing_items_settled' => 0,
            'new_items_created' => 0,
            'invoices_refreshed' => [],
            'totals_by_currency' => [],
            'slips_count' => 0,
        ];

        $now = now()->toDateTimeString();

        DB::beginTransaction();

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                $stats['total_rows']++;

                $slip = trim($row[1] ?? '');
                $gr = trim($row[2] ?? '');
                $name = trim($row[3] ?? '');
                $part = trim($row[4] ?? '');
                $dateRaw = trim($row[5] ?? '');
                $currRaw = trim($row[6] ?? '');
                $amtRaw = trim($row[7] ?? '0');

                $amt = (float) str_replace(',', '', $amtRaw);
                if ($amt <= 0) {
                    continue;
                }
                $amtMinor = (int) round($amt * 100);

                $currency = $this->normalizeCurrency($currRaw);
                $stats['totals_by_currency'][$currency] = ($stats['totals_by_currency'][$currency] ?? 0) + $amt;

                $paymentDate = $this->parseDate($dateRaw);
                $periodInfo = $this->parsePeriod($part);
                $periodStart = $periodInfo['start'];
                $periodEnd = $periodInfo['end'];
                $periodKey = $periodInfo['key'];

                // Student resolution
                $student = $students[$gr] ?? null;
                if (!$student) {
                    // Resolve known family-code references or new students
                    if ($gr === '6211' && str_contains($name, 'Hattan')) {
                        $famId = 453; // Family 453 (Yazan)
                        $studentId = DB::table('students')->insertGetId([
                            'family_id' => $famId,
                            'gr_number' => '6211',
                            'name' => 'Hattan',
                            'status' => 'present',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $enrollmentId = DB::table('enrollments')->insertGetId([
                            'student_id' => $studentId,
                            'campus_id' => 1,
                            'department_id' => 1,
                            'currency' => $currency,
                            'status' => 'active',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $student = (object)['id' => $studentId, 'gr_number' => '6211', 'family_id' => $famId, 'name' => 'Hattan'];
                        $students['6211'] = $student;
                        $enrollments[$studentId] = collect([(object)['id' => $enrollmentId, 'student_id' => $studentId, 'campus_id' => 1, 'department_id' => 1, 'currency' => $currency]]);
                    } elseif ($gr === '7586' && str_contains($name, 'Saaid')) {
                        $famId = 1079; // Family 1079 (Humna)
                        $studentId = DB::table('students')->insertGetId([
                            'family_id' => $famId,
                            'gr_number' => '7586',
                            'name' => 'Saaid Muzammil',
                            'status' => 'present',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $enrollmentId = DB::table('enrollments')->insertGetId([
                            'student_id' => $studentId,
                            'campus_id' => 1,
                            'department_id' => 1,
                            'currency' => $currency,
                            'status' => 'active',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $student = (object)['id' => $studentId, 'gr_number' => '7586', 'family_id' => $famId, 'name' => 'Saaid Muzammil'];
                        $students['7586'] = $student;
                        $enrollments[$studentId] = collect([(object)['id' => $enrollmentId, 'student_id' => $studentId, 'campus_id' => 1, 'department_id' => 1, 'currency' => $currency]]);
                    } elseif ($gr === '10112' && str_contains($name, 'Rao')) {
                        $famId = DB::table('families')->insertGetId([
                            'family_code' => 'FAM-10112',
                            'guardian_name' => 'Rao Muhammad Ammar Guardian',
                            'is_provisional' => 0,
                            'contact_verified' => 1,
                            'messaging_opt_in' => 1,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $studentId = DB::table('students')->insertGetId([
                            'family_id' => $famId,
                            'gr_number' => '10112',
                            'name' => 'Rao Muhammad Ammar',
                            'status' => 'present',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $enrollmentId = DB::table('enrollments')->insertGetId([
                            'student_id' => $studentId,
                            'campus_id' => 1,
                            'department_id' => 1,
                            'currency' => $currency,
                            'status' => 'active',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $student = (object)['id' => $studentId, 'gr_number' => '10112', 'family_id' => $famId, 'name' => 'Rao Muhammad Ammar'];
                        $students['10112'] = $student;
                        $enrollments[$studentId] = collect([(object)['id' => $enrollmentId, 'student_id' => $studentId, 'campus_id' => 1, 'department_id' => 1, 'currency' => $currency]]);
                    } else {
                        continue;
                    }
                }

                $enList = $enrollments[$student->id] ?? collect();
                $enrollment = $enList->first();
                $deptId = $enrollment?->department_id ?? 1;
                $campusId = $enrollment?->campus_id ?? 1;
                $family = $families[$student->family_id] ?? null;

                $tupleKey = "{$slip}|{$gr}|{$part}|{$amtMinor}";
                $csvTupleCounts[$tupleKey] = ($csvTupleCounts[$tupleKey] ?? 0) + 1;
                $occurrenceIndex = $csvTupleCounts[$tupleKey];

                // 1. Stage in qa_staged_collections (idempotent check accounting for identical installment rows on same slip)
                $existingStagedCount = DB::table('qa_staged_collections')
                    ->where('batch_id', self::BATCH_ID)
                    ->where('slip_number', $slip)
                    ->where('gr_number', $gr)
                    ->where('fee_particular', $part)
                    ->where('amount_minor', $amtMinor)
                    ->count();

                $alreadyStaged = ($existingStagedCount >= $occurrenceIndex);

                if (!$alreadyStaged) {
                    $maxStagedRow++;
                    DB::table('qa_staged_collections')->insert([
                        'batch_id' => self::BATCH_ID,
                        'source_row' => $maxStagedRow,
                        'slip_number' => $slip,
                        'gr_number' => $gr,
                        'student_name' => $name,
                        'registration_name' => $student->name,
                        'family_code' => $family?->family_code ?? "FAM-{$student->family_id}",
                        'present_at_22oct' => 'Present',
                        'fee_particular' => $part,
                        'payment_date_raw' => $dateRaw,
                        'payment_date_parsed' => $paymentDate,
                        'payment_method' => 'Cash',
                        'currency' => $currency,
                        'currency_original' => $currRaw,
                        'amount' => $amt,
                        'amount_minor' => $amtMinor,
                        'registration_currency' => $enrollment?->currency ?? $currency,
                        'is_currency_unresolved' => 0,
                        'has_currency_conflict' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $stats['staged_created']++;
                }

                // 2. Insert payment with receipt numbering
                $slipCounts[$slip] = ($slipCounts[$slip] ?? 0) + 1;
                $receiptNum = 'REC-' . $slip . ($slipCounts[$slip] > 1 ? '-' . $slipCounts[$slip] : '');
                $idempotencyKey = "myskool-paid-slip-{$slip}-row-{$line}";

                $existingPayment = DB::table('payments')->where('idempotency_key', $idempotencyKey)->first();

                if ($existingPayment) {
                    $paymentId = $existingPayment->id;
                    $hasExistingAlloc = DB::table('payment_allocations')->where('payment_id', $paymentId)->exists();
                    if ($hasExistingAlloc) {
                        // Payment and allocation already exist; preserve financial balances and avoid duplicate obligations
                        continue;
                    }
                } else {
                    $paymentId = DB::table('payments')->insertGetId([
                        'family_id' => $student->family_id,
                        'campus_id' => $campusId,
                        'department_id' => $deptId,
                        'currency' => $currency,
                        'receipt_number' => $receiptNum,
                        'amount_minor' => $amtMinor,
                        'unallocated_minor' => 0,
                        'received_on' => $paymentDate,
                        'selected_period' => $periodKey,
                        'note' => "Collection slip #{$slip} ({$part})",
                        'idempotency_key' => $idempotencyKey,
                        'request_hash' => hash('sha256', $idempotencyKey),
                        'status' => 'received',
                        'received_by' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $stats['payments_created']++;
                }

                // 3. Match or create invoice item & allocation
                $feeTypeId = str_contains(strtolower($part), 'admission')
                    ? ($admissionFeeType?->id ?? 2)
                    : ($monthlyFeeType?->id ?? 1);

                $item = DB::table('invoice_items')
                    ->where('student_id', $student->id)
                    ->where('period_start', $periodStart)
                    ->where('currency', $currency)
                    ->where('status', 'active')
                    ->first();

                if ($item) {
                    // Check if allocation already exists
                    $existingAlloc = DB::table('payment_allocations')
                        ->where('payment_id', $paymentId)
                        ->where('invoice_item_id', $item->id)
                        ->exists();

                    if (!$existingAlloc) {
                        DB::table('payment_allocations')->insert([
                            'payment_id' => $paymentId,
                            'invoice_item_id' => $item->id,
                            'amount_minor' => $amtMinor,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $stats['allocations_created']++;

                        $newPaid = (int)$item->paid_minor + $amtMinor;
                        $newStatus = ($newPaid >= (int)$item->net_minor) ? 'paid' : 'active';
                        DB::table('invoice_items')->where('id', $item->id)->update([
                            'paid_minor' => $newPaid,
                            'status' => $newStatus,
                            'updated_at' => $now,
                        ]);

                        $stats['invoices_refreshed'][$item->invoice_id] = true;
                        $stats['existing_items_settled']++;
                    }
                } else {
                    // Check if an existing invoice or invoice item was already created
                    $existingAlloc = DB::table('payment_allocations')->where('payment_id', $paymentId)->first();

                    if (!$existingAlloc) {
                        $invoice = DB::table('invoices as i')
                            ->join('billing_runs as b', 'b.id', '=', 'i.billing_run_id')
                            ->where('i.family_id', $student->family_id)
                            ->where('i.currency', $currency)
                            ->where('b.period_start', 'like', "$periodKey%")
                            ->select('i.*')
                            ->first();

                        if (!$invoice) {
                            $billingRun = DB::table('billing_runs')
                                ->where('period_start', 'like', "$periodKey%")
                                ->where('campus_id', $campusId)
                                ->where('department_id', $deptId)
                                ->first();

                            if (!$billingRun) {
                                $billingRunId = DB::table('billing_runs')->insertGetId([
                                    'campus_id' => $campusId,
                                    'department_id' => $deptId,
                                    'frequency' => 'monthly',
                                    'period_key' => $periodKey,
                                    'period_start' => $periodStart,
                                    'period_end' => $periodEnd,
                                    'due_date' => date('Y-m-25', strtotime($periodStart)),
                                    'status' => 'generated',
                                    'created_by' => 1,
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ]);
                            } else {
                                $billingRunId = $billingRun->id;
                            }

                            $deptCode = ($deptId == 3) ? 'TUI' : 'SCH';
                            $invoiceId = DB::table('invoices')->insertGetId([
                                'billing_run_id' => $billingRunId,
                                'family_id' => $student->family_id,
                                'campus_id' => $campusId,
                                'department_id' => $deptId,
                                'currency' => $currency,
                                'invoice_number' => "{$deptCode}-" . date('Y') . "-TMP-" . uniqid(),
                                'issued_on' => $periodStart,
                                'due_date' => date('Y-m-25', strtotime($periodStart)),
                                'total_minor' => $amtMinor,
                                'paid_minor' => 0,
                                'previous_dues_snapshot_minor' => 0,
                                'status' => 'generated',
                                'approved_to_send' => 0,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                            DB::table('invoices')->where('id', $invoiceId)->update([
                                'invoice_number' => "{$deptCode}-" . date('Y') . "-" . str_pad((string)$invoiceId, 7, '0', STR_PAD_LEFT),
                            ]);
                        } else {
                            $invoiceId = $invoice->id;
                        }

                        $newItemId = DB::table('invoice_items')->insertGetId([
                            'invoice_id' => $invoiceId,
                            'student_id' => $student->id,
                            'enrollment_id' => $enrollment?->id,
                            'fee_plan_item_id' => null,
                            'fee_type_id' => $feeTypeId,
                            'description' => $part,
                            'period_key' => "monthly:{$periodStart}..{$periodEnd}",
                            'period_start' => $periodStart,
                            'period_end' => $periodEnd,
                            'currency' => $currency,
                            'amount_minor' => $amtMinor,
                            'discount_minor' => 0,
                            'exemption_minor' => 0,
                            'net_minor' => $amtMinor,
                            'paid_minor' => $amtMinor,
                            'status' => 'paid',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        DB::table('payment_allocations')->insert([
                            'payment_id' => $paymentId,
                            'invoice_item_id' => $newItemId,
                            'amount_minor' => $amtMinor,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $stats['allocations_created']++;
                        $stats['new_items_created']++;
                        $stats['invoices_refreshed'][$invoiceId] = true;
                    }
                }
            }
            fclose($handle);

            // Refresh affected invoices
            $invService = new InvoiceService();
            foreach (array_keys($stats['invoices_refreshed']) as $invId) {
                $invService->refreshInvoice((int) $invId);
            }

            $stats['slips_count'] = count($slipCounts);
            $stats['affected_invoices_count'] = count($stats['invoices_refreshed']);
            $stats['fully_paid_invoices_count'] = DB::table('invoices')
                ->whereIn('id', array_keys($stats['invoices_refreshed']))
                ->where('status', 'paid')
                ->count();

            if ($commit) {
                DB::commit();
            } else {
                DB::rollBack();
            }

            return $stats;

        } catch (\Throwable $e) {
            DB::rollBack();
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw $e;
        }
    }

    public function normalizeCurrency(?string $val): string
    {
        $c = strtoupper(trim(str_replace(['`', "'", '"', ' '], '', (string) $val)));
        if (in_array($c, ['SAR', 'SAFR', 'SAT', 'SR', 'SAR`'])) return 'SAR';
        if (in_array($c, ['AED', 'SAE', 'DHS', 'DIRHAM', 'AED`'])) return 'AED';
        if (in_array($c, ['RS', 'PKR', 'RUPEES', 'RS.'])) return 'PKR';
        if (in_array($c, ['QAR', 'QR'])) return 'QAR';
        if (in_array($c, ['USD', '$'])) return 'USD';
        if (in_array($c, ['OMR', 'RO'])) return 'OMR';
        if (in_array($c, ['BHD', 'BD'])) return 'BHD';
        return $c ?: 'SAR';
    }

    public function parseDate(string $val): string
    {
        $val = trim($val);
        if ($val === '') return '2026-10-23';

        if (preg_match('/^(\d{1,2})\s*-\s*([A-Za-z]{3})\s*-\s*(\d{2,4})$/', $val, $m)) {
            $day = (int) $m[1];
            $month = date('m', strtotime("1 {$m[2]} 2026"));
            $year = (int) $m[3];
            if ($year < 100) $year += 2000;
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        try {
            $ts = strtotime($val);
            if ($ts && $ts > 0) return date('Y-m-d', $ts);
        } catch (\Throwable) {}

        return '2026-10-23';
    }

    public function parsePeriod(string $part): array
    {
        $periodStart = '2026-10-01';
        $periodEnd = '2026-10-31';
        $periodKey = '2026-10';

        if (preg_match('/([A-Za-z]{3})-(\d{4})/', $part, $pm)) {
            $month = date('m', strtotime("1 {$pm[1]} {$pm[2]}"));
            $periodStart = "{$pm[2]}-{$month}-01";
            $periodEnd = date('Y-m-t', strtotime($periodStart));
            $periodKey = "{$pm[2]}-{$month}";
        } elseif (preg_match('/([A-Za-z]{3})-(\d{2})/', $part, $pm)) {
            $year = 2000 + (int) $pm[2];
            $month = date('m', strtotime("1 {$pm[1]} {$year}"));
            $periodStart = "{$year}-{$month}-01";
            $periodEnd = date('Y-m-t', strtotime($periodStart));
            $periodKey = "{$year}-{$month}";
        }

        return [
            'start' => $periodStart,
            'end' => $periodEnd,
            'key' => $periodKey,
        ];
    }
}
