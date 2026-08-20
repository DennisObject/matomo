<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use App\Matomo\Config\InstallationConfig;
use App\Matomo\CustomDimensions\CustomDimensionRepository;
use App\Matomo\Goals\GoalRepository;
use App\Matomo\Security\ClientIpResolver;
use App\Matomo\Sites\QueryParameterExclusionPolicy;
use App\Matomo\Sites\SiteRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;

final class TrackerRequestFactory
{
    private const int REFERRER_TYPE_DIRECT = 1;

    private const int REFERRER_TYPE_WEBSITE = 3;

    private const int REFERRER_TYPE_CAMPAIGN = 6;

    private const string MASKED_CAMPAIGN_VALUE = '__discarded_by_policy__';

    /** @var array<int, array<string, int|string|null>> */
    private array $sitesById = [];

    /** @var array<int, list<array<string, bool|int|string|list<array<string, mixed>>>>> */
    private array $dimensionsBySite = [];

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

    /** @var array<int, bool> */
    private array $collectsUserIdBySite = [];

    /** @var array<int, string> */
    private array $referrerAnonymisationBySite = [];

    /** @var array<int, bool> */
    private array $collectsScreenResolutionBySite = [];

    /** @var array<int, bool> */
    private array $campaignParametersMaskedBySite = [];

    /** @var array<string, array<string, float|int|string>|null> */
    private array $goalsBySiteAndId = [];

    public function __construct(
        private readonly ClientIpResolver $ips,
        private readonly SiteRepository $sites,
        private readonly QueryParameterExclusionPolicy $excludedParameters,
        private readonly TrackingRequestPolicy $policy,
        private readonly InstallationConfig $configuration,
        private readonly CustomDimensionRepository $dimensions,
        private readonly GoalRepository $goals,
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
        $search = (int) ($site['sitesearch'] ?? 1) === 1
            ? $this->optional($request->input('search'), 255)
            : null;
        $contentNameInput = $request->input('c_n');
        $contentName = $this->optional($contentNameInput, 255);

        if (($eventCategory === null) !== ($eventAction === null)) {
            throw new InvalidArgumentException('e_c and e_a must be provided together.');
        }

        if ($download === null && $outlink === null && $eventCategory === null
            && $request->exists('c_n') && $contentName === null) {
            throw new InvalidArgumentException('c_n must not be blank.');
        }

        $pageUrl = $request->input('url', '');
        $actionType = $download !== null ? 3 : ($outlink !== null ? 2 : ($eventCategory !== null ? 10 : ($contentName !== null ? 13 : ($search !== null ? 8 : 1))));
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

        $actionName = $actionType === 8 ? $search : $request->input('action_name', '');

        $eventValue = $actionType === 10 ? $request->input('e_v') : null;
        if (is_string($eventValue)) {
            $eventValue = trim($eventValue);
            $eventValue = $eventValue === '' ? null : $eventValue;
        }

        if ($eventValue !== null
            && (! is_numeric($eventValue) || ! is_finite((float) $eventValue))) {
            throw new InvalidArgumentException('e_v must be numeric.');
        }

        $searchCategory = $actionType === 8 ? $this->optional($request->input('search_cat'), 200) : null;
        $searchCount = $actionType === 8 ? $this->searchCount($request->input('search_count')) : null;

        $goalIdInput = $request->input('idgoal');
        $goal = null;
        $goalRevenue = null;
        if ($goalIdInput !== null) {
            if (filter_var($goalIdInput, FILTER_VALIDATE_INT) === false || (int) $goalIdInput < 1) {
                throw new InvalidArgumentException('idgoal must be a positive integer.');
            }

            $goal = $this->goal($siteId, (int) $goalIdInput);
            if ($goal === null) {
                throw new InvalidArgumentException('The requested goal does not exist.');
            }

            $goalRevenue = $this->goalRevenue(
                $request->input('revenue'),
                (float) ($goal['revenue'] ?? 0),
            );
        }

        $referrer = $request->input('urlref', '');
        if (! is_string($referrer) || strlen($referrer) > 1_500
            || ($referrer !== '' && (filter_var($referrer, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($referrer, PHP_URL_SCHEME)), ['http', 'https'], true)))) {
            throw new InvalidArgumentException('urlref must be a valid HTTP or HTTPS URL.');
        }

