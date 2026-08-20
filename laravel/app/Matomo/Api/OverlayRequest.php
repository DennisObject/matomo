<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class OverlayRequest
{
    public function __construct(
        public int $siteId,
        public string $period,
        public string $date,
        public string $url,
        public ?string $segment,
        public int $filterLimit,
        public int $filterOffset,
    ) {}
}
