<?php

declare(strict_types=1);

namespace App\Matomo\Archiving\Events;

final readonly class CronArchivingStarting
{
    /** @param list<int> $siteIds */
    public function __construct(public array $siteIds) {}
}
