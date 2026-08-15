<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Tracker\TrackerInstallationCheck;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class JsTrackerInstallCheckApiTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_initiates_and_reads_a_successful_tracker_installation_check(): void
    {
        CarbonImmutable::setTestNow('2026-08-15 12:00:00 UTC');
        $this->bindViewAccess(2);
        $options = $this->optionStore();
        $this->app->instance(MutableOptionRepository::class, $options);

        $response = $this->get(
            '/index.php?module=API&method=JsTrackerInstallCheck.initiateJsTrackerInstallTest'.
            '&idSite=7&url=https%3A%2F%2Fexample.test%2Fshop%3Fcampaign%3Dsummer'.
            '&format=json&token_auth=view-token',
        )->assertOk()->json();
        $this->assertIsArray($response);
        $nonce = $response['nonce'] ?? null;
        $this->assertIsString($nonce);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $nonce);
        $this->assertSame(
            'https://example.test/shop?campaign=summer&tracker_install_check='.$nonce,
            $response['url'] ?? null,
        );

        $this->assertTrue($this->app->make(TrackerInstallationCheck::class)->markSuccessful(7, $nonce));

        $this->get(
            '/index.php?module=API&method=JsTrackerInstallCheck.wasJsTrackerInstallTestSuccessful'.
            '&idSite=7&nonce='.$nonce.'&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([
            'isSuccess' => true,
            'mainUrl' => 'https://example.test',
        ]);
    }

    public function test_uses_the_main_site_url_when_no_url_is_provided(): void
    {
        $this->bindViewAccess();
        $this->app->instance(MutableOptionRepository::class, $this->optionStore());

        $response = $this->get(
            '/index.php?module=API&method=JsTrackerInstallCheck.initiateJsTrackerInstallTest'.
            '&idSite=7&format=json&token_auth=view-token',
        )->assertOk()->json();

        $this->assertIsArray($response);
        $this->assertStringStartsWith(
            'https://example.test?tracker_install_check=',
            (string) ($response['url'] ?? ''),
        );
    }

    public function test_rejects_invalid_nonce_and_url(): void
    {
        $this->bindViewAccess(2);
        $this->app->instance(MutableOptionRepository::class, $this->optionStore());

        $this->get(
            '/index.php?module=API&method=JsTrackerInstallCheck.wasJsTrackerInstallTestSuccessful'.
            '&idSite=7&nonce=invalid&format=json&token_auth=view-token',
        )->assertStatus(400)->assertJsonPath('message', 'The provided nonce is invalid.');

        $this->get(
            '/index.php?module=API&method=JsTrackerInstallCheck.initiateJsTrackerInstallTest'.
            '&idSite=7&url=javascript%3Aalert%281%29&format=json&token_auth=view-token',
        )->assertStatus(400)->assertJsonPath(
            'message',
            "The url 'javascript:alert(1)' is not a valid URL.",
        );
    }

    private function bindViewAccess(int $calls = 1): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->exactly($calls))
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), 7)
            ->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('mainUrl')->with(7)->willReturn('https://example.test');
        $this->app->instance(SiteRepository::class, $sites);
    }

    private function optionStore(): MutableOptionRepository
    {
        return new class implements MutableOptionRepository
        {
            /** @var array<string, string> */
            private array $values = [];

            public function value(string $name): ?string
            {
                return $this->values[$name] ?? null;
            }

            public function set(string $name, string $value, bool $autoload = false): void
            {
                $this->values[$name] = $value;
            }
        };
    }
}
