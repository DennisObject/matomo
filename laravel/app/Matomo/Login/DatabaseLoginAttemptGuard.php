<?php

declare(strict_types=1);

namespace App\Matomo\Login;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Matomo\Network\IP;

final readonly class DatabaseLoginAttemptGuard implements LoginAttemptGuard
{
    public function __construct(
        private ConnectionInterface $connection,
        private BruteForceSettings $settings,
    ) {}

    public function status(string $ipAddress, string $login): LoginAttemptStatus
    {
        if (! $this->settings->enabled()) {
            return LoginAttemptStatus::Allowed;
        }

        if ($this->isInRanges($ipAddress, $this->settings->blocklist())) {
            return LoginAttemptStatus::IpBlocked;
        }

        if (! $this->isInRanges($ipAddress, $this->settings->allowlist())) {
            $attempts = $this->connection
                ->table('brute_force_log')
                ->where('ip_address', $ipAddress)
                ->where('attempted_at', '>', $this->startTime($this->settings->timeRangeMinutes()))
                ->count();

            if ($attempts > $this->settings->maxAttempts()) {
                return LoginAttemptStatus::IpBlocked;
            }
        }

        if ($login !== '' && strtolower($login) !== 'anonymous') {
            $attempts = $this->connection
                ->table('brute_force_log')
                ->where('login', $login)
                ->where('attempted_at', '>', $this->startTime(60))
                ->count();
            $threshold = max(10, $this->settings->maxAttempts() * 3);

            if ($attempts > $threshold) {
                return LoginAttemptStatus::UserBlocked;
            }
        }

        return LoginAttemptStatus::Allowed;
    }

    public function recordFailure(string $ipAddress, string $login): void
    {
        if (! $this->settings->enabled()) {
            return;
        }

        $this->connection->table('brute_force_log')->insert([
            'ip_address' => $ipAddress,
            'attempted_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
            'login' => $login,
        ]);
    }

    /** @param list<string> $ranges */
    private function isInRanges(string $ipAddress, array $ranges): bool
    {
        return $ranges !== [] && IP::fromStringIP($ipAddress)->isInRanges($ranges);
    }

    private function startTime(int $minutes): string
    {
        return CarbonImmutable::now('UTC')->subMinutes($minutes)->format('Y-m-d H:i:s');
    }
}
