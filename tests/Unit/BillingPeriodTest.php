<?php
namespace Tests\Unit;

use App\Services\BillingPeriod;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BillingPeriodTest extends TestCase {
    public function test_selected_months_sorted_and_use_full_calendar_months(): void {
        $periods = BillingPeriod::selectedMonths(2028, [12, 2, 1], 10, '2028-01-01');
        $this->assertSame([
            ['month' => 1, 'start' => '2028-01-01', 'end' => '2028-01-31', 'due' => '2028-01-11'],
            ['month' => 2, 'start' => '2028-02-01', 'end' => '2028-02-29', 'due' => '2028-02-10'],
            ['month' => 12, 'start' => '2028-12-01', 'end' => '2028-12-31', 'due' => '2028-12-10'],
        ], $periods);
    }

    public function test_due_date_policy_examples_a_b_and_c(): void {
        // Example A: Fee month Oct 2026, Issue date 20 Sep 2026 -> Standard 5 Oct 2026, Min 30 Sep 2026 -> Final 5 Oct 2026
        $this->assertSame(
            '2026-10-05',
            BillingPeriod::calculateDueDate(2026, 10, BillingPeriod::DEFAULT_DUE_DAY, '2026-09-20')
        );

        // Example B: Fee month Oct 2026, Issue date 28 Oct 2026 -> Standard 5 Oct 2026, Min 7 Nov 2026 -> Final 7 Nov 2026
        $this->assertSame(
            '2026-11-07',
            BillingPeriod::calculateDueDate(2026, 10, BillingPeriod::DEFAULT_DUE_DAY, '2026-10-28')
        );

        // Example C: Fee month Nov 2026, Issue date 28 Oct 2026 -> Standard 5 Nov 2026, Min 7 Nov 2026 -> Final 7 Nov 2026
        $this->assertSame(
            '2026-11-07',
            BillingPeriod::calculateDueDate(2026, 11, BillingPeriod::DEFAULT_DUE_DAY, '2026-10-28')
        );
    }

    public function test_selected_months_defaults_to_day_5_and_enforces_10_day_minimum_window(): void {
        $periods = BillingPeriod::selectedMonths(2026, [10, 11, 12], BillingPeriod::DEFAULT_DUE_DAY, '2026-10-28');
        $this->assertSame([
            ['month' => 10, 'start' => '2026-10-01', 'end' => '2026-10-31', 'due' => '2026-11-07'],
            ['month' => 11, 'start' => '2026-11-01', 'end' => '2026-11-30', 'due' => '2026-11-07'],
            ['month' => 12, 'start' => '2026-12-01', 'end' => '2026-12-31', 'due' => '2026-12-05'],
        ], $periods);
    }

    public function test_preview_schedule_returns_separate_calculated_dates_for_multiple_months(): void {
        $preview = BillingPeriod::previewSchedule(2026, [10, 11, 12], 5, '2026-10-28', 10);
        $this->assertSame('2026-10-28', $preview['issue_date']);
        $this->assertSame(5, $preview['due_day']);
        $this->assertSame(10, $preview['minimum_payment_days']);
        $this->assertSame('2026-11-07', $preview['minimum_due_date']);
        $this->assertCount(3, $preview['periods']);
        $this->assertSame('2026-11-07', $preview['periods'][0]['due']);
        $this->assertTrue($preview['periods'][0]['window_applied']);
        $this->assertSame('2026-11-07', $preview['periods'][1]['due']);
        $this->assertTrue($preview['periods'][1]['window_applied']);
        $this->assertSame('2026-12-05', $preview['periods'][2]['due']);
        $this->assertFalse($preview['periods'][2]['window_applied']);
    }

    public function test_repeated_months_are_rejected(): void {
        $this->expectException(InvalidArgumentException::class);
        BillingPeriod::selectedMonths(2028, [3, 3], 10);
    }
}
