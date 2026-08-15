<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Insights\InsightSourceReport;
use App\Matomo\Insights\InsightSourceReportProvider;
use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Reporting\VisitsSummaryArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class InsightsReportApiTest extends TestCase
{
    public function test_generates_insights_and_marks_movers_and_shakers(): void
    {
        $this->bindAccess(true);
        $this->bindContext();
        $sources = $this->createMock(InsightSourceReportProvider::class);
        $sources->expects($this->exactly(2))
            ->method('report')
            ->willReturnOnConsecutiveCalls(
                $this->source([
                    ['label' => 'growing', 'nb_visits' => 80],
                    ['label' => 'new', 'nb_visits' => 30],
                    ['label' => 'falling', 'nb_visits' => 10],
                ]),
                $this->source([
                    ['label' => 'growing', 'nb_visits' => 20],
                    ['label' => 'falling', 'nb_visits' => 40],
                    ['label' => 'gone', 'nb_visits' => 30],
                ]),
            );
        $this->app->instance(InsightSourceReportProvider::class, $sources);

        $response = $this->get($this->url('Insights.getInsights', [
            'minImpactPercent' => 0,
            'minGrowthPercent' => 0,
            'limitIncreaser' => 10,
            'limitDecreaser' => 10,
        ]))->assertOk()->json();

        $this->assertSame(['growing', 'new', 'gone', 'falling'], array_column($response, 'label'));
        $this->assertTrue($response[0]['isMoverAndShaker']);
        $this->assertSame('300%', $response[0]['growth_percent']);
        $this->assertTrue($response[1]['isNew']);
        $this->assertTrue($response[2]['isDisappeared']);
    }

    public function test_generates_movers_and_shakers_with_direction_limits(): void
    {
        $this->bindAccess(true);
        $this->bindContext();
        $sources = $this->createStub(InsightSourceReportProvider::class);
        $sources->method('report')->willReturnOnConsecutiveCalls(
            $this->source([
                ['label' => 'largest-up', 'nb_visits' => 80],
                ['label' => 'other-up', 'nb_visits' => 50],
                ['label' => 'down', 'nb_visits' => 10],
            ]),
            $this->source([
                ['label' => 'largest-up', 'nb_visits' => 20],
                ['label' => 'other-up', 'nb_visits' => 20],
                ['label' => 'down', 'nb_visits' => 50],
            ]),
        );
        $this->app->instance(InsightSourceReportProvider::class, $sources);

        $response = $this->get($this->url('Insights.getMoversAndShakers', [
            'limitIncreaser' => 1,
            'limitDecreaser' => 1,
        ]))->assertOk()->json();

        $this->assertSame(['largest-up', 'down'], array_column($response, 'label'));
    }

    public function test_rejects_requests_without_site_view_access(): void
    {
        $this->bindAccess(false);
        $sources = $this->createMock(InsightSourceReportProvider::class);
        $sources->expects($this->never())->method('report');
        $this->app->instance(InsightSourceReportProvider::class, $sources);

        $this->get($this->url('Insights.getInsights'))->assertUnauthorized();
    }

    public function test_aggregates_available_core_reports_in_the_overview(): void
    {
        $this->bindAccess(true);
        $this->bindContext();
        $sources = $this->createStub(InsightSourceReportProvider::class);
        $sources->method('supports')->willReturnCallback(static fn (string $uniqueId): bool => in_array(
            $uniqueId,
            [
                'Actions_getPageUrls',
                'Actions_getPageTitles',
                'Actions_getDownloads',
                'UserCountry_getCountry',
            ],
            true,
        ));
        $sources->method('report')->willReturnCallback(function (
            string $uniqueId,
            int $siteId,
            ReportingPeriod $period,
        ): ?InsightSourceReport {
            if (! in_array($uniqueId, [
                'Actions_getPageUrls',
                'Actions_getPageTitles',
                'Actions_getDownloads',
                'UserCountry_getCountry',
            ], true)) {
                return null;
            }

            $current = $period->startDate === '2026-08-14';

            return new InsightSourceReport(
                [['label' => $uniqueId, 'nb_visits' => $current ? 50 : 20]],
                [
                    'name' => $uniqueId,
                    'metrics' => ['nb_visits' => 'Visits'],
                ],
                $current ? 50 : 20,
            );
        });
        $this->app->instance(InsightSourceReportProvider::class, $sources);

        $response = $this->get($this->url('Insights.getInsightsOverview'))
            ->assertOk()
            ->json();

        $this->assertSame([
            'Actions_getPageUrls',
            'Actions_getPageTitles',
            'Actions_getDownloads',
            'UserCountry_getCountry',
        ], array_keys($response));
    }

    private function bindAccess(bool $allowed): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn($allowed);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    private function bindContext(): void
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $segments = $this->createStub(SegmentHashResolver::class);
        $segments->method('resolve')->willReturn('segment-hash');
        $visits = $this->createStub(VisitsSummaryArchiveRepository::class);
        $visits->method('metrics')->willReturn([
            7 => [
                '2026-08-14,2026-08-14' => ['nb_visits' => 120],
                '2026-08-13,2026-08-13' => ['nb_visits' => 100],
            ],
        ]);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(SegmentHashResolver::class, $segments);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $visits);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function source(array $rows): InsightSourceReport
    {
        return new InsightSourceReport($rows, [
            'name' => 'Test report',
            'metrics' => ['nb_visits' => 'Visits'],
        ], array_sum(array_column($rows, 'nb_visits')));
    }

    /** @param array<string, int> $parameters */
    private function url(string $method, array $parameters = []): string
    {
        return '/index.php?module=API&method='.$method.
            '&idSite=7&period=day&date=2026-08-14&reportUniqueId=Actions_getPageUrls'.
            '&format=json&token_auth=view-token&'.http_build_query($parameters);
    }
}
