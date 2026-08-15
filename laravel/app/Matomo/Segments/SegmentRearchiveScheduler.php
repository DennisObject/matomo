<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

interface SegmentRearchiveScheduler
{
    /** @param array<string, bool|int|string|null> $segment */
    public function schedule(array $segment): void;
}
