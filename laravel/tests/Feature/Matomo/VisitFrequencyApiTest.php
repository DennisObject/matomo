<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Reporting\VisitsSummaryArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class VisitFrequencyApiTest extends TestCase
{
    public function test_merges_new_and_returning_visit_metrics(): void
    {
        $this->bindViewAccess([7]);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $segments = $this->createMock(SegmentHashResolver::class);
        $segments->expects($this->exactly(2))
            ->method('resolve')
            ->willReturnMap([
                ['countryCode==NZ;visitorType==new', 'new-hash'],
                ['countryCode==NZ;visitorType==returning,visitorType==returningCustomer', 'returning-hash'],
            ]);
        $archives = $this->createMock(VisitsSummaryArchiveRepository::class);
        $archives->expects($this->exactly(2))
            ->method('metrics')
            ->willReturnCallback(static function (array $siteIds, array $periods, string $hash): array {
                $metrics = $hash === 'new-hash'
                    ? ['nb_visits' => 2, 'nb_actions' => 6]
                    : ['nb_visits' => 3, 'nb_actions' => 5];

                return [7 => ['2026-08-14,2026-08-14' => $metrics]];
            });
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(SegmentHashResolver::class, $segments);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=VisitFrequency.get&idSite=7&period=day'.
            '&date=2026-08-14&segment=countryCode%3D%3DNZ'.
            '&columns=nb_visits_new,nb_actions_per_visit_new,nb_visits_returning'.
            '&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([
            'nb_visits_new' => 2,
            'nb_actions_per_visit_new' => 3,
            'nb_visits_returning' => 3,
        ]);
    }

    public function test_reads_only_the_requested_visitor_type(): void
    {
        $this->bindViewAccess([7]);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $segments = $this->createMock(SegmentHashResolver::class);
        $segments->expects($this->once())->method('resolve')->with('visitorType==new')->willReturn('new-hash');
        $archives = $this->createMock(VisitsSummaryArchiveRepository::class);
        $archives->expects($this->once())
            ->method('metrics')
            ->with([7], $this->isType('array'), 'new-hash', ['nb_visits'])
            ->willReturn([7 => ['2026-08-14,2026-08-14' => ['nb_visits' => 2]]]);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(SegmentHashResolver::class, $segments);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=VisitFrequency.get&idSite=7&period=day'.
            '&date=2026-08-14&columns=nb_visits_new&format=xml&token_auth=view-token',
        )->assertOk()->assertContent(<<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<result>
	<nb_visits_new>2</nb_visits_new>
</result>
XML);
    }

    public function test_unknown_columns_return_an_empty_report_without_archive_reads(): void
    {
        $this->bindViewAccess([7]);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $archives = $this->createMock(VisitsSummaryArchiveRepository::class);
        $archives->expects($this->never())->method('metrics');
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=VisitFrequency.get&idSite=7&period=day'.
            '&date=2026-08-14&columns=nb_visits&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([]);
    }

    /** @param list<int> $siteIds */
    private function bindViewAccess(array $siteIds): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->exactly(count($siteIds)))
            ->method('hasViewAccessToSite')
            ->with(
                $this->isInstanceOf(ApiAuthentication::class),
                $this->isType('int'),
            )
            ->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
