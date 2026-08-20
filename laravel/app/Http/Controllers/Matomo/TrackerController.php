<?php

declare(strict_types=1);

namespace App\Http\Controllers\Matomo;

use App\Http\Controllers\Controller;
use App\Matomo\Tracker\TrackerRequestFactory;
use App\Matomo\Tracker\TrackingRequestPolicy;
use App\Matomo\Tracker\VisitRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

final class TrackerController extends Controller
{
    private const string PIXEL = 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

    public function __invoke(
        Request $request,
        TrackerRequestFactory $requests,
        TrackingRequestPolicy $policy,
        VisitRecorder $visits,
    ): Response {
        if ($policy->honorsDoNotTrack($request)) {
            return $this->pixel()->header('Tk', 'N');
        }

        if (! $policy->records($request)) {
            return $this->pixel();
        }

        try {
            $trackingRequest = $requests->make($request);
            if ($trackingRequest !== null) {
                $visits->record($trackingRequest);
            }
        } catch (InvalidArgumentException $invalidArgumentException) {
            return response($invalidArgumentException->getMessage(), 400)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        return $this->pixel();
    }

    private function pixel(): Response
    {
        return response(base64_decode(self::PIXEL, true) ?: '', 200)
            ->header('Content-Type', 'image/gif')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
