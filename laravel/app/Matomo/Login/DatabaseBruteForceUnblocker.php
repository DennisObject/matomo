<?php

declare(strict_types=1);

namespace App\Matomo\Login;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Matomo\Network\IP;

final readonly class DatabaseBruteForceUnblocker implements BruteForceUnblocker
{
    public function __construct(
        private ConnectionInterface $connection,
        private BruteForceSettings $settings,
    ) {}

    public function unblockCurrentlyBlocked(): int
    {
        $maxAttempts = $this->settings->maxAttempts();
        $timeRange = $this->settings->timeRangeMinutes();
        $allowlist = $this->settings->allowlist();
        $startTime = CarbonImmutable::now('UTC')->subMinutes($timeRange)->format('Y-m-d H:i:s');

        return $this->connection->transaction(function () use ($maxAttempts, $allowlist, $startTime): int {
            $blockedIps = $this->connection
                ->table('brute_force_log')
                ->select('ip_address')
                ->where('attempted_at', '>', $startTime)
                ->groupBy('ip_address')
                ->havingRaw('COUNT(*) > ?', [$maxAttempts])
                ->lockForUpdate()
                ->pluck('ip_address')
                ->filter(static fn (mixed $value): bool => is_string($value))
                ->reject(fn (string $ip): bool => $this->isAllowlisted($ip, $allowlist))
                ->values()
                ->all();

            if ($blockedIps === []) {
                return 0;
            }

            return $this->connection
                ->table('brute_force_log')
                ->whereIn('ip_address', $blockedIps)
                ->where('attempted_at', '>', $startTime)
                ->delete();
        });
    }

    /** @param list<string> $allowlist */
    private function isAllowlisted(string $ipAddress, array $allowlist): bool
    {
        if ($allowlist === []) {
            return false;
        }

        return IP::fromStringIP($ipAddress)->isInRanges($allowlist);
    }
}
