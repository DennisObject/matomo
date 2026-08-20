<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class MultiSitesRequest
{
    /**
     * @param  list<string>  $showColumns
     */
    public function __construct(
        public ?int $siteId,
        public string $period,
        public string $date,
        public ?string $segment,
        public bool $enhanced,
        public ?string $pattern,
        public array $showColumns,
        public int $filterLimit,
        public int $filterOffset,
        public string $filterSortColumn,
        public string $filterSortOrder,
        public bool $formatMetrics,
    ) {}
}
