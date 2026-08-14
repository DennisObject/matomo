<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Options\OptionRepository;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class SitesManagerApiMethodHandler implements ApiMethodHandler
{
    private const string DEFAULT_CURRENCY_OPTION = 'SitesManager_DefaultCurrency';

    private const string DEFAULT_TIMEZONE_OPTION = 'SitesManager_DefaultTimezone';

    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private SiteRepository $sites,
        private OptionRepository $options,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->siteAccessRole() !== null
            || $request->isAllSiteIdsRequest()
            || $request->isViewableSiteIdsRequest()
            || $request->isSiteGroupsRequest()
            || $request->isDefaultCurrencyRequest()
            || $request->isDefaultTimezoneRequest();
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
}
