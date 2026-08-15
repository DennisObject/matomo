<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\AiProviders\AiProviderCentralConfiguration;
use PHPUnit\Framework\TestCase;

class AiProviderCentralConfigurationTest extends TestCase
{
    public function test_config_values_win_over_environment_values_per_field(): void
    {
        $environment = [
            'MATOMO_AIPROVIDERS_OPENAI_API_KEY' => 'environment-key',
            'MATOMO_AIPROVIDERS_OPENAI_MODEL' => 'environment-model',
            'MATOMO_AIPROVIDERS_BEDROCK_USE_FIPS_ENDPOINT' => '1',
        ];
        $configuration = new AiProviderCentralConfiguration([
            'defaultProvider' => 'openai',
            'defaultCapabilityLevel' => 'thinking',
            'openaiApiKey' => 'configured-key',
        ], static fn (string $name): string|false => $environment[$name] ?? false);

        $this->assertTrue($configuration->managed());
        $this->assertSame('openai', $configuration->defaultProvider());
        $this->assertSame('thinking', $configuration->defaultCapabilityLevel());
        $this->assertSame('configured-key', $configuration->configuration('openai')->apiKey);
        $this->assertSame('environment-model', $configuration->configuration('openai')->model);
        $this->assertTrue($configuration->configuration('bedrock')->useFipsEndpoint);
    }
}
