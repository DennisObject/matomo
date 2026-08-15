<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class PrivacyComplianceReadRequest
{
    public function __construct(
        public string $site,
        public string $policy,
    ) {}
}
