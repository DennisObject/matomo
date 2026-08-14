<?php

declare(strict_types=1);

namespace App\Matomo\Authentication;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabasePasswordConfirmationVerifier implements PasswordConfirmationVerifier
{
    public function __construct(private ConnectionInterface $connection) {}

    public function isCorrect(
        string $login,
        #[\SensitiveParameter]
        string $password,
    ): bool {
        $storedHash = $this->connection->table('user')->where('login', $login)->value('password');

        return is_string($storedHash) && password_verify(md5($password), $storedHash);
    }
}
