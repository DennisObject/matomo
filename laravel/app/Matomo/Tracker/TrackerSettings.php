<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

interface TrackerSettings
{
    /** @return list<string> */
    public function campaignNameParameters(): array;

    /** @return list<string> */
    public function campaignKeywordParameters(): array;
}
