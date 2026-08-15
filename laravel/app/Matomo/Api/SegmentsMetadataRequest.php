<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class SegmentsMetadataRequest
{
    /** @param list<int> $siteIds */
    public function __construct(
        public array $siteIds,
        public bool $hideImplementationData,
        public bool $showAllSegments,
    ) {}
}
