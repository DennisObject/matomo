<?php

declare(strict_types=1);

namespace App\Matomo\Scheduling\Events;

use App\Matomo\Scheduling\ScheduledTask;

final class ScheduledTaskStarting
{
    public function __construct(public ScheduledTask $task) {}
}
