<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

interface BatchBlobArchiveRepository
{
    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @param  list<string>  $recordNames
     * @return array<int, array<string, array<string, list<array{
     *     columns: array<string, float|int|string|null>,
     *     metadata: array<string, float|int|string|null>
     * }>>>>
     */
    public function rowsForRecords(
        array $siteIds,
        array $periods,
        string $segmentHash,
        array $recordNames,
    ): array;
}
