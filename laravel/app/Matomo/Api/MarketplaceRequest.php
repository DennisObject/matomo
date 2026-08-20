<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class MarketplaceRequest
{
    public function __construct(
        public ?string $email = null,
        public ?string $pluginName = null,
        #[\SensitiveParameter]
        public ?string $licenseKey = null,
    ) {}
}
