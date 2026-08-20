<?php

declare(strict_types=1);

namespace App\Matomo\Archiving\Events;

use App\Matomo\Archiving\ArchiveReportRequest;
use App\Matomo\Reporting\ReportingPeriod;
use Illuminate\Database\Query\Builder;

final class ArchiveActionsQueryBuilding
{
    public bool $segmentApplied;

    public function __construct(
        public readonly ArchiveReportRequest $request,
        public readonly ReportingPeriod $period,
        public readonly Builder $query,
    ) {
        $this->segmentApplied = $request->segment === null || $request->segment === '';
    }
}
