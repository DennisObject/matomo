<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

final readonly class TrackingRequestBatch
{
    /** @param list<TrackingRequest> $requests */
    public function __construct(
        public array $requests,
        public bool $doNotTrackHonored,
    ) {}
}
