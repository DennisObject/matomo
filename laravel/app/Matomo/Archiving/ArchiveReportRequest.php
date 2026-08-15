<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

final readonly class ArchiveReportRequest
{
    /**
     * @param  list<string>  $reports
     */
    public function __construct(
        public int $siteId,
        public string $period,
        public string $date,
        public ?string $segment = null,
        public ?string $plugin = null,
        public array $reports = [],
        public bool $force = false,
    ) {}
}
