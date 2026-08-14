<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Options\OptionRepository;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Sites\SiteRuntimeSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

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
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->siteAccessRole() !== null
            || $request->isAllSiteIdsRequest()
            || $request->isViewableSiteIdsRequest()
            || $request->isSiteGroupsRequest()
            || $request->isDefaultCurrencyRequest()
            || $request->isDefaultTimezoneRequest()
            || $request->isTimezoneSupportRequest()
            || $request->isWebsitesCountToDisplayRequest()
            || $request->isSiteUrlsRequest()
            || $request->isUniqueSiteTimezonesRequest()
            || $request->isSiteIdsFromTimezonesRequest()
            || $this->globalOption($request) !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The SitesManager API handler does not support this method.');
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
}
