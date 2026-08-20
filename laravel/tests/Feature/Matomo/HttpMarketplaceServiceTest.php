<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Marketplace\HttpMarketplaceService;
use App\Matomo\Marketplace\MarketplaceException;
use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Security\EgressHostResolver;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpMarketplaceServiceTest extends TestCase
{
    public function test_creates_an_account_and_stores_the_returned_license(): void
    {
        Http::fake(['https://marketplace.example/api/createAccount' => Http::response([
            'data' => ['license_key' => 'created-key'],
        ], 200)]);

        $this->service()->createAccount('owner@example.test');

        $this->assertSame('created-key', $this->optionRepository()->value('marketplace_license_key'));
        Http::assertSent(fn ($request): bool => $request['email'] === 'owner@example.test');
    }

    public function test_validates_a_license_before_storing_it(): void
    {
        Http::fake(['https://marketplace.example/api/consumer/validate*' => Http::response([
            'isValid' => true,
        ])]);

        $this->service()->saveLicenseKey(' validated-key ');

        $this->assertSame('validated-key', $this->optionRepository()->value('marketplace_license_key'));
        Http::assertSent(fn ($request): bool => $request->data()['access_token'] === 'validated-key');
    }

    public function test_rejects_disallowed_email_domains_without_a_request(): void
    {
        Http::fake();

        $this->expectException(MarketplaceException::class);
        $this->expectExceptionMessage('The email address domain is not allowed.');

        $this->service(['example.test'])->createAccount('owner@blocked.test');
    }

    public function test_allowed_email_domains_are_case_insensitive(): void
    {
        Http::fake(['https://marketplace.example/api/createAccount' => Http::response([
            'data' => ['license_key' => 'created-key'],
        ], 200)]);

        $this->service([' EXAMPLE.TEST '])->createAccount('owner@example.test');

        $this->assertSame('created-key', $this->optionRepository()->value('marketplace_license_key'));
    }

    /** @param list<string> $allowedDomains */
    private function service(array $allowedDomains = []): HttpMarketplaceService
    {
        return new HttpMarketplaceService(
            http: $this->app->make(Factory::class),
            connection: $this->createStub(ConnectionInterface::class),
            options: $this->optionRepository(),
            hosts: new EgressHostResolver(resolver: static fn (string $host): array => ['93.184.216.34']),
            endpoint: 'https://marketplace.example/api',
            allowedEmailDomains: $allowedDomains,
        );
    }

    private function optionRepository(): MutableOptionRepository
    {
        return $this->app->make(MutableOptionRepository::class);
    }
}
