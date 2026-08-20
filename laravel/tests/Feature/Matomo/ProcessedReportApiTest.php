<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

final class ProcessedReportApiTest extends TestCase
{
    public function test_wraps_native_report_data_with_metadata(): void
    {
        $this->bindDependencies(true);

        $response = $this->get($this->url())->assertOk()->json();

        $this->assertSame('Example website', $response['website'] ?? null);
        $this->assertSame('Saturday, August 15, 2026', $response['prettyDate'] ?? null);
        $this->assertSame('Visits Summary', $response['metadata']['name'] ?? null);
        $this->assertSame('Visits', $response['columns']['nb_visits'] ?? null);
        $this->assertIsArray($response['reportData'] ?? null);
        $this->assertIsArray($response['reportTotal'] ?? null);
        $this->assertIsFloat($response['timerMillis'] ?? null);
    }

    public function test_rejects_site_without_view_access(): void
    {
        $this->bindDependencies(false);

        $this->get($this->url())->assertUnauthorized();
    }

    public function test_preserves_header_authentication_for_the_nested_report(): void
    {
        $authentications = [];
        $this->bindDependencies(
            true,
            static function (ApiAuthentication $authentication) use (&$authentications): bool {
                $authentications[] = $authentication;

                return true;
            },
        );

        $this->withHeader('Authorization', 'Bearer secure-token')
            ->get($this->url())
            ->assertOk();

        $this->assertNotEmpty($authentications);
        foreach ($authentications as $authentication) {
            $this->assertSame('secure-token', $authentication->token);
            $this->assertTrue($authentication->tokenIsSecure);
        }
    }

    public function test_can_omit_the_timer(): void
    {
        $this->bindDependencies(true);

        $this->get($this->url(['showTimer' => 0]))
            ->assertOk()
            ->assertJsonMissingPath('timerMillis');
    }

    public function test_rejects_table_reports_until_row_metadata_is_available(): void
    {
        $this->bindDependencies(true);

        $this->get($this->url(['apiModule' => 'Actions', 'apiAction' => 'getPageUrls']))
            ->assertStatus(501)
            ->assertJsonPath(
                'message',
                'Processed table reports and row metadata have not moved to Laravel yet.',
            );
    }

    public function test_rejects_dynamic_metadata_variants(): void
    {
        $this->bindDependencies(true);

        $this->get($this->url(['apiParameters' => 'idGoal=1']))
            ->assertStatus(501)
            ->assertJsonPath(
                'message',
                'Processed report metadata variants have not moved to Laravel yet.',
            );
    }

    public function test_rejects_non_day_periods_until_dynamic_metadata_is_available(): void
    {
        $this->bindDependencies(true);

        $this->get($this->url(['period' => 'week']))
            ->assertStatus(501)
            ->assertJsonPath(
                'message',
                'Processed report metadata for non-day periods has not moved to Laravel yet.',
            );
    }

    /** @param (callable(ApiAuthentication, int): bool)|null $siteAccess */
    private function bindDependencies(bool $view, ?callable $siteAccess = null): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturnCallback(
            $siteAccess ?? static fn (): bool => $view,
        );
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $sites = $this->createStub(SiteRepository::class);
        $sites->method('details')->willReturn(['name' => 'Example website']);
        $this->app->instance(SiteRepository::class, $sites);
    }

    /** @param array<string, mixed> $parameters */
    private function url(array $parameters = []): string
    {
        return '/index.php?'.http_build_query(array_replace([
            'module' => 'API',
            'method' => 'API.getProcessedReport',
            'format' => 'json',
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
            'apiModule' => 'VisitsSummary',
            'apiAction' => 'get',
        ], $parameters));
    }
}
