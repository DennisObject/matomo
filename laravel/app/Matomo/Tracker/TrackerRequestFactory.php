<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use App\Matomo\CustomDimensions\CustomDimensionRepository;
use App\Matomo\Security\ClientIpResolver;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Http\Request;
use InvalidArgumentException;

final readonly class TrackerRequestFactory
{
    public function __construct(
        private ClientIpResolver $ips,
        private SiteRepository $sites,
        private CustomDimensionRepository $dimensions,
    ) {}

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

        $referrer = $this->optional($request->input('urlref'), 4096);
        if ($referrer !== null && (filter_var($referrer, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($referrer, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            throw new InvalidArgumentException('urlref must be a valid HTTP or HTTPS URL.');
        }

        $hour = $this->boundedInteger($request->input('h'), 0, 23);
        $minute = $this->boundedInteger($request->input('m'), 0, 59);
        $second = $this->boundedInteger($request->input('s'), 0, 59);
        $resolution = $this->optional($request->input('res'), 9) ?? '';
        if ($resolution !== '' && preg_match('/^[0-9]{1,5}x[0-9]{1,5}$/D', $resolution) !== 1) {
            throw new InvalidArgumentException('res must be a screen resolution such as 1920x1080.');
        }

        [$visitProperties, $actionProperties] = $this->customProperties($request, (int) $siteId);

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
            $this->optional($request->input('uid'), 200),
            $referrer ?? '',
            $this->optional($request->input('lang'), 20) ?? '',
            sprintf('%02d:%02d:%02d', $hour, $minute, $second),
            $resolution,
            $request->boolean('cookie', false),
            $visitProperties,
            $actionProperties,
        );
    }

    /** @return list<TrackingRequest> */
    public function many(Request $request): array
    {
        $payload = $request->input('requests');
        if ($payload === null) {
            return [$this->make($request)];
        }

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        if (is_array($payload) && isset($payload['requests'])) {
            $payload = $payload['requests'];
        }

        if (! is_array($payload) || count($payload) > 50) {
            throw new InvalidArgumentException('requests must contain at most 50 tracking query strings.');
        }

        $tracking = [];
        foreach ($payload as $query) {
            if (! is_string($query)) {
                throw new InvalidArgumentException('Every bulk tracking request must be a query string.');
            }

            parse_str(ltrim($query, '?'), $parameters);
            $nested = Request::create('/matomo.php', 'POST', $parameters, $request->cookies->all(), [], $request->server->all());
            $tracking[] = $this->make($nested);
        }

        return $tracking;
    }

    private function optional(mixed $value, int $length): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $length) : null;
    }

    private function boundedInteger(mixed $value, int $minimum, int $maximum): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $minimum || (int) $value > $maximum) {
            throw new InvalidArgumentException('Tracker time components are invalid.');
        }

        return (int) $value;
    }

    /** @return array{array<string, string>, array<string, string>} */
    private function customProperties(Request $request, int $siteId): array
    {
        $visit = $this->customVariables($request->input('_cvar'));
        $action = $this->customVariables($request->input('cvar'));
        foreach ($this->dimensions->configuredForSite($siteId) as $dimension) {
            if (! ($dimension['active'] ?? false)) {
                continue;
            }

            $index = $dimension['index'] ?? null;
            $scope = $dimension['scope'] ?? null;
            if (! is_numeric($index) || ! in_array($scope, ['visit', 'action'], true)) {
                continue;
            }

            $value = $this->optional($request->input('dimension'.(int) $index), 255);
            if ($value !== null) {
                $column = 'custom_dimension_'.(int) $index;
                if ($scope === 'visit') {
                    $visit[$column] = $value;
                } else {
                    $action[$column] = $value;
                }
            }
        }

        return [$visit, $action];
    }

    /** @return array<string, string> */
    private function customVariables(mixed $input): array
    {
        if (is_string($input) && $input !== '') {
            $input = json_decode($input, true);
        }

        if ($input === null || $input === '') {
            return [];
        }

        if (! is_array($input)) {
            throw new InvalidArgumentException('Custom variables must be a JSON object.');
        }

        $properties = [];
        foreach ($input as $index => $pair) {
            if (filter_var($index, FILTER_VALIDATE_INT) === false || (int) $index < 1 || (int) $index > 5
                || ! is_array($pair) || ! isset($pair[0], $pair[1]) || ! is_string($pair[0]) || ! is_scalar($pair[1])) {
                throw new InvalidArgumentException('Custom variable entries are invalid.');
            }

            $properties['custom_var_k'.(int) $index] = mb_substr($pair[0], 0, 200);
            $properties['custom_var_v'.(int) $index] = mb_substr((string) $pair[1], 0, 200);
        }

        return $properties;
    }
}
