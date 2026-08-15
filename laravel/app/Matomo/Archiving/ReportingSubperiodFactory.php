<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Reporting\ReportingPeriod;

interface ReportingSubperiodFactory
{
    /** @return list<ReportingPeriod> */
    public function children(ReportingPeriod $period): array;
}
