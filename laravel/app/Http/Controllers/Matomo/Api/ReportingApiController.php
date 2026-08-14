<?php

declare(strict_types=1);

namespace App\Http\Controllers\Matomo\Api;

use App\Http\Controllers\Controller;
use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\Exceptions\ConflictingAuthenticationParameters;
use App\Matomo\Api\Exceptions\InvalidApiParameter;
use App\Matomo\Api\Exceptions\MissingApiParameter;
use App\Matomo\Api\Methods\ApiMethodDispatcher;
use App\Matomo\Security\ReportingApiIpAllowlist;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportingApiController extends Controller
{
    public function __construct(
        private readonly ApiMethodDispatcher $methods,
        private readonly ApiResponseFactory $responses,
        private readonly ReportingApiIpAllowlist $ipAllowlist,
    ) {}

    public function __invoke(Request $request): Response
    {
        try {
            $apiRequest = ApiRequest::fromRequest($request);
        } catch (ConflictingAuthenticationParameters|InvalidApiParameter|MissingApiParameter $invalidApiRequest) {
            return $this->responses->error(
                ApiRequest::withoutAuthentication($request),
                $invalidApiRequest->getMessage(),
                400,
            );
        }

        $deniedClientIp = $this->ipAllowlist->deniedClientIp($request);

        if ($deniedClientIp !== null) {
            return $this->responses->error(
                $apiRequest,
                "You cannot use this Matomo as your IP {$deniedClientIp} is not allowed.",
                401,
            );
        }

        if (! $this->methods->supports($apiRequest)) {
            return $this->responses->error($apiRequest, 'This API method has not moved to Laravel yet.', 501);
        }

        if (! $apiRequest->hasSupportedFormat()) {
            return $this->responses->error($apiRequest, 'This response format has not moved to Laravel yet.', 501);
        }

        return $this->methods->dispatch($apiRequest, $request);
    }
}
