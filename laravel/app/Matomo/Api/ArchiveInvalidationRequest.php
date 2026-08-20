<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class ArchiveInvalidationRequest
{
    /**
     * @param  list<int>  $siteIds
     * @param  list<string>  $dates
     */
    public function __construct(
        public array $siteIds,
        public bool $allSites,
        public array $dates,
        public ?string $period,
        public ?string $segment,
        public bool $cascadeDown,
        public bool $forceInvalidateNonexistent,
    ) {}
}
