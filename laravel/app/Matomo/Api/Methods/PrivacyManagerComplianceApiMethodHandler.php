<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Privacy\CompliancePolicyCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class PrivacyManagerComplianceApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiResponseFactory $responses,
        private CompliancePolicyCatalog $policies,
        private LanguageResolver $languages,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->method === 'PrivacyManager.getCompliancePolicies';
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The privacy compliance handler does not support this request.');
        }

        $language = $this->languages->resolve($httpRequest, $request->authentication);

        return $this->responses->rows($request, $this->policies->all($language));
    }
}
