<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Tracker\TrackingRequest;
use App\Matomo\Tracker\VisitRecorder;
use Tests\TestCase;

final class TrackerEndpointTest extends TestCase
{
    public function test_records_valid_page_view_and_returns_pixel(): void
    {
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(static fn (TrackingRequest $request): bool => $request->siteId === 1 && $request->visitorId === '0123456789abcdef'));
        $this->app->instance(VisitRecorder::class, $recorder);
        $this->get('/matomo.php?idsite=1&url=https%3A%2F%2Fexample.test%2Fpage&_id=0123456789abcdef')->assertOk()->assertHeader('Content-Type', 'image/gif');
    }

    public function test_rejects_invalid_tracking_input(): void
    {
        $this->get('/matomo.php?idsite=0&url=javascript%3Aalert%281%29')->assertBadRequest();
    }

    public function test_respects_do_not_track(): void
    {
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);
        $this->withHeader('DNT', '1')->get('/piwik.php')->assertOk();
    }
}
