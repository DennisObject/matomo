<?php

declare(strict_types=1);

namespace App\Matomo\Scheduling;

use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Scheduling\Events\ScheduledTaskExecutionChecking;
use App\Matomo\Scheduling\Events\ScheduledTaskFinished;
use App\Matomo\Scheduling\Events\ScheduledTasksCollecting;
use App\Matomo\Scheduling\Events\ScheduledTaskStarting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class DatabaseScheduledTaskRunner implements ScheduledTaskRunner
{
    private const string TIMETABLE_OPTION = 'TaskScheduler.timetable';

    private const string RETRY_OPTION = 'TaskScheduler.retryList';

    public function __construct(
        private MutableOptionRepository $options,
        private ScheduledTaskLock $locks,
        private Dispatcher $events,
        private LoggerInterface $logger,
    ) {}

    public function run(): array
    {
        $collecting = new ScheduledTasksCollecting;
        $this->events->dispatch($collecting);
        $tasks = $this->uniqueTasks($collecting->tasks);
        $this->removeInactiveTasks($tasks);
        $this->logger->info('Starting Scheduled tasks... ');
        $results = [];
        $readTimetable = true;
        $timetable = [];

        for ($priority = ScheduledTask::HIGHEST_PRIORITY; $priority <= ScheduledTask::LOWEST_PRIORITY; $priority++) {
            foreach ($tasks as $task) {
                if ($task->priority !== $priority || ! $this->locks->acquire($task->name, $task->lockTtl)) {
                    continue;
                }

                try {
                    if ($readTimetable) {
                        $timetable = $this->storedIntegerMap(self::TIMETABLE_OPTION);
                        $readTimetable = false;
                    }

                    $now = CarbonImmutable::now('UTC');
                    $shouldExecute = isset($timetable[$task->name])
                        && $now->getTimestamp() >= $timetable[$task->name];

                    if (! isset($timetable[$task->name]) || $shouldExecute) {
                        $timetable[$task->name] = $task->nextRun($now);
                        $this->storeIntegerMap(self::TIMETABLE_OPTION, $timetable);
                        $readTimetable = true;
                    }

                    $checking = new ScheduledTaskExecutionChecking($shouldExecute, $task);
                    $this->events->dispatch($checking);

                    if (! $checking->shouldExecute) {
                        continue;
                    }

                    $readTimetable = true;
                    [$output, $retry] = $this->execute($task);

                    if ($retry) {
                        $this->scheduleRetry($task);
                    } else {
                        $this->clearRetry($task->name);
                    }

                    $results[] = ['task' => $task->name, 'output' => $output];
                } finally {
                    $this->locks->release();
                }
            }
        }

        $this->logger->info('done');

        return $results;
    }

    /** @return array{string, bool} */
    private function execute(ScheduledTask $task): array
    {
        $this->logger->info('Scheduler: executing task {taskName}...', ['taskName' => $task->name]);
        $starting = new ScheduledTaskStarting($task);
        $this->events->dispatch($starting);
        $start = microtime(true);
        $retry = false;
        $successful = true;

        try {
            ($starting->task->execute)();
            $output = 'Time elapsed: '.number_format(microtime(true) - $start, 3, '.', '').'s';
        } catch (Throwable $throwable) {
            $this->logger->error(
                "Scheduler: Error {errorMessage} for task '{task}'",
                ['errorMessage' => $throwable->getMessage(), 'task' => $task->name],
            );
            $output = 'ERROR: '.$throwable->getMessage();
            $retry = $throwable instanceof RetryableScheduledTaskException;
            $successful = false;
        }

        $this->events->dispatch(new ScheduledTaskFinished(
            $starting->task,
            $output,
            $successful,
        ));
        $this->logger->info('Scheduler: finished. {timeElapsed}', ['timeElapsed' => $output]);

        return [$output, $retry];
    }

    private function scheduleRetry(ScheduledTask $task): void
    {
        $retries = $this->storedIntegerMap(self::RETRY_OPTION);
        $retryCount = $retries[$task->name] ?? 0;

        if ($retryCount > 10_000) {
            $retryCount = 0;
        }

        if ($retryCount === 3) {
            unset($retries[$task->name]);
            $this->storeIntegerMap(self::RETRY_OPTION, $retries);
            $this->logger->warning(
                "Scheduler: '{task}' has already been retried three times, giving up",
                ['task' => $task->name],
            );

            return;
        }

        $timetable = $this->storedIntegerMap(self::TIMETABLE_OPTION);
        $timetable[$task->name] = CarbonImmutable::now('UTC')->addHour()->getTimestamp();
        $this->storeIntegerMap(self::TIMETABLE_OPTION, $timetable);
        $retries[$task->name] = $retryCount + 1;
        $this->storeIntegerMap(self::RETRY_OPTION, $retries);
    }

    private function clearRetry(string $taskName): void
    {
        $retries = $this->storedIntegerMap(self::RETRY_OPTION);

        if (isset($retries[$taskName])) {
            unset($retries[$taskName]);
            $this->storeIntegerMap(self::RETRY_OPTION, $retries);
        }
    }

    /** @param list<ScheduledTask> $tasks */
    private function removeInactiveTasks(array $tasks): void
    {
        $activeNames = array_fill_keys(array_map(
            static fn (ScheduledTask $task): string => $task->name,
            $tasks,
        ), true);
        $timetable = $this->storedIntegerMap(self::TIMETABLE_OPTION);
        $filtered = array_intersect_key($timetable, $activeNames);

        if ($filtered !== $timetable) {
            $this->storeIntegerMap(self::TIMETABLE_OPTION, $filtered);
        }
    }

    /**
     * @param  list<ScheduledTask>  $tasks
     * @return list<ScheduledTask>
     */
    private function uniqueTasks(array $tasks): array
    {
        $unique = [];

        foreach ($tasks as $task) {
            $unique[$task->name] = $task;
        }

        return array_values($unique);
    }

    /** @return array<string, int> */
    private function storedIntegerMap(string $option): array
    {
        $serialized = $this->options->value($option);

        if ($serialized === null || $serialized === '') {
            return [];
        }

        $decoded = @unserialize($serialized, ['allowed_classes' => false]);

        if (! is_array($decoded)) {
            return [];
        }

        $values = [];

        foreach ($decoded as $key => $value) {
            if (is_string($key) && (is_int($value) || ctype_digit((string) $value))) {
                $values[$key] = (int) $value;
            }
        }

        return $values;
    }

    /** @param array<string, int> $values */
    private function storeIntegerMap(string $option, array $values): void
    {
        $this->options->set($option, serialize($values));
    }
}
