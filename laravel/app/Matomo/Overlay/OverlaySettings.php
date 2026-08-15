<?php

declare(strict_types=1);

namespace App\Matomo\Overlay;

interface OverlaySettings
{
    public function followingPagesLimit(): int;

    /** @return list<string> */
    public function urlQueryParametersToExclude(): array;

    /** @return list<string> */
    public function campaignNameParameters(): array;

    /** @return list<string> */
    public function campaignKeywordParameters(): array;

    public function pageMaximumLength(): int;
}
