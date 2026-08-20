<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class ReportMetadataRequest
{
    /** @param array<string, mixed> $apiParameters */
    public function __construct(
        public string $method,
        public int $siteId,
        public ?string $apiModule,
        public ?string $apiAction,
        public array $apiParameters,
        public ?string $period,
        public ?string $date,
        public bool $hideMetricsDocumentation,
        public bool $showSubtableReports,
    ) {}
}
