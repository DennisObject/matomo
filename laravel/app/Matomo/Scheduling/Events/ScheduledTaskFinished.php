<?php

declare(strict_types=1);

namespace App\Matomo\Scheduling\Events;

use App\Matomo\Scheduling\ScheduledTask;

final readonly class ScheduledTaskFinished
{
    public function __construct(
        public ScheduledTask $task,
        public string $output,
        public bool $successful,
    ) {}
}
