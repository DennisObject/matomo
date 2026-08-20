<?php

declare(strict_types=1);

namespace App\Matomo\Geolocation;

final readonly class DisabledGeolocationProvider implements GeolocationProvider
{
    public function id(): string
    {
        return 'disabled';
    }

    public function available(): bool
    {
        return true;
    }

    public function locate(string $ipAddress, string $browserLanguage, string $currentIpAddress): ?array
    {
        return null;
    }

    public function activate(): void {}
}
