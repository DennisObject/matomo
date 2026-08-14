<?php

declare(strict_types=1);

namespace App\Matomo\Login;

interface BruteForceUnblocker
{
    public function unblockCurrentlyBlocked(): int;
}
