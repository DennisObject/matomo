<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo\Reporting;

use App\Matomo\Reporting\CarbonReportingPeriodFactory;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CarbonReportingPeriodFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_builds_calendar_periods_and_legacy_result_keys(): void
    {
        $factory = new CarbonReportingPeriodFactory;

        [$weeks, $multipleWeeks] = $factory->make('week', '2026-08-14', 'UTC');
        [$months, $multipleMonths] = $factory->make('month', '2026-08-14', 'UTC');

        $this->assertFalse($multipleWeeks);
        $this->assertSame('2026-08-10', $weeks[0]->startDate);
        $this->assertSame('2026-08-16', $weeks[0]->endDate);
        $this->assertSame('2026-08-10,2026-08-16', $weeks[0]->resultKey);
        $this->assertFalse($multipleMonths);
        $this->assertSame('2026-08-01', $months[0]->startDate);
        $this->assertSame('2026-08-31', $months[0]->endDate);
        $this->assertSame('2026-08', $months[0]->resultKey);
    }

    public function test_builds_last_and_previous_period_lists_in_the_site_timezone(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-14 23:30:00', 'UTC'));
        $factory = new CarbonReportingPeriodFactory;

        [$lastDays, $multipleDays] = $factory->make('day', 'last3', 'Pacific/Auckland');
        [$previousMonths, $multipleMonths] = $factory->make('month', 'previous2', 'UTC');

        $this->assertTrue($multipleDays);
        $this->assertSame(
            ['2026-08-13', '2026-08-14', '2026-08-15'],
            array_column($lastDays, 'resultKey'),
        );
        $this->assertTrue($multipleMonths);
        $this->assertSame(['2026-06', '2026-07'], array_column($previousMonths, 'resultKey'));
    }

    public function test_builds_range_and_converts_a_one_day_range_to_day(): void
    {
        $factory = new CarbonReportingPeriodFactory;

        [$range] = $factory->make('range', '2026-07-01,2026-08-14', 'UTC');
        [$oneDay] = $factory->make('range', '2026-08-14,2026-08-14', 'UTC');

        $this->assertSame('range', $range[0]->label);
        $this->assertSame(5, $range[0]->id);
        $this->assertSame('2026-07-01,2026-08-14', $range[0]->resultKey);
        $this->assertSame('day', $oneDay[0]->label);
        $this->assertSame(1, $oneDay[0]->id);
        $this->assertSame('2026-08-14', $oneDay[0]->resultKey);
    }

    public function test_rejects_a_reversed_date_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The report date range starts after it ends.');

        (new CarbonReportingPeriodFactory)->make('day', '2026-08-14,2026-08-01', 'UTC');
    }
}
