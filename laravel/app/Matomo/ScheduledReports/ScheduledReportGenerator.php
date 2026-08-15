<?php

declare(strict_types=1);

namespace App\Matomo\ScheduledReports;

use Illuminate\Http\Request;

interface ScheduledReportGenerator
{
    /** @param array<string, mixed> $report */
    public function generate(array $report, string $date, string $period, Request $request): string;
}
