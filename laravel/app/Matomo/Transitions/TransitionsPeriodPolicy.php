<?php

declare(strict_types=1);

namespace App\Matomo\Transitions;

interface TransitionsPeriodPolicy
{
    public function isAllowed(int $siteId, string $period, string $date): bool;
}
