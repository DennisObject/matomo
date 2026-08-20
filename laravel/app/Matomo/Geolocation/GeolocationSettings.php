<?php

declare(strict_types=1);

namespace App\Matomo\Geolocation;

interface GeolocationSettings
{
    public function adminEnabled(): bool;
}
