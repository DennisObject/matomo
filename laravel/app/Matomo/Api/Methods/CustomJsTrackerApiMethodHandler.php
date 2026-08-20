<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Plugins\TrackerFileAvailability;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class CustomJsTrackerApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private TrackerFileAvailability $trackerFiles,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isCustomJsTrackerRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The CustomJsTracker API handler does not support this request.');
        }

        if (! $this->authorizer->hasSomeAdminAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires admin access for at least one website.",
                401,
            );
        }

        return $this->responses->scalar($request, $this->trackerFiles->canUpdate());
    }
}
