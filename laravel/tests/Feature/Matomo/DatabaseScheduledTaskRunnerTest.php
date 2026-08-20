<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Options\DatabaseOptionRepository;
use App\Matomo\Scheduling\DatabaseScheduledTaskLock;
use App\Matomo\Scheduling\DatabaseScheduledTaskRunner;
use App\Matomo\Scheduling\Events\ScheduledTaskExecutionChecking;
use App\Matomo\Scheduling\Events\ScheduledTaskFinished;
use App\Matomo\Scheduling\Events\ScheduledTasksCollecting;
use App\Matomo\Scheduling\RetryableScheduledTaskException;
use App\Matomo\Scheduling\ScheduledTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Psr\Log\NullLogger;
use Tests\TestCase;

class DatabaseScheduledTaskRunnerTest extends TestCase
{
    private Connection $connection;

    private Dispatcher $events;

    private DatabaseOptionRepository $options;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-15 12:00:00 UTC');
        config()->set('database.connections.matomo_scheduled_task_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_scheduled_task_test');

        $this->connection = $databases->connection('matomo_scheduled_task_test');
        $schema = $this->connection->getSchemaBuilder();
        $schema->create('option', static function (Blueprint $table): void {
            $table->string('option_name')->primary();
            $table->text('option_value');
            $table->boolean('autoload')->default(false);
        });
        $schema->create('locks', static function (Blueprint $table): void {
            $table->string('key', 70)->primary();
            $table->string('value')->nullable();
            $table->unsignedBigInteger('expiry_time')->default(9_999_999_999);
        });
        $this->events = new Dispatcher;
        $this->options = new DatabaseOptionRepository($this->connection);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_schedules_new_tasks_then_executes_due_tasks_in_priority_order(): void
    {
        $executed = [];
        $this->collect([
            $this->task('low', ScheduledTask::LOW_PRIORITY, $executed),
            $this->task('high', ScheduledTask::HIGH_PRIORITY, $executed),
        ]);
        $this->options->set('TaskScheduler.timetable', serialize(['inactive' => 1]));

        $this->assertSame([], $this->runner()->run());
        $this->assertSame([], $executed);
        $this->assertSame(['high', 'low'], array_keys($this->stored('TaskScheduler.timetable')));

        $this->options->set('TaskScheduler.timetable', serialize([
            'low' => CarbonImmutable::now('UTC')->subMinute()->getTimestamp(),
            'high' => CarbonImmutable::now('UTC')->subMinute()->getTimestamp(),
        ]));
        $results = $this->runner()->run();

        $this->assertSame(['high', 'low'], $executed);
        $this->assertSame(['high', 'low'], array_column($results, 'task'));
        $this->assertMatchesRegularExpression('/^Time elapsed: [0-9]+\.[0-9]{3}s$/D', $results[0]['output']);
        $this->assertGreaterThan(
            CarbonImmutable::now('UTC')->getTimestamp(),
            $this->stored('TaskScheduler.timetable')['high'],
        );
    }

    public function test_extension_can_skip_due_task_and_observe_completion(): void
    {
        $executed = [];
        $finished = [];
        $this->collect([$this->task('skip', ScheduledTask::NORMAL_PRIORITY, $executed)]);
        $this->events->listen(
            ScheduledTaskExecutionChecking::class,
            static function (ScheduledTaskExecutionChecking $event): void {
                $event->shouldExecute = false;
            },
        );
        $this->events->listen(
            ScheduledTaskFinished::class,
            static function (ScheduledTaskFinished $event) use (&$finished): void {
                $finished[] = $event->task->name;
            },
        );
        $this->options->set('TaskScheduler.timetable', serialize(['skip' => 1]));

        $this->assertSame([], $this->runner()->run());
        $this->assertSame([], $executed);
        $this->assertSame([], $finished);
    }

    public function test_retryable_failure_is_reported_and_rescheduled_in_one_hour(): void
    {
        $this->collect([
            new ScheduledTask(
                name: 'retry',
                execute: static function (): void {
                    throw new RetryableScheduledTaskException('try again');
                },
                nextRunAt: static fn (CarbonImmutable $now): int => $now->addDay()->getTimestamp(),
            ),
        ]);
        $this->options->set('TaskScheduler.timetable', serialize(['retry' => 1]));

        $results = $this->runner()->run();

        $this->assertSame([['task' => 'retry', 'output' => 'ERROR: try again']], $results);
        $this->assertSame(1, $this->stored('TaskScheduler.retryList')['retry']);
        $this->assertSame(
            CarbonImmutable::now('UTC')->addHour()->getTimestamp(),
            $this->stored('TaskScheduler.timetable')['retry'],
        );
        $this->assertSame(0, $this->connection->table('locks')->count());
    }

    public function test_active_lock_skips_task_and_expired_lock_is_recovered(): void
    {
        $executed = [];
        $this->collect([$this->task('locked', ScheduledTask::NORMAL_PRIORITY, $executed)]);
        $this->options->set('TaskScheduler.timetable', serialize(['locked' => 1]));
        $this->connection->table('locks')->insert([
            'key' => 'ScheduledTasklocked',
            'value' => 'another-runner',
            'expiry_time' => CarbonImmutable::now('UTC')->addHour()->getTimestamp(),
        ]);

        $this->assertSame([], $this->runner()->run());
        $this->assertSame([], $executed);

        $this->connection->table('locks')->update([
            'expiry_time' => CarbonImmutable::now('UTC')->subSecond()->getTimestamp(),
        ]);
        $this->assertSame(['locked'], array_column($this->runner()->run(), 'task'));
        $this->assertSame(['locked'], $executed);
        $this->assertSame(0, $this->connection->table('locks')->count());
    }

    /** @param list<ScheduledTask> $tasks */
    private function collect(array $tasks): void
    {
        $this->events->listen(
            ScheduledTasksCollecting::class,
            static function (ScheduledTasksCollecting $event) use ($tasks): void {
                $event->tasks = [...$event->tasks, ...$tasks];
            },
        );
    }

    /** @param list<string> $executed */
    private function task(string $name, int $priority, array &$executed): ScheduledTask
    {
        return new ScheduledTask(
            name: $name,
            execute: static function () use (&$executed, $name): void {
                $executed[] = $name;
            },
            nextRunAt: static fn (CarbonImmutable $now): int => $now->addDay()->getTimestamp(),
            priority: $priority,
        );
    }

    private function runner(): DatabaseScheduledTaskRunner
    {
        return new DatabaseScheduledTaskRunner(
            options: $this->options,
            locks: new DatabaseScheduledTaskLock($this->connection),
            events: $this->events,
            logger: new NullLogger,
        );
    }

    /** @return array<string, int> */
    private function stored(string $name): array
    {
        $value = $this->options->value($name);
        $decoded = unserialize((string) $value, ['allowed_classes' => false]);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
