<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\CustomDimensions\CustomDimensionCatalog;
use App\Matomo\Localization\LanguageResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class CustomDimensionsApiMethodHandler implements ApiMethodHandler
{
    private const array READ_METHODS = [
        'CustomDimensions.getConfiguredCustomDimensions',
        'CustomDimensions.getConfiguredCustomDimensionsHavingScope',
        'CustomDimensions.getAvailableScopes',
        'CustomDimensions.getAvailableExtractionDimensions',
    ];

    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private LanguageResolver $languages,
        private CustomDimensionCatalog $catalog,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->module === 'API' && in_array($request->method, self::READ_METHODS, true);
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The CustomDimensions API handler does not support this request.');
        }

        $query = $request->customDimensions
            ?? throw new LogicException('The CustomDimensions request was not parsed.');
        $language = $this->languages->resolve($httpRequest, $request->authentication);

        if ($request->method === 'CustomDimensions.getAvailableExtractionDimensions') {
            if (! $this->authorizer->hasSomeAdminAccess($request->authentication)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires at least one 'write' access.",
                    401,
                );
            }

            return $this->responses->rows($request, $this->catalog->extractionDimensions($language));
        }

        $siteId = $query->siteId ?? throw new LogicException('The site ID was not parsed.');

        if (! $this->authorizer->hasViewAccessToSite($request->authentication, $siteId)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'view' access for the website id = {$siteId}.",
                401,
            );
        }

        return match ($request->method) {
            'CustomDimensions.getConfiguredCustomDimensions' => $this->responses->rows(
                $request,
                $this->catalog->configured($siteId),
            ),
            'CustomDimensions.getConfiguredCustomDimensionsHavingScope' => $this->responses->rows(
                $request,
                $this->catalog->configured($siteId, $query->scope),
            ),
            'CustomDimensions.getAvailableScopes' => $this->responses->rows(
                $request,
                $this->catalog->scopes($siteId, $language),
            ),
            default => throw new LogicException('The CustomDimensions API method is not implemented.'),
        };
    }
}
