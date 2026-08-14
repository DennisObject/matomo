<?php

declare(strict_types=1);

namespace App\Matomo\Api;

use App\Matomo\Api\Exceptions\ConflictingAuthenticationParameters;
use App\Matomo\Api\Exceptions\InvalidApiParameter;
use App\Matomo\Api\Exceptions\MissingApiParameter;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Authentication\SiteAccessRole;
use Illuminate\Http\Request;

final readonly class ApiRequest
{
    private function __construct(
        public string $module,
        public string $method,
        public string $format,
        public ?string $callback,
        public bool $serialize,
        public bool $convertToUnicode,
        public bool $showMetadata,
        public ?string $restrictSitesToLogin,
        public ?int $idSite,
        /** @var list<string>|null */
        public ?array $timezones,
        public ?string $ipRange,
        public ?string $pluginName,
        public ?string $siteUrl,
        public ?string $timezone,
        public ?string $countryCode,
        public ?bool $multipleTimezonesInCountry,
        public ?string $siteGroup,
        public bool $fetchAliasUrls,
        public ?string $sitePattern,
        public ?int $siteLimit,
        /** @var list<int> */
        public array $sitesToExclude,
        public ?SiteAccessRole $minimumSiteAccessRole,
        /** @var list<string> */
        public array $siteTypesToExclude,
        public ApiAuthentication $authentication,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return self::make($request, self::authentication($request));
    }

    public static function withoutAuthentication(Request $request): self
    {
        return new self(
            module: '',
            method: '',
            format: strtolower(self::safeStringInput($request, 'format', 'xml')),
            callback: self::safeNullableStringInput($request, 'callback')
                ?? self::safeNullableStringInput($request, 'jsoncallback'),
            serialize: self::booleanInput($request, 'serialize', false),
            convertToUnicode: self::booleanInput($request, 'convertToUnicode', true),
            showMetadata: self::booleanInput($request, 'showMetadata', true),
            restrictSitesToLogin: self::safeNullableStringInput($request, '_restrictSitesToLogin'),
            idSite: null,
            timezones: null,
            ipRange: null,
            pluginName: null,
            siteUrl: null,
            timezone: null,
            countryCode: null,
            multipleTimezonesInCountry: null,
            siteGroup: null,
            fetchAliasUrls: false,
            sitePattern: null,
            siteLimit: null,
            sitesToExclude: [],
            minimumSiteAccessRole: null,
            siteTypesToExclude: [],
            authentication: new ApiAuthentication(null, false, false, null),
        );
    }

    public function isVersionRequest(): bool
    {
        return $this->module === 'API'
            && in_array($this->method, ['API.getMatomoVersion', 'API.getPiwikVersion'], true);
    }

    public function isPhpVersionRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'API.getPhpVersion';
    }

    public function isClientIpRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'API.getIpFromHeader';
    }

    public function siteAccessRole(): ?SiteAccessRole
    {
        if ($this->module !== 'API') {
            return null;
        }

        return match ($this->method) {
            'SitesManager.getSitesIdWithViewAccess' => SiteAccessRole::View,
            'SitesManager.getSitesIdWithWriteAccess' => SiteAccessRole::Write,
            'SitesManager.getSitesIdWithAdminAccess' => SiteAccessRole::Admin,
            default => null,
        };
    }

    public function isAllSiteIdsRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getAllSitesId';
    }

    public function isAllSitesRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getAllSites';
    }

    public function isViewableSiteIdsRequest(): bool
    {
        return $this->module === 'API'
            && $this->method === 'SitesManager.getSitesIdWithAtLeastViewAccess';
    }

    public function isSiteGroupsRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSitesGroups';
    }

    public function isSitesFromGroupRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSitesFromGroup';
    }

    public function isAdminSitesRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSitesWithAdminAccess';
    }

    public function isMinimumAccessSitesRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSitesWithMinimumAccess';
    }

    public function isViewSitesRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSitesWithViewAccess';
    }

    public function isAtLeastViewSitesRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSitesWithAtLeastViewAccess';
    }

    public function isDefaultCurrencyRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getDefaultCurrency';
    }

    public function isCurrencySymbolsRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getCurrencySymbols';
    }

    public function isCurrencyListRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getCurrencyList';
    }

    public function isDefaultTimezoneRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getDefaultTimezone';
    }

    public function isTimezoneNameRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getTimezoneName';
    }

    public function isTimezoneListRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getTimezonesList';
    }

    public function isTimezoneSupportRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.isTimezoneSupportEnabled';
    }

    public function isWebsitesCountToDisplayRequest(): bool
    {
        return $this->module === 'API'
            && $this->method === 'SitesManager.getNumWebsitesToDisplayPerPage';
    }

    public function isSiteUrlsRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSiteUrlsFromId';
    }

    public function isSiteDetailsRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSiteFromId';
    }

    public function isExcludedReferrersRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getExcludedReferrers';
    }

    public function isExcludedQueryParametersRequest(): bool
    {
        return $this->module === 'API'
            && $this->method === 'SitesManager.getExcludedQueryParameters';
    }

    public function isGlobalExcludedQueryParametersRequest(): bool
    {
        return $this->module === 'API'
            && $this->method === 'SitesManager.getExcludedQueryParametersGlobal';
    }

    public function isQueryParameterExclusionTypeRequest(): bool
    {
        return $this->module === 'API'
            && $this->method === 'SitesManager.getExclusionTypeForQueryParams';
    }

    public function isUniqueSiteTimezonesRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getUniqueSiteTimezones';
    }

    public function isSiteIdsFromTimezonesRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSitesIdFromTimezones';
    }

    public function isIpRangeRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getIpsForRange';
    }

    public function isPluginActivatedRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'API.isPluginActivated';
    }

    public function isSiteIdFromUrlRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSitesIdFromSiteUrl';
    }

    public function hasSupportedFormat(): bool
    {
        return in_array(
            $this->format,
            ['console', 'csv', 'html', 'json', 'original', 'rss', 'tsv', 'xml'],
            true,
        );
    }

    private static function make(Request $request, ApiAuthentication $authentication): self
    {
        $module = self::stringInput($request, 'module');
        $method = self::stringInput($request, 'method');

        return new self(
            module: $module,
            method: $method,
            format: strtolower(self::stringInput($request, 'format', 'xml')),
            callback: self::nullableStringInput($request, 'callback')
                ?? self::nullableStringInput($request, 'jsoncallback'),
            serialize: self::booleanInput($request, 'serialize', false),
            convertToUnicode: self::booleanInput($request, 'convertToUnicode', true),
            showMetadata: self::booleanInput($request, 'showMetadata', true),
            restrictSitesToLogin: self::nullableStringInput($request, '_restrictSitesToLogin'),
            idSite: self::siteId($request, $module, $method),
            timezones: self::timezones($request, $module, $method),
            ipRange: self::ipRange($request, $module, $method),
            pluginName: self::pluginName($request, $module, $method),
            siteUrl: self::siteUrl($request, $module, $method),
            timezone: self::timezone($request, $module, $method),
            countryCode: self::timezoneCountryCode($request, $module, $method),
            multipleTimezonesInCountry: self::multipleTimezonesInCountry($request, $module, $method),
            siteGroup: self::siteGroup($request, $module, $method),
            fetchAliasUrls: self::fetchAliasUrls($request, $module, $method),
            sitePattern: self::sitePattern($request, $module, $method),
            siteLimit: self::siteLimit($request, $module, $method),
            sitesToExclude: self::sitesToExclude($request, $module, $method),
            minimumSiteAccessRole: self::minimumSiteAccessRole($request, $module, $method),
            siteTypesToExclude: self::siteTypesToExclude($request, $module, $method),
            authentication: $authentication,
        );
    }

    private static function siteId(Request $request, string $module, string $method): ?int
    {
        $requiredMethods = [
            'SitesManager.getExcludedQueryParameters',
            'SitesManager.getExcludedReferrers',
            'SitesManager.getSiteFromId',
            'SitesManager.getSiteUrlsFromId',
        ];
        $optionalMethods = [
            'SitesManager.getExcludedQueryParametersGlobal',
            'SitesManager.getExclusionTypeForQueryParams',
        ];

        if ($module !== 'API' || ! in_array($method, [
            ...$requiredMethods,
            ...$optionalMethods,
        ], true)) {
            return null;
        }

        $value = self::nullableStringInput($request, 'idSite');

        if (($value === null || $value === '') && in_array($method, $optionalMethods, true)) {
            return null;
        }

        if ($value === null || $value === '' || (string) (int) $value !== $value) {
            throw new MissingApiParameter('idSite');
        }

        return (int) $value;
    }

    /**
     * @return list<string>|null
     */
    private static function timezones(Request $request, string $module, string $method): ?array
    {
        if ($module !== 'API' || $method !== 'SitesManager.getSitesIdFromTimezones') {
            return null;
        }

        $query = $request->query->all();
        $post = $request->request->all();

        if (array_key_exists('timezones', $query)) {
            $value = $query['timezones'];
        } elseif (array_key_exists('timezones', $post)) {
            $value = $post['timezones'];
        } else {
            throw new MissingApiParameter('timezones');
        }

        $values = is_array($value) ? $value : explode(',', (string) $value);
        $timezones = [];

        foreach ($values as $timezone) {
            if (! is_scalar($timezone)) {
                throw new InvalidApiParameter('timezones');
            }

            $timezones[] = str_replace("\0", '', (string) $timezone);
        }

        return array_values(array_unique($timezones));
    }

    private static function ipRange(Request $request, string $module, string $method): ?string
    {
        if ($module !== 'API' || $method !== 'SitesManager.getIpsForRange') {
            return null;
        }

        $value = self::nullableStringInput($request, 'ipRange');

        if ($value === null) {
            throw new MissingApiParameter('ipRange');
        }

        return $value;
    }

    private static function pluginName(Request $request, string $module, string $method): ?string
    {
        if ($module !== 'API' || $method !== 'API.isPluginActivated') {
            return null;
        }

        $value = self::nullableStringInput($request, 'pluginName');

        if ($value === null) {
            throw new MissingApiParameter('pluginName');
        }

        return $value;
    }

    private static function siteUrl(Request $request, string $module, string $method): ?string
    {
        if ($module !== 'API' || $method !== 'SitesManager.getSitesIdFromSiteUrl') {
            return null;
        }

        $value = self::nullableStringInput($request, 'url');

        if ($value === null) {
            throw new MissingApiParameter('url');
        }

        return $value;
    }

    private static function timezone(Request $request, string $module, string $method): ?string
    {
        if ($module !== 'API' || $method !== 'SitesManager.getTimezoneName') {
            return null;
        }

        $value = self::nullableStringInput($request, 'timezone');

        if ($value === null) {
            throw new MissingApiParameter('timezone');
        }

        return $value;
    }

    private static function timezoneCountryCode(Request $request, string $module, string $method): ?string
    {
        return $module === 'API' && $method === 'SitesManager.getTimezoneName'
            ? self::nullableStringInput($request, 'countryCode')
            : null;
    }

    private static function multipleTimezonesInCountry(
        Request $request,
        string $module,
        string $method,
    ): ?bool {
        if ($module !== 'API' || $method !== 'SitesManager.getTimezoneName') {
            return null;
        }

        return self::booleanFromArray($request->query->all(), 'multipleTimezonesInCountry')
            ?? self::booleanFromArray($request->request->all(), 'multipleTimezonesInCountry');
    }

    private static function siteGroup(Request $request, string $module, string $method): ?string
    {
        return $module === 'API' && $method === 'SitesManager.getSitesFromGroup'
            ? trim(self::stringInput($request, 'group'))
            : null;
    }

    private static function fetchAliasUrls(Request $request, string $module, string $method): bool
    {
        if ($module !== 'API' || $method !== 'SitesManager.getSitesWithAdminAccess') {
            return false;
        }

        return self::booleanFromArray($request->query->all(), 'fetchAliasUrls')
            ?? self::booleanFromArray($request->request->all(), 'fetchAliasUrls')
            ?? false;
    }

    private static function sitePattern(Request $request, string $module, string $method): ?string
    {
        if (! self::supportsSiteListFilters($module, $method)) {
            return null;
        }

        $pattern = self::nullableStringInput($request, 'pattern');

        if ($method === 'SitesManager.getSitesWithMinimumAccess' && ($pattern === '' || $pattern === '0')) {
            return null;
        }

        return $pattern;
    }

    private static function siteLimit(Request $request, string $module, string $method): ?int
    {
        if (! self::supportsSiteLimit($module, $method)) {
            return null;
        }

        $value = self::nullableStringInput($request, 'limit');

        if ($value === null || $value === '' || (string) (int) $value !== $value || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return list<int>
     */
    private static function sitesToExclude(Request $request, string $module, string $method): array
    {
        if (! self::supportsSiteListFilters($module, $method)) {
            return [];
        }

        $query = $request->query->all();
        $post = $request->request->all();

        if (array_key_exists('sitesToExclude', $query)) {
            $value = $query['sitesToExclude'];
        } elseif (array_key_exists('sitesToExclude', $post)) {
            $value = $post['sitesToExclude'];
        } else {
            return [];
        }

        $values = is_array($value) ? $value : explode(',', (string) $value);
        $siteIds = [];

        foreach ($values as $siteId) {
            if (! is_scalar($siteId) || (string) (int) $siteId !== (string) $siteId) {
                throw new InvalidApiParameter('sitesToExclude');
            }

            $siteIds[] = (int) $siteId;
        }

        return array_values(array_unique($siteIds));
    }

    private static function minimumSiteAccessRole(
        Request $request,
        string $module,
        string $method,
    ): ?SiteAccessRole {
        if ($module !== 'API' || $method !== 'SitesManager.getSitesWithMinimumAccess') {
            return null;
        }

        $permission = self::nullableStringInput($request, 'permission');

        if ($permission === null || $permission === '') {
            throw new MissingApiParameter('permission');
        }

        return SiteAccessRole::tryFrom(strtolower($permission))
            ?? throw new InvalidApiParameter('permission', 'Invalid permission provided');
    }

    /**
     * @return list<string>
     */
    private static function siteTypesToExclude(Request $request, string $module, string $method): array
    {
        if ($module !== 'API' || $method !== 'SitesManager.getSitesWithMinimumAccess') {
            return [];
        }

        $query = $request->query->all();
        $post = $request->request->all();

        if (array_key_exists('siteTypesToExclude', $query)) {
            $value = $query['siteTypesToExclude'];
        } elseif (array_key_exists('siteTypesToExclude', $post)) {
            $value = $post['siteTypesToExclude'];
        } else {
            return [];
        }

        $values = is_array($value) ? $value : explode(',', (string) $value);
        $siteTypes = [];

        foreach ($values as $siteType) {
            if (! is_scalar($siteType)) {
                throw new InvalidApiParameter('siteTypesToExclude');
            }

            $siteTypes[] = str_replace("\0", '', (string) $siteType);
        }

        return array_values(array_unique($siteTypes));
    }

    private static function supportsSiteListFilters(string $module, string $method): bool
    {
        return $module === 'API' && in_array($method, [
            'SitesManager.getSitesWithAdminAccess',
            'SitesManager.getSitesWithMinimumAccess',
        ], true);
    }

    private static function supportsSiteLimit(string $module, string $method): bool
    {
        return self::supportsSiteListFilters($module, $method)
            || ($module === 'API' && $method === 'SitesManager.getSitesWithAtLeastViewAccess');
    }

    private static function authentication(Request $request): ApiAuthentication
    {
        $authorization = $request->headers->get('Authorization');
        $headerToken = is_string($authorization) && str_starts_with($authorization, 'Bearer ')
            ? substr($authorization, 7)
            : null;
        $postToken = self::stringFromArray($request->request->all(), 'token_auth');
        $queryToken = self::stringFromArray($request->query->all(), 'token_auth');
        $postForceSession = self::booleanFromArray($request->request->all(), 'force_api_session');
        $queryForceSession = self::booleanFromArray($request->query->all(), 'force_api_session');

        $providedTokens = array_filter(
            [$headerToken, $postToken, $queryToken],
            static fn (?string $token): bool => ! empty($token),
        );

        if (count(array_unique($providedTokens)) > 1) {
            throw new ConflictingAuthenticationParameters;
        }

        if (
            $postForceSession !== null
            && $queryForceSession !== null
            && $postForceSession !== $queryForceSession
        ) {
            throw new ConflictingAuthenticationParameters;
        }

        if ($headerToken !== null) {
            return new ApiAuthentication($headerToken, true, false, self::sessionId($request));
        }

        if ($postToken !== null && $postToken !== '') {
            return new ApiAuthentication(
                $postToken,
                true,
                $postForceSession ?? false,
                self::sessionId($request),
            );
        }

        return new ApiAuthentication(
            $queryToken,
            false,
            $queryToken !== null && $queryToken !== '' && ($queryForceSession ?? false),
            self::sessionId($request),
        );
    }

    private static function stringInput(Request $request, string $key, string $default = ''): string
    {
        return self::nullableStringInput($request, $key) ?? $default;
    }

    private static function nullableStringInput(Request $request, string $key): ?string
    {
        $queryValue = self::stringFromArray($request->query->all(), $key);

        return $queryValue ?? self::stringFromArray($request->request->all(), $key);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function stringFromArray(array $input, string $key): ?string
    {
        if (! array_key_exists($key, $input)) {
            return null;
        }

        $value = $input[$key];

        if (! is_scalar($value)) {
            throw new InvalidApiParameter($key);
        }

        return str_replace("\0", '', (string) $value);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function booleanFromArray(array $input, string $key): ?bool
    {
        if (! array_key_exists($key, $input)) {
            return null;
        }

        $value = $input[$key];

        if (in_array($value, [true, 1, '1'], true) || (is_string($value) && strtolower($value) === 'true')) {
            return true;
        }

        if (in_array($value, [false, 0, '0'], true) || (is_string($value) && strtolower($value) === 'false')) {
            return false;
        }

        return false;
    }

    private static function sessionId(Request $request): ?string
    {
        $sessionId = $request->cookies->get('MATOMO_SESSID');

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    private static function safeStringInput(Request $request, string $key, string $default = ''): string
    {
        return self::safeNullableStringInput($request, $key) ?? $default;
    }

    private static function safeNullableStringInput(Request $request, string $key): ?string
    {
        $value = $request->query->all()[$key] ?? $request->request->all()[$key] ?? null;

        return is_scalar($value) ? str_replace("\0", '', (string) $value) : null;
    }

    private static function booleanInput(Request $request, string $key, bool $default): bool
    {
        $value = self::safeNullableStringInput($request, $key);

        return match (strtolower($value ?? '')) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => $default,
        };
    }
}
