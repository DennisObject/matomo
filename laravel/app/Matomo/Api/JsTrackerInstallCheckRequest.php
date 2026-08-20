<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class JsTrackerInstallCheckRequest
{
    public function __construct(
        public int $siteId,
        public string $nonce,
        public string $url,
    ) {}
}
