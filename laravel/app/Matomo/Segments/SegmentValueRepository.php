<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

interface SegmentValueRepository
{
    /** @return list<float|int|string> */
    public function mostFrequent(int $siteId, string $segmentName, int $limit): array;
}
