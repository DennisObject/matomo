<?php

declare(strict_types=1);

namespace App\Matomo\Users;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseUserIdentityRepository implements UserIdentityRepository
{
    public function __construct(private ConnectionInterface $connection) {}

    public function loginExists(string $login): bool
    {
        return $this->connection->table('user')
            ->whereRaw('LOWER(login) = LOWER(?)', [$login])
            ->exists();
    }

    public function emailExists(string $email): bool
    {
        return $this->connection->table('user')->where('email', $email)->exists();
    }

    public function loginForEmail(string $email): ?string
    {
        $login = $this->connection->table('user')->where('email', $email)->value('login');

        return is_string($login) ? $login : null;
    }
}
