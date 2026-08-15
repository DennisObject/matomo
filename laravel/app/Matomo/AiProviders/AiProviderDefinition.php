<?php

declare(strict_types=1);

namespace App\Matomo\AiProviders;

use Closure;
use InvalidArgumentException;

final readonly class AiProviderDefinition
{
    /** @var (Closure(AiProviderConfiguration): list<string>)|null */
    private ?Closure $connectionTester;

    /**
     * @param  (callable(AiProviderConfiguration): list<string>)|null  $connectionTester
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public bool $supportsCustomEndpoint,
        public bool $supportsFipsEndpoint,
        public string $defaultEndpointUrl,
        public string $endpointFieldTitle,
        public string $endpointFieldPlaceholder,
        public string $defaultModel,
        public bool $endpointFieldRequiresUrl = true,
        ?callable $connectionTester = null,
    ) {
        $this->connectionTester = $connectionTester === null ? null : $connectionTester(...);
    }

    /** @return array<string, bool|string> */
    public function metadata(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'supportsCustomEndpoint' => $this->supportsCustomEndpoint,
            'supportsFipsEndpoint' => $this->supportsFipsEndpoint,
            'defaultEndpointUrl' => $this->defaultEndpointUrl,
            'endpointFieldTitle' => $this->endpointFieldTitle,
            'endpointFieldPlaceholder' => $this->endpointFieldPlaceholder,
            'defaultModel' => $this->defaultModel,
        ];
    }

    public function normalizeEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);

        if (! $this->supportsCustomEndpoint) {
            return '';
        }

        if ($this->id === 'bedrock') {
            if ($endpoint === '') {
                return 'us-east-1';
            }

            $endpoint = strtolower($endpoint);

            if (preg_match('/^[a-z]{2}(?:-[a-z0-9]+)+-[0-9]+$/D', $endpoint) !== 1) {
                throw new InvalidArgumentException('AIProviders_ErrorInvalidAwsRegion');
            }

            return $endpoint;
        }

        if ($endpoint === '') {
            return '';
        }

        $parts = parse_url($endpoint);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false
            || ! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('AIProviders_ErrorInvalidEndpointUrl');
        }

        return $endpoint;
    }

    public function isConfigured(AiProviderConfiguration $configuration): bool
    {
        if ($this->id === 'bedrock') {
            return $configuration->apiKey !== '';
        }

        return $this->supportsCustomEndpoint
            ? $configuration->endpointUrl !== ''
            : $configuration->apiKey !== '';
    }

    public function hasConnectionTester(): bool
    {
        return $this->connectionTester !== null;
    }

    /** @return list<string> */
    public function testConnection(#[\SensitiveParameter] AiProviderConfiguration $configuration): array
    {
        return $this->connectionTester === null ? [] : ($this->connectionTester)($configuration);
    }
}
