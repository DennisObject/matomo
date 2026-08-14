<?php

declare(strict_types=1);

namespace App\Matomo\Geolocation;

interface GeolocationProviderRegistry
{
    /** @return array<string, float|int|string|null>|null */
    public function locate(
        string $ipAddress,
        string $browserLanguage,
        string $currentIpAddress,
        ?string $providerId = null,
    ): ?array;

    public function setCurrent(string $providerId): void;
}
