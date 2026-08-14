<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

interface BlobArchiveRepository
{
    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @return array<int, array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>>>
     */
    public function rows(
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $recordName,
    ): array;
}
