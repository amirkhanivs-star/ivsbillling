<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FeeImportService
{
    private array $departments;
    private int $defaultCampusId;
    private array $feeTypesMap;

    public function __construct()
    {
        $this->departments = DB::table('departments')->pluck('id', 'code')->toArray();
        $this->defaultCampusId = DB::table('campuses')->value('id') ?? 1;
        $this->feeTypesMap = DB::table('fee_types')->pluck('id', 'name')->toArray();
    }

    private function getFeeTypeId(string $desc, int $deptId): int
    {
        $trimDesc = trim($desc);
        foreach ($this->feeTypesMap as $name => $id) {
            if (strcasecmp($name, $trimDesc) === 0) {
                return $id;
            }
        }

        if (stripos($trimDesc, 'Monthly') !== false) {
            return DB::table('fee_types')->where('code', 'monthly')->value('id') ?? 1;
        }
        if (stripos($trimDesc, 'Admission') !== false) {
            return DB::table('fee_types')->where('code', 'admission')->value('id') ?? 3;
        }
        if (stripos($trimDesc, 'SLC') !== false) {
            return DB::table('fee_types')->where('code', 'slc_charges')->value('id') ?? 9;
        }
        if (stripos($trimDesc, 'Book') !== false) {
            return DB::table('fee_types')->where('code', 'books')->value('id') ?? 4;
        }
        if (stripos($trimDesc, 'Exam') !== false) {
            return DB::table('fee_types')->where('code', 'exam')->value('id') ?? 5;
        }

        // Custom subject / course fee
        $slug = Str::slug(substr($trimDesc, 0, 40), '_');
        $existing = DB::table('fee_types')->where('code', $slug)->first();
        if ($existing) {
            $this->feeTypesMap[$trimDesc] = $existing->id;
            return $existing->id;
        }

        $id = DB::table('fee_types')->insertGetId([
            'name' => $trimDesc,
            'code' => $slug,
            'department_id' => $deptId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->feeTypesMap[$trimDesc] = $id;
        return $id;
    }

    public function normalizeCurrency(?string $val): string
    {
        $c = strtoupper(trim(str_replace(['`', "'", '"', ' '], '', (string)$val)));
        if (in_array($c, ['SAR', 'SAFR', 'SAT', 'SR', 'SAR`'])) return 'SAR';
        if (in_array($c, ['AED', 'SAE', 'DHS', 'DIRHAM', 'AED`'])) return 'AED';
        if (in_array($c, ['RS', 'PKR', 'RUPEES', 'RS.'])) return 'PKR';
        if (in_array($c, ['QAR', 'QR'])) return 'QAR';
        if (in_array($c, ['USD', '$'])) return 'USD';
        if (in_array($c, ['OMR', 'RO'])) return 'OMR';
        if (in_array($c, ['BHD', 'BD'])) return 'BHD';
        return $c ?: 'SAR';
    }

    public function parsePaidDate(?string $val): string
    {
        $val = trim((string)$val);
        if ($val === '') return '2026-08-01';

        // 1-Mar-25 or 15-Oct-2026
        if (preg_match('/^(\d{1,2})\s*-\s*([A-Za-z]{3})\s*-\s*(\d{2,4})$/', $val, $m)) {
            $day = (int)$m[1];
            $monthName = $m[2];
            $year = (int)$m[3];
            if ($year < 100) $year += 2000;
            $mNum = date('m', strtotime("1 $monthName $year"));
            return sprintf('%04d-%02d-%02d', $year, $mNum, $day);
        }
        // 15 - Oct (default year 2026)
        if (preg_match('/^(\d{1,2})\s*-\s*([A-Za-z]{3})$/', $val, $m)) {
            $day = (int)$m[1];
            $mNum = date('m', strtotime("1 {$m[2]} 2026"));
            return sprintf('2026-%02d-%02d', $mNum, $day);
        }
        try {
            $ts = strtotime($val);
            if ($ts && $ts > 0) return date('Y-m-d', $ts);
        } catch (\Throwable) {}

        return '2026-08-01';
    }

    public function importOutstanding(string $filePath): array
    {
        throw new \RuntimeException('Direct-to-ledger outstanding fee imports are disabled during Phase 1 Recovery Protection.');

        $currentStudent = null;
        $studentsCache = DB::table('students')->select('id', 'gr_number', 'family_id')->get()->keyBy('gr_number')->toArray();
        $enrollmentsCache = DB::table('enrollments')->select('id', 'student_id', 'department_id', 'campus_id', 'grade_label')->get()->groupBy('student_id')->toArray();
        $billingRunsCache = [];
        $invoicesCache = [];

        $headerFound = false;
        $line = 0;
        $itemsImported = 0;
        $studentsCount = [];
        $now = now()->toDateTimeString();

        $grCol = 1; $nameCol = 2; $contactCol = 3; $partCol = 4; $currCol = 5; $balCol = 6;

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if (!$headerFound) {
                    $joined = strtolower(implode(' ', array_map('trim', $row)));
                    if (str_contains($joined, 'grn') && str_contains($joined, 'particular')) {
                        $headerFound = true;
                        foreach ($row as $idx => $h) {
                            $hLow = strtolower(trim($h));
                            if ($hLow === 'grn' || $hLow === 'gr' || $hLow === 'gr no') $grCol = $idx;
                            if (str_contains($hLow, 'student') || str_contains($hLow, 'name')) $nameCol = $idx;
                            if (str_contains($hLow, 'contact')) $contactCol = $idx;
                            if (str_contains($hLow, 'particular')) $partCol = $idx;
                            if (str_contains($hLow, 'currency')) $currCol = $idx;
                            if (str_contains($hLow, 'balance') || str_contains($hLow, 'amount')) $balCol = $idx;
                        }
                    }
                    continue;
                }

                $c0 = trim($row[0] ?? '');
                $c1 = trim($row[$grCol] ?? '');
                $c2 = trim($row[$nameCol] ?? '');
                $c4 = trim($row[$partCol] ?? '');

                // Check layout for Currency and Balance
                if ($currCol !== -1 && isset($row[$balCol]) && is_numeric(str_replace(',', '', $row[$balCol]))) {
                    $currency = $this->normalizeCurrency($row[$currCol] ?? 'SAR');
                    $balanceRaw = trim($row[$balCol]);
                } elseif (isset($row[6]) && is_numeric(str_replace(',', '', $row[6]))) {
                    $currency = $this->normalizeCurrency($row[5] ?? 'SAR');
                    $balanceRaw = trim($row[6]);
                } else {
                    $currency = 'SAR';
                    $balanceRaw = trim($row[5] ?? '');
                }

                // Skip class total summary rows e.g. "FS-1 - A 171 students - Rs 293,086"
                if (stripos($c1, 'students') !== false || stripos($c0, 'students') !== false || stripos($c1, 'grand total') !== false) {
                    $currentGr = '';
                    $currentStudent = null;
                    continue;
                }

                // Skip summation/subtotal rows e.g. "2,400" or 555 in col 1 with empty particular
                if ($c1 !== '' && $c2 === '' && $c4 === '') {
                    $currentGr = '';
                    $currentStudent = null;
                    continue;
                }

                // Class header line (e.g. FS-1 - A in col 0, or in col 1)
                if ($c0 !== '' && $c1 === '' && $c4 === '') {
                    $currentClass = $c0;
                    continue;
                }
                if ($c1 !== '' && $c2 === '' && $c4 === '' && $balanceRaw === '' && !is_numeric(str_replace(',', '', $c1))) {
                    $currentClass = $c1;
                    continue;
                }

                // Student main line
                $cleanGr = trim(str_replace(',', '', $c1));
                if ($cleanGr !== '' && is_numeric($cleanGr) && $c2 !== '') {
                    $currentGr = $cleanGr;
                    $currentStudent = $studentsCache[$currentGr] ?? null;
                    if ($currentStudent) {
                        $studentsCount[$currentGr] = true;
                    }
                }

                // Obligation line
                if ($currentGr !== '' && $currentStudent && $c4 !== '' && is_numeric(str_replace(',', '', $balanceRaw))) {
                    $balance = (float)str_replace(',', '', $balanceRaw);
                    if ($balance <= 0) continue;

                    $amountMinor = (int)round($balance * 100);

                    // Parse particular (e.g. Monthly Fee - Jul-2025)
                    $periodStart = '2026-08-01';
                    $desc = $c4;
                    if (preg_match('/^(.*?)\s*-\s*([A-Za-z]{3})-(\d{4})$/', $c4, $pm)) {
                        $desc = trim($pm[1]);
                        $month = date('m', strtotime("1 {$pm[2]} {$pm[3]}"));
                        $periodStart = "{$pm[3]}-{$month}-01";
                    } elseif (preg_match('/^(.*?)\s*-\s*([A-Za-z]{3})\s+(\d{4})$/', $c4, $pm)) {
                        $desc = trim($pm[1]);
                        $month = date('m', strtotime("1 {$pm[2]} {$pm[3]}"));
                        $periodStart = "{$pm[3]}-{$month}-01";
                    } elseif (preg_match('/^(.*?)\s*-\s*([A-Za-z]{3})-(\d{2})$/', $c4, $pm)) {
                        $desc = trim($pm[1]);
                        $year = 2000 + (int)$pm[3];
                        $month = date('m', strtotime("1 {$pm[2]} {$year}"));
                        $periodStart = "{$year}-{$month}-01";
                    } elseif (preg_match('/^(.*?)\s*-\s*([A-Za-z]{3})$/', $c4, $pm)) {
                        $desc = trim($pm[1]);
                        $month = date('m', strtotime("1 {$pm[2]} 2026"));
                        $periodStart = "2026-{$month}-01";
                    }
                    $periodEnd = date('Y-m-t', strtotime($periodStart));
                    $periodKey = substr($periodStart, 0, 7);

                    // Resolve enrollment
                    $enList = $enrollmentsCache[$currentStudent->id] ?? [];
                    $enrollment = $enList[0] ?? null;
                    $deptId = $enrollment?->department_id ?? ($this->departments['school'] ?? 1);
                    $campusId = $enrollment?->campus_id ?? $this->defaultCampusId;
                    $feeTypeId = $this->getFeeTypeId($desc, $deptId);

                    // Billing Run cache
                    $runKey = "{$campusId}:{$deptId}:{$periodStart}";
                    if (!isset($billingRunsCache[$runKey])) {
                        $run = DB::table('billing_runs')->where('campus_id', $campusId)->where('department_id', $deptId)->where('period_start', $periodStart)->where('frequency', 'monthly')->first();
                        if (!$run) {
                            $runId = DB::table('billing_runs')->insertGetId([
                                'campus_id' => $campusId,
                                'department_id' => $deptId,
                                'frequency' => 'monthly',
                                'period_key' => $periodKey,
                                'period_start' => $periodStart,
                                'period_end' => $periodEnd,
                                'due_date' => $periodEnd,
                                'status' => 'generated',
                                'created_by' => 1,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        } else {
                            $runId = $run->id;
                        }
                        $billingRunsCache[$runKey] = $runId;
                    }
                    $runId = $billingRunsCache[$runKey];

                    // Invoice cache (separated by currency)
                    $familyId = $currentStudent->family_id;
                    $invKey = "{$runId}:{$familyId}:{$currency}";
                    if (!isset($invoicesCache[$invKey])) {
                        $inv = DB::table('invoices')->where('billing_run_id', $runId)->where('family_id', $familyId)->where('currency', $currency)->whereNotIn('status', ['void', 'voided'])->first();
                        if (!$inv) {
                            $deptCode = DB::table('departments')->where('id', $deptId)->value('code') ?? 'SCH';
                            $invId = DB::table('invoices')->insertGetId([
                                'billing_run_id' => $runId,
                                'family_id' => $familyId,
                                'campus_id' => $campusId,
                                'department_id' => $deptId,
                                'currency' => $currency,
                                'invoice_number' => 'PENDING-' . Str::uuid(),
                                'issued_on' => $periodStart,
                                'due_date' => $periodEnd,
                                'total_minor' => 0,
                                'paid_minor' => 0,
                                'previous_dues_snapshot_minor' => 0,
                                'status' => 'generated',
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                            DB::table('invoices')->where('id', $invId)->update([
                                'invoice_number' => strtoupper(substr($deptCode, 0, 3)) . '-' . date('Y') . '-' . str_pad((string)$invId, 7, '0', STR_PAD_LEFT),
                            ]);
                        } else {
                            $invId = $inv->id;
                        }
                        $invoicesCache[$invKey] = $invId;
                    }
                    $invId = $invoicesCache[$invKey];

                    // Insert invoice item if not already exists
                    $exists = DB::table('invoice_items')
                        ->where('invoice_id', $invId)
                        ->where('student_id', $currentStudent->id)
                        ->where('fee_type_id', $feeTypeId)
                        ->where('period_start', $periodStart)
                        ->where('currency', $currency)
                        ->exists();

                    if (!$exists) {
                        DB::table('invoice_items')->insert([
                            'invoice_id' => $invId,
                            'student_id' => $currentStudent->id,
                            'enrollment_id' => $enrollment?->id ?? null,
                            'fee_type_id' => $feeTypeId,
                            'description' => $desc,
                            'period_key' => $periodKey,
                            'period_start' => $periodStart,
                            'period_end' => $periodEnd,
                            'currency' => $currency,
                            'amount_minor' => $amountMinor,
                            'discount_minor' => 0,
                            'net_minor' => $amountMinor,
                            'paid_minor' => 0,
                            'status' => 'active',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $itemsImported++;
                    }
                }
            }

            // Refresh all invoices
            $invService = new InvoiceService();
            foreach ($invoicesCache as $id) {
                $invService->refreshInvoice($id);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($handle);
            throw $e;
        }

        fclose($handle);

        return [
            'students' => count($studentsCount),
            'items' => $itemsImported,
            'invoices' => count($invoicesCache),
        ];
    }

    public function importCollection(string $filePath): array
    {
        throw new \RuntimeException('Direct-to-ledger historical collection imports are disabled during Phase 1 Recovery Protection.');

        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Collection CSV not found at {$filePath}");
        }

        $handle = fopen($filePath, 'r');
        $line = 0;
        $currentSlip = '';
        $currentGr = '';
        $studentsCache = DB::table('students')->select('id', 'gr_number', 'family_id')->get()->keyBy('gr_number')->toArray();
        $enrollmentsCache = DB::table('enrollments')->select('id', 'student_id', 'department_id', 'campus_id')->get()->groupBy('student_id')->toArray();

        $headerFound = false;
        $receiptsImported = 0;
        $itemsPaid = 0;
        $now = now()->toDateTimeString();

        $slipCol = 1; $grCol = 2; $nameCol = 3; $partCol = 4; $dateCol = 5; $currCol = 6; $amtCol = 7;

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if (!$headerFound) {
                    $joined = strtolower(implode(' ', array_map('trim', $row)));
                    if (str_contains($joined, 'slip') && (str_contains($joined, 'particular') || str_contains($joined, 'amount'))) {
                        $headerFound = true;
                        foreach ($row as $idx => $h) {
                            $hLow = strtolower(trim($h));
                            if (str_contains($hLow, 'slip')) $slipCol = $idx;
                            if ($hLow === 'grn' || $hLow === 'gr' || $hLow === 'gr no') $grCol = $idx;
                            if (str_contains($hLow, 'student') || str_contains($hLow, 'name')) $nameCol = $idx;
                            if (str_contains($hLow, 'particular')) $partCol = $idx;
                            if (str_contains($hLow, 'date')) $dateCol = $idx;
                            if (str_contains($hLow, 'currency')) $currCol = $idx;
                            if (str_contains($hLow, 'amount') || str_contains($hLow, 'balance')) $amtCol = $idx;
                        }
                    }
                    continue;
                }

                $slip = trim($row[$slipCol] ?? '');
                $gr = trim(str_replace(',', '', $row[$grCol] ?? ''));
                $part = trim($row[$partCol] ?? '');
                $dateRaw = trim($row[$dateCol] ?? '');
                $currRaw = $currCol !== -1 ? trim($row[$currCol] ?? 'SAR') : 'SAR';
                $amtRaw = trim($row[$amtCol] ?? '');

                if ($currCol === -1 || !is_numeric(str_replace(',', '', $amtRaw))) {
                    if (isset($row[6]) && is_numeric(str_replace(',', '', $row[6]))) {
                        $amtRaw = trim($row[6]);
                        $currRaw = 'SAR';
                    }
                }
                $currency = $this->normalizeCurrency($currRaw);

                if ($slip !== '') $currentSlip = $slip;
                if ($gr !== '') $currentGr = $gr;

                if ($currentSlip !== '' && $currentGr !== '' && $part !== '' && is_numeric(str_replace(',', '', $amtRaw))) {
                    $amt = (float)str_replace(',', '', $amtRaw);
                    if ($amt <= 0) continue;
                    $amountMinor = (int)round($amt * 100);

                    $student = $studentsCache[$currentGr] ?? null;
                    if (!$student) continue;

                    // Parse receipt date
                    $receivedOn = $this->parsePaidDate($dateRaw);

                    // Parse particular & period
                    $periodStart = '2026-08-01';
                    $desc = $part;
                    if (preg_match('/^(.*?)\s*-\s*([A-Za-z]{3})-(\d{4})$/', $part, $pm)) {
                        $desc = trim($pm[1]);
                        $mNum = date('m', strtotime("1 {$pm[2]} {$pm[3]}"));
                        $periodStart = "{$pm[3]}-{$mNum}-01";
                    } elseif (preg_match('/^(.*?)\s*-\s*([A-Za-z]{3})\s+(\d{4})$/', $part, $pm)) {
                        $desc = trim($pm[1]);
                        $mNum = date('m', strtotime("1 {$pm[2]} {$pm[3]}"));
                        $periodStart = "{$pm[3]}-{$mNum}-01";
                    } elseif (preg_match('/^(.*?)\s*-\s*([A-Za-z]{3})-(\d{2})$/', $part, $pm)) {
                        $desc = trim($pm[1]);
                        $year = 2000 + (int)$pm[3];
                        $mNum = date('m', strtotime("1 {$pm[2]} {$year}"));
                        $periodStart = "{$year}-{$mNum}-01";
                    } elseif (preg_match('/^(.*?)\s*-\s*([A-Za-z]{3})$/', $part, $pm)) {
                        $desc = trim($pm[1]);
                        $mNum = date('m', strtotime("1 {$pm[2]} 2026"));
                        $periodStart = "2026-{$mNum}-01";
                    }

                    $enList = $enrollmentsCache[$student->id] ?? [];
                    $enrollment = $enList[0] ?? null;
                    $deptId = $enrollment?->department_id ?? ($this->departments['school'] ?? 1);
                    $campusId = $enrollment?->campus_id ?? $this->defaultCampusId;
                    $feeTypeId = $this->getFeeTypeId($desc, $deptId);

                    // Find matching active unpaid invoice item
                    $item = DB::table('invoice_items')
                        ->where('student_id', $student->id)
                        ->where('fee_type_id', $feeTypeId)
                        ->where('period_start', $periodStart)
                        ->where('currency', $currency)
                        ->where('status', 'active')
                        ->first();

                    $slipKey = (string)$currentSlip;
                    $slipCounts[$slipKey] = ($slipCounts[$slipKey] ?? 0) + 1;
                    $receiptNum = 'REC-' . $currentSlip . ($slipCounts[$slipKey] > 1 ? '-' . $slipCounts[$slipKey] : '');

                    // Record payment matching schema
                    $paymentId = DB::table('payments')->insertGetId([
                        'family_id' => $student->family_id,
                        'campus_id' => $campusId,
                        'department_id' => $deptId,
                        'currency' => $currency,
                        'amount_minor' => $amountMinor,
                        'unallocated_minor' => 0,
                        'received_on' => $receivedOn,
                        'receipt_number' => $receiptNum,
                        'note' => "Collection slip #{$currentSlip}",
                        'idempotency_key' => "import-slip-{$currentSlip}-{$line}",
                        'request_hash' => md5("slip-{$currentSlip}-{$line}"),
                        'received_by' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $receiptsImported++;

                    if ($item) {
                        DB::table('payment_allocations')->insert([
                            'payment_id' => $paymentId,
                            'invoice_item_id' => $item->id,
                            'amount_minor' => $amountMinor,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        $newPaid = (int)$item->paid_minor + $amountMinor;
                        $itemStatus = ($newPaid >= (int)$item->net_minor) ? 'paid' : 'active';
                        DB::table('invoice_items')->where('id', $item->id)->update([
                            'paid_minor' => $newPaid,
                            'status' => $itemStatus,
                            'updated_at' => $now,
                        ]);

                        (new InvoiceService())->refreshInvoice($item->invoice_id);
                        $itemsPaid++;
                    }
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($handle);
            throw $e;
        }

        fclose($handle);

        return [
            'receipts' => $receiptsImported,
            'items_paid' => $itemsPaid,
        ];
    }

    public function clearFeeData(): void
    {
        throw new \RuntimeException('Dangerous operation disabled: raw database ledger truncation is locked for Phase 1 Recovery Protection.');
    }

    public function clearAllData(): void
    {
        throw new \RuntimeException('Dangerous operation disabled: mass database purge is locked for Phase 1 Recovery Protection.');
    }
}
