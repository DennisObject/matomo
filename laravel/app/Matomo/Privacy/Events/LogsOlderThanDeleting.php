<?php

declare(strict_types=1);

namespace App\Matomo\Privacy\Events;

use Carbon\CarbonImmutable;

final readonly class LogsOlderThanDeleting
{
    public function __construct(
        public CarbonImmutable $cutoff,
        public int $olderThanDays,
    ) {}
}
