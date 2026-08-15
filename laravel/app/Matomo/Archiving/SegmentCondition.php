<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

final readonly class SegmentCondition
{
    public function __construct(
        public string $name,
        public string $operator,
        public string $value,
    ) {}
}
