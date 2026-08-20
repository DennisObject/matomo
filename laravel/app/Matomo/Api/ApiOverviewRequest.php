<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class ApiOverviewRequest
{
    /** @param list<string> $columns */
    public function __construct(
        public int $siteId,
        public string $period,
        public string $date,
        public ?string $segment,
        public array $columns,
    ) {}
}
