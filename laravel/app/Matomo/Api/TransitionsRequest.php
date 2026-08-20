<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class TransitionsRequest
{
    public function __construct(
        public int $siteId,
        public string $period,
        public string $date,
    ) {}
}
