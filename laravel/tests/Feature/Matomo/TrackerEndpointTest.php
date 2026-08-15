<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Sites\SiteRepository;
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

    public function test_rejects_oversized_bulk_request(): void
    {
        $this->bindSite();
        $this->bindUnusedRecorder();
        $requests = array_fill(0, 51, '?idsite=1&url=https%3A%2F%2Fexample.test');
        $this->post('/matomo.php', ['requests' => $requests])->assertBadRequest();
    }

    public function test_respects_do_not_track(): void
    {
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);
        $this->withHeader('DNT', '1')->get('/piwik.php')->assertOk();
    }

    private function bindSite(): void
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('details')->willReturn(['idsite' => 1]);
        $this->app->instance(SiteRepository::class, $sites);
    }

    private function bindUnusedRecorder(): void
    {
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);
    }
}
