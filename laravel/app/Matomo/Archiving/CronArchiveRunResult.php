<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

final readonly class CronArchiveRunResult
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public array $lines,
        public int $archives,
        public int $errors,
    ) {}
}
