<?php

declare(strict_types=1);

namespace App\Matomo\Login;

use Illuminate\Database\ConnectionInterface;
use JsonException;

final class DatabaseBruteForceSettings implements BruteForceSettings
{
    /** @var array<string, mixed>|null */
    private ?array $storedSettings = null;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ?int $configuredMaxAttempts,
        private readonly ?int $configuredTimeRange,
        /** @var list<string>|null */
        private readonly ?array $configuredAllowlist,
    ) {}

    public function enabled(): bool
    {
        $value = $this->setting('enableBruteForceDetection');

        return $value === null || in_array($value, [true, 1, '1', 'true'], true);
    }

    public function maxAttempts(): int
    {
        return $this->configuredMaxAttempts ?? $this->integerSetting('maxAllowedRetries', 20);
    }

    public function timeRangeMinutes(): int
    {
        return $this->configuredTimeRange ?? $this->integerSetting('allowedRetriesTimeRange', 60);
    }

    public function allowlist(): array
    {
        return $this->configuredAllowlist ?? $this->listSetting('whitelisteBruteForceIps');
    }

    public function blocklist(): array
    {
        return $this->listSetting('blacklistedBruteForceIps');
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
        return $this->settings()[$name] ?? null;
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        if ($this->storedSettings !== null) {
            return $this->storedSettings;
        }

        $settings = $this->connection
            ->table('plugin_setting')
            ->where('plugin_name', 'Login')
            ->where('user_login', '')
            ->pluck('setting_value', 'setting_name')
            ->all();

        return $this->storedSettings = array_filter(
            $settings,
            static fn (mixed $value, mixed $name): bool => is_string($name),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
