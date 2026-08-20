<?php

declare(strict_types=1);

namespace App\Matomo\Tour;

use App\Matomo\Config\InstallationConfig;

final readonly class ConfiguredTourSettings implements TourSettings
{
    public function __construct(private InstallationConfig $installation) {}

    public function usersAdminEnabled(): bool
    {
        return $this->installation->usersAdminEnabled();
    }

    public function sitesAdminEnabled(): bool
    {
        return $this->installation->sitesAdminEnabled();
    }

    public function generalSettingsAdminEnabled(): bool
    {
        return $this->installation->generalSettingsAdminEnabled();
    }

    public function geolocationAdminEnabled(): bool
    {
        return $this->installation->geolocationAdminEnabled();
    }

    public function customLogoEnabled(): bool
    {
        return $this->installation->customLogoEnabled();
    }

    public function browserArchivingTriggerEnabled(): bool
    {
        return $this->installation->browserArchivingTriggerEnabled();
    }
}
