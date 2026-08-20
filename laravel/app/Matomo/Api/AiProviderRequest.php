<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class AiProviderRequest
{
    public function __construct(
        public string $providerId = '',
        public string $defaultProviderId = '',
        public string $defaultCapabilityLevel = '',
        #[\SensitiveParameter]
        private string $providerConfiguration = '{}',
        #[\SensitiveParameter]
        private string $providerConfigurations = '{}',
    ) {}

    public function providerConfiguration(): string
    {
        return $this->providerConfiguration;
    }

    public function providerConfigurations(): string
    {
        return $this->providerConfigurations;
    }
}
