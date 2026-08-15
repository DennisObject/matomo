<?php

declare(strict_types=1);

namespace App\Matomo\Goals;

interface SiteTrackerCacheInvalidator
{
    public function clear(int $siteId): void;
}
