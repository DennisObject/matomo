<?php

declare(strict_types=1);

namespace App\Matomo\AiProviders;

final readonly class AiProviderStoredSettings
{
    /**
     * @param  array<string, array{apiKey: string, endpointUrl: string, model: string, useFipsEndpoint: bool}>  $providerCredentials
     */
    public function __construct(
        public string $defaultProvider,
        public string $defaultCapabilityLevel,
        #[\SensitiveParameter]
        public array $providerCredentials,
    ) {}
}
