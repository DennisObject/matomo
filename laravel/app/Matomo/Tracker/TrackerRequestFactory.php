<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use App\Matomo\Config\InstallationConfig;
use App\Matomo\Security\ClientIpResolver;
use App\Matomo\Sites\QueryParameterExclusionPolicy;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Http\Request;
use InvalidArgumentException;

final readonly class TrackerRequestFactory
{
    public function __construct(
        private ClientIpResolver $ips,
        private SiteRepository $sites,
        private QueryParameterExclusionPolicy $excludedParameters,
        private TrackingRequestPolicy $policy,
        private InstallationConfig $configuration,
    ) {}

    public function make(Request $request): ?TrackingRequest
    {
        $siteId = filter_var($request->input('idsite'), FILTER_VALIDATE_INT);
        if ($siteId === false || $siteId < 1) {
            throw new InvalidArgumentException('idsite must be a positive integer.');
        }

        $site = $this->sites->details((int) $siteId);
        if ($site === []) {
            throw new InvalidArgumentException('The requested website does not exist.');
        }

        $url = $request->input('url', '');
        if (! is_string($url)
            || strlen($url) > $this->configuration->pageMaximumLength()
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException('url must be a valid HTTP or HTTPS URL.');
        }

        if ((int) ($site['exclude_unknown_urls'] ?? 0) === 1 && ! $this->belongsToSite($url, (int) $siteId)) {
            throw new InvalidArgumentException('url does not belong to the requested website.');
        }

        $ipAddress = $this->ips->resolve($request);
        $userAgent = mb_substr((string) $request->userAgent(), 0, 512);
        if ($this->policy->excludesVisit($site, $ipAddress, $userAgent)) {
            return null;
        }

        $visitorId = $request->input('_id', '');
        if (! is_string($visitorId) || preg_match('/^[a-f0-9]{16}$/iD', $visitorId) !== 1) {
            $visitorId = bin2hex(random_bytes(8));
        }

        $actionName = $request->input('action_name', '');

        return new TrackingRequest(
            siteId: (int) $siteId,
            url: $this->filteredUrl($url, (int) $siteId, $site),
            actionName: is_string($actionName) ? $this->clean($actionName, 255) : '',
            visitorId: strtolower($visitorId),
            ipAddress: $this->policy->storedIpAddress((int) $siteId, $ipAddress),
            userAgent: $userAgent,
        );
    }

    private function belongsToSite(string $url, int $siteId): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach ($this->sites->urls($siteId) as $siteUrl) {
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

        $excluded = [
            ...$this->configuration->urlQueryParametersToExclude(),
            ...$this->configuration->campaignNameParameters(),
            ...$this->configuration->campaignKeywordParameters(),
            ...$this->list($this->excludedParameters->parameters($siteId)),
            ...$this->list($site['excluded_parameters'] ?? null),
            'ignore_referrer',
            'ignore_referer',
        ];
        $excluded = array_map(strtolower(...), $excluded);

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
