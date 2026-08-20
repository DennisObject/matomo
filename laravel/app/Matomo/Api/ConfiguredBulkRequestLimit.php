<?php

declare(strict_types=1);

namespace App\Matomo\Api;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Config\InstallationConfig;
use Closure;

final readonly class ConfiguredBulkRequestLimit implements BulkRequestLimit
{
    /** @param Closure(): InstallationConfig $configuration */
    public function __construct(
        private Closure $configuration,
        private ApiAccessAuthorizer $authorizer,
    ) {}

    public function current(ApiAuthentication $authentication): int
    {
        $configuredLimit = ($this->configuration)()->apiBulkRequestLimit();
        $login = $this->authorizer->authenticatedLogin($authentication);

        if ($login !== null && $login !== 'anonymous') {
            return $configuredLimit;
        }

        $anonymousLimit = $this->authorizer->hasSomeViewAccess($authentication) ? 50 : 10;

        return $configuredLimit > -1 ? min($anonymousLimit, $configuredLimit) : $anonymousLimit;
    }
}
