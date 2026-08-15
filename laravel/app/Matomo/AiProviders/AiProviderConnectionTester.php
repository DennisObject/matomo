<?php

declare(strict_types=1);

namespace App\Matomo\AiProviders;

interface AiProviderConnectionTester
{
    /** @return list<string> */
    public function test(
        AiProviderDefinition $provider,
        #[\SensitiveParameter]
        AiProviderConfiguration $configuration,
    ): array;
}
