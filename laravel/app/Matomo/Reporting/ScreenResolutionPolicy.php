<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

interface ScreenResolutionPolicy
{
    public function detectionDisabled(int $idSite): bool;
}
