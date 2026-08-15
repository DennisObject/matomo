<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class ReportMetadataRequest
{
    public function __construct(
        public string $method,
        public int $siteId,
        public ?string $apiModule,
        public ?string $apiAction,
        public bool $hideMetricsDocumentation,
    ) {}
}
