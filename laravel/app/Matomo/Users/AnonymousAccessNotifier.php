<?php

declare(strict_types=1);

namespace App\Matomo\Users;

interface AnonymousAccessNotifier
{
    /** @param list<int> $siteIds */
    public function notify(array $siteIds): void;
}
