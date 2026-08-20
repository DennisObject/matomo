<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class LiveRequest
{
    /**
     * @param  list<int>  $siteIds
     * @param  list<string>  $showColumns
     * @param  list<string>  $hideColumns
     */
    public function __construct(
        public array $siteIds,
        public bool $allSites,
        public ?int $lastMinutes,
        public ?string $segment,
        public array $showColumns,
        public array $hideColumns,
        public ?string $period = null,
        public ?string $date = null,
        public ?int $minimumTimestamp = null,
        public int $filterOffset = 0,
        public int $filterLimit = 10,
        public ?string $visitorId = null,
        public bool $fetchActions = true,
        public bool $flat = false,
        public int $profileVisitLimit = 10,
        public ?string $intersectSegment = null,
    ) {}
}
