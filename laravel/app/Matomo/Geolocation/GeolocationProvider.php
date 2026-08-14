<?php

declare(strict_types=1);

namespace App\Matomo\Geolocation;

interface GeolocationProvider
{
    public function id(): string;

    public function available(): bool;

    /** @return array<string, float|int|string|null>|null */
    public function locate(string $ipAddress, string $browserLanguage, string $currentIpAddress): ?array;

    public function activate(): void;
}
