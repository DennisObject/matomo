<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Segments\EditableStoredSegmentResolver;
use App\Matomo\Segments\Events\SegmentDeactivating;
use App\Matomo\Segments\MutableStoredSegmentRepository;
use App\Matomo\Segments\SegmentCacheInvalidator;
use App\Matomo\Segments\SegmentWriteDenied;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

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
        private EditableStoredSegmentResolver $editableSegments,
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
        try {
            $this->editableSegments->resolve($request->authentication, $segmentId);
        } catch (SegmentWriteDenied $segmentWriteDenied) {
            return $this->responses->error(
                $request,
                $segmentWriteDenied->getMessage(),
                $segmentWriteDenied->status,
            );
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
}
