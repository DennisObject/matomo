<?php

declare(strict_types=1);

namespace App\Matomo\Annotations;

use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Sites\SiteRepository;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class AnnotationPeriodResolver
{
    public function __construct(
        private ReportingPeriodFactory $periods,
        private SiteRepository $sites,
    ) {}

    /** @return array{string|null, string|null} */
    public function range(int $siteId, ?string $date, string $period, ?int $lastN): array
    {
        if ($date === null || $date === '') {
            return [null, null];
        }

        $timezone = $this->sites->timezone($siteId)
            ?? throw new InvalidArgumentException("The website id = {$siteId} does not exist.");
        [$resolved] = $this->periods->make($period, $date, $timezone);
        $first = $resolved[0] ?? throw new InvalidArgumentException('The annotation date range is empty.');
        $last = $resolved[array_key_last($resolved)];

        if ($lastN !== null && $lastN > 1 && $period !== 'range' && count($resolved) === 1) {
            $start = CarbonImmutable::parse($first->startDate, $timezone);
            $start = match ($period) {
                'day' => $start->subDays($lastN - 1),
                'week' => $start->subWeeks($lastN - 1),
                'month' => $start->subMonthsNoOverflow($lastN - 1),
                'year' => $start->subYearsNoOverflow($lastN - 1),
                default => throw new InvalidArgumentException("The period '{$period}' is not supported."),
            };

            return $this->bounded($start->toDateString(), $last->endDate, $timezone);
        }

        return $this->bounded($first->startDate, $last->endDate, $timezone);
    }

    /** @return array{string, string} */
    private function bounded(string $start, string $end, string $timezone): array
    {
        $startDate = CarbonImmutable::parse($start, $timezone);
        $endDate = CarbonImmutable::parse($end, $timezone);
        $maximumEnd = CarbonImmutable::now($timezone)->addYears(10)->endOfYear()->startOfDay();

        if ($endDate->greaterThan($maximumEnd)) {
            $endDate = $maximumEnd;
        }

        if ($startDate->greaterThan($endDate)) {
            $startDate = $endDate;
        }

        return [$startDate->toDateString(), $endDate->toDateString()];
    }
}
