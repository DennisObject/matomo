<?php

declare(strict_types=1);

namespace App\Matomo\Scheduling;

interface ScheduledTaskRunner
{
    /** @return list<array{task: string, output: string}> */
    public function run(): array;
}
