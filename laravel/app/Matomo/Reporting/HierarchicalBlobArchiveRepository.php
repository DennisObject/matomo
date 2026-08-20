<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

interface HierarchicalBlobArchiveRepository
{
    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @return array<int, array<string, array<string, list<array{
     *     columns: array<string, float|int|string|null>,
     *     metadata: array<string, float|int|string|null>,
     *     subtableId: int|null
     * }>>>>
     */
    public function records(
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $recordName,
        bool $includeSubtables,
    ): array;
}
