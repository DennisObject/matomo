<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Segments\SegmentMetadataCatalog;
use App\Matomo\Segments\SegmentSuggestionPolicy;
use App\Matomo\Segments\SegmentValueRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class SegmentSuggestionsApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private SegmentSuggestionPolicy $policy,
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
        if (! $this->policy->enabled()) {
            return $this->responses->values($request, []);
        }

        if ($parameters->allSites) {
            if (! $this->authorizer->hasSomeViewAccess($request->authentication)) {
                return $this->responses->error(
                    $request,
                    'You must have view access to at least one website.',
                    401,
                );
            }

            return $this->responses->error(
                $request,
                'All-sites segment suggestions have not moved to Laravel yet.',
                501,
            );
        }

        $siteId = $parameters->siteId
            ?? throw new LogicException('The site ID is missing from the segment suggestion request.');
        if (! $this->authorizer->hasViewAccessToSite($request->authentication, $siteId)) {
            return $this->responses->error($request, "You do not have view access to website {$siteId}.", 401);
        }

        $login = $this->authorizer->authenticatedLogin($request->authentication);
        $segment = $this->findSegment(
            $siteId,
            $parameters->segmentName,
            ! in_array($login, [null, '', 'anonymous'], true),
        );
        if ($segment === null) {
            return $this->responses->error($request, "The segment '{$parameters->segmentName}' does not exist.", 400);
        }

        if (isset($segment['suggestedValuesApi'])
            || isset($segment['suggestedValuesCallback'])
            || isset($segment['unionOfSegments'])
            || ! $this->values->supports($parameters->segmentName)) {
            return $this->responses->error(
                $request,
                "Suggested values for the segment '{$parameters->segmentName}' have not moved to Laravel yet.",
                501,
            );
        }

        return $this->responses->values(
            $request,
            $this->values->mostFrequent($siteId, $parameters->segmentName, 30),
        );
    }

    /** @return array<string, mixed>|null */
    private function findSegment(int $siteId, string $name, bool $includeRestricted): ?array
    {
        foreach ($this->segments->metadata([$siteId], 'en', $includeRestricted) as $segment) {
            if (($segment['segment'] ?? null) === $name) {
                return $segment;
            }
        }

        return null;
    }
}
