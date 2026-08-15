<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Segments\SegmentMetadataCatalog;
use App\Matomo\Segments\SegmentValueRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class SegmentSuggestionsApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private SegmentMetadataCatalog $segments,
        private SegmentValueRepository $values,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->segmentSuggestions !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        $parameters = $request->segmentSuggestions
            ?? throw new LogicException('The segment suggestion parameters are missing.');
        if (! $this->authorizer->hasViewAccessToSite($request->authentication, $parameters->siteId)) {
            return $this->responses->error($request, "You do not have view access to website {$parameters->siteId}.", 401);
        }

        $known = false;
        foreach ($this->segments->metadata([$parameters->siteId], 'en', true) as $segment) {
            if (($segment['segment'] ?? null) === $parameters->segmentName) {
                $known = true;
                break;
            }
        }

        if (! $known) {
            return $this->responses->error($request, "The segment '{$parameters->segmentName}' does not exist.", 400);
        }

        return $this->responses->structured(
            $request,
            $this->values->mostFrequent($parameters->siteId, $parameters->segmentName, 30),
        );
    }
}
