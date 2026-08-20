<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Live\LiveAccessPolicy;
use App\Matomo\Live\LiveCounterRepository;
use App\Matomo\Live\LiveVisitorIdentityRepository;
use App\Matomo\Live\LiveVisitRepository;
use Tests\TestCase;

final class LiveApiTest extends TestCase
{
    public function test_counters_apply_site_access_and_column_filters(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('siteIdsWithAtLeastViewAccess')->willReturn([2, 7]);
        $counters = $this->createMock(LiveCounterRepository::class);
        $counters->expects($this->once())->method('counters')->with([2, 7], 30, 'countryCode==nz')
            ->willReturn(['visits' => 4, 'actions' => 9, 'visitors' => 3, 'visitsConverted' => 1]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LiveCounterRepository::class, $counters);

        $this->get('/index.php?module=API&method=Live.getCounters&idSite=all&lastMinutes=30'.
            '&segment=countryCode%3D%3Dnz&showColumns=visits,actions&hideColumns=actions'.
            '&format=json&token_auth=view-token')
            ->assertOk()->assertExactJson([['visits' => 4]]);
    }

    public function test_profile_is_disabled_if_any_requested_site_disables_it(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('siteIdsWithAtLeastViewAccess')->willReturn([2, 7]);
        $access = $this->createStub(LiveAccessPolicy::class);
        $access->method('visitorProfileEnabled')->willReturnMap([[2, true], [7, false]]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LiveAccessPolicy::class, $access);

        $this->get('/index.php?module=API&method=Live.isVisitorProfileEnabled'.
            '&idSite=2,7&format=json&token_auth=view-token')
            ->assertOk()->assertExactJson(['value' => false]);
    }

    public function test_most_recent_visitor_id_requires_enabled_single_site(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('siteIdsWithAtLeastViewAccess')->willReturn([2]);
        $access = $this->createStub(LiveAccessPolicy::class);
        $access->method('visitorLogEnabled')->willReturn(true);
        $visitors = $this->createMock(LiveVisitorIdentityRepository::class);
        $visitors->expects($this->once())->method('mostRecentVisitorId')
            ->with(2, 'userId==person')->willReturn('0102');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LiveAccessPolicy::class, $access);
        $this->app->instance(LiveVisitorIdentityRepository::class, $visitors);

