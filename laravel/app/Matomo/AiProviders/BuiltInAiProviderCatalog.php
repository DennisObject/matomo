<?php

declare(strict_types=1);

namespace App\Matomo\AiProviders;

use App\Matomo\AiProviders\Events\AiProvidersCollecting;
use Illuminate\Contracts\Events\Dispatcher;

final class BuiltInAiProviderCatalog implements AiProviderCatalog
{
    /** @var list<AiProviderDefinition>|null */
    private ?array $providers = null;

    /** @var array<string, bool> */
    private array $selectableProviders = [];

    public function __construct(private readonly ?Dispatcher $events = null) {}

    public function all(): array
    {
        $this->collect();

        return $this->providers ?? [];
    }

    public function selectable(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (AiProviderDefinition $provider): bool => $this->isSelectable($provider->id),
        ));
    }

    public function find(string $providerId): ?AiProviderDefinition
    {
        foreach ($this->all() as $provider) {
            if ($provider->id === $providerId) {
                return $provider;
            }
        }

        return null;
    }

    public function isSelectable(string $providerId): bool
    {
        $this->collect();

        return $this->selectableProviders[$providerId] ?? false;
    }

    private function collect(): void
    {
        if ($this->providers !== null) {
            return;
        }

        $collecting = new AiProvidersCollecting([
            new AiProviderDefinition(
                id: 'anthropic',
                name: 'Anthropic',
                description: 'AIProviders_AnthropicDefaultModelDescription',
                supportsCustomEndpoint: false,
                supportsFipsEndpoint: false,
                defaultEndpointUrl: 'https://api.anthropic.com/v1/messages',
                endpointFieldTitle: 'AIProviders_EndpointUrl',
                endpointFieldPlaceholder: 'AIProviders_EndpointUrlPlaceholder',
                defaultModel: 'claude-haiku-4-5',
            ),
            new AiProviderDefinition(
                id: 'google',
                name: 'Google',
                description: 'AIProviders_GoogleDefaultModelDescription',
                supportsCustomEndpoint: false,
                supportsFipsEndpoint: false,
                defaultEndpointUrl: 'https://generativelanguage.googleapis.com/v1beta/models/'
                    .'gemini-3.1-flash-lite:generateContent',
                endpointFieldTitle: 'AIProviders_EndpointUrl',
                endpointFieldPlaceholder: 'AIProviders_EndpointUrlPlaceholder',
                defaultModel: 'gemini-3.1-flash-lite',
            ),
            new AiProviderDefinition(
                id: 'openai',
                name: 'OpenAI',
                description: 'AIProviders_OpenAIDefaultModelDescription',
                supportsCustomEndpoint: false,
                supportsFipsEndpoint: false,
                defaultEndpointUrl: 'https://api.openai.com/v1/chat/completions',
                endpointFieldTitle: 'AIProviders_EndpointUrl',
                endpointFieldPlaceholder: 'AIProviders_EndpointUrlPlaceholder',
                defaultModel: 'gpt-5.4-mini',
            ),
            new AiProviderDefinition(
                id: 'bedrock',
                name: 'AWS Bedrock',
                description: 'AIProviders_BedrockDescription',
                supportsCustomEndpoint: true,
                supportsFipsEndpoint: true,
                defaultEndpointUrl: 'us-east-1',
                endpointFieldTitle: 'AIProviders_BedrockEndpointTitle',
                endpointFieldPlaceholder: 'AIProviders_BedrockEndpointPlaceholder',
                defaultModel: 'openai.gpt-oss-120b-1:0',
                endpointFieldRequiresUrl: false,
            ),
            new AiProviderDefinition(
                id: 'custom-provider',
                name: 'Custom Provider',
                description: 'AIProviders_CustomProviderDescription',
                supportsCustomEndpoint: true,
                supportsFipsEndpoint: false,
                defaultEndpointUrl: '',
                endpointFieldTitle: 'AIProviders_EndpointUrl',
                endpointFieldPlaceholder: 'AIProviders_EndpointUrlPlaceholder',
                defaultModel: '',
            ),
        ]);
        $this->events?->dispatch($collecting);
        $this->providers = $collecting->all();
        $this->selectableProviders = array_fill_keys(
            array_map(static fn (AiProviderDefinition $provider): string => $provider->id, $collecting->selectable()),
            true,
        );
    }
}
