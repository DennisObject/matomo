<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Reporting\VisitsSummaryArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class PagePerformanceApiTest extends TestCase
{
    public function test_returns_averages_in_seconds_and_removes_temporary_metrics(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), 7)
            ->willReturn(true);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $archives = $this->createMock(VisitsSummaryArchiveRepository::class);
        $archives->expects($this->once())
            ->method('metrics')
            ->with([7], $this->isType('array'), '', $this->countOf(14))
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [
                'PagePerformance_network_time' => 40,
                'PagePerformance_network_hits' => 2,
                'PagePerformance_servery_time' => 200,
                'PagePerformance_server_hits' => 2,
                'PagePerformance_transfer_time' => 1200,
                'PagePerformance_transfer_hits' => 2,
                'PagePerformance_domprocessing_time' => 4000,
                'PagePerformance_domprocessing_hits' => 2,
                'PagePerformance_domcompletion_time' => 1200,
                'PagePerformance_domcompletion_hits' => 2,
                'PagePerformance_onload_time' => 240,
                'PagePerformance_onload_hits' => 2,
                'PagePerformance_pageload_time' => 6880,
                'PagePerformance_pageload_hits' => 2,
            ]]]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=PagePerformance.get&idSite=7'.
            '&period=day&date=2026-08-14&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([
            'avg_time_network' => 0.02,
            'avg_time_server' => 0.1,
            'avg_time_transfer' => 0.6,
            'avg_time_dom_processing' => 2,
            'avg_time_dom_completion' => 0.6,
            'avg_time_on_load' => 0.12,
            'avg_page_load_time' => 3.44,
        ]);
    }

    public function test_returns_zero_averages_when_no_timing_hits_exist(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(true);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get(
            '/index.php?module=API&method=PagePerformance.get&idSite=7'.
            '&period=day&date=2026-08-14&format=json&token_auth=view-token',
        )->assertOk()
            ->assertJsonPath('avg_time_network', 0)
            ->assertJsonPath('avg_page_load_time', 0);
    }
}
