<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

interface ReportingSettings
{
    public function periodEnabled(string $period): bool;

    public function uniqueVisitorsEnabled(string $period): bool;

    public function anonymousSegmentsEnabled(): bool;
}
