<?php

declare(strict_types=1);

namespace App\Matomo\Scheduling;

interface ScheduledTaskLock
{
    public function acquire(string $taskName, int $ttl): bool;

    public function release(): void;
}
