<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Archiving\CronArchiveRunner;
use App\Matomo\Archiving\CronArchiveRunResult;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class CoreArchiveCommandTest extends TestCase
{
    public function test_runs_scheduled_archiving_and_prints_the_result_lines(): void
    {
        $this->app->instance(CronArchiveRunner::class, new class implements CronArchiveRunner
        {
            public function run(): CronArchiveRunResult
            {
                return new CronArchiveRunResult(['Archived 1 report.'], 1, 0);
            }
        });

        $command = $this->artisan('core:archive');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->expectsOutput('Archived 1 report.')->assertSuccessful();
    }

    public function test_fails_when_archiving_reports_errors(): void
    {
        $this->app->instance(CronArchiveRunner::class, new class implements CronArchiveRunner
        {
            public function run(): CronArchiveRunResult
            {
                return new CronArchiveRunResult(['Archiving failed.'], 0, 1);
            }
        });

        $command = $this->artisan('core:archive');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->expectsOutput('Archiving failed.')->assertFailed();
    }
}
