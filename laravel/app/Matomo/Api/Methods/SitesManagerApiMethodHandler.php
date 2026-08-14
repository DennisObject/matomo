<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use Illuminate\Http\Response;
use LogicException;

final readonly class SitesManagerApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isAdminSiteIdsRequest();
    }

    public function handle(ApiRequest $request): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The SitesManager API handler does not support this method.');
        }

        return $this->responses->values(
            $request,
            $this->authorizer->siteIdsWithAdminAccess($request->authentication),
        );
    }
}
