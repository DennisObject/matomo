<?php

declare(strict_types=1);

namespace App\Matomo\Authentication;

interface ApiAccessAuthorizer
{
    public function hasSomeViewAccess(ApiAuthentication $authentication): bool;

    public function hasSuperUserAccess(ApiAuthentication $authentication): bool;

    /**
     * @return list<int>
     */
    public function siteIdsWithRole(
        ApiAuthentication $authentication,
        SiteAccessRole $role,
    ): array;
}
