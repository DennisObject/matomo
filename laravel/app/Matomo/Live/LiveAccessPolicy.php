<?php

declare(strict_types=1);

namespace App\Matomo\Live;

interface LiveAccessPolicy
{
    public function visitorLogEnabled(int $siteId): bool;

    public function visitorProfileEnabled(int $siteId): bool;
}
