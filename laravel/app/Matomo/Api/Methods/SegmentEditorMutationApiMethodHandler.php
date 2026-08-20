<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Segments\EditableStoredSegmentResolver;
use App\Matomo\Segments\Events\SegmentUpdating;
use App\Matomo\Segments\MutableStoredSegmentRepository;
use App\Matomo\Segments\SegmentCacheInvalidator;
use App\Matomo\Segments\SegmentCreationAuthorizer;
use App\Matomo\Segments\SegmentEditorSettings;
use App\Matomo\Segments\SegmentRearchiveScheduler;
use App\Matomo\Segments\SegmentWriteDenied;
use App\Matomo\Segments\StoredSegmentReader;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class SegmentEditorMutationApiMethodHandler implements ApiMethodHandler
{
    private const array METHODS = ['SegmentEditor.add', 'SegmentEditor.update'];

    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private MutableStoredSegmentRepository $segments,
        private StoredSegmentReader $reader,
        private SegmentCreationAuthorizer $creation,
        private SegmentEditorSettings $settings,
        private SegmentRearchiveScheduler $rearchive,
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
            throw new LogicException('The SegmentEditor mutation API handler does not support this request.');
        }

        return $request->method === 'SegmentEditor.add'
            ? $this->add($request)
            : $this->update($request);
    }

    private function add(ApiRequest $request): Response
    {
        $parameters = $request->segmentEditor
            ?? throw new LogicException('The SegmentEditor API parameters were not parsed.');
        $denied = $this->validateTargetSite($request, $parameters->siteId, true);

        if ($denied !== null) {
            return $denied;
        }

        $superUser = $this->authorizer->hasSuperUserAccess($request->authentication);

        if ($parameters->enabledAllUsers && ! $superUser) {
            return $this->responses->error(
                $request,
                'enabledAllUsers=1 requires Super User access',
                400,
            );
        }

        $autoArchiveError = $this->validateAutoArchive(
            $request,
            $parameters->autoArchive ?? false,
            $parameters->siteId,
            $superUser,
        );

        if ($autoArchiveError !== null) {
            return $autoArchiveError;
        }

        $login = $this->authorizer->authenticatedLogin($request->authentication);

        if ($login === null || $login === 'anonymous') {
            return $this->responses->error($request, 'You must be logged in to access this resource.', 401);
        }

        try {
            $values = $this->mutationValues($request, true);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
        }

        $values['login'] = $login;
        $values['ts_created'] = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $values['starred'] = 0;
        $values['starred_by'] = null;
        $values['deleted'] = 0;
        $segmentId = $this->segments->create($values);

        if ($segmentId < 1) {
            return $this->responses->error($request, 'The segment could not be created.', 500);
        }

        $this->cache->clear();

        if (($parameters->autoArchive ?? false)
            && ! $this->settings->browserTriggerEnabled()
            && $this->settings->processNewSegmentsFrom() !== 'segment_creation_time') {
            $segment = $this->segments->find($segmentId);

            if ($segment !== null) {
                $this->rearchive->schedule($segment);
            }
        }

        return $this->responses->scalar($request, $segmentId);
    }

    private function update(ApiRequest $request): Response
    {
        $parameters = $request->segmentEditor
            ?? throw new LogicException('The SegmentEditor API parameters were not parsed.');
        $segmentId = $parameters->segmentId
            ?? throw new LogicException('The segment ID was not parsed.');
        try {
            $segment = $this->editableSegments->resolve($request->authentication, $segmentId);
        } catch (SegmentWriteDenied $segmentWriteDenied) {
            return $this->responses->error(
                $request,
                $segmentWriteDenied->getMessage(),
                $segmentWriteDenied->status,
            );
        }

        $denied = $this->validateTargetSite($request, $parameters->siteId, false);

        if ($denied !== null) {
            return $denied;
        }

        $superUser = $this->authorizer->hasSuperUserAccess($request->authentication);
        $enabledAllUsers = $parameters->enabledAllUsers ?? false;
        $targetSiteId = $parameters->siteId ?? 0;

        if ((int) $segment['enable_all_users'] !== (int) $enabledAllUsers && ! $superUser) {
            return $this->responses->error(
                $request,
                'Changing value for enabledAllUsers is permitted to super users only.',
                400,
            );
        }

        if ((int) ($segment['enable_only_idsite'] ?? 0) !== $targetSiteId
            && ! $this->creation->allowed($request->authentication, $parameters->siteId)) {
            return $this->responses->error(
                $request,
                'Changing value for enable_only_idsite requires permission to add segments for the target site.',
                400,
            );
        }

        $autoArchive = $parameters->autoArchive ?? false;
        $autoArchiveError = $this->validateAutoArchive(
            $request,
            $autoArchive,
            $parameters->siteId,
            $superUser,
        );

        if ($autoArchiveError !== null) {
            return $autoArchiveError;
        }

        try {
            $values = $this->mutationValues($request, false);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
        }

        $this->events->dispatch(new SegmentUpdating($segmentId, $values));
        $this->segments->update($segmentId, $values);
        $definitionChanged = $segment['definition'] !== $values['definition'];

        if ($definitionChanged && $autoArchive && ! $this->settings->browserTriggerEnabled()) {
            $updated = $this->segments->find($segmentId);

            if ($updated !== null) {
                $this->rearchive->schedule($updated);
            }
        }

        $this->cache->clear();

        return $this->responses->success($request);
    }

    /** @return array<string, bool|int|string|null> */
    private function mutationValues(ApiRequest $request, bool $creating): array
    {
        $parameters = $request->segmentEditor
            ?? throw new LogicException('The SegmentEditor API parameters were not parsed.');
        $name = $parameters->name ?? '';

        if ($name === '') {
            throw new InvalidArgumentException('Invalid name for this custom segment.');
        }

        $name = htmlspecialchars($name, ENT_QUOTES | ENT_HTML401, 'UTF-8');
        $definition = $parameters->definition ?? '';
        $definition = str_replace(['#', "'", '&'], ['%23', '%27', '%26'], $definition);

        try {
            $this->reader->validateDefinition($definition);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new InvalidArgumentException(
                'The specified segment is invalid: '.$invalidArgumentException->getMessage(),
                previous: $invalidArgumentException,
            );
        }

        return [
            'name' => $name,
            'definition' => $definition,
            'enable_all_users' => (int) ($parameters->enabledAllUsers ?? false),
            'enable_only_idsite' => $parameters->siteId ?? 0,
            'auto_archive' => (int) ($parameters->autoArchive ?? false),
            ...($creating ? [] : [
                'ts_last_edit' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
            ]),
        ];
    }

    private function validateTargetSite(
        ApiRequest $request,
        ?int $siteId,
        bool $requireCreationAccess,
    ): ?Response {
        $superUser = $this->authorizer->hasSuperUserAccess($request->authentication);

        if ($siteId === null) {
            if (! $superUser) {
                return $this->responses->error(
                    $request,
                    'This action requires Super User access.',
                    401,
                );
            }

            if ($requireCreationAccess && ! $this->settings->allSitesAllowed()) {
                return $this->responses->error(
                    $request,
                    'Adding segments for all websites has been disabled.',
                    400,
                );
            }
        } elseif (! $this->authorizer->hasViewAccessToSite($request->authentication, $siteId)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'view' access for the website id = {$siteId}.",
                401,
            );
        }

        if ($requireCreationAccess
            && ! $this->creation->allowed($request->authentication, $siteId)) {
            return $this->responses->error(
                $request,
                "You don't have the required access level to create and edit segments.",
                401,
            );
        }

        return null;
    }

    private function validateAutoArchive(
        ApiRequest $request,
        bool $autoArchive,
        ?int $siteId,
        bool $superUser,
    ): ?Response {
        if (! $autoArchive) {
            return $this->settings->realtimeAllowed()
                ? null
                : $this->responses->error(
                    $request,
                    'Real time segments are disabled. You need to enable auto archiving.',
                    400,
                );
        }

        if ($siteId === null && ! $superUser) {
            return $this->responses->error(
                $request,
                'To modify a pre-processed segment for all websites, a user must have super user access.',
                401,
            );
        }

        if ($this->settings->browserTriggerEnabled()) {
            return $this->responses->error(
                $request,
                'Pre-processed segments can only be created if browser triggered archiving is disabled.',
                400,
            );
        }

        return null;
    }
}
