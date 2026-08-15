<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class RowEvolutionRequest
{
    public function __construct(
        public int $siteId,
        public string $period,
        public string $date,
        public string $apiModule,
        public string $apiAction,
        public ?string $label,
        public ?string $segment,
        public ?string $column,
    ) {}
}
