<?php

declare(strict_types=1);

namespace App\Matomo\Segments\Events;

final readonly class SegmentUpdating
{
    /** @param array<string, bool|int|string|null> $values */
    public function __construct(
        public int $segmentId,
        public array $values,
    ) {}
}
