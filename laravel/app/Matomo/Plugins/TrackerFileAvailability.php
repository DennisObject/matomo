<?php

declare(strict_types=1);

namespace App\Matomo\Plugins;

interface TrackerFileAvailability
{
    public function canUpdate(): bool;
}
