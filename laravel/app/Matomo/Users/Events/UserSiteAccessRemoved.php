<?php

declare(strict_types=1);

namespace App\Matomo\Users\Events;

final readonly class UserSiteAccessRemoved
{
    /** @param list<int> $siteIds */
    public function __construct(
        public string $login,
        public array $siteIds,
    ) {}
}
