<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\CustomDimensions\CustomDimensionRepository;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Tracker\TrackerSettings;
use App\Matomo\Tracker\TrackingRequest;
use App\Matomo\Tracker\VisitRecorder;
use Tests\TestCase;

final class TrackerEndpointTest extends TestCase
{
    public function test_records_valid_page_view_and_returns_pixel(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(static fn (TrackingRequest $request): bool => $request->siteId === 1 && $request->visitorId === '0123456789abcdef'));
        $this->app->instance(VisitRecorder::class, $recorder);
        $this->get('/matomo.php?idsite=1&url=https%3A%2F%2Fexample.test%2Fpage&_id=0123456789abcdef')->assertOk()->assertHeader('Content-Type', 'image/gif');
    }

    public function test_rejects_invalid_tracking_input(): void
    {
        $this->bindSite();
        $this->bindUnusedRecorder();
        $this->get('/matomo.php?idsite=0&url=javascript%3Aalert%281%29')->assertBadRequest();
    }

    public function test_records_event_parameters(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->actionType === 10
                && $request->eventCategory === 'Video'
                && $request->eventAction === 'Play'
                && $request->eventValue === 2.5,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);
        $this->get('/matomo.php?idsite=1&url=https%3A%2F%2Fexample.test&e_c=Video&e_a=Play&e_v=2.5')->assertOk();
    }

    public function test_validates_and_records_visitor_context(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->userId === 'alice'
                && $request->referrerUrl === 'https://search.example/'
                && $request->browserLanguage === 'en-US'
                && $request->localTime === '14:05:09'
                && $request->resolution === '1920x1080'
                && $request->cookiesEnabled,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);
        $this->get('/matomo.php?idsite=1&url=https%3A%2F%2Fexample.test&uid=alice'.
            '&urlref=https%3A%2F%2Fsearch.example%2F&lang=en-US&h=14&m=5&s=9&res=1920x1080&cookie=1')->assertOk();
    }

    public function test_records_bulk_tracking_requests(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->exactly(2))->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->post('/matomo.php', ['requests' => [
            '?idsite=1&url=https%3A%2F%2Fexample.test%2Fa',
            '?idsite=1&url=https%3A%2F%2Fexample.test%2Fb',
        ]])->assertOk()->assertHeader('Content-Type', 'image/gif');
    }

    public function test_records_scoped_custom_dimensions_and_variables(): void
    {
        $this->bindSite([
            ['index' => 1, 'scope' => 'visit', 'active' => true],
            ['index' => 2, 'scope' => 'action', 'active' => true],
        ]);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->visitProperties === [
                'custom_var_k1' => 'Plan', 'custom_var_v1' => 'Pro', 'custom_dimension_1' => 'Account',
            ] && $request->actionProperties === ['custom_dimension_2' => 'Article'],
        ));
        $this->app->instance(VisitRecorder::class, $recorder);
        $this->get('/matomo.php?idsite=1&url=https%3A%2F%2Fexample.test'.
            '&_cvar=%7B%221%22%3A%5B%22Plan%22%2C%22Pro%22%5D%7D&dimension1=Account&dimension2=Article')->assertOk();
    }

    public function test_attributes_campaigns_and_external_referrers(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->exactly(2))->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->referrerType === 6
                ? $request->referrerName === 'summer' && $request->referrerKeyword === 'shoes'
                : $request->referrerType === 3 && $request->referrerName === 'news.example',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get('/matomo.php?idsite=1&url=https%3A%2F%2Fexample.test%2F%3Futm_campaign%3Dsummer%26utm_term%3Dshoes')->assertOk();
        $this->get('/matomo.php?idsite=1&url=https%3A%2F%2Fexample.test&urlref=https%3A%2F%2Fnews.example%2Fstory')->assertOk();
    }

    public function test_records_page_performance_timings(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->performanceTimings === [
                'time_network' => 12,
                'time_server' => 34,
                'time_on_load' => 56,
            ],
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get('/matomo.php?idsite=1&url=https%3A%2F%2Fexample.test&pf_net=12&pf_srv=34&pf_onl=56')->assertOk();
    }

    public function test_records_site_search_parameters(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->actionType === 8
                && $request->actionName === 'blue shoes'
                && $request->searchCategory === 'products'
                && $request->searchCount === 12,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get('/matomo.php?idsite=1&url=https%3A%2F%2Fexample.test%2Fsearch&search=blue%20shoes&search_cat=products&search_count=12')->assertOk();
    }

    public function test_rejects_invalid_page_performance_timings(): void
    {
        $this->bindSite();
        $this->bindUnusedRecorder();

        $this->get('/matomo.php?idsite=1&url=https%3A%2F%2Fexample.test&pf_net=-1')->assertBadRequest();
    }

    public function test_rejects_oversized_bulk_request(): void
    {
        $this->bindSite();
        $this->bindUnusedRecorder();
        $requests = array_fill(0, 51, '?idsite=1&url=https%3A%2F%2Fexample.test');
        $this->post('/matomo.php', ['requests' => $requests])->assertBadRequest();
    }

    public function test_respects_do_not_track(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);
        $this->withHeader('DNT', '1')->get('/piwik.php')->assertOk();
    }

    /** @param list<array<string, mixed>> $dimensions */
    private function bindSite(array $dimensions = []): void
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('details')->willReturn(['idsite' => 1]);
        $this->app->instance(SiteRepository::class, $sites);
        $customDimensions = $this->createStub(CustomDimensionRepository::class);
        $customDimensions->method('configuredForSite')->willReturn($dimensions);
        $this->app->instance(CustomDimensionRepository::class, $customDimensions);
        $settings = $this->createStub(TrackerSettings::class);
        $settings->method('campaignNameParameters')->willReturn(['utm_campaign']);
        $settings->method('campaignKeywordParameters')->willReturn(['utm_term']);
        $this->app->instance(TrackerSettings::class, $settings);
    }

    private function bindUnusedRecorder(): void
    {
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);
    }
}
