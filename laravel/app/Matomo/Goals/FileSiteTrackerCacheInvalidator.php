<?php

declare(strict_types=1);

namespace App\Matomo\Goals;

use RuntimeException;

final readonly class FileSiteTrackerCacheInvalidator implements SiteTrackerCacheInvalidator
{
    public function __construct(private string $directory) {}

    public function clear(int $siteId): void
    {
        $path = rtrim($this->directory, '/').'/matomocache_'.$siteId.'.php';

        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException("The tracker cache for website {$siteId} could not be cleared.");
        }
    }
}
