<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Reporting\ReportingSettings;
use App\Matomo\Reporting\VisitsSummaryArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Http\Request;
use Tests\TestCase;

final class ApiOverviewTest extends TestCase
{
    public function test_combines_native_get_reports_and_filters_columns(): void
    {
        $this->bindAccess(true);

        $response = $this->get($this->url().'&columns=nb_visits,nb_actions')->assertOk()->json();

        $this->assertIsArray($response);
        $this->assertArrayHasKey('nb_visits', $response);
        $this->assertArrayHasKey('nb_actions', $response);
        $this->assertArrayNotHasKey('bounce_count', $response);
    }

    public function test_rejects_site_without_view_access(): void
    {
        $this->bindAccess(false);
        $this->get($this->url())->assertUnauthorized();
    }

    public function test_keeps_the_verified_session_authentication_for_nested_reports(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->exactly(2))
            ->method('hasViewAccessToSite')
            ->with(
                $this->callback(static fn (ApiAuthentication $authentication): bool => $authentication->token === 'session-token'
                    && $authentication->tokenIsSecure
                    && $authentication->forceSession
                    && $authentication->sessionId === 'session-id'),
                1,
            )
            ->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->bindVisitsSummary([1 => [
            '2026-08-15,2026-08-15' => ['nb_visits' => 4],
        ]]);

        $this->withUnencryptedCookie('MATOMO_SESSID', 'session-id')
            ->post('/index.php', [
                'module' => 'API',
                'method' => 'API.get',
                'format' => 'json',
                'idSite' => 1,
                'period' => 'day',
                'date' => '2026-08-15',
                'columns' => 'nb_visits',
                'token_auth' => 'session-token',
                'force_api_session' => '1',
            ])->assertOk()->assertExactJson(['nb_visits' => 4]);
    }

    public function test_keeps_date_maps_when_filtering_columns(): void
    {
        $this->bindAccess(true);
        $this->bindVisitsSummary([1 => [
            '2026-08-14,2026-08-14' => ['nb_visits' => 2],
            '2026-08-15,2026-08-15' => ['nb_visits' => 4],
        ]]);

        $this->get(str_replace(
            'date=2026-08-15',
            'date=2026-08-14,2026-08-15',
            $this->url().'&columns=nb_visits',
        ))->assertOk()->assertExactJson([
            '2026-08-14' => ['nb_visits' => 2],
            '2026-08-15' => ['nb_visits' => 4],
        ]);
    }

    public function test_uses_stable_module_names_with_translated_metadata(): void
    {
        $this->bindAccess(true);
        $this->app->instance(LanguageResolver::class, new class implements LanguageResolver
        {
            public function resolve(Request $request, ApiAuthentication $authentication): string
            {
                return 'de';
            }
        });
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $sites->method('urls')->willReturn(['https://example.test/']);
        $this->app->instance(SiteRepository::class, $sites);
        $archives = $this->createMock(NumericArchiveRepository::class);
        $archives->expects($this->once())
            ->method('pluginMetrics')
            ->with([1], $this->isType('array'), '', ['Actions_nb_pageviews'], 'Actions')
            ->willReturn([1 => [
                '2026-08-15,2026-08-15' => ['Actions_nb_pageviews' => 6],
            ]]);
        $this->app->instance(NumericArchiveRepository::class, $archives);

        $this->get($this->url().'&columns=nb_pageviews')
            ->assertOk()
            ->assertExactJson(['nb_pageviews' => 6]);
    }

    public function test_propagates_nested_report_errors(): void
    {
        $this->bindAccess(true);
        $settings = $this->createMock(ReportingSettings::class);
        $settings->expects($this->once())->method('periodEnabled')->with('day')->willReturn(false);
        $this->app->instance(ReportingSettings::class, $settings);

        $this->get($this->url().'&columns=nb_visits')
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => "The period 'day' is not enabled.",
            ]);
    }

    public function test_rejects_requested_metrics_from_an_unmigrated_report(): void
    {
        $this->bindAccess(true);

        foreach ([$this->url(), $this->url().'&columns=nb_total_overall_bandwidth'] as $url) {
            $this->get($url)->assertStatus(501)->assertExactJson([
                'result' => 'error',
                'message' => 'The overview metrics provided by Bandwidth.get have not moved to Laravel yet.',
            ]);
        }
    }

    public function test_renders_the_combined_result_as_a_report_format(): void
    {
        $this->bindAccess(true);
        $this->bindVisitsSummary([1 => [
            '2026-08-15,2026-08-15' => ['nb_visits' => 4],
        ]]);

        $this->get(str_replace('format=json', 'format=csv&convertToUnicode=0', $this->url()).'&columns=nb_visits')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.ms-excel')
            ->assertContent("nb_visits\n4");
    }

    public function test_validates_report_parameters_before_discovery(): void
    {
        $this->bindAccess(true);

        $this->get(str_replace('idSite=1', 'idSite=0', $this->url()))->assertBadRequest();
        $this->get(str_replace('period=day', 'period=quarter', $this->url()))->assertBadRequest();
        $this->get(str_replace('date=2026-08-15', 'date=not-a-date', $this->url()))->assertBadRequest();
    }

    private function bindAccess(bool $view): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn($view);
        $authorizer->method('hasSomeViewAccess')->willReturn($view);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    /** @param array<int, array<string, array<string, int>>> $metrics */
    private function bindVisitsSummary(array $metrics): void
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(SiteRepository::class, $sites);
        $archives = $this->createStub(VisitsSummaryArchiveRepository::class);
        $archives->method('metrics')->willReturn($metrics);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $archives);
    }

    private function url(): string
    {
        return '/index.php?module=API&method=API.get&format=json&idSite=1&period=day&date=2026-08-15';
    }
}
