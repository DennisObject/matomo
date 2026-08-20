<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\AiProviders\AiProviderConfiguration;
use App\Matomo\AiProviders\AiProviderConnectionException;
use App\Matomo\AiProviders\BuiltInAiProviderCatalog;
use App\Matomo\AiProviders\HttpAiProviderConnectionTester;
use App\Matomo\Security\EgressHostResolver;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

class HttpAiProviderConnectionTesterTest extends TestCase
{
    public function test_lists_sorted_custom_models_and_sends_the_optional_bearer_key(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake([
            'https://llm.example/v1/models' => Factory::response([
                'data' => [['id' => 'z-model'], ['id' => 'a-model'], ['invalid' => true]],
            ]),
        ]);
        $provider = (new BuiltInAiProviderCatalog)->find('custom-provider');
        $this->assertNotNull($provider);

        $models = $this->tester($http)->test(
            $provider,
            new AiProviderConfiguration(
                'secret-key',
                'https://llm.example/v1/chat/completions',
                'a-model',
                false,
            ),
        );

        $this->assertSame(['a-model', 'z-model'], $models);
        $http->assertSent(fn ($request): bool => $request->url() === 'https://llm.example/v1/models'
            && $request->hasHeader('Authorization', 'Bearer secret-key'));
    }

    public function test_uses_only_a_derived_bedrock_hostname_and_lists_models(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake([
            'https://bedrock-fips.eu-west-1.amazonaws.com/*' => Factory::response([
                'modelSummaries' => [['modelId' => 'model-b'], ['modelId' => 'model-a']],
            ]),
        ]);
        $provider = (new BuiltInAiProviderCatalog)->find('bedrock');
        $this->assertNotNull($provider);

        $models = $this->tester($http)->test(
            $provider,
            new AiProviderConfiguration('aws-key', 'eu-west-1', '', true),
        );

        $this->assertSame(['model-a', 'model-b'], $models);
        $http->assertSent(fn ($request): bool => str_starts_with(
            $request->url(),
            'https://bedrock-fips.eu-west-1.amazonaws.com/foundation-models',
        ));
    }

    public function test_blocks_custom_endpoints_that_resolve_to_private_addresses(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();

        $provider = (new BuiltInAiProviderCatalog)->find('custom-provider');
        $this->assertNotNull($provider);
        $tester = new HttpAiProviderConnectionTester(
            http: $http,
            hosts: new EgressHostResolver(
                resolver: static fn (string $host): array => ['169.254.169.254'],
            ),
            trustedAwsHosts: $this->resolver(),
            internetFeaturesEnabled: true,
        );

        try {
            $tester->test(
                $provider,
                new AiProviderConfiguration('', 'http://metadata.example/latest', '', false),
            );
            $this->fail('The private endpoint was not blocked.');
        } catch (AiProviderConnectionException $aiProviderConnectionException) {
            $this->assertSame(400, $aiProviderConnectionException->responseStatus);
            $this->assertStringContainsString('private or reserved', $aiProviderConnectionException->getMessage());
        }

        $http->assertNothingSent();
    }

    public function test_does_not_send_a_request_when_internet_features_are_disabled(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();

        $provider = (new BuiltInAiProviderCatalog)->find('openai');
        $this->assertNotNull($provider);

        $this->expectException(AiProviderConnectionException::class);
        $this->expectExceptionMessage('Internet features are disabled.');

        (new HttpAiProviderConnectionTester(
            $http,
            $this->resolver(),
            $this->resolver(),
            false,
        ))->test($provider, new AiProviderConfiguration('key', '', '', false));
    }

    public function test_retries_a_transient_status_and_then_succeeds(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fakeSequence('https://api.openai.com/v1/models')
            ->push([], 503)
            ->push([], 200);
        $provider = (new BuiltInAiProviderCatalog)->find('openai');
        $this->assertNotNull($provider);

        $this->assertSame(
            [],
            $this->tester($http)->test($provider, new AiProviderConfiguration('key', '', '', false)),
        );
        $http->assertSentCount(2);
    }

    public function test_does_not_retry_a_permanent_status(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['https://api.openai.com/v1/models' => Factory::response([], 401)]);

        $provider = (new BuiltInAiProviderCatalog)->find('openai');
        $this->assertNotNull($provider);

        try {
            $this->tester($http)->test($provider, new AiProviderConfiguration('bad-key', '', '', false));
            $this->fail('The permanent provider error was not returned.');
        } catch (AiProviderConnectionException $aiProviderConnectionException) {
            $this->assertSame(400, $aiProviderConnectionException->responseStatus);
            $this->assertStringContainsString('HTTP 401', $aiProviderConnectionException->getMessage());
        }

        $http->assertSentCount(1);
    }

    private function tester(Factory $http): HttpAiProviderConnectionTester
    {
        return new HttpAiProviderConnectionTester(
            $http,
            $this->resolver(),
            $this->resolver(),
            true,
        );
    }

    private function resolver(): EgressHostResolver
    {
        return new EgressHostResolver(
            resolver: static fn (string $host): array => ['93.184.216.34'],
            blockedHosts: [],
        );
    }
}
