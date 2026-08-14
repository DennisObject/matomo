<?php

declare(strict_types=1);

namespace App\Matomo\Login;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use JsonException;
use Matomo\Network\IP;

final readonly class DatabaseBruteForceUnblocker implements BruteForceUnblocker
{
    public function __construct(
        private ConnectionInterface $connection,
        private ?int $configuredMaxAttempts,
        private ?int $configuredTimeRange,
        /** @var list<string>|null */
        private ?array $configuredAllowlist,
    ) {}

    public function unblockCurrentlyBlocked(): int
    {
        $maxAttempts = $this->configuredMaxAttempts ?? $this->integerSetting('maxAllowedRetries', 20);
        $timeRange = $this->configuredTimeRange ?? $this->integerSetting('allowedRetriesTimeRange', 60);
        $allowlist = $this->configuredAllowlist ?? $this->listSetting('whitelisteBruteForceIps');
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

    private function integerSetting(string $name, int $default): int
    {
        $value = $this->setting($name);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /** @return list<string> */
    private function listSetting(string $name): array
    {
        $value = $this->setting($name);

        if (! is_string($value)) {
            return [];
        }

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];
    }

    private function setting(string $name): mixed
    {
        return $this->connection
            ->table('plugin_setting')
            ->where('plugin_name', 'Login')
            ->where('user_login', '')
            ->where('setting_name', $name)
            ->value('setting_value');
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
