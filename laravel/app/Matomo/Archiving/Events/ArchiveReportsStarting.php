<?php

declare(strict_types=1);

namespace App\Matomo\Archiving\Events;

use App\Matomo\Archiving\ArchiveReportRequest;

final readonly class ArchiveReportsStarting
{
    public function __construct(public ArchiveReportRequest $request) {}
}
