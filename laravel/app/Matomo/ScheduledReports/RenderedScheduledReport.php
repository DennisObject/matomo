<?php

declare(strict_types=1);

namespace App\Matomo\ScheduledReports;

final readonly class RenderedScheduledReport
{
    public function __construct(
        public string $contents,
        public string $mimeType,
        public string $extension,
    ) {}
}
