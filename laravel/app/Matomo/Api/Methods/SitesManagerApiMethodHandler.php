<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Goals\SiteTrackerCacheInvalidator;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Options\OptionRepository;
use App\Matomo\Sites\ConsentManagerDetector;
use App\Matomo\Sites\CurrencyProvider;
use App\Matomo\Sites\Events\SiteRemovalWarningsCollecting;
use App\Matomo\Sites\QueryParameterExclusionPolicy;
use App\Matomo\Sites\SiteDetailsPresenter;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Sites\SiteRuntimeSettings;
use App\Matomo\Sites\SiteSettingsProvider;
use App\Matomo\Sites\SiteTrackingCodeGenerator;
use App\Matomo\Sites\TimezoneProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use Matomo\Network\IPUtils;

final readonly class SitesManagerApiMethodHandler implements ApiMethodHandler
{
    private const string DEFAULT_SEARCH_KEYWORD_PARAMETERS = 'q,query,s,search,searchword,k,keyword,keywords';

    private const string DEFAULT_CURRENCY_OPTION = 'SitesManager_DefaultCurrency';

    private const string DEFAULT_TIMEZONE_OPTION = 'SitesManager_DefaultTimezone';

    /**
     * @var array<string, array{name: string, access: 'admin'|'view', default: bool|string}>
     */
    private const array GLOBAL_OPTIONS = [
        'SitesManager.getSearchKeywordParametersGlobal' => [
            'name' => 'SitesManager_SearchKeywordParameters',
            'access' => 'admin',
            'default' => self::DEFAULT_SEARCH_KEYWORD_PARAMETERS,
        ],
        'SitesManager.getSearchCategoryParametersGlobal' => [
            'name' => 'SitesManager_SearchCategoryParameters',
            'access' => 'admin',
            'default' => false,
        ],
        'SitesManager.getExcludedUserAgentsGlobal' => [
            'name' => 'SitesManager_ExcludedUserAgentsGlobal',
            'access' => 'admin',
            'default' => false,
        ],
        'SitesManager.getExcludedReferrersGlobal' => [
            'name' => 'SitesManager_ExcludedReferrersGlobal',
            'access' => 'admin',
            'default' => '',
        ],
        'SitesManager.getKeepURLFragmentsGlobal' => [
            'name' => 'SitesManager_KeepURLFragmentsGlobal',
            'access' => 'view',
            'default' => false,
        ],
        'SitesManager.getExcludedIpsGlobal' => [
            'name' => 'SitesManager_ExcludedIpsGlobal',
            'access' => 'admin',
            'default' => false,
        ],
    ];

    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private Dispatcher $events,
        private ApiResponseFactory $responses,
        private SiteRepository $sites,
        private OptionRepository $options,
        private MutableOptionRepository $mutableOptions,
        private TrackerCacheInvalidator $trackerCache,
        private SiteTrackerCacheInvalidator $siteTrackerCache,
        private SiteRuntimeSettings $runtime,
        private CurrencyProvider $currencies,
        private QueryParameterExclusionPolicy $queryParameterExclusions,
        private LanguageResolver $languages,
        private TimezoneProvider $timezones,
        private SiteDetailsPresenter $siteDetails,
        private ConsentManagerDetector $consentManagers,
        private SiteTrackingCodeGenerator $trackingCodes,
        private SiteSettingsProvider $siteSettings,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->siteAccessRole() !== null
            || $request->isAllSiteIdsRequest()
            || $request->isAllSitesRequest()
            || $request->isViewableSiteIdsRequest()
            || $request->isSiteGroupsRequest()
            || $request->isSitesFromGroupRequest()
            || $request->isAdminSitesRequest()
            || $request->isMinimumAccessSitesRequest()
            || $request->isViewSitesRequest()
            || $request->isAtLeastViewSitesRequest()
            || $request->isSiteRemovalWarningsRequest()
            || $request->isPatternMatchSitesRequest()
            || $request->isConsentManagerDetectionRequest()
            || $request->isDefaultCurrencyRequest()
            || $request->isDefaultCurrencyUpdateRequest()
            || $request->isCurrencySymbolsRequest()
            || $request->isCurrencyListRequest()
            || $request->isDefaultTimezoneRequest()
            || $request->isDefaultTimezoneUpdateRequest()
            || $request->isTimezoneNameRequest()
            || $request->isTimezoneListRequest()
            || $request->isTimezoneSupportRequest()
            || $request->isWebsitesCountToDisplayRequest()
            || $request->isSiteUrlsRequest()
            || $request->isSiteDetailsRequest()
            || $request->isExcludedReferrersRequest()
            || $request->isExcludedQueryParametersRequest()
            || $request->isGlobalExcludedQueryParametersRequest()
            || $request->isQueryParameterExclusionTypeRequest()
            || $request->isUniqueSiteTimezonesRequest()
            || $request->isSiteIdsFromTimezonesRequest()
            || $request->isIpRangeRequest()
            || $request->isSiteIdFromUrlRequest()
            || $request->isSitesManagerGlobalSettingsRequest()
            || $request->isSiteAliasMutationRequest()
            || $request->isSiteGroupRenameRequest()
            || $request->isSitesManagerTrackingCodeRequest()
            || $request->isSiteSettingsRequest()
            || $this->globalOption($request) !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The SitesManager API handler does not support this method.');
        }

        if ($request->isSitesManagerGlobalSettingsRequest()) {
            return $this->setGlobalSettings($request);
        }

        if ($request->isSiteAliasMutationRequest()) {
            return $this->mutateSiteAliases($request);
        }

        if ($request->isSiteGroupRenameRequest()) {
            return $this->renameSiteGroup($request);
        }

        if ($request->isSitesManagerTrackingCodeRequest()) {
            $tracking = $request->sitesManagerTrackingCode
                ?? throw new LogicException('The tracking-code parameters were not parsed.');

            if ($request->method === 'SitesManager.getJavascriptTag'
                && ! $this->authorizer->hasViewAccessToSite($request->authentication, $tracking->siteId)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires 'view' access for the website id = {$tracking->siteId}.",
                    401,
                );
            }

            $code = $request->method === 'SitesManager.getJavascriptTag'
                ? $this->trackingCodes->javascript($tracking, $httpRequest->root())
                : $this->trackingCodes->image($tracking, $httpRequest->isSecure());

            return $this->responses->scalar($request, $code);
        }

        if ($request->isSiteSettingsRequest()) {
            $idSite = $request->idSite ?? throw new LogicException('The site ID was not parsed.');
            $adminSites = $this->authorizer->siteIdsWithRole(
                $request->authentication,
                SiteAccessRole::Admin,
            );

            if (! in_array($idSite, $adminSites, true)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires 'admin' access for the website id = {$idSite}.",
                    401,
                );
            }

            return $this->responses->rows(
                $request,
                $this->siteSettings->metadata(
                    $idSite,
                    $this->languages->resolve($httpRequest, $request->authentication),
                ),
            );
        }

        if ($request->isCurrencySymbolsRequest()) {
            return $this->responses->row($request, $this->currencies->symbols());
        }

        if ($request->isCurrencyListRequest()) {
            $language = $this->languages->resolve($httpRequest, $request->authentication);

            return $this->responses->row($request, $this->currencies->names($language));
        }

        if ($request->isTimezoneNameRequest()) {
            $language = $this->languages->resolve($httpRequest, $request->authentication);

            return $this->responses->scalar(
                $request,
                $this->timezones->name(
                    $request->timezone ?? throw new LogicException('The timezone was not parsed.'),
                    $language,
                    $request->countryCode,
                    $request->multipleTimezonesInCountry,
                ),
            );
        }

        if ($request->isTimezoneListRequest()) {
            $language = $this->languages->resolve($httpRequest, $request->authentication);

            return $this->responses->structured(
                $request,
                $this->timezones->all($language, $this->runtime->timezoneSupportEnabled()),
            );
        }

        if ($request->isSiteDetailsRequest()) {
            $idSite = $request->idSite ?? throw new LogicException('The site ID was not parsed.');

            if (! $this->authorizer->hasViewAccessToSite($request->authentication, $idSite)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires 'view' access for the website id = {$idSite}.",
                    401,
                );
            }

            $site = $this->sites->details($idSite);

            if ($site === []) {
                return $this->responses->error(
                    $request,
                    "An unexpected website was found in the request: website id was set to '{$idSite}' .",
                    500,
                );
            }

            return $this->responses->row(
                $request,
                $this->siteDetails->present(
                    $site,
                    $this->languages->resolve($httpRequest, $request->authentication),
                    $this->authorizer->hasSuperUserAccess($request->authentication),
                ),
            );
        }

        if ($request->isAllSitesRequest()) {
            if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires a 'superuser' access.",
                    401,
                );
            }

            $language = $this->languages->resolve($httpRequest, $request->authentication);
            $sites = [];

            foreach ($this->sites->allDetails() as $idSite => $site) {
                $sites[$idSite] = $this->siteDetails->present($site, $language, true);
            }

            return $this->responses->keyedRows($request, $sites);
        }

        if ($request->isSitesFromGroupRequest()) {
            if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires a 'superuser' access.",
                    401,
                );
            }

            $language = $this->languages->resolve($httpRequest, $request->authentication);
            $sites = array_map(
                fn (array $site): array => $this->siteDetails->present($site, $language, true),
                $this->sites->detailsInGroup($request->siteGroup ?? ''),
            );

            return $this->responses->rows($request, $sites);
        }

        if ($request->isAdminSitesRequest()) {
            $idSites = array_values(array_diff(
                $this->authorizer->siteIdsWithRole($request->authentication, SiteAccessRole::Admin),
                $request->sitesToExclude,
            ));

            if ($idSites === []) {
                return $this->responses->rows($request, []);
            }

            $siteRecords = $this->sites->detailsForIds(
                $idSites,
                $request->sitePattern,
                $request->siteLimit,
            );
            $language = $this->languages->resolve($httpRequest, $request->authentication);
            $includeCreator = $this->authorizer->hasSuperUserAccess($request->authentication);
            $aliasUrls = $request->fetchAliasUrls
                ? $this->sites->aliasUrlsForIds(array_map(
                    static fn (array $site): int => (int) ($site['idsite'] ?? 0),
                    $siteRecords,
                ))
                : [];
            $sites = [];

            foreach ($siteRecords as $site) {
                $site = $this->siteDetails->present($site, $language, $includeCreator);

                if ($request->fetchAliasUrls) {
                    $idSite = $site['idsite'] ?? null;
                    $mainUrl = $site['main_url'] ?? null;
                    $sites[] = [...$site, 'alias_urls' => [
                        ...(is_string($mainUrl) ? [$mainUrl] : []),
                        ...((is_int($idSite) || is_string($idSite)) ? ($aliasUrls[(int) $idSite] ?? []) : []),
                    ]];

                    continue;
                }

                $sites[] = $site;
            }

            return $this->responses->rows($request, $sites);
        }

        if ($request->isMinimumAccessSitesRequest()) {
            $role = $request->minimumSiteAccessRole
                ?? throw new LogicException('The minimum site access role was not parsed.');
            $idSites = array_values(array_diff(
                $this->authorizer->siteIdsWithMinimumRole($request->authentication, $role),
                $request->sitesToExclude,
            ));

            if ($idSites === []) {
                return $this->responses->rows($request, []);
            }

            $language = $this->languages->resolve($httpRequest, $request->authentication);
            $includeCreator = $this->authorizer->hasSuperUserAccess($request->authentication);
            $sites = array_map(
                fn (array $site): array => $this->siteDetails->present($site, $language, $includeCreator),
                $this->sites->detailsForIds(
                    $idSites,
                    $request->sitePattern,
                    $request->siteLimit,
                    $request->siteTypesToExclude,
                ),
            );

            return $this->responses->rows($request, $sites);
        }

        if ($request->isViewSitesRequest()) {
            $idSites = $this->authorizer->siteIdsWithRole(
                $request->authentication,
                SiteAccessRole::View,
            );

            if ($idSites === []) {
                return $this->responses->rows($request, []);
            }

            $language = $this->languages->resolve($httpRequest, $request->authentication);
            $sites = array_map(
                fn (array $site): array => $this->siteDetails->present($site, $language, false),
                $this->sites->detailsForIds($idSites),
            );

            return $this->responses->rows($request, $sites);
        }

        if ($request->isAtLeastViewSitesRequest()) {
            $idSites = $this->authorizer->siteIdsWithAtLeastViewAccess(
                $request->authentication,
                $request->restrictSitesToLogin,
            );

            if ($idSites === []) {
                return $this->responses->rows($request, []);
            }

            $language = $this->languages->resolve($httpRequest, $request->authentication);
            $includeCreator = $this->authorizer->hasSuperUserAccess($request->authentication);
            $sites = array_map(
                fn (array $site): array => $this->siteDetails->present($site, $language, $includeCreator),
                $this->sites->detailsForIds($idSites, null, $request->siteLimit),
            );

            return $this->responses->rows($request, $sites);
        }

        if ($request->isSiteRemovalWarningsRequest()) {
            if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires a 'superuser' access.",
                    401,
                );
            }

            $event = new SiteRemovalWarningsCollecting(
                $request->idSite ?? throw new LogicException('The site ID was not parsed.'),
            );
            $this->events->dispatch($event);

            return $this->responses->values($request, $event->messages);
        }

        if ($request->isPatternMatchSitesRequest()) {
            $idSites = array_values(array_diff(
                $this->authorizer->siteIdsWithAtLeastViewAccess($request->authentication),
                $request->sitesToExclude,
            ));

            if ($idSites === []) {
                return $this->responses->rows($request, []);
            }

            $language = $this->languages->resolve($httpRequest, $request->authentication);
            $includeCreator = $this->authorizer->hasSuperUserAccess($request->authentication);
            $sites = array_map(
                fn (array $site): array => $this->siteDetails->present($site, $language, $includeCreator),
                $this->sites->detailsForIds(
                    $idSites,
                    $request->sitePattern ?? throw new LogicException('The site pattern was not parsed.'),
                    $request->siteLimit,
                ),
            );

            return $this->responses->rows($request, $sites);
        }

        if ($request->isConsentManagerDetectionRequest()) {
            $idSite = $request->idSite ?? throw new LogicException('The site ID was not parsed.');

            if (! $this->authorizer->hasViewAccessToSite($request->authentication, $idSite)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires 'view' access for the website id = {$idSite}.",
                    401,
                );
            }

            $url = $this->sites->mainUrl($idSite);
            $result = $url === null
                ? null
                : $this->consentManagers->detect(
                    $url,
                    $request->siteContentTimeout
                        ?? throw new LogicException('The site content timeout was not parsed.'),
                );

            return $result === null
                ? $this->responses->success($request)
                : $this->responses->row($request, $result);
        }

        $role = $request->siteAccessRole();

        if ($role !== null) {
            return $this->responses->values(
                $request,
                $this->authorizer->siteIdsWithRole($request->authentication, $role),
            );
        }

        if ($request->isViewableSiteIdsRequest()) {
            return $this->responses->values(
                $request,
                $this->authorizer->siteIdsWithAtLeastViewAccess(
                    $request->authentication,
                    $request->restrictSitesToLogin,
                ),
            );
        }

        if ($request->isSiteGroupsRequest()) {
            if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires a 'superuser' access.",
                    401,
                );
            }

            return $this->responses->values($request, $this->sites->groups());
        }

        if ($request->isDefaultTimezoneRequest()) {
            return $this->responses->scalar(
                $request,
                $this->optionOrDefault(self::DEFAULT_TIMEZONE_OPTION, 'UTC'),
            );
        }

        if ($request->isDefaultTimezoneUpdateRequest()) {
            if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires a 'superuser' access.",
                    401,
                );
            }

            $timezone = $request->timezone
                ?? throw new LogicException('The default timezone was not parsed.');
            $validTimezones = array_merge(...array_values($this->timezones->all('en', true)));

            if (! array_key_exists($timezone, $validTimezones)) {
                return $this->responses->error(
                    $request,
                    "The timezone \"{$timezone}\" is not valid. Please enter a valid timezone.",
                    400,
                );
            }

            $this->mutableOptions->set(self::DEFAULT_TIMEZONE_OPTION, $timezone);

            return $this->responses->scalar($request, true);
        }

        if ($request->isDefaultCurrencyRequest()) {
            if (! $this->authorizer->hasSomeAdminAccess($request->authentication)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires admin access for at least one website.",
                    401,
                );
            }

            return $this->responses->scalar(
                $request,
                $this->optionOrDefault(self::DEFAULT_CURRENCY_OPTION, 'USD'),
            );
        }

        if ($request->isDefaultCurrencyUpdateRequest()) {
            if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires a 'superuser' access.",
                    401,
                );
            }

            $currency = $request->defaultCurrency
                ?? throw new LogicException('The default currency was not parsed.');

            if (! array_key_exists($currency, $this->currencies->symbols())) {
                return $this->responses->error(
                    $request,
                    "The currency \"{$currency}\" is not valid. Please enter a valid currency symbol (eg. USD, EUR, etc.)",
                    400,
                );
            }

            $this->mutableOptions->set(self::DEFAULT_CURRENCY_OPTION, $currency);

            return $this->responses->scalar($request, true);
        }

        if ($request->isTimezoneSupportRequest() || $request->isWebsitesCountToDisplayRequest()) {
            if (! $this->authorizer->hasSomeViewAccess($request->authentication)) {
                return $this->responses->error(
                    $request,
                    'You must have view access to at least one website.',
                    401,
                );
            }

            return $this->responses->scalar(
                $request,
                $request->isTimezoneSupportRequest()
                    ? $this->runtime->timezoneSupportEnabled()
                    : $this->runtime->websitesCountToDisplay(),
            );
        }

        if ($request->isSiteUrlsRequest()) {
            $idSite = $request->idSite ?? throw new LogicException('The site ID was not parsed.');

            if (! $this->authorizer->hasViewAccessToSite($request->authentication, $idSite)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires 'view' access for the website id = {$idSite}.",
                    401,
                );
            }

            return $this->responses->values($request, $this->sites->urls($idSite));
        }

        if ($request->isExcludedReferrersRequest()) {
            $idSite = $request->idSite ?? throw new LogicException('The site ID was not parsed.');

            if (! $this->authorizer->hasViewAccessToSite($request->authentication, $idSite)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires 'view' access for the website id = {$idSite}.",
                    401,
                );
            }

            $globalReferrers = $this->options->value('SitesManager_ExcludedReferrersGlobal') ?? '';
            $siteReferrers = $this->sites->excludedReferrers($idSite) ?? '';

            return $this->responses->values(
                $request,
                $this->commaSeparatedValues($globalReferrers.','.$siteReferrers),
            );
        }

        if ($request->isExcludedQueryParametersRequest()) {
            $idSite = $request->idSite ?? throw new LogicException('The site ID was not parsed.');

            if (! $this->authorizer->hasViewAccessToSite($request->authentication, $idSite)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires 'view' access for the website id = {$idSite}.",
                    401,
                );
            }

            $siteParameters = $this->sites->excludedParameters($idSite) ?? '';
            $globalParameters = $this->queryParameterExclusions->parameters($idSite);

            return $this->responses->values(
                $request,
                $this->commaSeparatedValues($siteParameters.','.$globalParameters),
            );
        }

        if ($request->isGlobalExcludedQueryParametersRequest()
            || $request->isQueryParameterExclusionTypeRequest()) {
            if (! $this->authorizer->hasSomeViewAccess($request->authentication)) {
                return $this->responses->error(
                    $request,
                    'You must have view access to at least one website.',
                    401,
                );
            }

            return $this->responses->scalar(
                $request,
                $request->isQueryParameterExclusionTypeRequest()
                    ? $this->queryParameterExclusions->type($request->idSite)
                    : $this->queryParameterExclusions->parameters($request->idSite),
            );
        }

        if ($request->isUniqueSiteTimezonesRequest()) {
            if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires a 'superuser' access.",
                    401,
                );
            }

            return $this->responses->values($request, $this->sites->timezones());
        }

        if ($request->isSiteIdsFromTimezonesRequest()) {
            if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires a 'superuser' access.",
                    401,
                );
            }

            return $this->responses->values(
                $request,
                $this->sites->idsInTimezones($request->timezones ?? []),
            );
        }

        if ($request->isIpRangeRequest()) {
            $range = IPUtils::getIPRangeBounds($request->ipRange ?? '');

            if ($range === null) {
                return $this->responses->scalar($request, false);
            }

            return $this->responses->values($request, [
                IPUtils::binaryToStringIP($range[0]),
                IPUtils::binaryToStringIP($range[1]),
            ]);
        }

        if ($request->isSiteIdFromUrlRequest()) {
            $url = $this->withoutTrailingSlash($request->siteUrl ?? '');
            $host = str_replace(['www.', 'http://', 'https://'], '', $url);
            $urls = [
                $url,
                "http://{$host}",
                "http://www.{$host}",
                "https://{$host}",
                "https://www.{$host}",
            ];
            $allowedSiteIds = $this->authorizer->siteIdsWithAtLeastViewAccess(
                $request->authentication,
            );

            return $this->responses->rows(
                $request,
                $this->sites->idsForUrls($urls, $allowedSiteIds),
            );
        }

        $globalOption = $this->globalOption($request);

        if ($globalOption !== null) {
            if ($globalOption['access'] === 'view') {
                if (! $this->authorizer->hasSomeViewAccess($request->authentication)) {
                    return $this->responses->error(
                        $request,
                        'You must have view access to at least one website.',
                        401,
                    );
                }
            } elseif (! $this->authorizer->hasSomeAdminAccess($request->authentication)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires admin access for at least one website.",
                    401,
                );
            }

            $value = $this->options->value($globalOption['name']);

            if ($request->method === 'SitesManager.getKeepURLFragmentsGlobal') {
                return $this->responses->scalar($request, (bool) $value);
            }

            return $this->responses->scalar($request, $value ?? $globalOption['default']);
        }

        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires a 'superuser' access.",
                401,
            );
        }

        return $this->responses->values($request, $this->sites->allIds());
    }

    private function setGlobalSettings(ApiRequest $request): Response
    {
        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires a 'superuser' access.",
                401,
            );
        }

        $settings = $request->sitesManagerGlobalSettings
            ?? throw new LogicException('The global site settings were not parsed.');
        $returnsValue = false;

        if ($request->method === 'SitesManager.setGlobalExcludedIps') {
            $ips = $this->commaSeparated($settings->excludedIps ?? '');

            foreach ($ips === '' ? [] : explode(',', $ips) as $ip) {
                if (IPUtils::getIPRangeBounds($ip) === null) {
                    return $this->responses->error(
                        $request,
                        "The IP to exclude \"{$ip}\" does not have a valid IP format (eg. 1.2.3.4, 1.2.3.*, or 1.2.3.4/5).",
                        400,
                    );
                }
            }

            $this->mutableOptions->set('SitesManager_ExcludedIpsGlobal', $ips);
            $returnsValue = true;
        } elseif ($request->method === 'SitesManager.setGlobalSearchParameters') {
            $this->mutableOptions->set(
                'SitesManager_SearchKeywordParameters',
                $settings->searchKeywordParameters ?? '',
            );
            $this->mutableOptions->set(
                'SitesManager_SearchCategoryParameters',
                $settings->searchCategoryParameters ?? '',
            );
            $returnsValue = true;
        } elseif ($request->method === 'SitesManager.setGlobalExcludedUserAgents') {
            $this->mutableOptions->set(
                'SitesManager_ExcludedUserAgentsGlobal',
                $this->commaSeparated($settings->excludedUserAgents ?? ''),
            );
        } elseif ($request->method === 'SitesManager.setGlobalExcludedReferrers') {
            $referrers = $this->commaSeparated($settings->excludedReferrers ?? '');

            foreach ($referrers === '' ? [] : explode(',', $referrers) as $referrer) {
                $host = parse_url('https://'.ltrim(preg_replace('#^https?://#', '', $referrer) ?? '', '.'), PHP_URL_HOST);

                if (! is_string($host) || $host === '' || str_contains($host, ' ')) {
                    return $this->responses->error(
                        $request,
                        "The url '{$referrer}' is not a valid URL.",
                        400,
                    );
                }
            }

            $this->mutableOptions->set('SitesManager_ExcludedReferrersGlobal', $referrers);
        } elseif ($request->method === 'SitesManager.setGlobalQueryParamExclusion') {
            $type = $settings->queryParameterExclusionType ?? '';
            $parameters = $this->commaSeparated($settings->queryParametersToExclude ?? '');

            if (! in_array($type, ['common_session_parameters', 'matomo_recommended_pii', 'custom'], true)) {
                return $this->responses->error(
                    $request,
                    "The value '{$type}' is not allowed for the field exclusionType.",
                    400,
                );
            }

            if ($type === 'custom' && $parameters === '') {
                return $this->responses->error(
                    $request,
                    'Query parameters to exclude must be provided when exclusion type is custom',
                    400,
                );
            }

            if ($type !== 'custom' && $parameters !== '') {
                return $this->responses->error(
                    $request,
                    'Query parameters to exclude must not be provided when exclusion type is not custom',
                    400,
                );
            }

            $this->mutableOptions->set('SitesManager_ExcludeTypeQueryParamsGlobal', $type);

            if ($type === 'custom') {
                $this->mutableOptions->set('SitesManager_ExcludedQueryParameters', $parameters);
            } else {
                $this->mutableOptions->delete('SitesManager_ExcludedQueryParameters');
            }
        } else {
            $this->mutableOptions->set(
                'SitesManager_KeepURLFragmentsGlobal',
                $settings->keepUrlFragments === true ? '1' : '0',
            );
        }

        $this->trackerCache->clearGeneral();

        return $returnsValue
            ? $this->responses->scalar($request, true)
            : $this->responses->success($request);
    }

    private function mutateSiteAliases(ApiRequest $request): Response
    {
        $idSite = $request->idSite ?? throw new LogicException('The site ID was not parsed.');
        $adminSites = $this->authorizer->siteIdsWithRole(
            $request->authentication,
            SiteAccessRole::Admin,
        );

        if (! in_array($idSite, $adminSites, true)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'admin' access for the website id = {$idSite}.",
                401,
            );
        }

        $mainUrl = $this->sites->mainUrl($idSite);

        if ($mainUrl === null) {
            return $this->responses->error(
                $request,
                "An unexpected website was found in the request: website id was set to '{$idSite}' .",
                500,
            );
        }

        $incoming = $this->normalizeSiteUrls($request->siteAliasUrls ?? []);

        if ($incoming === null) {
            return $this->responses->error($request, 'One of the provided URLs is not a valid URL.', 400);
        }

        $initial = $this->normalizeSiteUrls($this->sites->urls($idSite)) ?? [$mainUrl];
        $normalizedMain = $this->normalizeSiteUrls([$mainUrl]) ?? [$mainUrl];
        $mainUrl = $normalizedMain[0];
        $requested = $request->method === 'SitesManager.addSiteAliasUrls'
            ? [...$initial, ...$incoming]
            : [$mainUrl, ...$incoming];
        $final = array_values(array_unique($requested));
        $aliases = array_values(array_filter(
            $final,
            static fn (string $url): bool => $url !== $mainUrl,
        ));
        $this->sites->replaceAliasUrls($idSite, $aliases);
        $this->siteTrackerCache->clear($idSite);
        $this->trackerCache->clearGeneral();
        $inserted = $request->method === 'SitesManager.addSiteAliasUrls'
            ? count(array_diff($final, $initial))
            : count(array_diff($final, [$mainUrl, ...$incoming]));

        return $this->responses->scalar($request, $inserted);
    }

    private function renameSiteGroup(ApiRequest $request): Response
    {
        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires a 'superuser' access.",
                401,
            );
        }

        $oldGroup = $request->oldSiteGroup
            ?? throw new LogicException('The old site group was not parsed.');
        $newGroup = $request->newSiteGroup
            ?? throw new LogicException('The new site group was not parsed.');

        if ($oldGroup === $newGroup) {
            return $this->responses->scalar($request, true);
        }

        $siteIds = $this->sites->renameGroup($oldGroup, $newGroup);

        foreach ($siteIds as $idSite) {
            $this->siteTrackerCache->clear($idSite);
        }

        if ($siteIds !== []) {
            $this->trackerCache->clearGeneral();
        }

        return $this->responses->scalar($request, true);
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>|null
     */
    private function normalizeSiteUrls(array $urls): ?array
    {
        $normalized = [];

        foreach ($urls as $url) {
            $url = trim(urldecode($url));

            if ($url === '') {
                continue;
            }

            if (! str_contains($url, '://')) {
                $url = str_starts_with($url, '//') ? 'http:'.$url : 'http://'.$url;
            }

            if (strlen($url) > 5 && str_ends_with($url, '/')) {
                $url = substr($url, 0, -1);
            }

            $parts = parse_url($url);

            if (! is_array($parts)
                || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
                || ! is_string($parts['host'] ?? null)
                || $parts['host'] === '') {
                return null;
            }

            $normalized[] = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return array_values(array_unique($normalized));
    }

    private function commaSeparated(string $value): string
    {
        $values = array_values(array_unique(array_filter(
            array_map(trim(...), explode(',', trim($value))),
            static fn (string $item): bool => $item !== '',
        )));

        return implode(',', $values);
    }

    private function optionOrDefault(string $name, string $default): string
    {
        $value = $this->options->value($name);

        return $value ?: $default;
    }

    /**
     * @return array{name: string, access: 'admin'|'view', default: bool|string}|null
     */
    private function globalOption(ApiRequest $request): ?array
    {
        if ($request->module !== 'API') {
            return null;
        }

        return self::GLOBAL_OPTIONS[$request->method] ?? null;
    }

    private function withoutTrailingSlash(string $url): string
    {
        if (strlen($url) > 5 && str_ends_with($url, '/')) {
            return substr($url, 0, -1);
        }

        return $url;
    }

    /**
     * @return list<string>
     */
    private function commaSeparatedValues(string $values): array
    {
        return array_values(array_unique(array_filter(
            explode(',', $values),
            static fn (string $value): bool => $value !== '',
        )));
    }
}
