<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class InsightsRequest
{
    public function __construct(
        public string $period,
        public string $date,
    ) {}
}
