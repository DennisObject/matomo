<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Privacy\DataSubjectRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class PrivacyManagerDataSubjectsApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private DataSubjectRepository $dataSubjects,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->privacyDataSubjects !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The privacy data-subject handler does not support this request.');
        }

        if (! $this->authorizer->hasSomeAdminAccess($request->authentication)) {
            return $this->responses->error($request, 'Admin access is required.', 401);
        }

        $parameters = $request->privacyDataSubjects
            ?? throw new LogicException('The data-subject parameters were not parsed.');
        $siteIds = array_values(array_unique(array_column($parameters->visits, 'idsite')));
        $adminSiteIds = $this->authorizer->siteIdsWithRole(
            $request->authentication,
            SiteAccessRole::Admin,
        );
        if (array_diff($siteIds, $adminSiteIds) !== []) {
            return $this->responses->error($request, 'Admin access is required for every requested website.', 403);
        }

        $result = $parameters->delete
            ? $this->dataSubjects->delete($parameters->visits)
            : $this->dataSubjects->export($parameters->visits);

        return $this->responses->structured($request, $result);
    }
}
