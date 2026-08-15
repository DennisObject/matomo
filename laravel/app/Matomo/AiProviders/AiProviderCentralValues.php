<?php

declare(strict_types=1);

namespace App\Matomo\AiProviders;

final readonly class AiProviderCentralValues
{
    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
        public string $endpointUrl,
        public string $model,
        public ?bool $useFipsEndpoint,
    ) {}
}
