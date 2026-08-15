<?php

declare(strict_types=1);

namespace App\Matomo\Dashboard;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Authentication\SiteAccessRole;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseDashboardRecipientPolicy implements DashboardRecipientPolicy
{
    public function __construct(
        private ConnectionInterface $connection,
        private ApiAccessAuthorizer $authorizer,
    ) {}

    public function canCopyTo(ApiAuthentication $authentication, string $login): bool
    {
        $user = $this->connection
            ->table('user')
            ->select(['login', 'invite_token', 'invited_by'])
            ->where('login', $login)
            ->first();

        if ($user === null) {
            return false;
        }

        if ($this->authorizer->hasSuperUserAccess($authentication)) {
            return true;
        }

        $currentLogin = $this->authorizer->authenticatedLogin($authentication);
        $values = get_object_vars($user);

        if ($currentLogin === null || $currentLogin === '') {
            return false;
        }

        if ($login === $currentLogin) {
            return true;
        }

        $inviteToken = $values['invite_token'] ?? null;

        if (is_string($inviteToken) && $inviteToken !== '' && ($values['invited_by'] ?? null) !== $currentLogin) {
            return false;
        }

        $adminSiteIds = $this->authorizer->siteIdsWithRole($authentication, SiteAccessRole::Admin);

        return $adminSiteIds !== [] && $this->connection
            ->table('access')
            ->where('login', $login)
            ->whereIn('idsite', $adminSiteIds)
            ->whereIn('access', ['view', 'write', 'admin'])
            ->exists();
    }
}
