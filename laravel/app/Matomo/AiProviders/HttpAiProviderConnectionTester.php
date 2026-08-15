<?php

declare(strict_types=1);

namespace App\Matomo\AiProviders;

use App\Matomo\Security\BlockedEgressTarget;
use App\Matomo\Security\EgressHostResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Throwable;

final readonly class HttpAiProviderConnectionTester implements AiProviderConnectionTester
{
    private const int TIMEOUT_SECONDS = 15;

    /** @var list<int> */
    private const array TRANSIENT_STATUSES = [408, 429, 500, 502, 503, 504, 529];

    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private Factory $http,
        private EgressHostResolver $hosts,
        private EgressHostResolver $trustedAwsHosts,
        private bool $internetFeaturesEnabled,
        private ?string $outboundProxyHost = null,
        /** @var list<string> */
        private array $outboundProxyExcludedHosts = [],
    ) {}

    public function test(
        AiProviderDefinition $provider,
        #[\SensitiveParameter]
        AiProviderConfiguration $configuration,
    ): array {
        if (! $this->internetFeaturesEnabled) {
            throw new AiProviderConnectionException('Internet features are disabled.', 400);
        }

        if ($provider->hasConnectionTester()) {
            return $provider->testConnection($configuration);
        }

        if (! function_exists('curl_init')) {
            throw new AiProviderConnectionException(
                'AI provider connection tests require the curl PHP extension.',
                500,
            );
        }

        try {
            [$url, $headers] = $this->request($provider, $configuration);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $message = match ($invalidArgumentException->getMessage()) {
                'AIProviders_ErrorInvalidAwsRegion' => sprintf(
                    'The AWS region for %s is invalid.',
                    $provider->name,
                ),
                'AIProviders_ErrorInvalidEndpointUrl' => sprintf(
                    'The endpoint URL for %s is invalid.',
                    $provider->name,
                ),
                default => $invalidArgumentException->getMessage(),
            };

            throw new AiProviderConnectionException($message, 400);
        }

        $response = $this->response($provider, $url, $headers);

        return $this->models($provider, $response);
    }

    /** @param array<string, string> $headers */
    private function response(
        AiProviderDefinition $provider,
        string $url,
        #[\SensitiveParameter]
        array $headers,
    ): Response {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->get($url, $headers, $provider->id === 'bedrock');
            } catch (BlockedEgressTarget $exception) {
                throw new AiProviderConnectionException($exception->getMessage(), 400);
            } catch (ConnectionException) {
                if ($attempt < self::MAX_ATTEMPTS) {
                    $this->retryDelay($attempt);

                    continue;
                }

                throw new AiProviderConnectionException(
                    sprintf('%s connection test failed.', $provider->name),
                );
            } catch (Throwable) {
                throw new AiProviderConnectionException(
                    sprintf('%s connection test failed.', $provider->name),
                );
            }

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();

            if ($attempt < self::MAX_ATTEMPTS && in_array($status, self::TRANSIENT_STATUSES, true)) {
                $this->retryDelay($attempt);

                continue;
            }

            throw new AiProviderConnectionException(
                sprintf('%s connection test failed with HTTP %d.', $provider->name, $status),
                $status >= 400 && $status < 500 ? 400 : 502,
            );
        }

        throw new AiProviderConnectionException(sprintf('%s connection test failed.', $provider->name));
    }

    private function retryDelay(int $attempt): void
    {
        usleep($attempt * 100_000);
    }

    /**
     * @return array{string, array<string, string>}
     */
    private function request(
        AiProviderDefinition $provider,
        #[\SensitiveParameter]
        AiProviderConfiguration $configuration,
    ): array {
        $apiKey = $configuration->apiKey;

        return match ($provider->id) {
            'anthropic' => [
                'https://api.anthropic.com/v1/models',
                ['anthropic-version' => '2023-06-01', 'x-api-key' => $this->requiredKey($provider, $apiKey)],
            ],
            'google' => [
                'https://generativelanguage.googleapis.com/v1beta/models',
                ['x-goog-api-key' => $this->requiredKey($provider, $apiKey)],
            ],
            'openai' => [
                'https://api.openai.com/v1/models',
                ['Authorization' => 'Bearer '.$this->requiredKey($provider, $apiKey)],
            ],
            'bedrock' => $this->bedrockRequest($provider, $configuration),
            'custom-provider' => $this->customRequest($provider, $configuration),
            default => throw new AiProviderConnectionException(
                sprintf('No connection test is registered for %s.', $provider->name),
                400,
            ),
        };
    }

    /** @return array{string, array<string, string>} */
    private function bedrockRequest(
        AiProviderDefinition $provider,
        #[\SensitiveParameter]
        AiProviderConfiguration $configuration,
    ): array {
        $region = $provider->normalizeEndpoint($configuration->endpointUrl);
        $service = $configuration->useFipsEndpoint ? 'bedrock-fips' : 'bedrock';

        return [
            "https://{$service}.{$region}.amazonaws.com/foundation-models?byInferenceType=ON_DEMAND",
            ['Authorization' => 'Bearer '.$this->requiredKey($provider, $configuration->apiKey)],
        ];
    }

    /** @return array{string, array<string, string>} */
    private function customRequest(
        AiProviderDefinition $provider,
        #[\SensitiveParameter]
        AiProviderConfiguration $configuration,
    ): array {
        $endpoint = $provider->normalizeEndpoint($configuration->endpointUrl);
        $base = preg_replace('#/chat/completions/?$#', '', $endpoint) ?? $endpoint;
        $headers = $configuration->apiKey === ''
            ? []
            : ['Authorization' => 'Bearer '.$configuration->apiKey];

        return [rtrim($base, '/').'/models', $headers];
    }

    private function requiredKey(AiProviderDefinition $provider, #[\SensitiveParameter] string $apiKey): string
    {
        if ($apiKey === '') {
            throw new AiProviderConnectionException(
                sprintf('No API key is configured for %s.', $provider->name),
                400,
            );
        }

        return $apiKey;
    }

    /** @param array<string, string> $headers */
    private function get(string $url, #[\SensitiveParameter] array $headers, bool $trustedAws): Response
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new BlockedEgressTarget('SSRF-safe HTTP requests only support valid http and https URLs.');
        }

        if ($this->outboundProxyAppliesTo($host)) {
            throw new BlockedEgressTarget(
                'SSRF-safe HTTP requests cannot be routed through a configured proxy.',
            );
        }

        if ($trustedAws && preg_match(
            '/^bedrock(?:-fips)?\.[a-z]{2}(?:-[a-z0-9]+)+-[0-9]+\.amazonaws\.com$/iD',
            $host,
        ) !== 1) {
            throw new BlockedEgressTarget('The AWS Bedrock hostname is invalid.');
        }

        [$canonicalHost, $pinnedIp] = ($trustedAws ? $this->trustedAwsHosts : $this->hosts)
            ->resolveTarget($host);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $curl = [CURLOPT_PROXY => ''];

        if ($canonicalHost !== $pinnedIp) {
            $address = str_contains($pinnedIp, ':') ? "[{$pinnedIp}]" : $pinnedIp;
            $curl[CURLOPT_RESOLVE] = ["{$canonicalHost}:{$port}:{$address}"];
        }

        return $this->http
            ->withHeaders($headers)
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(5)
            ->withOptions([
                'allow_redirects' => false,
                'proxy' => '',
                'curl' => $curl,
            ])
            ->get($url);
    }

    private function outboundProxyAppliesTo(string $host): bool
    {
        return $this->outboundProxyHost !== null
            && ! in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)
            && ! in_array($host, $this->outboundProxyExcludedHosts, true);
    }

    /** @return list<string> */
    private function models(AiProviderDefinition $provider, Response $response): array
    {
        if (! in_array($provider->id, ['bedrock', 'custom-provider'], true)) {
            return [];
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new AiProviderConnectionException(
                sprintf('%s returned an invalid model list.', $provider->name),
            );
        }

        $rows = $provider->id === 'bedrock'
            ? ($decoded['modelSummaries'] ?? [])
            : ($decoded['data'] ?? []);
        $field = $provider->id === 'bedrock' ? 'modelId' : 'id';
        $models = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $model = is_array($row) ? ($row[$field] ?? null) : null;

                if (is_string($model) && $model !== '') {
                    $models[] = $model;
                }
            }
        }

        sort($models);

        return array_values(array_unique($models));
    }
}
