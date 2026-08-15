<?php

declare(strict_types=1);

namespace App\Matomo\AiProviders;

use Closure;

final readonly class AiProviderCentralConfiguration
{
    /** @var Closure(string): (string|false) */
    private Closure $environment;

    /**
     * @param  array<string, mixed>  $values
     * @param  (callable(string): (string|false))|null  $environment
     */
    public function __construct(private array $values = [], ?callable $environment = null)
    {
        $this->environment = $environment === null
            ? self::environmentValue(...)
            : $environment(...);
    }

    public function managed(): bool
    {
        return $this->defaultProvider() !== '';
    }

    public function defaultProvider(): string
    {
        return $this->configuredString('defaultProvider');
    }

    public function defaultCapabilityLevel(): string
    {
        return $this->configuredString('defaultCapabilityLevel');
    }

    public function hasApiKey(string $providerId): bool
    {
        return $this->stringValue($providerId, 'ApiKey', 'API_KEY') !== '';
    }

    public function endpointIsSupplied(string $providerId): bool
    {
        return $this->stringValue($providerId, 'EndpointUrl', 'ENDPOINT_URL') !== '';
    }

    public function configuration(string $providerId): AiProviderCentralValues
    {
        return new AiProviderCentralValues(
            apiKey: $this->stringValue($providerId, 'ApiKey', 'API_KEY'),
            endpointUrl: $this->stringValue($providerId, 'EndpointUrl', 'ENDPOINT_URL'),
            model: $this->stringValue($providerId, 'Model', 'MODEL'),
            useFipsEndpoint: $this->booleanValue(
                $providerId,
                'UseFipsEndpoint',
                'USE_FIPS_ENDPOINT',
            ),
        );
    }

    private function stringValue(string $providerId, string $suffix, string $environmentSuffix): string
    {
        $configured = $this->configuredString($providerId.$suffix);

        if ($configured !== '') {
            return $configured;
        }

        $environment = ($this->environment)(
            'MATOMO_AIPROVIDERS_'.strtoupper(str_replace('-', '_', $providerId)).'_'.$environmentSuffix,
        );

        return is_string($environment) ? trim($environment) : '';
    }

    private function booleanValue(
        string $providerId,
        string $suffix,
        string $environmentSuffix,
    ): ?bool {
        $key = $providerId.$suffix;

        if (array_key_exists($key, $this->values) && is_scalar($this->values[$key])) {
            return filter_var($this->values[$key], FILTER_VALIDATE_BOOLEAN);
        }

        $environment = ($this->environment)(
            'MATOMO_AIPROVIDERS_'.strtoupper(str_replace('-', '_', $providerId)).'_'.$environmentSuffix,
        );

        return is_string($environment) ? filter_var($environment, FILTER_VALIDATE_BOOLEAN) : null;
    }

    private function configuredString(string $key): string
    {
        $value = $this->values[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function environmentValue(string $name): string|false
    {
        return getenv($name);
    }
}
