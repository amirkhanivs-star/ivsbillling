<?php

namespace App\Services\Staging;

class MySkoolReportParser
{
    /**
     * Parse MySkool Outstanding (Dues) Report CSV with full hierarchical normalization.
     *
     * Handles:
     * - School title blocks
     * - Class header rows
     * - Student main rows
     * - Student continuation rows (inheriting student identifiers only where continuity is proven)
     * - Subtotal/summation rows (skipping them and resetting active student context)
     * - Blank rows
     * - Thousands-separated monetary amounts
     * - Exact currency normalization (preserving unresolved currencies without guessing)
     * - Period key and canonical fee description extraction
     *
     * @param string $filePath
     * @return array<int, array<string, mixed>>
     */
    public function parseDuesReport(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException("Outstanding report CSV not found or unreadable: {$filePath}");
        }

        $fp = fopen($filePath, 'r');
        if (!$fp) {
            throw new \RuntimeException("Unable to open file: {$filePath}");
        }

        $headerFound = false;
        $currentClass = 'General';
        $currentGr = '';
        $currentName = '';
        $currentContact = '';
        $dues = [];
        $lineNum = 0;

        while (($row = fgetcsv($fp)) !== false) {
            $lineNum++;

            // Detect table column header row
            if (!$headerFound) {
                $joined = strtolower(implode(' ', array_map('trim', $row)));
                if (str_contains($joined, 'grn') && str_contains($joined, 'particular')) {
                    $headerFound = true;
                    $firstCol = trim($row[0] ?? '');
                    if ($firstCol !== '' && strtolower($firstCol) !== 'class') {
                        $currentClass = $firstCol;
                    }
                }
                continue;
            }

            $col0 = trim($row[0] ?? ''); // Class header
            $col1 = trim($row[1] ?? ''); // GRN or Subtotal Amount
            $col2 = trim($row[2] ?? ''); // Student Name
            $col3 = trim($row[3] ?? ''); // Contact #
            $col4 = trim($row[4] ?? ''); // Particular
            $col5 = trim($row[5] ?? ''); // Currency
            $col6 = trim($row[6] ?? ''); // Balance Amount

            // Repeated column header row for subsequent classes: e.g. "FS-1 - B,GRN,Student Name..."
            if (str_contains(strtolower($col1), 'grn') && str_contains(strtolower($col4), 'particular')) {
                if ($col0 !== '' && strtolower($col0) !== 'class') {
                    $currentClass = $col0;
                    $currentGr = '';
                    $currentName = '';
                    $currentContact = '';
                }
                continue;
            }

            // 1. Class section header: col 0 populated, other critical columns empty
            if ($col0 !== '' && $col1 === '' && $col4 === '') {
                $currentClass = $col0;
                $currentGr = '';
                $currentName = '';
                $currentContact = '';
                continue;
            }

            // 2. Subtotal/summation row: col 1 has amount/summation, but col 2 and col 4 are blank
            if ($col1 !== '' && $col2 === '' && $col4 === '') {
                // Reset student context immediately so subsequent rows cannot inherit past a subtotal
                $currentGr = '';
                $currentName = '';
                $currentContact = '';
                continue;
            }

            // 3. Class summary lines e.g. "FS-1 - A 171 students - Rs 293,086"
            if (stripos($col1, 'students') !== false || stripos($col0, 'students') !== false || stripos($col0, 'total') !== false) {
                $currentGr = '';
                $currentName = '';
                $currentContact = '';
                continue;
            }

            // 4. Student main row: col 1 is numeric GR, col 2 has student name
            $cleanCol1 = str_replace(',', '', $col1);
            if ($col1 !== '' && is_numeric($cleanCol1) && $col2 !== '') {
                $currentGr = trim($cleanCol1);
                $currentName = $col2;
                $currentContact = $col3;
            }

            // 5. Valid Fee Detail Item: must have active student GR, non-empty particular, and positive numeric amount
            $cleanAmount = str_replace(',', '', $col6);
            if ($currentGr !== '' && $col4 !== '' && is_numeric($cleanAmount)) {
                $amount = (float)$cleanAmount;
                if ($amount <= 0) {
                    continue;
                }

                $curr = $this->normalizeCurrency($col5);
                $periodKey = $this->extractPeriodKey($col4);
                $feeDesc = $this->extractCanonicalFeeDescription(null, $col4);

                $dues[] = [
                    'source_row' => $lineNum,
                    'report_class' => $currentClass,
                    'gr_number' => $currentGr,
                    'student_name' => $currentName,
                    'contact_raw' => $currentContact,
                    'particular_raw' => $col4,
                    'fee_description' => $feeDesc,
                    'period_key' => $periodKey,
                    'currency_iso' => $curr,
                    'outstanding_amount' => $amount,
                ];
            }
        }

