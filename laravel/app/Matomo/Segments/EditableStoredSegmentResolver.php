<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;

/** @phpstan-import-type StoredSegment from StoredSegmentRepository */
final readonly class EditableStoredSegmentResolver
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private StoredSegmentRepository $segments,
    ) {}

    /** @return StoredSegment */
    public function resolve(ApiAuthentication $authentication, int $segmentId): array
    {
        if (! $this->authorizer->hasSomeViewAccess($authentication)) {
            throw new SegmentWriteDenied('You must have view access to at least one website.', 401);
        }

        $segment = $this->segments->find($segmentId);

        if ($segment === null) {
            throw new SegmentWriteDenied('Requested segment not found', 400);
        }

        $superUser = $this->authorizer->hasSuperUserAccess($authentication);
        $siteId = (int) ($segment['enable_only_idsite'] ?? 0);

        if (! $superUser
            && $siteId !== 0
            && ! $this->authorizer->hasViewAccessToSite($authentication, $siteId)) {
            throw new SegmentWriteDenied(
                "You can't access this resource as it requires 'view' access for the website id = {$siteId}.",
                401,
            );
        }

        if ($segment['deleted'] !== 0) {
            throw new SegmentWriteDenied('This segment is marked as deleted. ', 400);
        }

        if ($superUser) {
            return $segment;
        }

        $login = $this->authorizer->authenticatedLogin($authentication);

        if ($login === null || $login === 'anonymous') {
            throw new SegmentWriteDenied('You must be logged in to access this resource.', 401);
        }

        if ($segment['login'] !== $login) {
            throw new SegmentWriteDenied(
                'You can only edit and delete custom segments that you have created yourself. '.
                    "This segment was created and 'shared with you' by the Super User. ".
                    "To modify this segment, you can first create a new one by clicking on 'Add new segment'. ".
                    "Then you can customize the segment's definition.",
                400,
            );
        }

        if ($siteId === 0) {
            throw new SegmentWriteDenied(
                'This segment was made accessible to all sites by the super user. '.
                    'Now, only super users are allowed to update or remove it.',
                400,
            );
        }

        return $segment;
    }
}
