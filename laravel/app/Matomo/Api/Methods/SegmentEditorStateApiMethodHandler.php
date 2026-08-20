<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Segments\Events\SegmentDeactivating;
use App\Matomo\Segments\MutableStoredSegmentRepository;
use App\Matomo\Segments\SegmentCacheInvalidator;
use App\Matomo\Segments\StoredSegmentRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

/** @phpstan-import-type StoredSegment from StoredSegmentRepository */
final readonly class SegmentEditorStateApiMethodHandler implements ApiMethodHandler
{
    private const array METHODS = [
        'SegmentEditor.delete',
        'SegmentEditor.star',
        'SegmentEditor.unstar',
    ];

    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private MutableStoredSegmentRepository $segments,
        private SegmentCacheInvalidator $cache,
        private Dispatcher $events,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->module === 'API' && in_array($request->method, self::METHODS, true);
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The SegmentEditor state API handler does not support this request.');
        }

        $segmentId = $request->segmentEditor->segmentId
            ?? throw new LogicException('The segment ID was not parsed.');
        $segment = $this->editableSegment($request, $segmentId);

        if ($segment instanceof Response) {
            return $segment;
        }

        return match ($request->method) {
            'SegmentEditor.delete' => $this->delete($request, $segmentId),
            'SegmentEditor.star' => $this->star($request, $segmentId),
            'SegmentEditor.unstar' => $this->unstar($request, $segmentId),
            default => throw new LogicException('The SegmentEditor state API method is not implemented.'),
        };
    }

    private function delete(ApiRequest $request, int $segmentId): Response
    {
        $this->events->dispatch(new SegmentDeactivating($segmentId));
        $this->segments->delete($segmentId, CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'));
        $this->cache->clear();

        return $this->responses->success($request);
    }

    private function star(ApiRequest $request, int $segmentId): Response
    {
        $login = $this->authorizer->authenticatedLogin($request->authentication)
            ?? throw new LogicException('An authenticated login is required to star a segment.');
        $result = $this->segments->update($segmentId, [
            'starred' => 1,
            'starred_by' => $login,
        ]);

        return $this->responses->row($request, [
            'result' => $result,
            'starred' => 1,
            'starred_by' => $login,
        ]);
    }

    private function unstar(ApiRequest $request, int $segmentId): Response
    {
        $result = $this->segments->update($segmentId, [
            'starred' => 0,
            'starred_by' => null,
        ]);

        return $this->responses->row($request, [
            'starred' => 0,
            'result' => $result,
        ]);
    }

    /** @return StoredSegment|Response */
    private function editableSegment(ApiRequest $request, int $segmentId): array|Response
    {
        if (! $this->authorizer->hasSomeViewAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                'You must have view access to at least one website.',
                401,
            );
        }

        $segment = $this->segments->find($segmentId);

        if ($segment === null) {
            return $this->responses->error($request, 'Requested segment not found', 400);
        }

        $superUser = $this->authorizer->hasSuperUserAccess($request->authentication);
        $siteId = (int) ($segment['enable_only_idsite'] ?? 0);

        if (! $superUser
            && $siteId !== 0
            && ! $this->authorizer->hasViewAccessToSite($request->authentication, $siteId)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'view' access for the website id = {$siteId}.",
                401,
            );
        }

        if ($segment['deleted'] !== 0) {
            return $this->responses->error($request, 'This segment is marked as deleted. ', 400);
        }

        if ($superUser) {
            return $segment;
        }

        $login = $this->authorizer->authenticatedLogin($request->authentication);

        if ($login === null || $login === 'anonymous') {
            return $this->responses->error($request, 'You must be logged in to access this resource.', 401);
        }

        if ($segment['login'] !== $login) {
            return $this->responses->error(
                $request,
                'You can only edit and delete custom segments that you have created yourself. '.
                    "This segment was created and 'shared with you' by the Super User. ".
                    "To modify this segment, you can first create a new one by clicking on 'Add new segment'. ".
                    "Then you can customize the segment's definition.",
                400,
            );
        }

        if ($siteId === 0) {
            return $this->responses->error(
                $request,
                'Only a Super User can update segments that apply to all websites.',
                400,
            );
        }

        return $segment;
    }
}
