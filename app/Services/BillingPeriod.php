<?php
namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

/** Pure date logic, shared by the HTTP endpoint and standalone regression tests. */
final class BillingPeriod {
    /** @return list<array{month:int,start:string,end:string,due:string}> */
    public static function selectedMonths(int $year, array $months, int $dueDay): array {
        if ($year < 2000 || $year > 2100 || $dueDay < 1 || $dueDay > 28 || count($months) < 1 || count($months) > 12) {
            throw new InvalidArgumentException('Invalid billing year, month selection or due day.');
        }
        $normalized = [];
        foreach ($months as $month) {
            if (!(is_int($month) || (is_string($month) && preg_match('/^(?:[1-9]|1[0-2])$/D', $month)))) {
                throw new InvalidArgumentException('Months must be between 1 and 12.');
            }
            $number = (int) $month;
            if ($number < 1 || $number > 12 || isset($normalized[$number])) {
                throw new InvalidArgumentException('Selected months must be unique and valid.');
            }
            $normalized[$number] = true;
        }
        ksort($normalized, SORT_NUMERIC);
        $result = [];
        foreach (array_keys($normalized) as $number) {
            $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $number));
            $result[] = [
                'month' => $number,
                'start' => $start->format('Y-m-d'),
                'end' => $start->format('Y-m-t'),
                'due' => $start->format('Y-m-').sprintf('%02d', $dueDay),
            ];
        }
        return $result;
    }
}
