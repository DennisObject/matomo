<?php

declare(strict_types=1);

namespace App\Matomo\Archiving\Events;

use App\Matomo\Archiving\ArchiveReportRequest;
use App\Matomo\Archiving\ArchiveReportResult;

final readonly class ArchiveReportsCompleted
{
    public function __construct(
        public ArchiveReportRequest $request,
        public ArchiveReportResult $result,
    ) {}
}
