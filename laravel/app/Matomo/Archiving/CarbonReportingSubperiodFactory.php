<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Reporting\ReportingPeriod;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class CarbonReportingSubperiodFactory implements ReportingSubperiodFactory
{
    public function children(ReportingPeriod $period): array
    {
        return match ($period->label) {
            'day' => [],
            'week' => $this->days($period),
            'month' => $this->weeksAndDays($period),
            'year' => $this->months($period),
            'range' => $this->optimalPeriods($period),
            default => throw new InvalidArgumentException(
                "The period '{$period->label}' is not supported.",
            ),
        };
    }

    /** @return list<ReportingPeriod> */
    private function days(ReportingPeriod $period): array
    {
        $periods = [];
        $cursor = $this->date($period->startDate);
        $end = $this->date($period->endDate);

        while ($cursor->lessThanOrEqualTo($end)) {
            $periods[] = $this->period('day', $cursor, $cursor);
            $cursor = $cursor->addDay();
        }

        return $periods;
    }

    /** @return list<ReportingPeriod> */
    private function weeksAndDays(ReportingPeriod $period): array
    {
        $periods = [];
        $cursor = $this->date($period->startDate);
        $end = $this->date($period->endDate);

        while ($cursor->lessThanOrEqualTo($end)) {
            $endOfWeek = $cursor->endOfWeek();

            if ($cursor->isMonday() && $endOfWeek->lessThanOrEqualTo($end)) {
                $periods[] = $this->period('week', $cursor, $endOfWeek);
                $cursor = $endOfWeek->addDay()->startOfDay();

                continue;
            }

            $periods[] = $this->period('day', $cursor, $cursor);
            $cursor = $cursor->addDay();
        }

        return $periods;
    }

    /** @return list<ReportingPeriod> */
    private function months(ReportingPeriod $period): array
    {
        $periods = [];
        $cursor = $this->date($period->startDate)->startOfMonth();
        $end = $this->date($period->endDate);

        while ($cursor->lessThanOrEqualTo($end)) {
            $periods[] = $this->period('month', $cursor, $cursor->endOfMonth());
            $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $periods;
    }

    /** @return list<ReportingPeriod> */
    private function optimalPeriods(ReportingPeriod $period): array
    {
        $periods = [];
        $cursor = $this->date($period->startDate);
        $end = $this->date($period->endDate);

        while ($cursor->lessThanOrEqualTo($end)) {
            $endOfYear = $cursor->endOfYear();
            $endOfMonth = $cursor->endOfMonth();
            $endOfWeek = $cursor->endOfWeek();

            if ($cursor->isStartOfYear() && $endOfYear->lessThanOrEqualTo($end)) {
                $periods[] = $this->period('year', $cursor, $endOfYear);
                $cursor = $endOfYear->addDay()->startOfDay();
            } elseif ($cursor->isStartOfMonth() && $endOfMonth->lessThanOrEqualTo($end)) {
                $periods[] = $this->period('month', $cursor, $endOfMonth);
                $cursor = $endOfMonth->addDay()->startOfDay();
            } elseif ($cursor->isMonday() && $endOfWeek->lessThanOrEqualTo($end)) {
                $periods[] = $this->period('week', $cursor, $endOfWeek);
                $cursor = $endOfWeek->addDay()->startOfDay();
            } else {
                $periods[] = $this->period('day', $cursor, $cursor);
                $cursor = $cursor->addDay();
            }
        }

        return $periods;
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'UTC')->startOfDay();
    }

    private function period(
        string $label,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): ReportingPeriod {
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        return new ReportingPeriod(
            label: $label,
            id: match ($label) {
                'day' => 1,
                'week' => 2,
                'month' => 3,
                'year' => 4,
                default => throw new InvalidArgumentException("The period '{$label}' is not supported."),
            },
            startDate: $startDate,
            endDate: $endDate,
            resultKey: match ($label) {
                'day' => $startDate,
                'week' => $startDate.','.$endDate,
                'month' => $start->format('Y-m'),
                'year' => $start->format('Y'),
            },
        );
    }
}
