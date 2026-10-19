<?php

namespace App\Services\Staging;

class SnapshotReconciliationService
{
    protected MySkoolReportParser $parser;

    public function __construct(?MySkoolReportParser $parser = null)
    {
        $this->parser = $parser ?? new MySkoolReportParser();
    }

    /**
     * Compare Version 1 (authoritative baseline) against Version 2 (fresh snapshot)
     * strictly by currency, without blending amounts across currency boundaries.
     *
     * Handles:
     * - Canonical fee description normalization (e.g. "Monthly Fee - Jul-2025" matches "Monthly Fee")
     * - Strict currency isolation
     * - Repeated composite keys and multiplicity preservation
     * - Currency change conflict detection
     * - Line-level historical payment comparison (beyond just slip + GR)
     * - Multi-currency receipt separation
     *
     * @param iterable<int, object|array> $v1Dues
     * @param iterable<int, object|array> $v2Dues
     * @param iterable<int, object|array> $v1Payments
     * @param iterable<int, object|array> $v2Payments
     * @return array<string, mixed>
     */
    public function compareSnapshots(
        iterable $v1Dues,
        iterable $v2Dues,
        iterable $v1Payments = [],
        iterable $v2Payments = []
    ): array {
        $currencies = ['SAR', 'AED', 'PKR', 'QAR', 'BHD', 'OMR', 'USD', 'UNRESOLVED'];

        $results = [
            'currencies' => [],
            'summary' => [
                'v1_dues_total_rows' => 0,
                'v2_dues_total_rows' => 0,
                'v1_payments_total_rows' => 0,
                'v2_payments_total_rows' => 0,
                'total_classification_conflicts' => 0,
                'new_payments_total_count' => 0,
                'new_payments_total_amount' => 0.0,
            ],
            'conflicts' => [],
            'new_payments' => [],
            'missing_payments' => [],
            'changed_payments' => [],
        ];

        foreach ($currencies as $curr) {
            $results['currencies'][$curr] = [
                'unchanged_count' => 0,
                'unchanged_amount' => 0.0,
                'increased_count' => 0,
                'increased_delta' => 0.0,
                'decreased_count' => 0,
                'decreased_delta' => 0.0,
                'newly_appearing_count' => 0,
                'newly_appearing_amount' => 0.0,
                'disappearing_count' => 0,
                'disappearing_amount' => 0.0,
                'v1_total_amount' => 0.0,
                'v2_total_amount' => 0.0,
                'net_variance' => 0.0,
                'new_payments_count' => 0,
                'new_payments_amount' => 0.0,
            ];
        }

        // =========================================================================
        // 1. INDEX V1 AND V2 DUES BY COMPOSITE KEY WITH CURRENCY BOUNDARY
        // =========================================================================
        $v1DuesMap = [];
        $v1DuesByKeyNoCurrency = [];

        foreach ($v1Dues as $due) {
            $d = (object) $due;
            $results['summary']['v1_dues_total_rows']++;
            $curr = $this->normalizeCurrency($d->currency_iso ?? $d->currency ?? null);
            $gr = trim((string)($d->gr_number ?? ''));
            $period = $this->canonicalPeriodKey($d->period_key ?? null, $d->particular_raw ?? null);
            $normDesc = $this->canonicalFeeDescription($d->fee_description ?? null, $d->particular_raw ?? null);
            $amt = (float)($d->outstanding_amount ?? $d->amount ?? 0);

            $key = "{$curr}|{$gr}|{$period}|{$normDesc}";
            $keyNoCurr = "{$gr}|{$period}|{$normDesc}";

            $item = [
                'id' => $d->id ?? null,
                'source_row' => $d->source_row ?? null,
                'source_key' => $d->source_key ?? null,
                'gr_number' => $gr,
                'student_name' => (string)($d->student_name ?? ''),
                'period_key' => $period,
                'fee_description' => (string)($d->fee_description ?? $d->particular_raw ?? ''),
                'particular_raw' => (string)($d->particular_raw ?? ''),
                'currency' => $curr,
                'amount' => $amt,
            ];

            $v1DuesMap[$key][] = $item;
            $v1DuesByKeyNoCurrency[$keyNoCurr][$curr][] = $item;

            if (isset($results['currencies'][$curr])) {
                $results['currencies'][$curr]['v1_total_amount'] += $amt;
            }
        }

        $v2DuesMap = [];
        $v2DuesByKeyNoCurrency = [];

        foreach ($v2Dues as $due) {
            $d = (object) $due;
            $results['summary']['v2_dues_total_rows']++;
            $curr = $this->normalizeCurrency($d->currency_iso ?? $d->currency ?? null);
            $gr = trim((string)($d->gr_number ?? ''));
            $period = $this->canonicalPeriodKey($d->period_key ?? null, $d->particular_raw ?? null);
            $normDesc = $this->canonicalFeeDescription($d->fee_description ?? null, $d->particular_raw ?? null);
            $amt = (float)($d->outstanding_amount ?? $d->amount ?? 0);

            $key = "{$curr}|{$gr}|{$period}|{$normDesc}";
            $keyNoCurr = "{$gr}|{$period}|{$normDesc}";

            $item = [
                'id' => $d->id ?? null,
                'source_row' => $d->source_row ?? null,
                'source_key' => $d->source_key ?? null,
                'gr_number' => $gr,
                'student_name' => (string)($d->student_name ?? ''),
                'period_key' => $period,
                'fee_description' => (string)($d->fee_description ?? $d->particular_raw ?? ''),
                'particular_raw' => (string)($d->particular_raw ?? ''),
                'currency' => $curr,
                'amount' => $amt,
            ];

            $v2DuesMap[$key][] = $item;
            $v2DuesByKeyNoCurrency[$keyNoCurr][$curr][] = $item;

            if (isset($results['currencies'][$curr])) {
                $results['currencies'][$curr]['v2_total_amount'] += $amt;
            }
        }

        // =========================================================================
        // 2. DETECT CROSS-CURRENCY SHIFTS (CURRENCY CONFLICTS)
        // =========================================================================
        $crossCurrencyHandledV1 = [];
        $crossCurrencyHandledV2 = [];

        foreach ($v1DuesByKeyNoCurrency as $keyNoCurr => $v1ByCurr) {
            if (isset($v2DuesByKeyNoCurrency[$keyNoCurr])) {
                $v2ByCurr = $v2DuesByKeyNoCurrency[$keyNoCurr];
                $v1Currs = array_keys($v1ByCurr);
                $v2Currs = array_keys($v2ByCurr);

                // If currencies do not match, record conflict
                if (count(array_diff($v1Currs, $v2Currs)) > 0 || count(array_diff($v2Currs, $v1Currs)) > 0) {
                    foreach ($v1ByCurr as $c1 => $items1) {
                        foreach ($v2ByCurr as $c2 => $items2) {
                            if ($c1 !== $c2) {
                                $results['summary']['total_classification_conflicts']++;
                                $results['conflicts'][] = [
                                    'type' => 'CURRENCY_MISMATCH',
                                    'key' => $keyNoCurr,
                                    'gr_number' => $items1[0]['gr_number'],
                                    'student_name' => $items1[0]['student_name'] ?: $items2[0]['student_name'],
                                    'v1_currency' => $c1,
                                    'v2_currency' => $c2,
                                    'v1_amount' => array_sum(array_column($items1, 'amount')),
                                    'v2_amount' => array_sum(array_column($items2, 'amount')),
                                ];
                            }
                        }
                    }
                }
            }
        }

        // =========================================================================
        // 3. COMPARE DUES WITHIN STRICT CURRENCY BOUNDARIES (WITH MULTIPLICITY)
        // =========================================================================
        $allKeys = array_unique(array_merge(array_keys($v1DuesMap), array_keys($v2DuesMap)));

        foreach ($allKeys as $key) {
            $parts = explode('|', $key, 4);
            $curr = $parts[0];
            $v1Items = $v1DuesMap[$key] ?? [];
            $v2Items = $v2DuesMap[$key] ?? [];

            if (empty($v1Items) && !empty($v2Items)) {
                // All items newly appearing in V2
                foreach ($v2Items as $item) {
                    $results['currencies'][$curr]['newly_appearing_count']++;
                    $results['currencies'][$curr]['newly_appearing_amount'] += $item['amount'];
                }
                continue;
            }

            if (!empty($v1Items) && empty($v2Items)) {
                // All items disappeared from V1
                foreach ($v1Items as $item) {
                    $results['currencies'][$curr]['disappearing_count']++;
                    $results['currencies'][$curr]['disappearing_amount'] += $item['amount'];
                }
                continue;
            }

            // Both V1 and V2 have items under this currency and key
            // Check student name consistency
            $v1Name = strtolower(trim($v1Items[0]['student_name']));
            $v2Name = strtolower(trim($v2Items[0]['student_name']));
            if ($v1Name !== '' && $v2Name !== '' && $v1Name !== $v2Name) {
                $results['summary']['total_classification_conflicts']++;
                $results['conflicts'][] = [
                    'type' => 'STUDENT_NAME_MISMATCH',
                    'key' => $key,
                    'gr_number' => $v1Items[0]['gr_number'],
                    'v1_name' => $v1Items[0]['student_name'],
                    'v2_name' => $v2Items[0]['student_name'],
                ];
            }

            // Line-level multiplicity matcher:
            // Step 1: Match identical amounts exactly
            $unmatchedV1 = [];
            $matchedV2Indices = [];

            foreach ($v1Items as $v1Index => $item1) {
                $amt1 = $item1['amount'];
                $foundMatch = false;

                foreach ($v2Items as $v2Index => $item2) {
                    if (isset($matchedV2Indices[$v2Index])) {
                        continue;
                    }
                    if (abs($amt1 - $item2['amount']) < 0.001) {
                        $matchedV2Indices[$v2Index] = true;
                        $results['currencies'][$curr]['unchanged_count']++;
                        $results['currencies'][$curr]['unchanged_amount'] += $amt1;
                        $foundMatch = true;
                        break;
                    }
                }

                if (!$foundMatch) {
                    $unmatchedV1[] = $item1;
                }
            }

            // Step 2: For remaining unmatched V2 items
            $unmatchedV2 = [];
            foreach ($v2Items as $v2Index => $item2) {
                if (!isset($matchedV2Indices[$v2Index])) {
                    $unmatchedV2[] = $item2;
                }
            }

            // Step 3: Pair up remaining unmatched items 1-to-1 to calculate partial increases/decreases
            $pairs = min(count($unmatchedV1), count($unmatchedV2));
            for ($p = 0; $p < $pairs; $p++) {
                $u1 = $unmatchedV1[$p];
                $u2 = $unmatchedV2[$p];
                $diff = round($u2['amount'] - $u1['amount'], 2);

                if (abs($diff) < 0.001) {
                    $results['currencies'][$curr]['unchanged_count']++;
                    $results['currencies'][$curr]['unchanged_amount'] += $u1['amount'];
                } elseif ($diff > 0) {
                    $results['currencies'][$curr]['increased_count']++;
                    $results['currencies'][$curr]['increased_delta'] += $diff;
                } else {
                    $results['currencies'][$curr]['decreased_count']++;
                    $results['currencies'][$curr]['decreased_delta'] += abs($diff);
                }
            }

            // Step 4: Any leftover V1 items are disappearing
            if (count($unmatchedV1) > $pairs) {
                for ($p = $pairs; $p < count($unmatchedV1); $p++) {
                    $results['currencies'][$curr]['disappearing_count']++;
                    $results['currencies'][$curr]['disappearing_amount'] += $unmatchedV1[$p]['amount'];
                }
            }

            // Step 5: Any leftover V2 items are newly appearing
            if (count($unmatchedV2) > $pairs) {
                for ($p = $pairs; $p < count($unmatchedV2); $p++) {
                    $results['currencies'][$curr]['newly_appearing_count']++;
                    $results['currencies'][$curr]['newly_appearing_amount'] += $unmatchedV2[$p]['amount'];
                }
            }
        }

        // =========================================================================
        // 4. LINE-LEVEL HISTORICAL PAYMENT MATCHING
        // =========================================================================
        $v1PayMap = [];
        foreach ($v1Payments as $p) {
            $pObj = (object) $p;
            $results['summary']['v1_payments_total_rows']++;
            $curr = $this->normalizeCurrency($pObj->currency_iso ?? $pObj->currency ?? null);
            $slip = trim((string)($pObj->slip_number ?? ''));
            $gr = trim((string)($pObj->gr_number ?? ''));
            $period = $this->canonicalPeriodKey($pObj->period_key ?? null, $pObj->particular_raw ?? null);
            $normDesc = $this->canonicalFeeDescription($pObj->fee_description ?? null, $pObj->particular_raw ?? null);
            $date = $pObj->payment_date_iso ?? $pObj->payment_date_raw ?? '';
            $amt = (float)($pObj->paid_amount ?? $pObj->amount ?? 0);

            $payKey = "{$curr}|{$slip}|{$gr}|{$period}|{$normDesc}";

            $v1PayMap[$payKey][] = [
                'source_row' => $pObj->source_row ?? null,
                'slip_number' => $slip,
                'gr_number' => $gr,
                'student_name' => (string)($pObj->student_name ?? ''),
                'particular_raw' => (string)($pObj->particular_raw ?? ''),
                'payment_date' => $date,
                'currency' => $curr,
                'amount' => $amt,
            ];
        }

        $v2PayMap = [];
        foreach ($v2Payments as $p) {
            $pObj = (object) $p;
            $results['summary']['v2_payments_total_rows']++;
            $curr = $this->normalizeCurrency($pObj->currency_iso ?? $pObj->currency ?? null);
            $slip = trim((string)($pObj->slip_number ?? ''));
            $gr = trim((string)($pObj->gr_number ?? ''));
            $period = $this->canonicalPeriodKey($pObj->period_key ?? null, $pObj->particular_raw ?? null);
            $normDesc = $this->canonicalFeeDescription($pObj->fee_description ?? null, $pObj->particular_raw ?? null);
            $date = $pObj->payment_date_iso ?? $pObj->payment_date_raw ?? '';
            $amt = (float)($pObj->paid_amount ?? $pObj->amount ?? 0);

            $payKey = "{$curr}|{$slip}|{$gr}|{$period}|{$normDesc}";

            $v2PayMap[$payKey][] = [
                'source_row' => $pObj->source_row ?? null,
                'slip_number' => $slip,
                'gr_number' => $gr,
                'student_name' => (string)($pObj->student_name ?? ''),
                'particular_raw' => (string)($pObj->particular_raw ?? ''),
                'payment_date' => $date,
                'currency' => $curr,
                'amount' => $amt,
            ];
        }

        $allPayKeys = array_unique(array_merge(array_keys($v1PayMap), array_keys($v2PayMap)));

        foreach ($allPayKeys as $payKey) {
            $parts = explode('|', $payKey, 5);
            $curr = $parts[0];
            $v1Items = $v1PayMap[$payKey] ?? [];
            $v2Items = $v2PayMap[$payKey] ?? [];

            if (empty($v1Items) && !empty($v2Items)) {
                // Entirely new payment lines
                foreach ($v2Items as $item) {
                    $results['currencies'][$curr]['new_payments_count']++;
                    $results['currencies'][$curr]['new_payments_amount'] += $item['amount'];
                    $results['new_payments'][] = $item;
                    $results['summary']['new_payments_total_count']++;
                    $results['summary']['new_payments_total_amount'] += $item['amount'];
                }
                continue;
            }

            if (!empty($v1Items) && empty($v2Items)) {
                // Entirely missing in V2
                foreach ($v1Items as $item) {
                    $results['missing_payments'][] = $item;
                }
                continue;
            }

            // Both exist: match line by line (matching identical amounts and dates first)
            $matchedV2Indices = [];
            $unmatchedV1 = [];

            foreach ($v1Items as $item1) {
                $found = false;
                foreach ($v2Items as $v2Index => $item2) {
                    if (isset($matchedV2Indices[$v2Index])) {
                        continue;
                    }
                    if (abs($item1['amount'] - $item2['amount']) < 0.001) {
                        $matchedV2Indices[$v2Index] = true;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $unmatchedV1[] = $item1;
                }
            }

            $unmatchedV2 = [];
            foreach ($v2Items as $v2Index => $item2) {
                if (!isset($matchedV2Indices[$v2Index])) {
                    $unmatchedV2[] = $item2;
                }
            }

            // Pair remaining unmatched lines to report changed payments
            $pairs = min(count($unmatchedV1), count($unmatchedV2));
            for ($p = 0; $p < $pairs; $p++) {
                $results['changed_payments'][] = [
                    'slip_number' => $unmatchedV1[$p]['slip_number'],
                    'gr_number' => $unmatchedV1[$p]['gr_number'],
                    'v1_amount' => $unmatchedV1[$p]['amount'],
                    'v2_amount' => $unmatchedV2[$p]['amount'],
                    'v1_date' => $unmatchedV1[$p]['payment_date'],
                    'v2_date' => $unmatchedV2[$p]['payment_date'],
                    'currency' => $curr,
                ];
            }

            if (count($unmatchedV1) > $pairs) {
                for ($p = $pairs; $p < count($unmatchedV1); $p++) {
                    $results['missing_payments'][] = $unmatchedV1[$p];
                }
            }

            if (count($unmatchedV2) > $pairs) {
                for ($p = $pairs; $p < count($unmatchedV2); $p++) {
                    $results['currencies'][$curr]['new_payments_count']++;
                    $results['currencies'][$curr]['new_payments_amount'] += $unmatchedV2[$p]['amount'];
                    $results['new_payments'][] = $unmatchedV2[$p];
                    $results['summary']['new_payments_total_count']++;
                    $results['summary']['new_payments_total_amount'] += $unmatchedV2[$p]['amount'];
                }
            }
        }

        // =========================================================================
        // 5. CALCULATE NET VARIANCE BY CURRENCY
        // =========================================================================
        foreach ($currencies as $curr) {
            $v1Tot = $results['currencies'][$curr]['v1_total_amount'];
            $v2Tot = $results['currencies'][$curr]['v2_total_amount'];
            $results['currencies'][$curr]['net_variance'] = round($v2Tot - $v1Tot, 2);
        }

        return $results;
    }

    /**
     * Canonical fee description normalization.
     * Removes trailing period suffixes (e.g. "- Jul-2025") and normalizes to lowercase alphanumeric string.
     */
    public function canonicalFeeDescription(?string $feeDescription, ?string $particularRaw): string
    {
        $raw = trim((string)($feeDescription ?: $particularRaw ?: ''));
        // Strip trailing period pattern e.g. - Jul-2025, - Jul 2025, - Jul-25, - Jul
        $cleaned = preg_replace('/\s*-\s*[A-Za-z]{3}(?:[- ]*\d{2,4})?$/i', '', $raw);
        $cleaned = preg_replace('/\s+/', ' ', trim($cleaned));
        return strtolower(preg_replace('/[^a-z0-9]/', '', $cleaned));
    }

    /**
     * Canonical period key normalization (YYYY-MM).
     */
    public function canonicalPeriodKey(?string $periodKey, ?string $particularRaw): string
    {
        $p = trim((string)$periodKey);
        if (preg_match('/^\d{4}-\d{2}$/', $p)) {
            return $p;
        }

        if (preg_match('/(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*[- ]*(\d{2,4})/i', (string)$particularRaw, $m)) {
            $year = (int)$m[2];
            if ($year < 100) {
                $year += 2000;
            }
            $month = date('m', strtotime("1 {$m[1]} {$year}"));
            return sprintf('%04d-%02d', $year, $month);
        }

        if (preg_match('/[- ]+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*$/i', (string)$particularRaw, $m)) {
            $month = date('m', strtotime("1 {$m[1]} 2026"));
            return "2026-{$month}";
        }

        return '';
    }

    /**
     * Normalize currency code strictly without guessing.
     */
    public function normalizeCurrency(?string $raw): string
    {
        $c = strtoupper(trim((string)$raw));
        if (in_array($c, ['SAR', 'AED', 'PKR', 'QAR', 'BHD', 'OMR', 'USD'], true)) {
            return $c;
        }
        return 'UNRESOLVED';
    }
}
