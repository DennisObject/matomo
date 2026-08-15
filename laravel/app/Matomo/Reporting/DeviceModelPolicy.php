<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

interface DeviceModelPolicy
{
    public function detectionDisabled(int $idSite): bool;
}
