<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use App\Matomo\Security\ClientIpResolver;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Http\Request;
use InvalidArgumentException;

final readonly class TrackerRequestFactory
{
    public function __construct(private ClientIpResolver $ips, private SiteRepository $sites) {}

    public function make(Request $request): TrackingRequest
    {
        $siteId = filter_var($request->input('idsite'), FILTER_VALIDATE_INT);
        if ($siteId === false || $siteId < 1) {
            throw new InvalidArgumentException('idsite must be a positive integer.');
        }

        if ($this->sites->details((int) $siteId) === []) {
            throw new InvalidArgumentException('The requested website does not exist.');
        }

        $eventCategory = $this->optional($request->input('e_c'), 255);
        $eventAction = $this->optional($request->input('e_a'), 255);
        $download = $this->optional($request->input('download'), 4096);
        $outlink = $this->optional($request->input('link'), 4096);
        if (($eventCategory === null) !== ($eventAction === null)) {
            throw new InvalidArgumentException('e_c and e_a must be provided together.');
        }

        $actionType = $eventCategory !== null ? 10 : ($download !== null ? 3 : ($outlink !== null ? 2 : 1));
        $url = $download ?? $outlink ?? $request->input('url', '');
        if (! is_string($url) || strlen($url) > 4096 || filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException('url must be a valid HTTP or HTTPS URL.');
        }

        $visitorId = $request->input('_id', '');
        if (! is_string($visitorId) || preg_match('/^[a-f0-9]{16}$/iD', $visitorId) !== 1) {
            $visitorId = bin2hex(random_bytes(8));
        }

        $actionName = $request->input('action_name', '');

        $eventValue = $request->input('e_v');
        if ($eventValue !== null && ! is_numeric($eventValue)) {
            throw new InvalidArgumentException('e_v must be numeric.');
        }

        return new TrackingRequest(
            (int) $siteId,
            $url,
            is_string($actionName) ? mb_substr($actionName, 0, 255) : '',
            strtolower($visitorId),
            $this->ips->resolve($request),
            mb_substr((string) $request->userAgent(), 0, 512),
            $actionType,
            $eventCategory,
            $eventAction,
            $this->optional($request->input('e_n'), 255),
            $eventValue === null ? null : (float) $eventValue,
        );
    }

    private function optional(mixed $value, int $length): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $length) : null;
    }
}
