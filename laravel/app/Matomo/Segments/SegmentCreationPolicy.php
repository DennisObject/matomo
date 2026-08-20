<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Config\InstallationConfig;
use Closure;

final readonly class SegmentCreationPolicy implements SegmentCreationAuthorizer
{
    /** @param Closure(): InstallationConfig $configuration */
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private Closure $configuration,
    ) {}

    public function allowed(ApiAuthentication $authentication, ?int $siteId): bool
    {
        $login = $this->authorizer->authenticatedLogin($authentication);

        if ($login === null || $login === 'anonymous') {
            return false;
        }

        if ($this->authorizer->hasSuperUserAccess($authentication)) {
            return true;
        }

        if ($siteId === null) {
            return false;
        }

        $role = match (($this->configuration)()->segmentCreationAccess($siteId)) {
            'admin' => SiteAccessRole::Admin,
            'write' => SiteAccessRole::Write,
            'view' => SiteAccessRole::View,
            default => null,
        };

        if ($role === null) {
            return false;
        }

        return in_array(
            $siteId,
            $this->authorizer->siteIdsWithMinimumRole($authentication, $role),
            true,
        );
    }
}
