<?php

declare(strict_types=1);

namespace App\Matomo\Transitions;

interface TransitionsSettings
{
    public function maxPeriodAllowed(int $siteId): string;
}
