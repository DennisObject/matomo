<?php

declare(strict_types=1);

namespace App\Matomo\Archiving\Events;

use App\Matomo\Archiving\ActionArchiveMetric;
use App\Matomo\Archiving\ArchiveReportRequest;
use App\Matomo\Reporting\ReportingPeriod;

final class ActionArchiveMetricsCollecting
{
    /** @param list<ActionArchiveMetric> $metrics */
    public function __construct(
        public array $metrics,
        public readonly ArchiveReportRequest $request,
        public readonly ReportingPeriod $period,
    ) {}
}
