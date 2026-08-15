<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Localization\MutableLanguagePreferenceRepository;
use App\Matomo\TrackingFailures\TrackingFailurePresenter;
use App\Matomo\TrackingFailures\TrackingFailureRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class CoreAdminHomeApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private TrackingFailureRepository $failures,
        private TrackingFailurePresenter $presenter,
        private LanguageResolver $languages,
        private MutableLanguagePreferenceRepository $preferences,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isCoreAdminHomeTrackingFailureRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The CoreAdminHome API handler does not support this request.');
        }

        if ($request->method === 'CoreAdminHome.deleteTrackingFailure') {
            return $this->deleteOne($request);
        }

        $superuser = $this->authorizer->hasSuperUserAccess($request->authentication);

        if (! $superuser && ! $this->authorizer->hasSomeAdminAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires admin access for at least one website.",
                401,
            );
        }

        $siteIds = $superuser
            ? []
            : $this->authorizer->siteIdsWithRole($request->authentication, SiteAccessRole::Admin);

        if ($request->method === 'CoreAdminHome.deleteAllTrackingFailures') {
            if ($superuser) {
                $this->failures->deleteAll();
            } else {
                $this->failures->deleteForSites($siteIds);
            }

            return $this->responses->success($request);
        }

        $failures = $superuser ? $this->failures->all() : $this->failures->forSites($siteIds);
        $login = $this->authorizer->authenticatedLogin($request->authentication);

        return $this->responses->rows(
            $request,
            $this->presenter->present(
                $failures,
                $this->languages->resolve($httpRequest, $request->authentication),
                is_string($login) && $this->preferences->uses12HourClock($login),
            ),
        );
    }

    private function deleteOne(ApiRequest $request): Response
    {
        $parameters = $request->coreAdminHome
            ?? throw new LogicException('The CoreAdminHome API parameters were not parsed.');
        $siteId = $parameters->siteId
            ?? throw new LogicException('The CoreAdminHome site ID was not parsed.');
        $failureId = $parameters->failureId
            ?? throw new LogicException('The CoreAdminHome failure ID was not parsed.');
        $allowed = $this->authorizer->hasSuperUserAccess($request->authentication)
            || in_array(
                $siteId,
                $this->authorizer->siteIdsWithRole($request->authentication, SiteAccessRole::Admin),
                true,
            );

        if (! $allowed) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'admin' access for the website id = {$siteId}.",
                401,
            );
        }

        $this->failures->delete($siteId, $failureId);

        return $this->responses->success($request);
    }
}
