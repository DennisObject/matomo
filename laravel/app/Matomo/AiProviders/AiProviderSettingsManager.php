<?php

declare(strict_types=1);

namespace App\Matomo\AiProviders;

use InvalidArgumentException;
use JsonException;

final readonly class AiProviderSettingsManager
{
    private const string DEFAULT_PROVIDER = 'openai';

    /** @var array<string, array{label: string, description: string}> */
    private const array CAPABILITY_LEVELS = [
        'instant' => [
            'label' => 'AIProviders_InstantCapability',
            'description' => 'AIProviders_InstantCapabilityDescription',
        ],
        'thinking' => [
            'label' => 'AIProviders_ThinkingCapability',
            'description' => 'AIProviders_ThinkingCapabilityDescription',
        ],
    ];

    public function __construct(
        private AiProviderCatalog $providers,
        private AiProviderSettingsRepository $repository,
        private AiProviderCentralConfiguration $central,
    ) {}

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->present($this->repository->read());
    }

    /** @return array<string, mixed> */
    public function save(
        string $defaultProviderId,
        string $defaultCapabilityLevel,
        #[\SensitiveParameter]
        string $providerConfigurationsJson,
    ): array {
        $stored = $this->repository->read();

        if ($this->central->managed()) {
            return $this->present($stored);
        }

        $submitted = $this->decodeMap(
            $providerConfigurationsJson,
            'Provider configurations must be a JSON object.',
        );
        $credentials = $this->credentialsToStore($stored->providerCredentials, $submitted);
        $candidate = new AiProviderStoredSettings(
            defaultProvider: $stored->defaultProvider,
            defaultCapabilityLevel: $stored->defaultCapabilityLevel,
            providerCredentials: $credentials,
        );
        $defaultProviderId = trim($defaultProviderId);

        if ($defaultProviderId === '') {
            $defaultProviderId = $this->firstConfiguredProviderId($candidate);
        } else {
            $provider = $this->provider($defaultProviderId);

            if (! $provider->isConfigured($this->effectiveConfiguration($provider, $candidate))) {
                throw new AiProviderConfigurationException(
                    'AIProviders_ErrorProviderNotConfigured',
                    [$provider->name],
                );
            }
        }

        $defaultCapabilityLevel = trim($defaultCapabilityLevel);
        $capability = $defaultCapabilityLevel === ''
            ? $this->capability($stored->defaultCapabilityLevel)
            : $this->validatedCapability($defaultCapabilityLevel);
        $updated = new AiProviderStoredSettings($defaultProviderId, $capability, $credentials);
        $this->repository->save($updated);

        return $this->present($updated);
    }

    public function connectionTarget(
        string $providerId,
        #[\SensitiveParameter]
        string $providerConfigurationJson,
    ): AiProviderConnectionTarget {
        $provider = $this->provider(trim($providerId));
        $submitted = $this->decodeMap(
            $providerConfigurationJson,
            'Provider configuration must be a JSON object.',
        );
        $stored = $this->repository->read();
        $effective = $this->effectiveConfiguration($provider, $stored);
        $this->assertEndpointMayBeSubmitted($provider, $submitted, $effective->endpointUrl);
        $apiKey = $this->submittedApiKey($submitted, [$provider->id => $effective->toArray()], $provider->id);
        $endpoint = array_key_exists('endpointUrl', $submitted)
            ? $this->submittedEndpoint($provider, $submitted)
            : $effective->endpointUrl;
        $model = array_key_exists('model', $submitted)
            ? $this->submittedModel($provider, $submitted)
            : $effective->model;
        $useFips = array_key_exists('useFipsEndpoint', $submitted)
            ? $this->submittedFips($provider, $submitted)
            : $effective->useFipsEndpoint;
        $configuration = new AiProviderConfiguration($apiKey, $endpoint, $model, $useFips);

        if (! $provider->isConfigured($configuration)) {
            throw new AiProviderConfigurationException(
                'AIProviders_ErrorProviderNotConfigured',
                [$provider->name],
            );
        }

        return new AiProviderConnectionTarget($provider, $configuration);
    }

    /** @return array<string, mixed> */
    public function disconnect(string $providerId): array
    {
        $providerId = trim($providerId);
        $this->provider($providerId);
        $stored = $this->repository->read();
        $credentials = $stored->providerCredentials;
        unset($credentials[$providerId]);
        $updated = new AiProviderStoredSettings(
            $stored->defaultProvider,
            $stored->defaultCapabilityLevel,
            $credentials,
        );
        $this->repository->save($updated);

        return $this->present($updated);
    }

    /** @return array<string, mixed> */
    private function present(AiProviderStoredSettings $stored): array
    {
        $providers = [];

        foreach ($this->providers->selectable() as $provider) {
            $configuration = $this->effectiveConfiguration($provider, $stored);
            $providers[] = [
                ...$provider->metadata(),
                'configuration' => [
                    'hasApiKey' => $configuration->apiKey !== '',
                    'endpointUrl' => $configuration->endpointUrl,
                    'model' => $configuration->model,
                    'useFipsEndpoint' => $configuration->useFipsEndpoint,
                    'isUsable' => $provider->isConfigured($configuration),
                ],
            ];
        }

        return [
            'defaultProviderId' => $this->defaultProviderId($stored),
            'defaultCapabilityLevel' => $this->capability(
                $this->central->defaultCapabilityLevel() !== ''
                    ? $this->central->defaultCapabilityLevel()
                    : $stored->defaultCapabilityLevel,
            ),
            'canEditProviderConfiguration' => ! $this->central->managed(),
            'canEditCapabilityLevel' => ! $this->central->managed(),
            'capabilityLevels' => self::CAPABILITY_LEVELS,
            'providers' => $providers,
        ];
    }

    private function defaultProviderId(AiProviderStoredSettings $stored): string
    {
        $forced = $this->central->defaultProvider();

        if ($forced !== '' && $this->providers->find($forced) !== null) {
            return $forced;
        }

        $selected = $this->providers->find($stored->defaultProvider);

        if ($selected !== null
            && $this->providers->isSelectable($selected->id)
            && $selected->isConfigured($this->effectiveConfiguration($selected, $stored))) {
            return $selected->id;
        }

        $preferred = $this->providers->find(self::DEFAULT_PROVIDER);

        if ($preferred !== null
            && $this->providers->isSelectable($preferred->id)
            && $preferred->isConfigured($this->effectiveConfiguration($preferred, $stored))) {
            return $preferred->id;
        }

        return $this->firstConfiguredProviderId($stored);
    }

    private function firstConfiguredProviderId(AiProviderStoredSettings $stored): string
    {
        foreach ($this->providers->selectable() as $provider) {
            if ($provider->isConfigured($this->effectiveConfiguration($provider, $stored))) {
                return $provider->id;
            }
        }

        return '';
    }

    private function capability(string $capability): string
    {
        return array_key_exists($capability, self::CAPABILITY_LEVELS) ? $capability : 'instant';
    }

    private function validatedCapability(string $capability): string
    {
        if (! array_key_exists($capability, self::CAPABILITY_LEVELS)) {
            throw new AiProviderConfigurationException(
                'AIProviders_ErrorUnknownCapabilityLevel',
                [$capability],
            );
        }

        return $capability;
    }

    private function provider(string $providerId): AiProviderDefinition
    {
        $provider = $this->providers->find($providerId);

        if ($provider === null || ! $this->providers->isSelectable($providerId)) {
            throw new AiProviderConfigurationException(
                'AIProviders_ErrorUnknownProvider',
                [$providerId],
            );
        }

        return $provider;
    }

    private function effectiveConfiguration(
        AiProviderDefinition $provider,
        AiProviderStoredSettings $stored,
    ): AiProviderConfiguration {
        $empty = ['apiKey' => '', 'endpointUrl' => '', 'model' => '', 'useFipsEndpoint' => false];
        $saved = $stored->providerCredentials[$provider->id] ?? $empty;
        $central = $this->central->configuration($provider->id);
        $endpoint = '';

        if ($provider->supportsCustomEndpoint) {
            $endpoint = $central->endpointUrl !== ''
                ? $central->endpointUrl
                : ($central->apiKey !== '' && $provider->endpointFieldRequiresUrl
                    ? ''
                    : $saved['endpointUrl']);
        }

        return new AiProviderConfiguration(
            apiKey: $central->apiKey !== '' ? $central->apiKey : $saved['apiKey'],
            endpointUrl: $endpoint,
            model: $central->model !== '' ? $central->model : $saved['model'],
            useFipsEndpoint: $central->useFipsEndpoint ?? $saved['useFipsEndpoint'],
        );
    }

    /**
     * @param  array<string, array{apiKey: string, endpointUrl: string, model: string, useFipsEndpoint: bool}>  $existing
     * @param  array<string, mixed>  $submitted
     * @return array<string, array{apiKey: string, endpointUrl: string, model: string, useFipsEndpoint: bool}>
     */
    private function credentialsToStore(array $existing, #[\SensitiveParameter] array $submitted): array
    {
        $credentials = [];

        foreach ($this->providers->selectable() as $provider) {
            $values = $submitted[$provider->id] ?? [];

            if (! is_array($values)) {
                throw new AiProviderConfigurationException(
                    null,
                    message: sprintf('Invalid configuration for AI provider "%s".', $provider->id),
                );
            }

            $effective = $this->effectiveConfiguration(
                $provider,
                new AiProviderStoredSettings('', 'instant', $existing),
            );
            $endpoint = $this->submittedEndpoint($provider, $values);
            $this->assertEndpointMayBeSubmitted($provider, $values, $effective->endpointUrl);
            $endpoint = $this->endpointIsCentral($provider)
                ? ($existing[$provider->id]['endpointUrl'] ?? '')
                : $endpoint;
            $configuration = [
                'apiKey' => $this->submittedApiKey($values, $existing, $provider->id),
                'endpointUrl' => $endpoint,
                'model' => $this->submittedModel($provider, $values),
                'useFipsEndpoint' => $this->submittedFips($provider, $values),
            ];

            if ($configuration['apiKey'] !== '' || $configuration['endpointUrl'] !== ''
                || $configuration['model'] !== '' || $configuration['useFipsEndpoint']) {
                $credentials[$provider->id] = $configuration;
            }
        }

        return $credentials;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array{apiKey: string, endpointUrl: string, model: string, useFipsEndpoint: bool}>  $existing
     */
    private function submittedApiKey(
        #[\SensitiveParameter] array $values,
        #[\SensitiveParameter] array $existing,
        string $providerId,
    ): string {
        $apiKey = $values['apiKey'] ?? null;

        return is_string($apiKey) && trim($apiKey) !== ''
            ? trim($apiKey)
            : ($existing[$providerId]['apiKey'] ?? '');
    }

    /** @param array<string, mixed> $values */
    private function submittedEndpoint(AiProviderDefinition $provider, array $values): string
    {
        if (! $provider->supportsCustomEndpoint) {
            return '';
        }

        $value = is_string($values['endpointUrl'] ?? null) ? $values['endpointUrl'] : '';

        if (trim($value) === '') {
            return '';
        }

        try {
            return $provider->normalizeEndpoint($value);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new AiProviderConfigurationException(
                $invalidArgumentException->getMessage(),
                [$provider->name],
            );
        }
    }

    /** @param array<string, mixed> $values */
    private function submittedModel(AiProviderDefinition $provider, array $values): string
    {
        if (! $provider->supportsCustomEndpoint) {
            return '';
        }

        return is_string($values['model'] ?? null) ? trim($values['model']) : '';
    }

    /** @param array<string, mixed> $values */
    private function submittedFips(AiProviderDefinition $provider, array $values): bool
    {
        return $provider->supportsFipsEndpoint && ! empty($values['useFipsEndpoint']);
    }

    /** @param array<string, mixed> $submitted */
    private function assertEndpointMayBeSubmitted(
        AiProviderDefinition $provider,
        #[\SensitiveParameter] array $submitted,
        string $effectiveEndpoint,
    ): void {
        if (! array_key_exists('endpointUrl', $submitted) || ! $this->endpointIsCentral($provider)) {
            return;
        }

        $submittedEndpoint = $this->submittedEndpoint($provider, $submitted);
        $normalizedEffective = $effectiveEndpoint;

        try {
            $normalizedEffective = $provider->normalizeEndpoint($effectiveEndpoint);
        } catch (InvalidArgumentException) {
        }

        if ($submittedEndpoint === $normalizedEffective) {
            return;
        }

        $central = $this->central->configuration($provider->id);
        $translationKey = $central->endpointUrl === ''
            ? 'AIProviders_ErrorEndpointUrlManagedWithApiKey'
            : 'AIProviders_ErrorEndpointManaged';

        throw new AiProviderConfigurationException(
            $translationKey,
            [$provider->name, $provider->id.'EndpointUrl'],
        );
    }

    private function endpointIsCentral(AiProviderDefinition $provider): bool
    {
        return $provider->supportsCustomEndpoint
            && ($this->central->endpointIsSupplied($provider->id)
                || ($this->central->hasApiKey($provider->id) && $provider->endpointFieldRequiresUrl));
    }

    /** @return array<string, mixed> */
    private function decodeMap(#[\SensitiveParameter] string $json, string $error): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiProviderConfigurationException(null, message: $error);
        }

        if (! is_array($decoded)) {
            throw new AiProviderConfigurationException(null, message: $error);
        }

        return $decoded;
    }
}
