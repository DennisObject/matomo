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
        public ?string $segment,
        public ?string $goalId,
        public ?string $language,
        public bool $showTimer,
        public bool $hideMetricsDocumentation,
        public ?int $subtableId,
        public bool $showRawMetrics,
        public ?string $formatMetrics,
        public ?int $dimensionId,
    ) {}
}
