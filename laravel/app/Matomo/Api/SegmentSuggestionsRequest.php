<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class SegmentSuggestionsRequest
{
    public function __construct(public int $siteId, public string $segmentName) {}
}
