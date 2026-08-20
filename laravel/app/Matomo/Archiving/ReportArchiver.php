<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

interface ReportArchiver
{
    public function archive(ArchiveReportRequest $request): ArchiveReportResult;
}
