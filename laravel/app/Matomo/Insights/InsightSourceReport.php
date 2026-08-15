<?php

declare(strict_types=1);

namespace App\Matomo\Insights;

final readonly class InsightSourceReport
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public array $rows,
        public array $metadata,
        public int $metricTotal,
    ) {}
}
