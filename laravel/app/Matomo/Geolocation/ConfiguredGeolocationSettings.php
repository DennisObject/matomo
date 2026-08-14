<?php

declare(strict_types=1);

namespace App\Matomo\Geolocation;

use App\Matomo\Config\InstallationConfig;

final readonly class ConfiguredGeolocationSettings implements GeolocationSettings
{
    public function __construct(private InstallationConfig $configuration) {}

    public function adminEnabled(): bool
    {
        return $this->configuration->geolocationAdminEnabled();
    }
}
