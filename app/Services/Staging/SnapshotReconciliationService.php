<?php

namespace App\Services\Staging;

use App\Exceptions\DatabaseSafetyException;
use Illuminate\Support\Facades\DB;

class SnapshotReconciliationService
{
    /**
     * Compare Version 1 (authoritative baseline) against Version 2 (fresh snapshot)
     * strictly by currency, without adding amounts across currencies.
     *
     * @param array<int, object|array> $v1Dues
     * @param array<int, object|array> $v2Dues
     * @param array<int, object|array> $v1Payments
     * @param array<int, object|array> $v2Payments
     * @return array
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
            ],
            'conflicts' => [],
            'new_payments' => [],
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

        // Index V1 dues by composite key: {gr_number}|{period_key}|{fee_description}
        $v1Map = [];
        foreach ($v1Dues as $due) {
            $d = (object) $due;
            $results['summary']['v1_dues_total_rows']++;
            $curr = $this->normalizeCurrency($d->currency_iso ?? $d->currency ?? null);
            $key = $this->buildCompositeKey($d);
            
            $v1Map[$key][] = [
                'id' => $d->id ?? null,
                'source_key' => $d->source_key ?? null,
                'gr_number' => (string)($d->gr_number ?? ''),
                'student_name' => (string)($d->student_name ?? ''),
                'period_key' => (string)($d->period_key ?? ''),
                'fee_description' => (string)($d->fee_description ?? $d->particular_raw ?? ''),
                'currency' => $curr,
                'amount' => (float)($d->outstanding_amount ?? $d->amount ?? 0),
            ];

            if (isset($results['currencies'][$curr])) {
                $results['currencies'][$curr]['v1_total_amount'] += (float)($d->outstanding_amount ?? $d->amount ?? 0);
            }
        }

        // Index V2 dues by composite key
        $v2Map = [];
        foreach ($v2Dues as $due) {
            $d = (object) $due;
            $results['summary']['v2_dues_total_rows']++;
            $curr = $this->normalizeCurrency($d->currency_iso ?? $d->currency ?? null);
            $key = $this->buildCompositeKey($d);

            $v2Map[$key][] = [
                'id' => $d->id ?? null,
                'source_key' => $d->source_key ?? null,
                'gr_number' => (string)($d->gr_number ?? ''),
                'student_name' => (string)($d->student_name ?? ''),
                'period_key' => (string)($d->period_key ?? ''),
                'fee_description' => (string)($d->fee_description ?? $d->particular_raw ?? ''),
                'currency' => $curr,
                'amount' => (float)($d->outstanding_amount ?? $d->amount ?? 0),
            ];

            if (isset($results['currencies'][$curr])) {
                $results['currencies'][$curr]['v2_total_amount'] += (float)($d->outstanding_amount ?? $d->amount ?? 0);
            }
        }

        // Compare V1 to V2
        $matchedV2Keys = [];

        foreach ($v1Map as $key => $v1Items) {
            if (!isset($v2Map[$key])) {
                // Disappearing dues (were in V1, absent in V2)
                foreach ($v1Items as $item) {
                    $c = $item['currency'];
                    $results['currencies'][$c]['disappearing_count']++;
                    $results['currencies'][$c]['disappearing_amount'] += $item['amount'];
                }
            } else {
                $matchedV2Keys[$key] = true;
                $v2Items = $v2Map[$key];

                $v1Sum = array_sum(array_column($v1Items, 'amount'));
                $v2Sum = array_sum(array_column($v2Items, 'amount'));
                $c = $v1Items[0]['currency'];

                // Check currency conflict
                $v2Curr = $v2Items[0]['currency'];
                if ($c !== $v2Curr) {
                    $results['summary']['total_classification_conflicts']++;
                    $results['conflicts'][] = [
                        'type' => 'CURRENCY_MISMATCH',
                        'key' => $key,
                        'v1_currency' => $c,
                        'v2_currency' => $v2Curr,
                        'gr_number' => $v1Items[0]['gr_number'],
                        'v1_amount' => $v1Sum,
                        'v2_amount' => $v2Sum,
                    ];
                    // Strict multi-currency isolation: currency cannot be converted or subtracted across ISO boundaries
                    $results['currencies'][$c]['disappearing_count'] += count($v1Items);
                    $results['currencies'][$c]['disappearing_amount'] += $v1Sum;
                    $results['currencies'][$v2Curr]['newly_appearing_count'] += count($v2Items);
                    $results['currencies'][$v2Curr]['newly_appearing_amount'] += $v2Sum;
                    continue;
                }

                // Check student name conflict
                if (strtolower(trim($v1Items[0]['student_name'])) !== strtolower(trim($v2Items[0]['student_name']))) {
                    if (!empty($v1Items[0]['student_name']) && !empty($v2Items[0]['student_name'])) {
                        $results['summary']['total_classification_conflicts']++;
                        $results['conflicts'][] = [
                            'type' => 'STUDENT_NAME_MISMATCH',
                            'key' => $key,
                            'v1_name' => $v1Items[0]['student_name'],
                            'v2_name' => $v2Items[0]['student_name'],
                            'gr_number' => $v1Items[0]['gr_number'],
                        ];
                    }
                }

                $diff = round($v2Sum - $v1Sum, 2);

                if (abs($diff) < 0.001) {
                    $results['currencies'][$c]['unchanged_count'] += count($v1Items);
                    $results['currencies'][$c]['unchanged_amount'] += $v1Sum;
                } elseif ($diff > 0) {
                    $results['currencies'][$c]['increased_count'] += count($v1Items);
                    $results['currencies'][$c]['increased_delta'] += $diff;
                } else {
                    $results['currencies'][$c]['decreased_count'] += count($v1Items);
                    $results['currencies'][$c]['decreased_delta'] += abs($diff);
                }
            }
        }

        // Check for Newly Appearing Dues (present in V2, absent in V1)
        foreach ($v2Map as $key => $v2Items) {
            if (!isset($matchedV2Keys[$key])) {
                foreach ($v2Items as $item) {
                    $c = $item['currency'];
                    $results['currencies'][$c]['newly_appearing_count']++;
                    $results['currencies'][$c]['newly_appearing_amount'] += $item['amount'];
                }
            }
        }

        // Compare payments if provided
        $v1SlipMap = [];
        foreach ($v1Payments as $p) {
            $pObj = (object) $p;
            $results['summary']['v1_payments_total_rows']++;
            $slip = trim((string)($pObj->slip_number ?? ''));
            $gr = trim((string)($pObj->gr_number ?? ''));
            $v1SlipMap["{$slip}_{$gr}"] = true;
        }

        foreach ($v2Payments as $p) {
            $pObj = (object) $p;
            $results['summary']['v2_payments_total_rows']++;
            $slip = trim((string)($pObj->slip_number ?? ''));
            $gr = trim((string)($pObj->gr_number ?? ''));
            $pKey = "{$slip}_{$gr}";

            if (!isset($v1SlipMap[$pKey])) {
                $curr = $this->normalizeCurrency($pObj->currency_iso ?? $pObj->currency ?? null);
                $amt = (float)($pObj->paid_amount ?? $pObj->amount ?? 0);
                $results['currencies'][$curr]['new_payments_count']++;
                $results['currencies'][$curr]['new_payments_amount'] += $amt;
                $results['new_payments'][] = [
                    'slip_number' => $slip,
                    'gr_number' => $gr,
                    'date' => $pObj->payment_date_iso ?? $pObj->payment_date_raw ?? null,
                    'amount' => $amt,
                    'currency' => $curr,
                ];
            }
        }

        // Calculate net variance by currency: V2 total - V1 total
        foreach ($currencies as $curr) {
            $v1Tot = $results['currencies'][$curr]['v1_total_amount'];
            $v2Tot = $results['currencies'][$curr]['v2_total_amount'];
            $results['currencies'][$curr]['net_variance'] = round($v2Tot - $v1Tot, 2);
        }

        return $results;
    }

    /**
     * Build matching composite key for fee item comparison.
     */
    protected function buildCompositeKey(object $due): string
    {
        $gr = trim((string)($due->gr_number ?? ''));
        $period = trim((string)($due->period_key ?? ''));
        $desc = strtolower(trim((string)($due->fee_description ?? $due->particular_raw ?? '')));
        // Normalize description by removing specific spaces and dashes
        $normDesc = preg_replace('/[^a-z0-9]/', '', $desc);

        return "{$gr}|{$period}|{$normDesc}";
    }

    /**
     * Normalize currency code.
     */
    protected function normalizeCurrency(?string $raw): string
    {
        $c = strtoupper(trim((string)$raw));
        if (in_array($c, ['SAR', 'AED', 'PKR', 'QAR', 'BHD', 'OMR', 'USD'], true)) {
            return $c;
        }
        return 'UNRESOLVED';
    }
}
