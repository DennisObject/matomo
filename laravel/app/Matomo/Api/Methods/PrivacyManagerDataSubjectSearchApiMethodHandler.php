<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Privacy\DataSubjectFinder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class PrivacyManagerDataSubjectSearchApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private LanguageResolver $languages,
        private DataSubjectFinder $dataSubjects,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->privacyDataSubjectSearch !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The privacy data-subject search handler does not support this request.');
        }

        if (! $this->authorizer->hasSomeAdminAccess($request->authentication)) {
            return $this->responses->error($request, 'Admin access is required.', 401);
        }

        $parameters = $request->privacyDataSubjectSearch
            ?? throw new LogicException('The data-subject search parameters were not parsed.');
        $viewable = $this->authorizer->siteIdsWithAtLeastViewAccess(
            $request->authentication,
            $request->restrictSitesToLogin,
        );
        $siteIds = $parameters->allSites
            ? $viewable
            : array_values(array_intersect($parameters->siteIds, $viewable));
        if ($siteIds === []) {
            return $this->responses->rows($request, []);
        }

        try {
            $rows = $this->dataSubjects->find(
                $siteIds,
                $parameters->segment,
                $this->languages->resolve($httpRequest, $request->authentication),
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
        }

        return $this->responses->rows($request, $rows);
    }
}
