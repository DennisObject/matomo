<?php

declare(strict_types=1);

namespace App\Matomo\Transitions;

use App\Matomo\Reporting\ReportingPeriodFactory;
use Carbon\CarbonImmutable;

final readonly class ConfiguredTransitionsPeriodPolicy implements TransitionsPeriodPolicy
{
    public function __construct(
        private TransitionsSettings $settings,
        private ReportingPeriodFactory $periods,
    ) {}

    public function isAllowed(int $siteId, string $period, string $date): bool
    {
        $maximum = $this->settings->maxPeriodAllowed($siteId);

        if ($maximum === 'all') {
            return true;
        }

        if ($period === 'range') {
            [$periods] = $this->periods->make('range', $date, 'UTC');
            $range = $periods[0];
            $days = (int) CarbonImmutable::parse($range->startDate, 'UTC')
                ->diffInDays(CarbonImmutable::parse($range->endDate, 'UTC')) + 1;

            return match ($maximum) {
                'day' => $days === 1,
                'week' => $days <= 7,
                'month' => $days <= 31,
                'year' => $days <= 365,
                default => false,
            };
        }

        return match ($maximum) {
            'day' => $period === 'day',
            'week' => in_array($period, ['day', 'week'], true),
            'month' => in_array($period, ['day', 'week', 'month'], true),
            'year' => in_array($period, ['day', 'week', 'month', 'year'], true),
            default => false,
        };
    }
}
