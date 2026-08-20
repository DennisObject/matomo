<?php

declare(strict_types=1);

namespace App\Matomo\Scheduling;

use Carbon\CarbonImmutable;
use Closure;
use InvalidArgumentException;

final readonly class ScheduledTask
{
    public const int HIGHEST_PRIORITY = 0;

    public const int HIGH_PRIORITY = 3;

    public const int NORMAL_PRIORITY = 6;

    public const int LOW_PRIORITY = 9;

    public const int LOWEST_PRIORITY = 12;

    /**
     * @param  Closure(): void  $execute
     * @param  Closure(CarbonImmutable): int  $nextRunAt
     */
    public function __construct(
        public string $name,
        public Closure $execute,
        public Closure $nextRunAt,
        public int $priority = self::NORMAL_PRIORITY,
        public int $lockTtl = 3600,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('A scheduled task name cannot be empty.');
        }

        if ($priority < self::HIGHEST_PRIORITY || $priority > self::LOWEST_PRIORITY) {
            throw new InvalidArgumentException("The scheduled task '{$name}' has an invalid priority.");
        }

        if ($lockTtl !== -1 && ($lockTtl < 1 || $lockTtl > 604_800)) {
            throw new InvalidArgumentException("The scheduled task '{$name}' has an invalid lock TTL.");
        }
    }

    public function nextRun(CarbonImmutable $now): int
    {
        return ($this->nextRunAt)($now);
    }
}
