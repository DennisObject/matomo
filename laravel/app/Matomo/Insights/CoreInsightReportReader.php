<?php

declare(strict_types=1);

namespace App\Matomo\Insights;

use App\Matomo\Reporting\ReportingPeriod;

interface CoreInsightReportReader
{
    /** @return list<array<string, mixed>> */
    public function actions(
        string $method,
        bool $flat,
        int $siteId,
        ReportingPeriod $period,
        string $segmentHash,
        string $language,
    ): array;

    /** @return list<array<string, mixed>> */
    public function countries(
        int $siteId,
        ReportingPeriod $period,
        string $segmentHash,
        string $language,
    ): array;
}
