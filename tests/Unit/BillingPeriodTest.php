<?php
namespace Tests\Unit;

use App\Services\BillingPeriod;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BillingPeriodTest extends TestCase {
    public function test_selected_months_sorted_and_use_full_calendar_months(): void {
        $periods = BillingPeriod::selectedMonths(2028, [12, 2, 1], 10);
        $this->assertSame([
            ['month' => 1, 'start' => '2028-01-01', 'end' => '2028-01-31', 'due' => '2028-01-10'],
            ['month' => 2, 'start' => '2028-02-01', 'end' => '2028-02-29', 'due' => '2028-02-10'],
            ['month' => 12, 'start' => '2028-12-01', 'end' => '2028-12-31', 'due' => '2028-12-10'],
        ], $periods);
    }

    public function test_repeated_months_are_rejected(): void {
        $this->expectException(InvalidArgumentException::class);
        BillingPeriod::selectedMonths(2028, [3, 3], 10);
    }
}
