<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

use Illuminate\Cache\CacheManager;

final readonly class LaravelSegmentCacheInvalidator implements SegmentCacheInvalidator
{
    public function __construct(private CacheManager $cache) {}

    public function clear(): void
    {
        $this->cache->store()->clear();
    }
}
