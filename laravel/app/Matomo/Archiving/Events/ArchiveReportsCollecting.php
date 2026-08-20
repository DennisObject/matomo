<?php

declare(strict_types=1);

namespace App\Matomo\Archiving\Events;

use App\Matomo\Archiving\ArchiveRecordSet;
use App\Matomo\Archiving\ArchiveReportRequest;
use App\Matomo\Reporting\ReportingPeriod;

final readonly class ArchiveReportsCollecting
{
    /**
     * @param  array<string, int|float>  $coreMetrics
     */
    public function __construct(
        public ArchiveReportRequest $request,
        public ReportingPeriod $period,
        public array $coreMetrics,
        public ArchiveRecordSet $records,
    ) {}
}
