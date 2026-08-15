<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

final readonly class ArchiveReportResult
{
    /**
     * @param  list<int>  $archiveIds
     */
    public function __construct(
        public array $archiveIds,
        public int|float $visits,
        public bool $cached,
    ) {}
}
