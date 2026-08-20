<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Login\BruteForceUnblocker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class LoginApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private BruteForceUnblocker $unblocker,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isLoginRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The Login API handler does not support this request.');
        }

        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires a 'superuser' access.",
                401,
            );
        }

        $this->unblocker->unblockCurrentlyBlocked();

        return $this->responses->success($request);
    }
}
