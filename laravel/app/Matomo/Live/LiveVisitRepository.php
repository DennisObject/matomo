<?php

declare(strict_types=1);

namespace App\Matomo\Live;

interface LiveVisitRepository
{
    /**
     * @param  list<int>  $siteIds
     * @return list<array<string, mixed>>
     */
    public function visits(
        array $siteIds,
        ?string $segment,
        ?string $start,
        ?string $end,
        ?int $minimumTimestamp,
        int $offset,
        int $limit,
        bool $ascending = false,
        ?string $visitorId = null,
        bool $fetchActions = true,
        bool $flat = false,
        ?string $intersectSegment = null,
        string $language = 'en',
    ): array;
}
