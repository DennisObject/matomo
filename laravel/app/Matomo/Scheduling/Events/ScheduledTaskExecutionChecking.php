<?php

declare(strict_types=1);

namespace App\Matomo\Scheduling\Events;

use App\Matomo\Scheduling\ScheduledTask;

final class ScheduledTaskExecutionChecking
{
    public function __construct(
        public bool $shouldExecute,
        public readonly ScheduledTask $task,
    ) {}
}
