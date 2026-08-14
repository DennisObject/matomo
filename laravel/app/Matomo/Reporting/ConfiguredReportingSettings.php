<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Config\InstallationConfig;

final readonly class ConfiguredReportingSettings implements ReportingSettings
{
    public function __construct(private InstallationConfig $installation) {}

    public function periodEnabled(string $period): bool
    {
        return $this->installation->reportingPeriodEnabled($period);
    }

    public function uniqueVisitorsEnabled(string $period): bool
    {
        return $this->installation->uniqueVisitorsEnabled($period);
    }

    public function anonymousSegmentsEnabled(): bool
    {
        return $this->installation->anonymousSegmentsEnabled();
    }
}
