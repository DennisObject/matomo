<?php

declare(strict_types=1);

namespace App\Matomo\Authentication\Events;

final class UserSiteAccessLoaded
{
    /**
     * @param  array<string, list<int>>  $siteIdsByAccess
     */
    public function __construct(
        public readonly string $login,
        public array $siteIdsByAccess,
    ) {}
}
