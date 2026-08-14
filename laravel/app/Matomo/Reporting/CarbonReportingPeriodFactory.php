<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class CarbonReportingPeriodFactory implements ReportingPeriodFactory
{
    private const array PERIOD_IDS = [
        'day' => 1,
        'week' => 2,
        'month' => 3,
        'year' => 4,
        'range' => 5,
    ];

    private const array MULTIPLE_PERIOD_LIMITS = [
        'day' => 5 * 365,
        'week' => 10 * 52,
        'month' => 10 * 12,
        'year' => 10,
    ];

    public function make(string $period, string $date, string $timezone): array
    {
        if ($period === 'range') {
            return [[$this->range($date, $timezone)], false];
        }

        if (preg_match('/^(last|previous)([0-9]*)$/D', $date, $matches) === 1) {
            return [$this->relativePeriods($period, $matches[1], (int) $matches[2], $timezone), true];
        }

        if (str_contains($date, ',')) {
            [$start, $end] = explode(',', $date, 2);

            return [$this->periodsBetween(
                $period,
                $this->date($start, $timezone),
                $this->date($end, $timezone),
            ), true];
        }

        return [[$this->period($period, $this->date($date, $timezone))], false];
    }

    private function range(string $date, string $timezone): ReportingPeriod
    {
        if (preg_match('/^(last|previous)([0-9]*)$/D', $date, $matches) === 1) {
            $count = max(1, (int) $matches[2]);
            $end = CarbonImmutable::today($timezone);

            if ($matches[1] === 'previous') {
                $end = $end->subDay();
            }

            return $this->rangePeriod($end->subDays($count - 1), $end);
        }

        [$startValue, $endValue] = explode(',', $date, 2);
        $start = $this->date($startValue, $timezone);
        $end = $this->date($endValue, $timezone);

        if ($start->isSameDay($end)) {
            return $this->period('day', $start);
        }

        return $this->rangePeriod($start, $end);
    }

    /**
     * @return list<ReportingPeriod>
     */
    private function relativePeriods(
        string $period,
        string $relative,
        int $count,
        string $timezone,
    ): array {
        $count = max(1, min($count, self::MULTIPLE_PERIOD_LIMITS[$period]));
        $end = CarbonImmutable::today($timezone);

        if ($relative === 'previous') {
            $end = $this->subtractPeriod($end, $period);
        }

        $start = $end;

        for ($index = 1; $index < $count; $index++) {
            $start = $this->subtractPeriod($start, $period);
        }

        $periods = [];
        $cursor = $start;

        for ($index = 0; $index < $count; $index++) {
            $periods[] = $this->period($period, $cursor);
            $cursor = $this->addPeriod($cursor, $period);
        }

        return $periods;
    }

    /**
     * @return list<ReportingPeriod>
     */
    private function periodsBetween(
        string $period,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        if ($start->isAfter($end)) {
            throw new InvalidArgumentException('The report date range starts after it ends.');
        }

        $periods = [$this->period($period, $end)];
        $cursor = $periods[0];

        while ($cursor->startDate > $start->toDateString()) {
            $cursor = $this->period(
                $period,
                $this->subtractPeriod($this->date($cursor->startDate, 'UTC'), $period),
            );
            $periods[] = $cursor;
        }

        return array_reverse($periods);
    }

    private function period(string $period, CarbonImmutable $date): ReportingPeriod
    {
        [$start, $end] = match ($period) {
            'day' => [$date, $date],
            'week' => [$date->startOfWeek(), $date->endOfWeek()],
            'month' => [$date->startOfMonth(), $date->endOfMonth()],
            'year' => [$date->startOfYear(), $date->endOfYear()],
            default => throw new InvalidArgumentException("The period '{$period}' is not supported."),
        };
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();
        $resultKey = match ($period) {
            'day' => $startDate,
            'month' => $start->format('Y-m'),
            'year' => $start->format('Y'),
            'week' => $startDate.','.$endDate,
        };

        return new ReportingPeriod(
            label: $period,
            id: self::PERIOD_IDS[$period],
            startDate: $startDate,
            endDate: $endDate,
            resultKey: $resultKey,
        );
    }

    private function rangePeriod(CarbonImmutable $start, CarbonImmutable $end): ReportingPeriod
    {
        if ($start->isAfter($end)) {
            throw new InvalidArgumentException('The report date range starts after it ends.');
        }

        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        return new ReportingPeriod(
            label: 'range',
            id: self::PERIOD_IDS['range'],
            startDate: $startDate,
            endDate: $endDate,
            resultKey: $startDate.','.$endDate,
        );
    }

    private function date(string $date, string $timezone): CarbonImmutable
    {
        if (preg_match('/^([0-9]{4})-([0-9]{1,2})-([0-9]{1,2})$/D', $date, $parts) === 1) {
            $created = CarbonImmutable::create(
                (int) $parts[1],
                (int) $parts[2],
                (int) $parts[3],
                0,
                0,
                0,
                $timezone,
            );

            if ($created === null) {
                throw new InvalidArgumentException("The date '{$date}' is not valid.");
            }

            return $created;
        }

        return match (strtolower($date)) {
            'now', 'today' => CarbonImmutable::today($timezone),
            'yesterday', 'yesterdaysametime' => CarbonImmutable::today($timezone)->subDay(),
            'lastweek', 'last week' => CarbonImmutable::today($timezone)->modify('last week'),
            'lastmonth', 'last month' => CarbonImmutable::today($timezone)->modify('last month'),
            'lastyear', 'last year' => CarbonImmutable::today($timezone)->modify('last year'),
            default => throw new InvalidArgumentException("The date '{$date}' is not valid."),
        };
    }

    private function subtractPeriod(CarbonImmutable $date, string $period): CarbonImmutable
    {
        return match ($period) {
            'day' => $date->subDay(),
            'week' => $date->subWeek(),
            'month' => $date->subMonthNoOverflow(),
            'year' => $date->subYearNoOverflow(),
            default => throw new InvalidArgumentException("The period '{$period}' is not supported."),
        };
    }

    private function addPeriod(CarbonImmutable $date, string $period): CarbonImmutable
    {
        return match ($period) {
            'day' => $date->addDay(),
            'week' => $date->addWeek(),
            'month' => $date->addMonthNoOverflow(),
            'year' => $date->addYearNoOverflow(),
            default => throw new InvalidArgumentException("The period '{$period}' is not supported."),
        };
    }
}
