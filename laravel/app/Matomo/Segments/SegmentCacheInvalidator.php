<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

interface SegmentCacheInvalidator
{
    public function clear(): void;
}
