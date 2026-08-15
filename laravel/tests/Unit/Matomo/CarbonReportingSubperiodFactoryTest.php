<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Archiving\CarbonReportingSubperiodFactory;
use App\Matomo\Reporting\ReportingPeriod;
use PHPUnit\Framework\TestCase;

class CarbonReportingSubperiodFactoryTest extends TestCase
{
    public function test_month_uses_complete_weeks_and_boundary_days(): void
    {
        $periods = (new CarbonReportingSubperiodFactory)->children(
            new ReportingPeriod('month', 3, '2026-08-01', '2026-08-31', '2026-08'),
        );

        $this->assertSame([
            'day:2026-08-01,2026-08-01',
            'day:2026-08-02,2026-08-02',
            'week:2026-08-03,2026-08-09',
            'week:2026-08-10,2026-08-16',
            'week:2026-08-17,2026-08-23',
            'week:2026-08-24,2026-08-30',
            'day:2026-08-31,2026-08-31',
        ], array_map(
            static fn (ReportingPeriod $period): string => $period->label.':'.$period->rangeKey(),
            $periods,
        ));
    }

    public function test_range_prefers_whole_years_months_and_weeks_without_crossing_boundaries(): void
    {
        $periods = (new CarbonReportingSubperiodFactory)->children(
            new ReportingPeriod('range', 5, '2024-01-01', '2026-02-10', '2024-01-01,2026-02-10'),
        );

        $this->assertSame([
            'year:2024-01-01,2024-12-31',
            'year:2025-01-01,2025-12-31',
            'month:2026-01-01,2026-01-31',
            'day:2026-02-01,2026-02-01',
            'week:2026-02-02,2026-02-08',
            'day:2026-02-09,2026-02-09',
            'day:2026-02-10,2026-02-10',
        ], array_map(
            static fn (ReportingPeriod $period): string => $period->label.':'.$period->rangeKey(),
            $periods,
        ));
    }
}
