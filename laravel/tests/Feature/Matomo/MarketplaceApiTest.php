<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Marketplace\MarketplaceService;
use Tests\TestCase;

final class MarketplaceApiTest extends TestCase
{
    public function test_superuser_can_manage_accounts_licenses_and_trials(): void
    {
        $this->bindAuthorizer('admin', true);
        $service = new RecordingMarketplaceService;
        $this->app->instance(MarketplaceService::class, $service);

        $this->post($this->url('createAccount'), ['email' => 'owner@example.test'])
            ->assertOk()->assertExactJson(['value' => true]);
        $this->post($this->url('saveLicenseKey'), ['licenseKey' => ' secret-key '])
            ->assertOk()->assertExactJson(['value' => true]);
        $this->post($this->url('startFreeTrial'), ['pluginName' => 'HeatmapSessionRecording'])
            ->assertOk()->assertExactJson(['value' => true]);
        $this->post($this->url('deleteLicenseKey'))
            ->assertOk()->assertExactJson(['value' => true]);

        $this->assertSame([
            ['createAccount', 'owner@example.test'],
            ['saveLicenseKey', 'secret-key'],
            ['startFreeTrial', 'HeatmapSessionRecording'],
            ['deleteLicenseKey'],
        ], $service->calls);
    }

    public function test_authenticated_non_superuser_can_request_a_trial(): void
    {
        $this->bindAuthorizer('member', false);
        $service = new RecordingMarketplaceService;
        $this->app->instance(MarketplaceService::class, $service);

        $this->post($this->url('requestTrial'), ['pluginName' => 'MediaAnalytics'])
            ->assertOk()->assertExactJson(['value' => true]);

        $this->assertSame([['requestTrial', 'MediaAnalytics', 'member']], $service->calls);
    }

    public function test_superuser_cannot_request_a_trial(): void
    {
        $this->bindAuthorizer('admin', true);
        $this->app->instance(MarketplaceService::class, new RecordingMarketplaceService);

        $this->post($this->url('requestTrial'), ['pluginName' => 'MediaAnalytics'])
            ->assertForbidden()->assertJsonPath('message', 'Cannot request trial as a super user');
    }

    public function test_license_mutations_require_superuser_access(): void
    {
        $this->bindAuthorizer('member', false);
        $this->app->instance(MarketplaceService::class, new RecordingMarketplaceService);

        $this->post($this->url('deleteLicenseKey'))->assertUnauthorized();
    }

    public function test_required_parameters_are_enforced_at_the_request_boundary(): void
    {
        $this->bindAuthorizer('admin', true);
        $this->app->instance(MarketplaceService::class, new RecordingMarketplaceService);

        $this->post($this->url('saveLicenseKey'))
            ->assertBadRequest()->assertJsonPath('message', "Please specify a value for 'licenseKey'.");
    }

    private function bindAuthorizer(string $login, bool $superUser): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn($login);
        $authorizer->method('hasSuperUserAccess')->willReturn($superUser);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    private function url(string $method): string
    {
        return "/index.php?module=API&method=Marketplace.{$method}&format=json&token_auth=test-token";
    }
}

final class RecordingMarketplaceService implements MarketplaceService
{
    /** @var list<list<string>> */
    public array $calls = [];

    public function createAccount(string $email): void
    {
        $this->calls[] = ['createAccount', $email];
    }

    public function deleteLicenseKey(): void
    {
        $this->calls[] = ['deleteLicenseKey'];
    }

    public function requestTrial(string $pluginName, string $login): void
    {
        $this->calls[] = ['requestTrial', $pluginName, $login];
    }

    public function startFreeTrial(string $pluginName): void
    {
        $this->calls[] = ['startFreeTrial', $pluginName];
    }

    public function saveLicenseKey(#[\SensitiveParameter] string $licenseKey): void
    {
        $this->calls[] = ['saveLicenseKey', $licenseKey];
    }
}
