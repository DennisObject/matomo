<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\AiProviders\AiProviderCentralConfiguration;
use App\Matomo\AiProviders\AiProviderConfiguration;
use App\Matomo\AiProviders\AiProviderConnectionTester;
use App\Matomo\AiProviders\AiProviderDefinition;
use App\Matomo\AiProviders\AiProviderSettingsRepository;
use App\Matomo\AiProviders\AiProviderStoredSettings;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use Tests\TestCase;

class AiProvidersApiTest extends TestCase
{
    public function test_returns_masked_provider_settings_to_a_superuser(): void
    {
        $this->bindSuperUser();
        $repository = new MemoryAiProviderSettingsRepository(new AiProviderStoredSettings(
            'openai',
            'thinking',
            ['openai' => [
                'apiKey' => 'do-not-return-me',
                'endpointUrl' => '',
                'model' => '',
                'useFipsEndpoint' => false,
            ]],
        ));
        $this->app->instance(AiProviderSettingsRepository::class, $repository);

        $response = $this->get($this->url('getSettings'));

        $response->assertOk()
            ->assertJsonPath('defaultProviderId', 'openai')
            ->assertJsonPath('defaultCapabilityLevel', 'thinking')
            ->assertJsonPath('providers.2.id', 'openai')
            ->assertJsonPath('providers.2.configuration.hasApiKey', true)
            ->assertJsonPath('providers.2.configuration.isUsable', true)
            ->assertJsonMissing(['apiKey' => 'do-not-return-me']);
        $this->assertStringNotContainsString('do-not-return-me', (string) $response->getContent());
    }

    public function test_saves_verbatim_secret_values_and_preserves_them_when_the_field_is_empty(): void
    {
        $this->bindSuperUser();
        $repository = new MemoryAiProviderSettingsRepository;
        $this->app->instance(AiProviderSettingsRepository::class, $repository);
        $secret = 'key-&<>-unchanged';

        $this->post($this->url('saveSettings'), [
            'defaultProviderId' => 'openai',
            'defaultCapabilityLevel' => 'thinking',
            'providerConfigurations' => json_encode([
                'openai' => ['apiKey' => $secret],
            ], JSON_THROW_ON_ERROR),
        ])->assertOk()
            ->assertJsonPath('defaultProviderId', 'openai')
            ->assertJsonPath('defaultCapabilityLevel', 'thinking')
            ->assertJsonMissing(['apiKey' => $secret]);

        $this->assertSame($secret, $repository->read()->providerCredentials['openai']['apiKey']);

        $this->post($this->url('saveSettings'), [
            'defaultProviderId' => 'openai',
            'defaultCapabilityLevel' => '',
            'providerConfigurations' => json_encode([
                'openai' => ['apiKey' => ''],
            ], JSON_THROW_ON_ERROR),
        ])->assertOk();

        $this->assertSame($secret, $repository->read()->providerCredentials['openai']['apiKey']);
        $this->assertSame('thinking', $repository->read()->defaultCapabilityLevel);
    }

    public function test_rejects_unknown_and_unconfigured_default_providers(): void
    {
        $this->bindSuperUser();

        $this->post($this->url('saveSettings'), [
            'defaultProviderId' => 'missing',
        ])->assertBadRequest()->assertJsonPath('message', 'Unknown AI provider "missing".');

        $this->post($this->url('saveSettings'), [
            'defaultProviderId' => 'anthropic',
        ])->assertBadRequest()->assertJsonPath('message', 'AI provider "Anthropic" is not configured.');
    }

    public function test_normalizes_bedrock_regions_and_rejects_invalid_endpoint_fields(): void
    {
        $this->bindSuperUser();
        $repository = new MemoryAiProviderSettingsRepository;
        $this->app->instance(AiProviderSettingsRepository::class, $repository);

        $this->post($this->url('saveSettings'), [
            'defaultProviderId' => 'bedrock',
            'providerConfigurations' => json_encode([
                'bedrock' => [
                    'apiKey' => 'aws-key',
                    'endpointUrl' => 'EU-WEST-1',
                    'useFipsEndpoint' => true,
                ],
            ], JSON_THROW_ON_ERROR),
        ])->assertOk()->assertJsonPath('defaultProviderId', 'bedrock');

        $this->assertSame('eu-west-1', $repository->read()->providerCredentials['bedrock']['endpointUrl']);
        $this->assertTrue($repository->read()->providerCredentials['bedrock']['useFipsEndpoint']);

        $this->post($this->url('saveSettings'), [
            'providerConfigurations' => json_encode([
                'custom-provider' => ['endpointUrl' => 'not-a-url'],
            ], JSON_THROW_ON_ERROR),
        ])->assertBadRequest()->assertJsonPath(
            'message',
            'The endpoint URL for Custom Provider is invalid.',
        );
    }

    public function test_rejects_connection_tests_that_redirect_a_central_key_to_an_admin_endpoint(): void
    {
        $this->bindSuperUser();
        $this->app->instance(AiProviderCentralConfiguration::class, new AiProviderCentralConfiguration([
            'custom-providerApiKey' => 'managed-key',
            'custom-providerEndpointUrl' => 'https://managed.example/v1',
        ], static fn (string $name): false => false));

        $this->post($this->url('testConnection'), [
            'providerId' => 'custom-provider',
            'providerConfiguration' => json_encode([
                'endpointUrl' => 'https://attacker.example/v1',
            ], JSON_THROW_ON_ERROR),
        ])->assertBadRequest()->assertJsonPath(
            'message',
            'The endpoint for Custom Provider is set in the Matomo server configuration, as '
                .'"custom-providerEndpointUrl" in the [AIProviders] section. It cannot be changed on this page.',
        );
    }

