<?php

declare(strict_types=1);

namespace App\Matomo\Tour;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseTourDataRepository implements TourDataRepository
{
    public function __construct(private ConnectionInterface $connection) {}

    public function progress(string $login): array
    {
        $progress = [];
        $rows = $this->connection
            ->table('plugin_setting')
            ->select(['setting_name', 'setting_value'])
            ->where('plugin_name', 'Tour')
            ->where('user_login', $login)
            ->get();

        foreach ($rows as $row) {
            if (! is_string($row->setting_name ?? null)
                || (! is_int($row->setting_value ?? null) && ! is_string($row->setting_value ?? null))) {
                continue;
            }

            $progress[$row->setting_name] = (string) $row->setting_value !== ''
                && (string) $row->setting_value !== '0';
        }

        return $progress;
    }

    public function skip(string $login, string $challengeId): void
    {
        $settingName = $challengeId.'_skipped';

        $this->connection->transaction(function () use ($login, $settingName): void {
            $this->connection
                ->table('plugin_setting')
                ->where('plugin_name', 'Tour')
                ->where('user_login', $login)
                ->where('setting_name', $settingName)
                ->delete();
            $this->connection->table('plugin_setting')->insert([
                'plugin_name' => 'Tour',
                'user_login' => $login,
                'setting_name' => $settingName,
                'setting_value' => '1',
                'json_encoded' => 0,
            ]);
        });
    }

    public function hasTrackedData(): bool
    {
        return $this->connection->table('log_visit')->exists();
    }

    public function hasAddedWebsite(string $login): bool
    {
        return $this->connection
            ->table('site')
            ->where('idsite', '!=', 1)
            ->where('creator_login', $login)
            ->exists();
    }

    public function hasAddedScheduledReport(string $login): bool
    {
        return $this->connection->table('report')->where('login', $login)->exists();
    }

    public function hasCustomizedDashboard(string $login): bool
    {
        return $this->connection->table('user_dashboard')->where('login', $login)->exists();
    }

    public function hasAddedSegment(string $login): bool
    {
        return $this->connection->table('segment')->where('login', $login)->exists();
    }

    public function usesTwoFactorAuthentication(string $login): bool
    {
        $secret = $this->connection->table('user')->where('login', $login)->value('twofactor_secret');

        return is_string($secret) && $secret !== '';
    }
}
