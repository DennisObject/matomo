<?php

declare(strict_types=1);

namespace App\Matomo\Live;

interface LiveVisitorIdentityRepository
{
    public function mostRecentVisitorId(int $siteId, ?string $segment): string|false;

    /** @param list<int> $siteIds */
    public function mostRecentVisitDateTime(array $siteIds, ?string $startDateTime, ?string $endDateTime): string;
}
