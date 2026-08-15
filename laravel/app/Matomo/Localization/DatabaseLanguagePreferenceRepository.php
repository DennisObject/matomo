<?php

declare(strict_types=1);

namespace App\Matomo\Localization;

use Exception;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseLanguagePreferenceRepository implements MutableLanguagePreferenceRepository
{
    public function __construct(private ConnectionInterface $connection) {}

    public function forLogin(string $login): ?string
    {
        try {
            $language = $this->connection
                ->table('user_language')
                ->where('login', $login)
                ->value('language');
        } catch (Exception) {
            return null;
        }

        return is_string($language) ? $language : null;
    }

    public function setLanguage(string $login, string $language): bool
    {
        return $this->connection->table('user_language')->updateOrInsert(
            ['login' => $login],
            ['language' => $language],
        );
    }

    public function uses12HourClock(string $login): bool
    {
        try {
            return (bool) $this->connection
                ->table('user_language')
                ->where('login', $login)
                ->value('use_12_hour_clock');
        } catch (Exception) {
            return false;
        }
    }

    public function set12HourClock(string $login, bool $use12HourClock): bool
    {
        return $this->connection->table('user_language')->updateOrInsert(
            ['login' => $login],
            static fn (bool $exists): array => [
                ...($exists ? [] : ['language' => '']),
                'use_12_hour_clock' => (int) $use12HourClock,
            ],
        );
    }
}
