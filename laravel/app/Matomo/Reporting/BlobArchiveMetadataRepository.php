<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

interface BlobArchiveMetadataRepository
{
    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @return array<int, array<string, BlobArchive>>
     */
    public function archives(
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $recordName,
    ): array;
}
