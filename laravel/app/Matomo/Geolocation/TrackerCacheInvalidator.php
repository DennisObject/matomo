<?php

declare(strict_types=1);

namespace App\Matomo\Geolocation;

interface TrackerCacheInvalidator
{
    public function clearGeneral(): void;
}