        $this->get('/index.php?module=API&method=Live.getMostRecentVisitorId'.
            '&idSite=2&segment=userId%3D%3Dperson&format=json&token_auth=view-token')
            ->assertOk()->assertExactJson(['value' => '0102']);
    }

    public function test_most_recent_datetime_supports_all_viewable_sites(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('siteIdsWithAtLeastViewAccess')->willReturn([2, 7]);
        $visitors = $this->createMock(LiveVisitorIdentityRepository::class);
        $visitors->expects($this->once())->method('mostRecentVisitDateTime')
            ->with([2, 7], null, null)->willReturn('2026-08-15 12:00:00');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LiveVisitorIdentityRepository::class, $visitors);

        $this->get('/index.php?module=API&method=Live.getMostRecentVisitsDateTime'.
            '&idSite=all&format=json&token_auth=view-token')
            ->assertOk()->assertExactJson(['value' => '2026-08-15 12:00:00']);
    }

    public function test_last_visits_filters_disabled_sites_and_passes_query_options(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('siteIdsWithAtLeastViewAccess')->willReturn([2, 7]);
        $access = $this->createStub(LiveAccessPolicy::class);
        $access->method('visitorLogEnabled')->willReturnMap([[2, true], [7, false]]);
        $visits = $this->createMock(LiveVisitRepository::class);
        $visits->expects($this->once())->method('visits')->with(
            [2], 'userId==person', null, null, 100, 3, 5, false, null, false, true, 'countryCode==nz', 'en',
        )->willReturn([['idVisit' => 12]]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LiveAccessPolicy::class, $access);
        $this->app->instance(LiveVisitRepository::class, $visits);

        $this->get('/index.php?module=API&method=Live.getLastVisitsDetails&idSite=2,7'.
            '&segment=userId%3D%3Dperson&minTimestamp=100&filter_offset=3&filter_limit=5'.
            '&doNotFetchActions=1&flat=1&intersectSegment=countryCode%3D%3Dnz'.
            '&format=json&token_auth=view-token')
            ->assertOk()->assertExactJson([['idVisit' => 12]]);
    }

    public function test_first_visit_requires_profile_and_returns_oldest_visit(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('siteIdsWithAtLeastViewAccess')->willReturn([2]);
        $access = $this->createStub(LiveAccessPolicy::class);
        $access->method('visitorLogEnabled')->willReturn(true);
        $access->method('visitorProfileEnabled')->willReturn(true);
        $visits = $this->createMock(LiveVisitRepository::class);
        $visits->expects($this->once())->method('visits')->with(
            [2], null, null, null, null, 0, 1, true, '0102', false, false, null, 'en',
        )->willReturn([['idVisit' => 1]]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LiveAccessPolicy::class, $access);
        $this->app->instance(LiveVisitRepository::class, $visits);

        $this->get('/index.php?module=API&method=Live.getFirstVisitForVisitorId'.
            '&idSite=2&visitorId=0102&format=json&token_auth=view-token')
            ->assertOk()->assertExactJson([['idVisit' => 1]]);
    }

    public function test_visitor_profile_aggregates_visits_and_limits_recent_rows(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('siteIdsWithAtLeastViewAccess')->willReturn([2]);
        $access = $this->createStub(LiveAccessPolicy::class);
        $access->method('visitorProfileEnabled')->willReturn(true);
        $visits = $this->createMock(LiveVisitRepository::class);
        $visits->expects($this->once())->method('visits')
            ->with([2], null, null, null, null, 0, 101, false, '0102', true, false, null, 'en')
            ->willReturn([
                ['idVisit' => 2, 'visitDuration' => 20, 'actions' => 1, 'goalConversions' => 0,
                    'actionDetails' => [['type' => 'action', 'url' => 'https://example.test']],
                    'latitude' => null, 'userId' => 'person', 'firstActionTimestamp' => 20],
                ['idVisit' => 1, 'visitDuration' => 10, 'actions' => 1, 'goalConversions' => 0,
                    'actionDetails' => [['type' => 'download', 'url' => 'https://example.test/file']],
                    'latitude' => null, 'userId' => 'person', 'firstActionTimestamp' => 10],
            ]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LiveAccessPolicy::class, $access);
        $this->app->instance(LiveVisitRepository::class, $visits);

        $this->get('/index.php?module=API&method=Live.getVisitorProfile'.
            '&idSite=2&visitorId=0102&limitVisits=1&format=json&token_auth=view-token')
            ->assertOk()->assertJsonPath('visitorId', '0102')->assertJsonPath('totalVisits', 2)
            ->assertJsonPath('totalVisitDuration', 30)->assertJsonPath('totalDownloads', 1)
            ->assertJsonCount(1, 'lastVisits');
    }

    public function test_anonymous_visit_details_hide_sensitive_identifiers(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('siteIdsWithAtLeastViewAccess')->willReturn([2]);
        $authorizer->method('authenticatedLogin')->willReturn('anonymous');
        $access = $this->createStub(LiveAccessPolicy::class);
        $access->method('visitorLogEnabled')->willReturn(true);
        $visits = $this->createStub(LiveVisitRepository::class);
        $visits->method('visits')->willReturn([[
            'visitorId' => '0102', 'visitIp' => '127.0.0.1',
            'fingerprint' => '0304', 'userId' => 'person',
        ]]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LiveAccessPolicy::class, $access);
        $this->app->instance(LiveVisitRepository::class, $visits);

        $this->get('/index.php?module=API&method=Live.getLastVisitsDetails'.
            '&idSite=2&format=json&token_auth=anonymous')
            ->assertOk()->assertExactJson([[
                'visitorId' => false, 'visitIp' => null, 'fingerprint' => false, 'userId' => null,
            ]]);
    }
}
