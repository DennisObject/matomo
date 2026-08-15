<?php

declare(strict_types=1);

namespace App\Matomo\Insights;

use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Reporting\ReportingPeriodFactory;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class InsightComparisonPeriodResolver
{
    public function __construct(private ReportingPeriodFactory $periods) {}

    public function previous(
        ReportingPeriod $current,
        int $comparedToXPeriods,
        string $timezone,
    ): ReportingPeriod {
        $start = CarbonImmutable::parse($current->startDate, $timezone);

        if ($current->label === 'range') {
            $end = CarbonImmutable::parse($current->endDate, $timezone);
            $days = $start->diffInDays($end) + 1;
            $previousEnd = $start->subDay();
            $previousStart = $previousEnd->subDays($days - 1);
            $date = $previousStart->toDateString().','.$previousEnd->toDateString();
        } else {
            $date = (match ($current->label) {
                'day' => $start->subDays($comparedToXPeriods),
                'week' => $start->subWeeks($comparedToXPeriods),
                'month' => $start->subMonthsNoOverflow($comparedToXPeriods),
                'year' => $start->subYearsNoOverflow($comparedToXPeriods),
                default => throw new InvalidArgumentException('The report period is not supported.'),
            })->toDateString();
        }

        [$periods] = $this->periods->make($current->label, $date, $timezone);

        return $periods[0] ?? throw new InvalidArgumentException('The previous report period is empty.');
    }
}
