<?php

declare(strict_types=1);

namespace App\Matomo\Users;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseUserDirectoryRepository implements UserDirectoryRepository
{
    public function __construct(private ConnectionInterface $connection) {}

    public function users(array $logins = []): array
    {
        $query = $this->connection->table('user')->orderBy('login');
        if ($logins !== []) {
            $query->whereIn('login', $logins);
        }

        return array_values(array_map($this->array(...), $query->get()->all()));
    }

    public function logins(): array
    {
        return array_values(array_filter(
            $this->connection->table('user')->orderBy('login')->pluck('login')->all(),
            is_string(...),
        ));
    }

    public function user(string $login): ?array
    {
        $record = $this->connection->table('user')->where('login', $login)->first();

        return $record === null ? null : $this->array($record);
    }

    public function userByEmail(string $email): ?array
    {
        $record = $this->connection->table('user')->where('email', $email)->first();

        return $record === null ? null : $this->array($record);
    }

    public function superusers(): array
    {
        return array_values(array_map(
            $this->array(...),
            $this->connection->table('user')
                ->where('superuser_access', 1)
                ->orderBy('date_registered')
                ->get()
                ->all(),
        ));
    }

    public function visibleLogins(string $currentLogin, array $adminSiteIds): array
    {
        if ($adminSiteIds === []) {
            return [$currentLogin];
        }

        $logins = $this->connection->table('access')
            ->whereIn('idsite', $adminSiteIds)
            ->whereIn('access', ['view', 'write', 'admin'])
            ->distinct()
            ->pluck('login')
            ->all();
        $logins[] = $currentLogin;

        return array_values(array_unique(array_filter($logins, is_string(...))));
    }

    /** @return array<string, mixed> */
    private function array(object $record): array
    {
        return get_object_vars($record);
    }
}
