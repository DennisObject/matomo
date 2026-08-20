<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Privacy\GranularComplianceSettingsProvider;
use App\Matomo\Privacy\PrivacyFeatureFlags;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class PrivacyManagerGranularComplianceApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private GranularComplianceSettingsProvider $settings,
        private PrivacyFeatureFlags $features,
        private LanguageResolver $languages,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->privacyGranularCompliance !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The granular compliance handler does not support this request.');
        }

        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error($request, 'Superuser access is required.', 401);
        }

        if (! $this->features->granularComplianceEnabled()) {
            return $this->responses->error(
                $request,
                'Granular compliance configuration is not enabled.',
                400,
            );
        }

        $parameters = $request->privacyGranularCompliance
            ?? throw new LogicException('The granular compliance parameters were not parsed.');
        if ($parameters->policy !== 'cnil_v1') {
            return $this->responses->error($request, 'Invalid compliance policy.', 400);
        }

        $idSite = $this->siteId($parameters->site);
        if ($idSite === false) {
            return $this->responses->error($request, 'The idSite must be a positive integer or all.', 400);
        }

        $language = $this->languages->resolve($httpRequest, $request->authentication);

        return $this->responses->structured($request, $this->settings->settings($idSite, $language));
    }

    private function siteId(string $site): int|null|false
    {
        if ($site === 'all') {
            return null;
        }

        return preg_match('/^[1-9][0-9]*$/D', $site) === 1 ? (int) $site : false;
    }
}
