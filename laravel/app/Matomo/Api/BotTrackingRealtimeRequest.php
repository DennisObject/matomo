<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class BotTrackingRealtimeRequest
{
    /**
     * @param  list<int>  $siteIds
     */
    public function __construct(
        public array $siteIds,
        public bool $allSites,
        public int $lastMinutes,
    ) {}
}
