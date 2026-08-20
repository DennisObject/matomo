<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Segments\SegmentCreationPolicy;
use App\Matomo\Segments\StoredSegmentReader;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class SegmentEditorReadApiMethodHandler implements ApiMethodHandler
{
    private const array METHODS = [
        'SegmentEditor.isUserCanAddNewSegment',
        'SegmentEditor.get',
        'SegmentEditor.getAll',
    ];

    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private StoredSegmentReader $segments,
        private SegmentCreationPolicy $creation,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->module === 'API' && in_array($request->method, self::METHODS, true);
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The SegmentEditor read API handler does not support this request.');
        }

        $parameters = $request->segmentEditor
            ?? throw new LogicException('The SegmentEditor API parameters were not parsed.');

        return match ($request->method) {
            'SegmentEditor.isUserCanAddNewSegment' => $this->responses->scalar(
                $request,
                $this->creation->allowed($request->authentication, $parameters->siteId),
            ),
            'SegmentEditor.get' => $this->get(
                $request,
                $parameters->segmentId
                    ?? throw new LogicException('The segment ID was not parsed.'),
            ),
            'SegmentEditor.getAll' => $this->getAll($request, $parameters->siteId),
            default => throw new LogicException('The SegmentEditor read API method is not implemented.'),
        };
    }

    private function get(ApiRequest $request, int $segmentId): Response
    {
        if (! $this->authorizer->hasSomeViewAccess($request->authentication)) {
            return $this->someViewAccessError($request);
        }

        $segment = $this->segments->find($segmentId);

        if ($segment === null) {
            return $this->responses->success($request);
        }

        $superUser = $this->authorizer->hasSuperUserAccess($request->authentication);
        $siteId = (int) ($segment['enable_only_idsite'] ?? 0);

        if (! $superUser
            && $siteId !== 0
            && ! $this->authorizer->hasViewAccessToSite($request->authentication, $siteId)) {
            return $this->siteViewAccessError($request, $siteId);
        }

        $login = $this->authorizer->authenticatedLogin($request->authentication);

        if (! $superUser && $segment['enable_all_users'] !== 1 && $segment['login'] !== $login) {
            return $this->responses->error(
                $request,
                'You can only edit and delete custom segments that you have created yourself. '.
                    "This segment was created and 'shared with you' by the Super User. ".
                    "To modify this segment, you can first create a new one by clicking on 'Add new segment'. ".
                    "Then you can customize the segment's definition.",
                400,
            );
        }

        if ($segment['deleted'] !== 0) {
            return $this->responses->error($request, 'This segment is marked as deleted. ', 400);
        }

        return $this->responses->row($request, $segment);
    }

    private function getAll(ApiRequest $request, ?int $siteId): Response
    {
        if ($siteId !== null) {
            if (! $this->authorizer->hasViewAccessToSite($request->authentication, $siteId)) {
                return $this->siteViewAccessError($request, $siteId);
            }
        } elseif (! $this->authorizer->hasSomeViewAccess($request->authentication)) {
            return $this->someViewAccessError($request);
        }

        $login = $this->authorizer->authenticatedLogin($request->authentication) ?? 'anonymous';
        $superUser = $this->authorizer->hasSuperUserAccess($request->authentication);
        $viewableSiteIds = $superUser
            ? []
            : $this->authorizer->siteIdsWithAtLeastViewAccess($request->authentication);

        return $this->responses->rows(
            $request,
            $this->segments->visible($login, $superUser, $siteId, $viewableSiteIds),
        );
    }

    private function someViewAccessError(ApiRequest $request): Response
    {
        return $this->responses->error(
            $request,
            'You must have view access to at least one website.',
            401,
        );
    }

    private function siteViewAccessError(ApiRequest $request, int $siteId): Response
    {
        return $this->responses->error(
            $request,
            "You can't access this resource as it requires 'view' access for the website id = {$siteId}.",
            401,
        );
    }
}
