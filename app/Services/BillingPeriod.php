<?php
namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/** Pure date logic, shared by the HTTP endpoint and standalone regression tests. */
final class BillingPeriod {
    public const DEFAULT_DUE_DAY = 5;
    public const DEFAULT_MINIMUM_PAYMENT_DAYS = 10;
    public const DEFAULT_TIMEZONE = 'Asia/Karachi';

    public static function schoolTimezone(): DateTimeZone {
        $tz = self::DEFAULT_TIMEZONE;
        if (function_exists('config')) {
            try {
                $configured = config('app.timezone');
                if (is_string($configured) && $configured !== '' && strtoupper($configured) !== 'UTC') {
                    $tz = $configured;
                }
            } catch (\Throwable) {
                // Fallback when Laravel container/config is not booted (e.g. standalone tests)
            }
        }
        $envTz = $_ENV['APP_TIMEZONE'] ?? $_SERVER['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE');
        if (is_string($envTz) && $envTz !== '') {
            $tz = $envTz;
        }
        return new DateTimeZone($tz);
    }

    public static function resolveIssueDate(DateTimeInterface|string|null $issueDate = null): string {
        $timezone = self::schoolTimezone();
        if ($issueDate instanceof DateTimeInterface) {
            if ($issueDate->format('H:i:s.u') === '00:00:00.000000') {
                return $issueDate->format('Y-m-d');
            }
            return DateTimeImmutable::createFromInterface($issueDate)->setTimezone($timezone)->format('Y-m-d');
        }
        if (is_string($issueDate) && trim($issueDate) !== '') {
            $raw = trim($issueDate);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw)) {
                return (new DateTimeImmutable($raw, $timezone))->format('Y-m-d');
            }
            return (new DateTimeImmutable($raw, $timezone))->setTimezone($timezone)->format('Y-m-d');
        }
        if (class_exists(\Carbon\Carbon::class)) {
            return \Carbon\Carbon::now($timezone)->format('Y-m-d');
        }
        return (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
    }

    public static function calculateDueDate(
        int $year,
        int $month,
        int $dueDay = self::DEFAULT_DUE_DAY,
        DateTimeInterface|string|null $issueDate = null,
        int $minimumPaymentDays = self::DEFAULT_MINIMUM_PAYMENT_DAYS
    ): string {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12 || $dueDay < 1 || $dueDay > 28 || $minimumPaymentDays < 0) {
            throw new InvalidArgumentException('Invalid billing year, month, due day, or minimum payment window.');
        }
        $timezone = self::schoolTimezone();
        $standardDueDate = (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $dueDay), $timezone))->format('Y-m-d');
        $resolvedIssueDate = self::resolveIssueDate($issueDate);
        $minimumPermittedDate = (new DateTimeImmutable($resolvedIssueDate, $timezone))
            ->modify(sprintf('+%d days', $minimumPaymentDays))
            ->format('Y-m-d');

        return max($standardDueDate, $minimumPermittedDate);
    }

    public static function enforceMinimumWindow(
        string $standardDueDate,
        DateTimeInterface|string|null $issueDate = null,
        int $minimumPaymentDays = self::DEFAULT_MINIMUM_PAYMENT_DAYS
    ): string {
        if ($minimumPaymentDays < 0 || trim($standardDueDate) === '') {
            throw new InvalidArgumentException('Invalid standard due date or minimum payment window.');
        }
        $timezone = self::schoolTimezone();
        $normalizedStandardDue = (new DateTimeImmutable(trim($standardDueDate), $timezone))->format('Y-m-d');
        $resolvedIssueDate = self::resolveIssueDate($issueDate);
        $minimumPermittedDate = (new DateTimeImmutable($resolvedIssueDate, $timezone))
            ->modify(sprintf('+%d days', $minimumPaymentDays))
            ->format('Y-m-d');

        return max($normalizedStandardDue, $minimumPermittedDate);
    }

    /** @return list<array{month:int,start:string,end:string,due:string}> */
    public static function selectedMonths(
        int $year,
        array $months,
        int $dueDay = self::DEFAULT_DUE_DAY,
        DateTimeInterface|string|null $issueDate = null,
        int $minimumPaymentDays = self::DEFAULT_MINIMUM_PAYMENT_DAYS
    ): array {
        if ($year < 2000 || $year > 2100 || $dueDay < 1 || $dueDay > 28 || count($months) < 1 || count($months) > 12 || $minimumPaymentDays < 0) {
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
        $timezone = self::schoolTimezone();
        $resolvedIssueDate = self::resolveIssueDate($issueDate);
        $result = [];
        foreach (array_keys($normalized) as $number) {
            $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $number), $timezone);
            $result[] = [
                'month' => $number,
                'start' => $start->format('Y-m-d'),
                'end' => $start->format('Y-m-t'),
                'due' => self::calculateDueDate($year, $number, $dueDay, $resolvedIssueDate, $minimumPaymentDays),
            ];
        }
        return $result;
    }

    /**
     * Build an authoritative server-side schedule preview for one or more selected fee months.
     *
     * @return array{
     *   year:int,
     *   issue_date:string,
     *   issue_date_formatted:string,
     *   due_day:int,
     *   minimum_payment_days:int,
     *   minimum_due_date:string,
     *   minimum_due_date_formatted:string,
     *   periods:list<array{
     *     month:int,
     *     month_name:string,
     *     period_label:string,
     *     start:string,
     *     end:string,
     *     standard_due:string,
     *     standard_due_formatted:string,
     *     minimum_due:string,
     *     minimum_due_formatted:string,
     *     due:string,
     *     due_formatted:string,
     *     window_applied:bool
     *   }>
     * }
     */
    public static function previewSchedule(
        int $year,
        array $months,
        int $dueDay = self::DEFAULT_DUE_DAY,
        DateTimeInterface|string|null $issueDate = null,
        int $minimumPaymentDays = self::DEFAULT_MINIMUM_PAYMENT_DAYS
    ): array {
        $timezone = self::schoolTimezone();
        $resolvedIssueDate = self::resolveIssueDate($issueDate);
        $issueDt = new DateTimeImmutable($resolvedIssueDate, $timezone);
        $minDueDt = $issueDt->modify(sprintf('+%d days', max(0, $minimumPaymentDays)));
        $minDueStr = $minDueDt->format('Y-m-d');

        $selected = self::selectedMonths($year, $months, $dueDay, $resolvedIssueDate, $minimumPaymentDays);
        $periods = [];
        foreach ($selected as $row) {
            $m = $row['month'];
            $startDt = new DateTimeImmutable($row['start'], $timezone);
            $standardDt = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $m, $dueDay), $timezone);
            $standardStr = $standardDt->format('Y-m-d');
            $finalDueDt = new DateTimeImmutable($row['due'], $timezone);
            $periods[] = [
                'month' => $m,
                'month_name' => $startDt->format('M'),
                'period_label' => $startDt->format('F Y'),
                'start' => $row['start'],
                'end' => $row['end'],
                'standard_due' => $standardStr,
                'standard_due_formatted' => $standardDt->format('d-F-Y'),
                'minimum_due' => $minDueStr,
                'minimum_due_formatted' => $minDueDt->format('d-F-Y'),
                'due' => $row['due'],
                'due_formatted' => $finalDueDt->format('d-F-Y'),
                'window_applied' => $row['due'] > $standardStr,
            ];
        }

        return [
            'year' => $year,
            'issue_date' => $resolvedIssueDate,
            'issue_date_formatted' => $issueDt->format('d-F-Y'),
            'due_day' => $dueDay,
            'minimum_payment_days' => $minimumPaymentDays,
            'minimum_due_date' => $minDueStr,
            'minimum_due_date_formatted' => $minDueDt->format('d-F-Y'),
            'periods' => $periods,
        ];
    }
}
