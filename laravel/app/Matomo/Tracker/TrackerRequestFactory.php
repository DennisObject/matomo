<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use App\Matomo\Config\InstallationConfig;
use App\Matomo\Security\ClientIpResolver;
use App\Matomo\Sites\QueryParameterExclusionPolicy;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class TrackerRequestFactory
{
    /** @var array<int, array<string, int|string|null>> */
    private array $sitesById = [];

    /** @var array<int, list<string>> */
    private array $siteUrlsById = [];

    /** @var array<int, list<string>> */
    private array $excludedParametersBySite = [];

    /** @var array<string, bool> */
    private array $doNotTrackByContext = [];

    /** @var array<string, bool> */
    private array $excludedVisitsByContext = [];

    /** @var array<string, string> */
    private array $storedIpsByContext = [];

    public function __construct(
        private readonly ClientIpResolver $ips,
        private readonly SiteRepository $sites,
        private readonly QueryParameterExclusionPolicy $excludedParameters,
        private readonly TrackingRequestPolicy $policy,
        private readonly InstallationConfig $configuration,
    ) {}

    public function make(Request $request): ?TrackingRequest
    {
        $siteId = filter_var($request->input('idsite'), FILTER_VALIDATE_INT);
        if ($siteId === false || $siteId < 1) {
            throw new InvalidArgumentException('idsite must be a positive integer.');
        }

        $site = $this->site((int) $siteId);
        if ($site === []) {
            throw new InvalidArgumentException('The requested website does not exist.');
        }

        $eventCategory = $this->optional($request->input('e_c'), 255);
        $eventAction = $this->optional($request->input('e_a'), 255);
        $maximumUrlLength = $this->configuration->pageMaximumLength();
        $download = $this->optional($request->input('download'), $maximumUrlLength + 1);
        $outlink = $this->optional($request->input('link'), $maximumUrlLength + 1);
        if (($eventCategory === null) !== ($eventAction === null)) {
            throw new InvalidArgumentException('e_c and e_a must be provided together.');
        }

        $pageUrl = $request->input('url', '');
        $actionType = $download !== null ? 3 : ($outlink !== null ? 2 : ($eventCategory !== null ? 10 : 1));
        $url = $download ?? $outlink ?? $pageUrl;
        if (! is_string($url)
            || strlen($url) > $maximumUrlLength
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException('url must be a valid HTTP or HTTPS URL.');
        }

        if ((int) ($site['exclude_unknown_urls'] ?? 0) === 1
            && (! is_string($pageUrl) || ! $this->belongsToSite($pageUrl, (int) $siteId))) {
            throw new InvalidArgumentException('url does not belong to the requested website.');
        }

        $ipAddress = $this->ips->resolve($request);
        $userAgent = mb_substr((string) $request->userAgent(), 0, 512);
        if ($this->excludesVisit($site, $ipAddress, $userAgent)) {
            return null;
        }

        $visitorId = $request->input('_id', '');
        if (! is_string($visitorId) || preg_match('/^[a-f0-9]{16}$/iD', $visitorId) !== 1) {
            $visitorId = bin2hex(random_bytes(8));
        }

        $actionName = $request->input('action_name', '');

        $eventValue = $actionType === 10 ? $request->input('e_v') : null;
        if (is_string($eventValue)) {
            $eventValue = trim($eventValue);
            $eventValue = $eventValue === '' ? null : $eventValue;
        }

        if ($eventValue !== null
            && (! is_numeric($eventValue) || ! is_finite((float) $eventValue))) {
            throw new InvalidArgumentException('e_v must be numeric.');
        }

        return new TrackingRequest(
            siteId: (int) $siteId,
            url: in_array($actionType, [1, 10], true)
                ? $this->filteredUrl($url, (int) $siteId, $site)
                : $this->clean($url, $maximumUrlLength),
            actionName: is_string($actionName) ? $this->clean($actionName, 255) : '',
            visitorId: strtolower($visitorId),
            ipAddress: $this->storedIpAddress((int) $siteId, $ipAddress),
            userAgent: $userAgent,
            actionType: $actionType,
            eventCategory: $actionType === 10 ? $eventCategory : null,
            eventAction: $actionType === 10 ? $eventAction : null,
            eventName: $actionType === 10 ? $this->optional($request->input('e_n'), 255) : null,
            eventValue: $eventValue === null ? null : (float) $eventValue,
        );
    }

    public function many(Request $request): TrackingRequestBatch
    {
        $payload = $request->input('requests');
        if ($payload === null) {
            return $this->batch([$request]);
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

        $requests = [];
        $server = $request->server->all();
        unset(
            $server['CONTENT_LENGTH'],
            $server['CONTENT_TYPE'],
            $server['HTTP_CONTENT_LENGTH'],
            $server['HTTP_CONTENT_TYPE'],
        );

        foreach ($payload as $query) {
            if (! is_string($query)) {
                throw new InvalidArgumentException('Every bulk tracking request must be a query string.');
            }

            parse_str(ltrim($query, '?'), $parameters);
            $requests[] = Request::create(
                '/matomo.php',
                'POST',
                $parameters,
                $request->cookies->all(),
                [],
                $server,
            );
        }

        return $this->batch($requests);
    }

    /** @param list<Request> $requests */
    private function batch(array $requests): TrackingRequestBatch
    {
        $tracking = [];
        $doNotTrackHonored = false;

        foreach ($requests as $request) {
            if ($this->honorsDoNotTrack($request)) {
                $doNotTrackHonored = true;

                continue;
            }

            if (! $this->policy->records($request)) {
                continue;
            }

            $trackingRequest = $this->make($request);
            if ($trackingRequest !== null) {
                $tracking[] = $trackingRequest;
            }
        }

        return new TrackingRequestBatch($tracking, $doNotTrackHonored);
    }

    /** @return array<string, int|string|null> */
    private function site(int $siteId): array
    {
        return $this->sitesById[$siteId] ??= $this->sites->details($siteId);
    }

    private function honorsDoNotTrack(Request $request): bool
    {
        $siteId = $request->input('idsite');
        $key = implode("\0", [
            is_scalar($siteId) ? (string) $siteId : '',
            (string) $request->header('DNT'),
            (string) $request->header('X-Do-Not-Track'),
        ]);

        return $this->doNotTrackByContext[$key] ??= $this->policy->honorsDoNotTrack($request);
    }

    /** @param array<string, int|string|null> $site */
    private function excludesVisit(array $site, string $ipAddress, string $userAgent): bool
    {
        $key = implode("\0", [(string) ($site['idsite'] ?? ''), $ipAddress, $userAgent]);

        return $this->excludedVisitsByContext[$key] ??= $this->policy->excludesVisit(
            $site,
            $ipAddress,
            $userAgent,
        );
    }

    private function storedIpAddress(int $siteId, string $ipAddress): string
    {
        $key = $siteId."\0".$ipAddress;

        return $this->storedIpsByContext[$key] ??= $this->policy->storedIpAddress($siteId, $ipAddress);
    }

    private function optional(mixed $value, int $length): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = $this->clean($value, $length);

        return $value === '' ? null : $value;
    }

    private function belongsToSite(string $url, int $siteId): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach ($this->siteUrlsById[$siteId] ??= $this->sites->urls($siteId) as $siteUrl) {
            if (strtolower((string) parse_url($siteUrl, PHP_URL_HOST)) === $host) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, int|string|null> $site */
    private function filteredUrl(string $url, int $siteId, array $site): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        $excluded = $this->excludedParametersBySite[$siteId] ??= array_map(strtolower(...), [
            ...$this->configuration->urlQueryParametersToExclude(),
            ...$this->configuration->campaignNameParameters(),
            ...$this->configuration->campaignKeywordParameters(),
            ...$this->list($this->excludedParameters->parameters($siteId)),
            ...$this->list($site['excluded_parameters'] ?? null),
            'ignore_referrer',
            'ignore_referer',
        ]);

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query = array_filter(
            $query,
            static fn (int|string $name): bool => ! in_array(strtolower((string) $name), $excluded, true),
            ARRAY_FILTER_USE_KEY,
        );

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = "[{$host}]";
        }

        $filtered = $scheme.'://'.$host;
        if (isset($parts['port'])) {
            $filtered .= ':'.(int) $parts['port'];
        }

        $filtered .= (string) ($parts['path'] ?? '');
        if ($query !== []) {
            $filtered .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        if ((int) ($site['keep_url_fragment'] ?? 0) === 1 && isset($parts['fragment'])) {
            $filtered .= '#'.$parts['fragment'];
        }

        return $this->clean($filtered, $this->configuration->pageMaximumLength());
    }

    private function clean(string $value, int $limit): string
    {
        return mb_substr(str_replace(["\n", "\r", "\0"], '', trim($value)), 0, $limit);
    }

    /** @return list<string> */
    private function list(int|string|null $value): array
    {
        return is_string($value)
            ? array_values(array_filter(array_map(trim(...), explode(',', $value))))
            : [];
    }
}
