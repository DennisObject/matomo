<?php

declare(strict_types=1);

namespace App\Matomo\Users;

interface MutableUserSiteAccessRepository
{
    /** @param list<int> $siteIds
     * @param  list<string>  $capabilities
     * @return 'updated'|'superuser'
     */
    public function replace(
        string $login,
        array $siteIds,
        ?string $role,
        array $capabilities,
    ): string;

    /**
     * @param  list<int>  $siteIds
     * @param  array<string, list<string>>  $includedInRoles
     * @return int|'superuser'|null Site ID missing a role, a protected target, or null on success.
     */
    public function addCapabilities(
        string $login,
        array $siteIds,
        array $includedInRoles,
    ): int|string|null;

    /** @param list<int> $siteIds
     * @param  list<string>  $capabilities
     */
    public function removeCapabilities(string $login, array $siteIds, array $capabilities): void;
}
