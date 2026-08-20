<?php

declare(strict_types=1);

namespace App\Matomo\Geolocation;

use RuntimeException;

final readonly class FileTrackerCacheInvalidator implements TrackerCacheInvalidator
{
    public function __construct(private string $generalCachePath) {}

    public function clearGeneral(): void
    {
        if (! is_file($this->generalCachePath)) {
            return;
        }

        if (! unlink($this->generalCachePath)) {
            throw new RuntimeException('The general tracker cache could not be cleared.');
        }
    }
}
