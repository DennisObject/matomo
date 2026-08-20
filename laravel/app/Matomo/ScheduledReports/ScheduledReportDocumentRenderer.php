<?php

declare(strict_types=1);

namespace App\Matomo\ScheduledReports;

interface ScheduledReportDocumentRenderer
{
    public function render(string $html, string $format): RenderedScheduledReport;
}
