<?php

declare(strict_types=1);

namespace App\Http\Controllers\Matomo\Api;

use App\Http\Controllers\Controller;
use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\Exceptions\ConflictingAuthenticationParameters;
use App\Matomo\Api\Exceptions\InvalidApiParameter;
use App\Matomo\Authentication\VersionAccessAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Piwik\Version;

class VersionController extends Controller
{
    public function __construct(
        private readonly VersionAccessAuthorizer $authorizer,
        private readonly ApiResponseFactory $responses,
    ) {}

    public function __invoke(Request $request): Response
    {
        try {
            $apiRequest = ApiRequest::fromRequest($request);
        } catch (ConflictingAuthenticationParameters|InvalidApiParameter $invalidApiRequest) {
            return $this->responses->error(
                ApiRequest::withoutAuthentication($request),
                $invalidApiRequest->getMessage(),
                400,
            );
        }

        if (! $apiRequest->isVersionRequest()) {
            return $this->responses->error($apiRequest, 'This API method has not moved to Laravel yet.', 501);
        }

        if (! $apiRequest->hasSupportedFormat()) {
            return $this->responses->error($apiRequest, 'This response format has not moved to Laravel yet.', 501);
        }

        if (! $this->authorizer->hasSomeViewAccess($apiRequest->token, $apiRequest->tokenIsSecure)) {
            return $this->responses->error(
                $apiRequest,
                'You must have view access to at least one website.',
                401,
            );
        }

        return $this->responses->scalar($apiRequest, Version::VERSION);
    }
}
