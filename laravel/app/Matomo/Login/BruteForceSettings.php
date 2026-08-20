<?php

declare(strict_types=1);

namespace App\Matomo\Login;

interface BruteForceSettings
{
    public function enabled(): bool;

    public function maxAttempts(): int;

    public function timeRangeMinutes(): int;

    /** @return list<string> */
    public function allowlist(): array;

    /** @return list<string> */
    public function blocklist(): array;
}
