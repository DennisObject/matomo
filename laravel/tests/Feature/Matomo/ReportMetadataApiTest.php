<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Localization\LanguageResolver;
use Illuminate\Http\Request;
use Tests\TestCase;

final class ReportMetadataApiTest extends TestCase
{
    public function test_returns_native_report_metadata(): void
    {
        $this->bindAccess(true);

        $reports = $this->get($this->url('API.getReportMetadata').'&idSite=7')->assertOk()->json();

        $this->assertIsArray($reports);
        $report = $this->report($reports, 'VisitsSummary', 'get');
        $this->assertSame('Visits Summary', $report['name'] ?? null);
        $this->assertSame('Visits', $report['metrics']['nb_visits'] ?? null);
        $this->assertArrayHasKey('metricsDocumentation', $report);
    }

    public function test_returns_one_report_and_can_hide_metric_documentation(): void
    {
        $this->bindAccess(true);

        $reports = $this->get($this->url('API.getMetadata')
            .'&idSite=7&apiModule=VisitsSummary&apiAction=get&hideMetricsDoc=1')->assertOk()->json();

        $this->assertIsArray($reports);
        $this->assertCount(1, $reports);
        $this->assertSame('VisitsSummary_get', $reports[0]['uniqueId'] ?? null);
        $this->assertArrayNotHasKey('metricsDocumentation', $reports[0]);
    }

    public function test_translates_generated_report_metadata(): void
    {
        $this->bindAccess(true);
        $this->app->instance(LanguageResolver::class, new class implements LanguageResolver
        {
            public function resolve(Request $request, ApiAuthentication $authentication): string
            {
                return 'de';
            }
        });

        $reports = $this->get($this->url('API.getReportMetadata').'&idSite=7')->assertOk()->json();
        $this->assertIsArray($reports);
        $this->assertSame('Besucherüberblick', $this->report($reports, 'VisitsSummary', 'get')['name'] ?? null);
    }

    public function test_rejects_site_without_view_access(): void
    {
        $this->bindAccess(false);

        $this->get($this->url('API.getReportMetadata').'&idSite=7')->assertUnauthorized();
    }

    public function test_rejects_dynamic_metadata_options_until_native_discovery_supports_them(): void
    {
        $this->bindAccess(true);

        foreach (['period=day', 'date=today', 'showSubtableReports=1', 'apiParameters[idGoal]=1'] as $query) {
            $this->get($this->url('API.getReportMetadata')."&idSite=7&{$query}")
                ->assertStatus(501)
                ->assertJsonPath('message', 'Dynamic report metadata is not available in the Laravel runtime.');
        }
    }

    private function bindAccess(bool $view): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn($view);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    private function url(string $method): string
    {
        return "/index.php?module=API&method={$method}&format=json&token_auth=token";
    }

    /**
     * @param  array<mixed>  $reports
     * @return array<string, mixed>
     */
    private function report(array $reports, string $module, string $action): array
    {
        foreach ($reports as $report) {
            if (is_array($report) && ($report['module'] ?? null) === $module && ($report['action'] ?? null) === $action) {
                return $report;
            }
        }

        return [];
    }
}
