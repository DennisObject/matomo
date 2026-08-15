<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

use App\Matomo\Config\InstallationConfig;

final readonly class ConfiguredSiteRuntimeSettings implements SiteRuntimeSettings
{
    public function __construct(private InstallationConfig $installation) {}

    public function timezoneSupportEnabled(): bool
    {
        return function_exists('date_create')
            && function_exists('date_default_timezone_set')
            && function_exists('timezone_identifiers_list')
            && function_exists('timezone_open')
            && function_exists('timezone_offset_get');
    }

    public function websitesCountToDisplay(): int
    {
        return $this->installation->websitesCountToDisplay();
    }

    public function administrationEnabled(): bool
    {
        return $this->installation->sitesAdminEnabled();
    }
}
