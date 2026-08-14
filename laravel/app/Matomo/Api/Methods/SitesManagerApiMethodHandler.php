<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Options\OptionRepository;
use App\Matomo\Sites\CurrencyProvider;
use App\Matomo\Sites\QueryParameterExclusionPolicy;
use App\Matomo\Sites\SiteDetailsPresenter;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Sites\SiteRuntimeSettings;
use App\Matomo\Sites\TimezoneProvider;
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
        private ApiResponseFactory $responses,
        private SiteRepository $sites,
        private OptionRepository $options,
        private SiteRuntimeSettings $runtime,
        private CurrencyProvider $currencies,
        private QueryParameterExclusionPolicy $queryParameterExclusions,
        private LanguageResolver $languages,
        private TimezoneProvider $timezones,
        private SiteDetailsPresenter $siteDetails,
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
            || $request->isDefaultCurrencyRequest()
            || $request->isCurrencySymbolsRequest()
            || $request->isCurrencyListRequest()
            || $request->isDefaultTimezoneRequest()
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
            || $this->globalOption($request) !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The SitesManager API handler does not support this method.');
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
