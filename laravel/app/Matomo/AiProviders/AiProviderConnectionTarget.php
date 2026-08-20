<?php

declare(strict_types=1);

namespace App\Matomo\AiProviders;

final readonly class AiProviderConnectionTarget
{
    public function __construct(
        public AiProviderDefinition $provider,
        #[\SensitiveParameter]
        public AiProviderConfiguration $configuration,
    ) {}
}
