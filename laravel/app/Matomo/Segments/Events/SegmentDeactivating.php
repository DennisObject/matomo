<?php

declare(strict_types=1);

namespace App\Matomo\Segments\Events;

final readonly class SegmentDeactivating
{
    public function __construct(public int $segmentId) {}
}
