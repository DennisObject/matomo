<?php

declare(strict_types=1);

namespace App\Matomo\TwoFactorAuth;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseTwoFactorUser implements TwoFactorUser
{
    public function __construct(private ConnectionInterface $connection) {}

    public function isEnabled(string $login): bool
    {
        if ($login === '' || strtolower($login) === 'anonymous') {
            return false;
        }

        $secret = $this->connection->table('user')->where('login', $login)->value('twofactor_secret');

        return is_string($secret) && $secret !== '';
    }
}
