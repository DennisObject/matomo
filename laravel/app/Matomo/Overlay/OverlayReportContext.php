<?php

declare(strict_types=1);

namespace App\Matomo\Overlay;

final readonly class OverlayReportContext
{
    public function __construct(
        public int $followingPagesLimit,
        public PageUrlQueryParameterFilter $pageUrls,
    ) {}
}
