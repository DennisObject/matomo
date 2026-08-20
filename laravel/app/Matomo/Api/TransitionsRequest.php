<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class TransitionsRequest
{
    /**
     * @param  'url'|'title'|null  $actionType
     * @param  list<'externalReferrers'|'followingActions'|'internalReferrers'>  $parts
     */
    public function __construct(
        public int $siteId,
        public string $period,
        public string $date,
        public ?string $actionName = null,
        public ?string $actionType = null,
        public ?string $segment = null,
        public int $limitBeforeGrouping = 0,
        public array $parts = [],
        public bool $allParts = true,
    ) {}
}
