<?php

declare(strict_types=1);

namespace App\Matomo\Authentication;

interface PasswordConfirmationVerifier
{
    public function isCorrect(
        string $login,
        #[\SensitiveParameter]
        string $password,
    ): bool;

    public function isCorrectHash(
        string $login,
        #[\SensitiveParameter]
        string $passwordHash,
    ): bool;
}
