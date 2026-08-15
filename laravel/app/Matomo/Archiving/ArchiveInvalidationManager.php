<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

interface ArchiveInvalidationManager
{
    /**
     * @param  list<int>  $siteIds
     * @param  list<string>  $dates
     * @return list<string>
     */
    public function invalidate(
        array $siteIds,
        array $dates,
        ?string $period,
        ?string $segment,
        bool $cascadeDown,
        bool $forceInvalidateNonexistent,
    ): array;
}
