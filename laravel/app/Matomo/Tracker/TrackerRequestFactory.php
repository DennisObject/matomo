<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use App\Matomo\CustomDimensions\CustomDimensionRepository;
use App\Matomo\Goals\GoalRepository;
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
        private TrackerSettings $settings,
        private GoalRepository $goals,
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
        $search = $this->optional($request->input('search'), 255);
        $contentName = $this->optionalTrimmed($request->input('c_n'), 255);
        if (is_string($request->input('c_n')) && $request->input('c_n') !== '' && $contentName === null) {
            throw new InvalidArgumentException('c_n must not contain only whitespace.');
        }

        if (($eventCategory === null) !== ($eventAction === null)) {
            throw new InvalidArgumentException('e_c and e_a must be provided together.');
        }

        if (count(array_filter([$eventCategory, $download, $outlink, $search, $contentName], static fn (?string $value): bool => $value !== null)) > 1) {
            throw new InvalidArgumentException('Only one tracker action type may be provided.');
        }

        $actionType = $eventCategory !== null ? 10 : ($download !== null ? 3 : ($outlink !== null ? 2 : ($search !== null ? 8 : ($contentName !== null ? 13 : 1))));
        $url = $download ?? $outlink ?? $request->input('url', '');
        if (! is_string($url) || strlen($url) > 4096 || filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException('url must be a valid HTTP or HTTPS URL.');
        }

        $visitorId = $request->input('_id', '');
        if (! is_string($visitorId) || preg_match('/^[a-f0-9]{16}$/iD', $visitorId) !== 1) {
            $visitorId = bin2hex(random_bytes(8));
        }

        $actionName = $search ?? $request->input('action_name', '');

        $eventValue = $request->input('e_v');
        if ($eventValue !== null && ! is_numeric($eventValue)) {
            throw new InvalidArgumentException('e_v must be numeric.');
        }

        $searchCount = $request->input('search_count');
        if ($searchCount !== null && (filter_var($searchCount, FILTER_VALIDATE_INT) === false || (int) $searchCount < 0)) {
            throw new InvalidArgumentException('search_count must be a non-negative integer.');
        }

        $goalId = $request->input('idgoal');
        $goal = null;
        if ($goalId !== null) {
            if (filter_var($goalId, FILTER_VALIDATE_INT) === false || (int) $goalId < 1) {
                throw new InvalidArgumentException('idgoal must be a positive integer.');
            }

            $goal = $this->goals->findActive((int) $siteId, (int) $goalId);
            if ($goal === null) {
                throw new InvalidArgumentException('The requested goal does not exist.');
            }
        }

        $goalRevenue = $request->input('revenue');
        if ($goalRevenue !== null && (! is_numeric($goalRevenue) || abs((float) $goalRevenue) > 1_000_000_000_000)) {
            throw new InvalidArgumentException('revenue must be numeric.');
        }

        $orderId = $this->optionalTrimmed($request->input('ec_id'), 100);
        if ($orderId !== null && $goal !== null) {
            throw new InvalidArgumentException('A request cannot record a goal and an ecommerce order together.');
        }

        if (($orderId !== null || $request->has('ec_items')) && $goalRevenue === null) {
            throw new InvalidArgumentException('revenue is required for ecommerce requests.');
        }

        $ecommerceValues = [];
        foreach (['ec_st', 'ec_tx', 'ec_sh', 'ec_dt'] as $parameter) {
            $value = $request->input($parameter);
            if ($value !== null && (! is_numeric($value) || abs((float) $value) > 1_000_000_000_000)) {
                throw new InvalidArgumentException('Ecommerce revenue values must be valid numbers.');
            }

            $ecommerceValues[$parameter] = $value === null ? null : (float) $value;
        }

        $referrer = $this->optional($request->input('urlref'), 4096);
        if ($referrer !== null && (filter_var($referrer, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($referrer, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            throw new InvalidArgumentException('urlref must be a valid HTTP or HTTPS URL.');
        }

        [$referrerType, $referrerName, $referrerKeyword] = $this->referrerAttribution(
            $request,
            $url,
            $referrer ?? '',
        );

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
            $this->optional($request->input('search_cat'), 255),
            $searchCount === null ? null : (int) $searchCount,
            $contentName,
            $this->optionalTrimmed($request->input('c_p'), 255),
            $this->optionalTrimmed($request->input('c_t'), 4096),
            $this->optionalTrimmed($request->input('c_i'), 255),
            $goal === null ? null : (int) $goalId,
            $orderId !== null || $request->has('ec_items')
                ? (float) $goalRevenue
                : ($goal === null ? null : ($goalRevenue === null ? (float) ($goal['revenue'] ?? 0) : (float) $goalRevenue)),
            (int) ($goal['allow_multiple'] ?? 0) === 1,
            $this->automaticGoals(
                (int) $siteId,
                $actionType,
                $url,
                is_string($actionName) ? $actionName : '',
                $eventCategory,
                $eventAction,
                $this->optional($request->input('e_n'), 255),
                $eventValue === null ? null : (float) $eventValue,
            ),
            $orderId,
            $ecommerceValues['ec_st'],
            $ecommerceValues['ec_tx'],
            $ecommerceValues['ec_sh'],
            $ecommerceValues['ec_dt'],
            $orderId === null && $request->has('ec_items'),
            $this->ecommerceItems($request),
            $this->optional($request->input('uid'), 200),
            $referrer ?? '',
            $referrerType,
            $referrerName,
            $referrerKeyword,
            $this->optional($request->input('lang'), 20) ?? '',
            sprintf('%02d:%02d:%02d', $hour, $minute, $second),
            $resolution,
            $request->boolean('cookie', false),
            $request->boolean('ping', false),
            $visitProperties,
            $actionProperties,
            $this->performanceTimings($request),
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

    private function optionalTrimmed(mixed $value, int $length): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $length) : null;
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

    /** @return array{int, string, string} */
    private function referrerAttribution(Request $request, string $url, string $referrer): array
    {
        $campaign = $this->optional($request->input('_rcn'), 70)
            ?? $this->queryValue($url, $this->settings->campaignNameParameters(), 70);
        $keyword = $this->optional($request->input('_rck'), 255)
            ?? $this->queryValue($url, $this->settings->campaignKeywordParameters(), 255)
            ?? '';
        if ($campaign !== null) {
            return [6, $campaign, $keyword];
        }

        $referrerHost = parse_url($referrer, PHP_URL_HOST);
        $currentHost = parse_url($url, PHP_URL_HOST);
        if (! is_string($referrerHost) || $referrerHost === ''
            || (is_string($currentHost) && strcasecmp($referrerHost, $currentHost) === 0)) {
            return [1, '', ''];
        }

        return [3, mb_substr(strtolower($referrerHost), 0, 70), ''];
    }

    /** @param list<string> $parameters */
    private function queryValue(string $url, array $parameters, int $length): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $values);
        foreach ($parameters as $parameter) {
            $value = $values[$parameter] ?? null;
            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, $length);
            }
        }

        return null;
    }

    /** @return array<string, int> */
    private function performanceTimings(Request $request): array
    {
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

            if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0 || (int) $value > 3_600_000) {
                throw new InvalidArgumentException('Page performance timings must be non-negative milliseconds.');
            }

            $timings[$column] = (int) $value;
        }

        return $timings;
    }

    /** @return list<array{sku: string, name: string, categories: list<string>, price: float, quantity: int}> */
    private function ecommerceItems(Request $request): array
    {
        $items = $request->input('ec_items');
        if (is_string($items) && $items !== '') {
            $items = json_decode($items, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('ec_items must contain valid JSON.');
            }
        }

        if ($items === null || $items === '') {
            return [];
        }

        if (! is_array($items) || count($items) > 1_000) {
            throw new InvalidArgumentException('ec_items must be an array.');
        }

        $clean = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item[0]) || ! is_scalar($item[0]) || trim((string) $item[0]) === '') {
                throw new InvalidArgumentException('Every ecommerce item must contain a SKU.');
            }

            $name = isset($item[1]) && is_scalar($item[1]) ? trim((string) $item[1]) : '';
            $categories = $item[2] ?? [];
            $categories = is_array($categories) ? $categories : [$categories];
            $categories = array_values(array_slice(array_filter(array_map(
                static fn (mixed $category): string => is_scalar($category) ? trim((string) $category) : '',
                $categories,
            )), 0, 5));
            $price = $item[3] ?? 0;
            $quantity = $item[4] ?? 1;
            if (! is_numeric($price) || abs((float) $price) > 1_000_000_000_000
                || filter_var($quantity, FILTER_VALIDATE_INT) === false || (int) $quantity < 1) {
                throw new InvalidArgumentException('Ecommerce item price or quantity is invalid.');
            }

            $clean[] = [
                'sku' => mb_substr(trim((string) $item[0]), 0, 255),
                'name' => mb_substr($name, 0, 255),
                'categories' => array_map(static fn (string $category): string => mb_substr($category, 0, 255), $categories),
                'price' => (float) $price,
                'quantity' => (int) $quantity,
            ];
        }

        return $clean;
    }

    /** @return list<array{id: int, revenue: float, allowMultiple: bool}> */
    private function automaticGoals(
        int $siteId,
        int $actionType,
        string $url,
        string $title,
        ?string $eventCategory,
        ?string $eventAction,
        ?string $eventName,
        ?float $eventValue,
    ): array {
        $values = match ($actionType) {
            1 => ['url' => $url, 'title' => $title],
            2 => ['external_website' => $url],
            3 => ['file' => $url],
            10 => ['event_category' => $eventCategory, 'event_action' => $eventAction, 'event_name' => $eventName],
            default => [],
        };
        $matches = [];
        foreach ($this->goals->activeForSites([$siteId]) as $goal) {
            $attribute = $goal['match_attribute'] ?? null;
            $value = is_string($attribute) ? ($values[$attribute] ?? null) : null;
            if (! is_string($value) || ! $this->goalPatternMatches($goal, $value)) {
                continue;
            }

            $matches[] = [
                'id' => (int) ($goal['idgoal'] ?? 0),
                'revenue' => (int) ($goal['event_value_as_revenue'] ?? 0) === 1 && $eventValue !== null
                    ? $eventValue
                    : (float) ($goal['revenue'] ?? 0),
                'allowMultiple' => (int) ($goal['allow_multiple'] ?? 0) === 1,
            ];
        }

        return array_values(array_filter($matches, static fn (array $goal): bool => $goal['id'] > 0));
    }

    /** @param array<string, float|int|string> $goal */
    private function goalPatternMatches(array $goal, string $value): bool
    {
        $pattern = (string) ($goal['pattern'] ?? '');
        $caseSensitive = (int) ($goal['case_sensitive'] ?? 0) === 1;
        $subject = $caseSensitive ? $value : mb_strtolower($value);
        $needle = $caseSensitive ? $pattern : mb_strtolower($pattern);

        return match ((string) ($goal['pattern_type'] ?? 'contains')) {
            'exact' => $subject === $needle,
            'regex' => @preg_match('~'.str_replace('~', '\\~', $pattern).'~'.($caseSensitive ? '' : 'i'), $value) === 1,
            default => str_contains($subject, $needle),
        };
    }
}