        fclose($fp);
        return $dues;
    }

    /**
     * Parse MySkool Collection (Payments) Report CSV with slip-level continuity.
     *
     * Handles:
     * - School title blocks and collection date ranges
     * - Payment method headers (Cash, Bank, etc.)
     * - Receipt slip grouping
     * - Student continuation rows strictly within the same slip
     * - Subtotal/summary rows
     * - Thousands-separated amounts
     * - Exact currency normalization
     * - Date normalization to ISO YYYY-MM-DD
     *
     * @param string $filePath
     * @return array<int, array<string, mixed>>
     */
    public function parsePaymentsReport(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException("Collection report CSV not found or unreadable: {$filePath}");
        }

        $fp = fopen($filePath, 'r');
        if (!$fp) {
            throw new \RuntimeException("Unable to open file: {$filePath}");
        }

        $headerFound = false;
        $currentMethod = 'Cash';
        $currentSlip = '';
        $currentGr = '';
        $currentName = '';
        $payments = [];
        $lineNum = 0;

        while (($row = fgetcsv($fp)) !== false) {
            $lineNum++;

            // Detect table column header row
            if (!$headerFound) {
                $joined = strtolower(implode(' ', array_map('trim', $row)));
                if (str_contains($joined, 'slip') && str_contains($joined, 'particular')) {
                    $headerFound = true;
                }
                continue;
            }

            $col0 = trim($row[0] ?? ''); // Payment Method header
            $col1 = trim($row[1] ?? ''); // Slip #
            $col2 = trim($row[2] ?? ''); // GRN
            $col3 = trim($row[3] ?? ''); // Student Name
            $col4 = trim($row[4] ?? ''); // Particular
            $col5 = trim($row[5] ?? ''); // Payment Date
            $col6 = trim($row[6] ?? ''); // Currency
            $col7 = trim($row[7] ?? ''); // Paid Amount

            // Payment method category header e.g. "Cash", "Bank", "Online"
            if ($col0 !== '' && $col1 === '' && $col4 === '') {
                $currentMethod = $col0;
            }

            // Slip Number tracking: when a new slip appears, reset student context
            if ($col1 !== '') {
                if ($col1 !== $currentSlip) {
                    $currentSlip = $col1;
                    $currentGr = '';
                    $currentName = '';
                }
            }

            // Student identification
            $cleanCol2 = str_replace(',', '', $col2);
            if ($col2 !== '') {
                $currentGr = trim($cleanCol2);
                $currentName = $col3;
            }

            // Valid Payment Detail Line
            $cleanAmount = str_replace(',', '', $col7);
            if ($currentSlip !== '' && $col4 !== '' && is_numeric($cleanAmount)) {
                $amount = (float)$cleanAmount;
                if ($amount <= 0) {
                    continue;
                }

                $curr = $this->normalizeCurrency($col6);
                $dateIso = $this->parseDateIso($col5);
                $periodKey = $this->extractPeriodKey($col4);
                $feeDesc = $this->extractCanonicalFeeDescription(null, $col4);

                $payments[] = [
                    'source_row' => $lineNum,
                    'payment_method' => $currentMethod,
                    'slip_number' => $currentSlip,
                    'gr_number' => $currentGr,
                    'student_name' => $currentName,
                    'particular_raw' => $col4,
                    'fee_description' => $feeDesc,
                    'period_key' => $periodKey,
                    'payment_date_iso' => $dateIso,
                    'payment_date_raw' => $col5,
                    'currency_iso' => $curr,
                    'paid_amount' => $amount,
                ];
            }
        }

        fclose($fp);
        return $payments;
    }

    /**
     * Normalize currency code strictly without guessing.
     * Typo codes (SAFR, SAE, SAT, 0, blank) remain empty string / UNRESOLVED.
     */
    public function normalizeCurrency(?string $val): string
    {
        $c = strtoupper(trim(str_replace(['`', "'", '"', ' '], '', (string)$val)));
        if ($c === 'SAR') return 'SAR';
        if ($c === 'AED') return 'AED';
        if (in_array($c, ['PKR', 'RS', 'RUPEES'], true)) return 'PKR';
        if ($c === 'QAR') return 'QAR';
        if ($c === 'OMR') return 'OMR';
        if ($c === 'BHD') return 'BHD';
        if ($c === 'USD') return 'USD';
        return ''; // Unresolved
    }

    /**
     * Extract canonical period key (YYYY-MM) from particular or period string.
     */
    public function extractPeriodKey(?string $val): ?string
    {
        $str = trim((string)$val);
        if (preg_match('/^\d{4}-\d{2}$/', $str)) {
            return $str;
        }

        // Pattern: Mon-YYYY or Mon YYYY or Mon-YY
        if (preg_match('/(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*[- ]*(\d{2,4})/i', $str, $m)) {
            $year = (int)$m[2];
            if ($year < 100) {
                $year += 2000;
            }
            $month = date('m', strtotime("1 {$m[1]} {$year}"));
            return sprintf('%04d-%02d', $year, $month);
        }

        // Pattern: Mon only e.g. "Monthly Fee - Jul" (default to year 2026)
        if (preg_match('/[- ]+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*$/i', $str, $m)) {
            $month = date('m', strtotime("1 {$m[1]} 2026"));
            return "2026-{$month}";
        }

        return null;
    }

    /**
     * Extract canonical fee description by stripping period suffix.
     * e.g. "Monthly Fee - Jul-2025" -> "Monthly Fee"
     */
    public function extractCanonicalFeeDescription(?string $feeDescription, ?string $particularRaw): string
    {
        $raw = trim((string)($feeDescription ?: $particularRaw ?: ''));
        // Strip trailing period pattern e.g. - Jul-2025, - Jul 2025, - Jul-25, - Jul
        $cleaned = preg_replace('/\s*-\s*[A-Za-z]{3}(?:[- ]*\d{2,4})?$/i', '', $raw);
        $cleaned = preg_replace('/\s+/', ' ', trim($cleaned));
        return $cleaned;
    }

    /**
     * Parse date string into standard ISO YYYY-MM-DD.
     */
    public function parseDateIso(?string $val): ?string
    {
        $val = trim((string)$val);
        if ($val === '') {
            return null;
        }

        // ISO format YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            return $val;
        }

        // Format: D-Mon-YY or DD-Mon-YYYY (e.g. 1-Mar-25 or 15-Oct-2026)
        if (preg_match('/^(\d{1,2})\s*-\s*([A-Za-z]{3})\s*-\s*(\d{2,4})$/', $val, $m)) {
            $day = (int)$m[1];
            $year = (int)$m[3];
            if ($year < 100) {
                $year += 2000;
            }
            $month = date('m', strtotime("1 {$m[2]} {$year}"));
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        // Fallback strtotime
        $ts = strtotime($val);
        if ($ts && $ts > 0) {
            return date('Y-m-d', $ts);
        }

        return null;
    }
}
