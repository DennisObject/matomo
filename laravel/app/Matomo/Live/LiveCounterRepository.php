<?php

declare(strict_types=1);

namespace App\Matomo\Live;

interface LiveCounterRepository
{
    /**
     * @param  list<int>  $siteIds
     * @return array{visits: int, actions: int, visitors: int, visitsConverted: int}
     */
    public function counters(array $siteIds, int $lastMinutes, ?string $segment): array;
}
