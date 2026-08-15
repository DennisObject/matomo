<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

final class ProcessedReportApiTest extends TestCase
{
    public function test_wraps_native_report_data_with_metadata(): void
    {
        $this->bindDependencies(true);

        $response = $this->get($this->url())->assertOk()->json();

        $this->assertSame('Example website', $response['website'] ?? null);
        $this->assertSame('Visits Summary', $response['metadata']['name'] ?? null);
        $this->assertSame('Visits', $response['columns']['nb_visits'] ?? null);
        $this->assertIsArray($response['reportData'] ?? null);
        $this->assertIsArray($response['reportTotal'] ?? null);
    }

    public function test_rejects_site_without_view_access(): void
    {
        $this->bindDependencies(false);

        $this->get($this->url())->assertUnauthorized();
    }

    private function bindDependencies(bool $view): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn($view);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $sites = $this->createStub(SiteRepository::class);
        $sites->method('details')->willReturn(['name' => 'Example website']);
        $this->app->instance(SiteRepository::class, $sites);
    }

    private function url(): string
    {
        return '/index.php?module=API&method=API.getProcessedReport&format=json&idSite=1'.
            '&period=day&date=2026-08-15&apiModule=VisitsSummary&apiAction=get';
    }
}
