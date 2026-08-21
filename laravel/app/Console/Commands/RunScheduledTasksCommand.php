<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Matomo\Scheduling\ScheduledTaskRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('scheduled-tasks:run')]
#[Description('Run scheduled tasks that are due.')]
final class RunScheduledTasksCommand extends Command
{
    /** @var list<string> */
    protected $aliases = ['core:run-scheduled-tasks'];

    public function handle(ScheduledTaskRunner $runner): int
    {
        foreach ($runner->run() as $task) {
            $this->line($task['task'].': '.$task['output']);
        }

        return self::SUCCESS;
    }
}