    public function test_rejects_an_admin_endpoint_when_a_central_custom_key_has_no_endpoint(): void
    {
        $this->bindSuperUser();
        $this->app->instance(AiProviderCentralConfiguration::class, new AiProviderCentralConfiguration([
            'custom-providerApiKey' => 'managed-key',
        ], static fn (string $name): false => false));

        $this->post($this->url('testConnection'), [
            'providerId' => 'custom-provider',
            'providerConfiguration' => json_encode([
                'endpointUrl' => 'https://attacker.example/v1',
            ], JSON_THROW_ON_ERROR),
        ])->assertBadRequest()->assertJsonPath(
            'message',
            'The API key for Custom Provider is set in the Matomo server configuration, so its endpoint URL '
                .'must be set there as well, as "custom-providerEndpointUrl" in the [AIProviders] section. '
                .'It cannot be changed on this page.',
        );
    }

    public function test_tests_a_submitted_custom_connection_without_exposing_its_key(): void
    {
        $this->bindSuperUser();
        $tester = new RecordingAiProviderConnectionTester(['model-b', 'model-a']);
        $this->app->instance(AiProviderConnectionTester::class, $tester);
        $secret = 'custom-secret';

        $response = $this->post($this->url('testConnection'), [
            'providerId' => 'custom-provider',
            'providerConfiguration' => json_encode([
                'apiKey' => $secret,
                'endpointUrl' => 'https://llm.example/v1/chat/completions',
                'model' => 'model-a',
            ], JSON_THROW_ON_ERROR),
        ]);

        $response->assertOk()->assertExactJson([
            'providerId' => 'custom-provider',
            'providerName' => 'Custom Provider',
            'models' => ['model-b', 'model-a'],
        ]);
        $this->assertNotNull($tester->configuration);
        $this->assertSame($secret, $tester->configuration->apiKey);
        $this->assertSame('https://llm.example/v1/chat/completions', $tester->configuration->endpointUrl);
        $this->assertStringNotContainsString($secret, (string) $response->getContent());
    }

    public function test_disconnect_removes_only_the_stored_provider_connection(): void
    {
        $this->bindSuperUser();
        $repository = new MemoryAiProviderSettingsRepository(new AiProviderStoredSettings(
            'openai',
            'instant',
            [
                'openai' => $this->configuration('openai-key'),
                'anthropic' => $this->configuration('anthropic-key'),
            ],
        ));
        $this->app->instance(AiProviderSettingsRepository::class, $repository);

        $this->post($this->url('disconnectProvider'), ['providerId' => 'anthropic'])
            ->assertOk()
            ->assertJsonPath('providers.0.configuration.hasApiKey', false)
            ->assertJsonPath('providers.2.configuration.hasApiKey', true);

        $this->assertSame(['openai'], array_keys($repository->read()->providerCredentials));
    }

    public function test_managed_configuration_ignores_all_admin_edits_and_pins_custom_keys(): void
    {
        $this->bindSuperUser();
        $repository = new MemoryAiProviderSettingsRepository;
        $this->app->instance(AiProviderSettingsRepository::class, $repository);
        $this->app->instance(AiProviderCentralConfiguration::class, new AiProviderCentralConfiguration([
            'defaultProvider' => 'openai',
            'openaiApiKey' => 'managed-key',
            'custom-providerApiKey' => 'managed-custom-key',
        ], static fn (string $name): false => false));

        $this->post($this->url('saveSettings'), [
            'defaultProviderId' => 'anthropic',
            'defaultCapabilityLevel' => 'thinking',
            'providerConfigurations' => json_encode([
                'anthropic' => ['apiKey' => 'submitted-key'],
            ], JSON_THROW_ON_ERROR),
        ])->assertOk()
            ->assertJsonPath('defaultProviderId', 'openai')
            ->assertJsonPath('canEditProviderConfiguration', false)
            ->assertJsonPath('providers.2.configuration.hasApiKey', true)
            ->assertJsonPath('providers.4.configuration.hasApiKey', true)
            ->assertJsonPath('providers.4.configuration.endpointUrl', '')
            ->assertJsonPath('providers.4.configuration.isUsable', false);

        $this->assertSame([], $repository->read()->providerCredentials);
    }

    public function test_rejects_access_without_superuser_permission(): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));

        $this->get($this->url('getSettings'))->assertUnauthorized();
    }

    private function bindSuperUser(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    private function url(string $method): string
    {
        return "/index.php?module=API&method=AIProviders.{$method}&format=json&token_auth=super-token";
    }

    /** @return array{apiKey: string, endpointUrl: string, model: string, useFipsEndpoint: bool} */
    private function configuration(string $apiKey): array
    {
        return ['apiKey' => $apiKey, 'endpointUrl' => '', 'model' => '', 'useFipsEndpoint' => false];
    }
}

final class MemoryAiProviderSettingsRepository implements AiProviderSettingsRepository
{
    public function __construct(
        private AiProviderStoredSettings $settings = new AiProviderStoredSettings('', 'instant', []),
    ) {}

    public function read(): AiProviderStoredSettings
    {
        return $this->settings;
    }

    public function save(#[\SensitiveParameter] AiProviderStoredSettings $settings): void
    {
        $this->settings = $settings;
    }
}

final class RecordingAiProviderConnectionTester implements AiProviderConnectionTester
{
    public ?AiProviderDefinition $provider = null;

    public ?AiProviderConfiguration $configuration = null;

    /** @param list<string> $models */
    public function __construct(private readonly array $models) {}

    public function test(
        AiProviderDefinition $provider,
        #[\SensitiveParameter]
        AiProviderConfiguration $configuration,
    ): array {
        $this->provider = $provider;
        $this->configuration = $configuration;

        return $this->models;
    }
}
