<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Scheduling\ScheduledTaskRunner;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class RunScheduledTasksCommandTest extends TestCase
{
    public function test_prints_due_scheduled_tasks(): void
    {
        $this->app->instance(ScheduledTaskRunner::class, new class implements ScheduledTaskRunner
        {
            public function run(): array
            {
                return [
                    ['task' => 'PrivacyManager.deleteLogData', 'output' => 'done'],
                ];
            }
        });

        $command = $this->artisan('scheduled-tasks:run');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->expectsOutput('PrivacyManager.deleteLogData: done')->assertSuccessful();
    }

    public function test_accepts_the_legacy_core_alias(): void
    {
        $command = $this->artisan('core:run-scheduled-tasks');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful();
    }
}
