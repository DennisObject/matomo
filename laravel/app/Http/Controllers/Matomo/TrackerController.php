<?php

declare(strict_types=1);

namespace App\Http\Controllers\Matomo;

use App\Http\Controllers\Controller;
use App\Matomo\Tracker\TrackerRequestFactory;
use App\Matomo\Tracker\VisitRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

final class TrackerController extends Controller
{
    private const string PIXEL = 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

    public function __invoke(Request $request, TrackerRequestFactory $requests, VisitRecorder $visits): Response
    {
        if ($request->header('DNT') !== '1' && $request->cookie('matomo_ignore') === null) {
            try {
                foreach ($requests->many($request) as $trackingRequest) {
                    $visits->record($trackingRequest);
                }
            } catch (InvalidArgumentException $exception) {
                return response($exception->getMessage(), 400)->header('Content-Type', 'text/plain; charset=utf-8');
            }
        }

        return response(base64_decode(self::PIXEL, true) ?: '', 200)->header('Content-Type', 'image/gif')->header('Cache-Control', 'no-store');
    }
}
