<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

interface SegmentValueRepository
{
    public function supports(string $segmentName): bool;

    /** @return list<string> */
    public function mostFrequent(int $siteId, string $segmentName, int $limit): array;
}
