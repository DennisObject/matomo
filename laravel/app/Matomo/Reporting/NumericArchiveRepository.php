<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

interface NumericArchiveRepository
{
    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @param  list<string>  $metrics
     * @return array<int, array<string, array<string, int|float>>>
     */
    public function pluginMetrics(
        array $siteIds,
        array $periods,
        string $segmentHash,
        array $metrics,
        string $pluginName,
    ): array;
}
