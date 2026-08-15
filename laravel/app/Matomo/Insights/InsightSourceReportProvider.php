<?php

declare(strict_types=1);

namespace App\Matomo\Insights;

use App\Matomo\Reporting\ReportingPeriod;

interface InsightSourceReportProvider
{
    public function supports(string $reportUniqueId): bool;

    public function report(
        string $reportUniqueId,
        int $siteId,
        ReportingPeriod $period,
        string $segmentHash,
        string $language,
    ): ?InsightSourceReport;
}