        $now = CarbonImmutable::now('UTC');
        $hour = $this->boundedInteger($request->input('h'), 0, 23, (int) $now->format('H'));
        $minute = $this->boundedInteger($request->input('m'), 0, 59, (int) $now->format('i'));
        $second = $this->boundedInteger($request->input('s'), 0, 59, (int) $now->format('s'));
        $resolution = $request->input('res');
        if ($resolution === null || $resolution === '') {
            $resolution = 'unknown';
        } elseif (! is_string($resolution)
            || strlen($resolution) > 9
            || preg_match('/^[0-9]{1,4}x[0-9]{1,4}$/D', $resolution) !== 1) {
            throw new InvalidArgumentException('res must be a screen resolution such as 1920x1080.');
        }

        $siteId = (int) $siteId;
        [$referrerType, $referrerName, $referrerKeyword, $ignoreReferrer] = $this->referrerAttribution(
            $request,
            $url,
            $referrer,
            $siteId,
        );
        if ($ignoreReferrer) {
            $referrer = '';
        }

        $userId = $this->optional($request->input('uid'), 200);
        if ($userId !== null
            && ! ($this->collectsUserIdBySite[$siteId] ??= $this->policy->collectsUserId($siteId))) {
            $userId = null;
        }

        if ($referrer !== '') {
            $referrerMode = $this->referrerAnonymisationBySite[$siteId]
                ??= $this->policy->referrerAnonymisation($siteId);
            $referrer = $this->anonymisedReferrer(
                $this->clean($referrer, 1_500),
                $referrerMode,
            );
            if ($referrerType === self::REFERRER_TYPE_WEBSITE && $referrerMode === 'exclude_all') {
                $referrerName = '';
            }
        }

        if ($referrerType === self::REFERRER_TYPE_CAMPAIGN
            && ($this->campaignParametersMaskedBySite[$siteId]
            ??= $this->policy->masksCampaignParameters($siteId))) {
            $referrerName = self::MASKED_CAMPAIGN_VALUE;
            $referrerKeyword = self::MASKED_CAMPAIGN_VALUE;
        }

        if ($resolution !== 'unknown'
            && ! ($this->collectsScreenResolutionBySite[$siteId]
            ??= $this->policy->collectsScreenResolution($siteId))) {
            $resolution = 'unknown';
        }

        [$visitProperties, $actionProperties] = $this->customProperties($request, $siteId);

