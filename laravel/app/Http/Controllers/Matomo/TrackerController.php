<?php

declare(strict_types=1);

namespace App\Http\Controllers\Matomo;

use App\Http\Controllers\Controller;
use App\Matomo\Tracker\MatomoCookie;
use App\Matomo\Tracker\MatomoHttpCookie;
use App\Matomo\Tracker\TrackerRequestFactory;
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
        VisitRecorder $visits,
    ): Response {
        try {
            $batch = $requests->many($request);
            foreach ($batch->requests as $trackingRequest) {
                $visits->record($trackingRequest);
            }
        } catch (InvalidArgumentException $invalidArgumentException) {
            return response($invalidArgumentException->getMessage(), 400)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $response = $this->pixel();
        if ($batch->doNotTrackHonored) {
            $response->header('Tk', 'N');
        }

        $cookie = null;
        foreach ($batch->requests as $trackingRequest) {
            if ($trackingRequest->visitorCookie !== null) {
                $cookie = $trackingRequest->visitorCookie;
            }
        }

        if ($cookie !== null) {
            $response->header('P3P', MatomoCookie::P3P_POLICY);
            $response->headers->setCookie(new MatomoHttpCookie($cookie));
        }

        return $response;
    }

    private function pixel(): Response
    {
        return response(base64_decode(self::PIXEL, true) ?: '', 200)
            ->header('Content-Type', 'image/gif')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
