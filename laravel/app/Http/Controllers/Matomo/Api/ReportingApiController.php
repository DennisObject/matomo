<?php

declare(strict_types=1);

namespace App\Http\Controllers\Matomo\Api;

use App\Http\Controllers\Controller;
use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\Exceptions\ConflictingAuthenticationParameters;
use App\Matomo\Api\Exceptions\InvalidApiParameter;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Security\ReportingApiIpAllowlist;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Piwik\Version;

class ReportingApiController extends Controller
{
    public function __construct(
        private readonly ApiAccessAuthorizer $authorizer,
        private readonly ApiResponseFactory $responses,
        private readonly ReportingApiIpAllowlist $ipAllowlist,
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

        $deniedClientIp = $this->ipAllowlist->deniedClientIp($request);

        if ($deniedClientIp !== null) {
            return $this->responses->error(
                $apiRequest,
                "You cannot use this Matomo as your IP {$deniedClientIp} is not allowed.",
                401,
            );
        }

        if (
            ! $apiRequest->isVersionRequest()
            && ! $apiRequest->isPhpVersionRequest()
            && ! $apiRequest->isAdminSiteIdsRequest()
        ) {
            return $this->responses->error($apiRequest, 'This API method has not moved to Laravel yet.', 501);
        }

        if (! $apiRequest->hasSupportedFormat()) {
            return $this->responses->error($apiRequest, 'This response format has not moved to Laravel yet.', 501);
        }

        if ($apiRequest->isPhpVersionRequest()) {
            if (! $this->authorizer->hasSuperUserAccess($apiRequest->authentication)) {
                return $this->responses->error(
                    $apiRequest,
                    "You can't access this resource as it requires a 'superuser' access.",
                    401,
                );
            }

            return $this->responses->row($apiRequest, [
                'version' => PHP_VERSION,
                'major' => PHP_MAJOR_VERSION,
                'minor' => PHP_MINOR_VERSION,
                'release' => PHP_RELEASE_VERSION,
                'versionId' => PHP_VERSION_ID,
                'extra' => PHP_EXTRA_VERSION,
            ]);
        }

        if ($apiRequest->isAdminSiteIdsRequest()) {
            return $this->responses->values(
                $apiRequest,
                $this->authorizer->siteIdsWithAdminAccess($apiRequest->authentication),
            );
        }

        if (! $this->authorizer->hasSomeViewAccess($apiRequest->authentication)) {
            return $this->responses->error(
                $apiRequest,
                'You must have view access to at least one website.',
                401,
            );
        }

        return $this->responses->scalar($apiRequest, Version::VERSION);
    }
}
