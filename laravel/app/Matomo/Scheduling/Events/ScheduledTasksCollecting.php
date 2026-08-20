<?php

declare(strict_types=1);

namespace App\Matomo\Scheduling\Events;

use App\Matomo\Scheduling\ScheduledTask;

final class ScheduledTasksCollecting
{
    /** @param list<ScheduledTask> $tasks */
    public function __construct(public array $tasks = []) {}
}
