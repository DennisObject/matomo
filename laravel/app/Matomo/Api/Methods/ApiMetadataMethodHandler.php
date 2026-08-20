<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Segments\SegmentMetadataCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class ApiMetadataMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private LanguageResolver $languages,
        private SegmentMetadataCatalog $segments,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->segmentsMetadata !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        $parameters = $request->segmentsMetadata
            ?? throw new LogicException('The API metadata parameters are missing.');
        if (! $parameters->hideImplementationData || $parameters->showAllSegments) {
            return $this->responses->error(
                $request,
                'Internal segment metadata is not available in the Laravel runtime.',
                501,
            );
        }

        if ($parameters->siteIds === []) {
            if (! $this->authorizer->hasSomeViewAccess($request->authentication)) {
                return $this->responses->error(
                    $request,
                    'You must have view access to at least one website.',
                    401,
                );
            }
        } else {
            foreach ($parameters->siteIds as $siteId) {
                if (! $this->authorizer->hasViewAccessToSite($request->authentication, $siteId)) {
                    return $this->responses->error(
                        $request,
                        "You do not have view access to website {$siteId}.",
                        401,
                    );
                }
            }
        }

        $login = $this->authorizer->authenticatedLogin($request->authentication);
        $language = $this->languages->resolve($httpRequest, $request->authentication);

        return $this->responses->structured($request, $this->segments->metadata(
            $parameters->siteIds,
            $language,
            ! in_array($login, [null, '', 'anonymous'], true),
        ));
    }
}
