<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class ProcessedReportRequest
{
    /** @param array<string, mixed> $apiParameters */
    public function __construct(
        public int $siteId,
        public string $period,
        public string $date,
        public string $apiModule,
        public string $apiAction,
        public array $apiParameters,
        public bool $hideMetricsDocumentation,
        public bool $showRawMetrics,
    ) {}
}
