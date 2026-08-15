<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use App\Matomo\Security\ClientIpResolver;
use Illuminate\Http\Request;
use InvalidArgumentException;

final readonly class TrackerRequestFactory
{
    public function __construct(private ClientIpResolver $ips) {}

    public function make(Request $request): TrackingRequest
    {
        $siteId = filter_var($request->input('idsite'), FILTER_VALIDATE_INT);
        if ($siteId === false || $siteId < 1) {
            throw new InvalidArgumentException('idsite must be a positive integer.');
        }

        $url = $request->input('url', '');
        if (! is_string($url) || strlen($url) > 4096 || filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException('url must be a valid HTTP or HTTPS URL.');
        }

        $visitorId = $request->input('_id', '');
        if (! is_string($visitorId) || preg_match('/^[a-f0-9]{16}$/iD', $visitorId) !== 1) {
            $visitorId = bin2hex(random_bytes(8));
        }

        $actionName = $request->input('action_name', '');

        return new TrackingRequest((int) $siteId, $url, is_string($actionName) ? mb_substr($actionName, 0, 255) : '', strtolower($visitorId), $this->ips->resolve($request), mb_substr((string) $request->userAgent(), 0, 512));
    }
}
