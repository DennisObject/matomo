<?php

declare(strict_types=1);

namespace App\Matomo\Users;

interface UserSiteAccessRepository
{
    /** @return array<string, list<int>> */
    public function sitesByLogin(string $access): array;

    /** @return array<string, string> */
    public function accessByLogin(int $siteId): array;

    /** @return list<string> */
    public function logins(int $siteId, string $access): array;

    /** @return list<array{site: int, access: string}> */
    public function forUser(string $login): array;
}
