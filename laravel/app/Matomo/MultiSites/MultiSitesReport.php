<?php

declare(strict_types=1);

namespace App\Matomo\MultiSites;

final readonly class MultiSitesReport
{
    /**
     * @param  array<string, list<array<string, float|int|string|null>>>  $rowsByPeriod
     * @param  array<string, array<string, float|int>>  $totalsByPeriod
     */
    public function __construct(
        public array $rowsByPeriod,
        public array $totalsByPeriod,
        public string $lastDate,
    ) {}
}
