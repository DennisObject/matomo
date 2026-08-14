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

    public function isViewableSiteIdsRequest(): bool
    {
        return $this->module === 'API'
            && $this->method === 'SitesManager.getSitesIdWithAtLeastViewAccess';
    }

    public function isSiteGroupsRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSitesGroups';
    }

    public function isDefaultCurrencyRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getDefaultCurrency';
    }

    public function isDefaultTimezoneRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getDefaultTimezone';
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

    public function isExcludedReferrersRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getExcludedReferrers';
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
            authentication: $authentication,
        );
    }

    private static function siteId(Request $request, string $module, string $method): ?int
    {
        if ($module !== 'API' || ! in_array($method, [
            'SitesManager.getExcludedReferrers',
            'SitesManager.getSiteUrlsFromId',
        ], true)) {
            return null;
        }

        $value = self::nullableStringInput($request, 'idSite');

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
