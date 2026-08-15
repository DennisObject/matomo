<?php

declare(strict_types=1);

namespace App\Matomo\Archiving\Events;

final class ArchiveSitesSelecting
{
    /**
     * @param  list<int>  $siteIds
     * @param  list<string>  $dates
     */
    public function __construct(
        public array $siteIds,
        public readonly array $dates,
        public readonly ?string $period,
        public readonly ?string $segment,
    ) {}
}