        return new TrackingRequest(
            siteId: $siteId,
            url: in_array($actionType, [1, 10, 13], true)
                ? $this->filteredUrl($url, $siteId, $site)
                : $this->clean($url, $maximumUrlLength),
            actionName: is_string($actionName) ? $this->clean($actionName, 255) : '',
            visitorId: strtolower($visitorId),
            ipAddress: $this->storedIpAddress($siteId, $ipAddress),
            userAgent: $userAgent,
            actionType: $actionType,
            eventCategory: $actionType === 10 ? $eventCategory : null,
            eventAction: $actionType === 10 ? $eventAction : null,
            eventName: $actionType === 10 ? $this->optional($request->input('e_n'), 255) : null,
            eventValue: $eventValue === null ? null : (float) $eventValue,
            searchCategory: $searchCategory,
            searchCount: $searchCount,
            contentName: $actionType === 13 ? $contentName : null,
            contentPiece: $actionType === 13 ? $this->optional($request->input('c_p'), 255) : null,
            contentTarget: $actionType === 13 ? $this->optional($request->input('c_t'), $maximumUrlLength) : null,
            contentInteraction: $actionType === 13 ? $this->optional($request->input('c_i'), 255) : null,
            goalId: $goal === null ? null : (int) $goalIdInput,
            goalRevenue: $goalRevenue,
            goalAllowsMultiple: (int) ($goal['allow_multiple'] ?? 0) === 1,
            userId: $userId,
            referrerUrl: $referrer,
            referrerType: $referrerType,
            referrerName: $referrerName,
            referrerKeyword: $referrerKeyword,
            browserLanguage: $this->browserLanguage($request),
            localTime: sprintf('%02d:%02d:%02d', $hour, $minute, $second),
            resolution: $resolution,
            cookiesEnabled: $request->boolean('cookie', false),
            heartbeat: in_array($request->input('ping'), [1, '1', true], true),
            visitProperties: $visitProperties,
            actionProperties: $actionProperties,
            performanceTimings: $this->performanceTimings($request, $actionType),
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

    /** @return array<string, float|int|string>|null */
    private function goal(int $siteId, int $goalId): ?array
    {
        $key = $siteId.':'.$goalId;
        if (! array_key_exists($key, $this->goalsBySiteAndId)) {
            $this->goalsBySiteAndId[$key] = $this->goals->findActive($siteId, $goalId);
        }

        return $this->goalsBySiteAndId[$key];
    }

    private function goalRevenue(mixed $value, float $default): float
    {
        if ($value === null) {
            $value = $default;
        }

        if (! is_scalar($value) || ! is_numeric($value) || ! is_finite((float) $value)) {
            throw new InvalidArgumentException('revenue must be numeric.');
        }

        $revenue = (float) $value;

        return abs($revenue) > 1_000_000_000_000 ? 0.0 : round($revenue, 2);
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

    private function boundedInteger(mixed $value, int $minimum, int $maximum, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
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
        if (! $this->hasCustomDimension($request)) {
            return [$visit, $action];
        }

        foreach ($this->dimensionsBySite[$siteId] ??= $this->dimensions->configuredForSite($siteId) as $dimension) {
            if (! ($dimension['active'] ?? false)) {
                continue;
            }

            $index = $dimension['index'] ?? null;
            $id = $dimension['idcustomdimension'] ?? null;
            $scope = $dimension['scope'] ?? null;
            if (! is_numeric($index) || (int) $index < 1
                || ! is_numeric($id) || (int) $id < 1
                || ! in_array($scope, ['visit', 'action'], true)) {
                continue;
            }

            $parameter = 'dimension'.(int) $id;
            if (! $request->exists($parameter)) {
                continue;
            }

            $value = $request->input($parameter);
            if (! is_scalar($value)) {
                throw new InvalidArgumentException("{$parameter} must be a string.");
            }

            $column = 'custom_dimension_'.(int) $index;
            if ($scope === 'visit') {
                $visit[$column] = $this->clean((string) $value, 250);
            } else {
                $action[$column] = $this->clean((string) $value, 250);
            }
        }

        return [$visit, $action];
    }

    /** @return array<string, string> */
    private function customVariables(mixed $input): array
    {
        if ($input === null || $input === '') {
            return [];
        }

        if (is_string($input)) {
            if (strlen($input) > 4_096) {
                throw new InvalidArgumentException('Custom variables are too large.');
            }

            try {
                $input = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new InvalidArgumentException('Custom variables must be valid JSON.');
            }
        }

        if (! is_array($input) || count($input) > 5) {
            throw new InvalidArgumentException('Custom variables must be a JSON object.');
        }

        $properties = [];
        foreach ($input as $index => $pair) {
            if (filter_var($index, FILTER_VALIDATE_INT) === false || (int) $index < 1 || (int) $index > 5
                || ! is_array($pair) || ! isset($pair[0], $pair[1]) || ! is_string($pair[0]) || ! is_scalar($pair[1])) {
                throw new InvalidArgumentException('Custom variable entries are invalid.');
            }

            $properties['custom_var_k'.(int) $index] = $this->clean($pair[0], 200);
            $properties['custom_var_v'.(int) $index] = $this->clean((string) $pair[1], 200);
        }

        return $properties;
    }

    /** @return array{int, string, string, bool} */
    private function referrerAttribution(Request $request, string $url, string $referrer, int $siteId): array
    {
        if ($this->ignoresReferrer($url)) {
            return [self::REFERRER_TYPE_DIRECT, '', '', true];
        }

        $campaignNames = $this->configuration->campaignNameParameters();
        $campaignKeywords = $this->configuration->campaignKeywordParameters();
        $campaign = $this->queryValue($url, $campaignNames, 70);
        $keyword = $this->queryValue($url, $campaignKeywords, 255);
        $requestCampaign = $this->requestValue($request, $campaignNames, 70);
        if ($requestCampaign !== null) {
            $campaign = $requestCampaign;
            $keyword = $this->requestValue($request, $campaignKeywords, 255);
        }

        if ($campaign !== null) {
            $referrerHost = parse_url($referrer, PHP_URL_HOST);
            $keyword ??= is_string($referrerHost) ? $referrerHost : '';

            return [
                self::REFERRER_TYPE_CAMPAIGN,
                mb_strtolower($campaign),
                mb_strtolower($this->clean($keyword, 255)),
                false,
            ];
        }

        $referrerHost = parse_url($referrer, PHP_URL_HOST);
        if (! is_string($referrerHost) || $referrerHost === '' || $this->belongsToSite($referrer, $siteId)) {
            return [self::REFERRER_TYPE_DIRECT, '', '', false];
        }

        return [self::REFERRER_TYPE_WEBSITE, $this->clean(mb_strtolower($referrerHost), 70), '', false];
    }

    private function ignoresReferrer(string $url): bool
    {
        $query = parse_url($url, PHP_URL_QUERY);

        return is_string($query)
            && $this->parameterValue($query, ['ignore_referrer', 'ignore_referer'], 1) === '1';
    }

    /** @param list<string> $parameters */
    private function queryValue(string $url, array $parameters, int $length): ?string
    {
        foreach ([PHP_URL_QUERY, PHP_URL_FRAGMENT] as $component) {
            $value = parse_url($url, $component);
            if (is_string($value)) {
                $result = $this->parameterValue($value, $parameters, $length);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /** @return array<string, int> */
    private function performanceTimings(Request $request, int $actionType): array
    {
        if ($actionType !== 1) {
            return [];
        }

        $parameters = [
            'pf_net' => 'time_network',
            'pf_srv' => 'time_server',
            'pf_tfr' => 'time_transfer',
            'pf_dm1' => 'time_dom_processing',
            'pf_dm2' => 'time_dom_completion',
            'pf_onl' => 'time_on_load',
        ];
        $timings = [];
        foreach ($parameters as $parameter => $column) {
            $value = $request->input($parameter);
            if ($value === null || $value === '') {
                continue;
            }

            if (! is_scalar($value)
                || ! is_numeric($value)
                || ! is_finite((float) $value)
                || (float) (int) $value !== (float) $value) {
                continue;
            }

            $timing = (int) $value;
            if ($timing === -1 || $timing > 16_777_215) {
                continue;
            }

            if ($timing < 0) {
                throw new InvalidArgumentException('Page performance timings must be non-negative milliseconds.');
            }

            $timings[$column] = $timing;
        }

        return $timings;
    }

    private function searchCount(mixed $value): ?int
    {
        if (! is_scalar($value)
            || ! is_numeric($value)
            || ! is_finite((float) $value)
            || (float) (int) $value !== (float) $value) {
            return null;
        }

        $count = (int) $value;

        return $count >= 0 && $count <= 4_294_967_295 ? $count : null;
    }

    /** @param list<string> $parameters */
    private function requestValue(Request $request, array $parameters, int $length): ?string
    {
        foreach ($parameters as $parameter) {
            $value = $this->optional($request->input($parameter), $length);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /** @param list<string> $parameters */
    private function parameterValue(string $input, array $parameters, int $length): ?string
    {
        parse_str($input, $values);
        foreach ($parameters as $parameter) {
            $value = $this->optional($values[$parameter] ?? null, $length);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function hasCustomDimension(Request $request): bool
    {
        foreach (array_keys($request->all()) as $name) {
            if (is_string($name) && preg_match('/^dimension[1-9][0-9]*$/D', $name) === 1) {
                return true;
            }
        }

        return false;
    }

    private function browserLanguage(Request $request): string
    {
        $language = $request->input('lang');
        if (! is_string($language) || $language === '') {
            $language = (string) $request->header('Accept-Language');
        }

        $language = strtolower(str_replace('_', '-', $this->clean($language, 512)));
        if (preg_match('/(?:^|,)([a-z]{2,3})(?:-[a-z]{4})?(-[a-z]{2})?/', $language, $matches) !== 1) {
            return $language === '' ? '' : 'xx';
        }

        return $matches[1].($matches[2] ?? '');
    }

    private function anonymisedReferrer(string $url, string $mode): string
    {
        if ($url === '' || $mode === '') {
            return $url;
        }

        if ($mode === 'exclude_all') {
            return '';
        }

        if ($mode === 'exclude_query') {
            return strtok($url, '?');
        }

        $parts = parse_url($url);
        if ($mode !== 'exclude_path' || ! is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            return $url;
        }

        return (isset($parts['scheme']) ? $parts['scheme'].'://' : '').$parts['host'].'/';
    }
}
