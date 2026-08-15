<?php

declare(strict_types=1);

namespace App\Matomo\Users;

interface MutableUserRepository
{
    /** @return 'created'|'login-exists'|'email-exists'|'login-is-email'|'email-is-login' */
    public function create(
        string $login,
        #[\SensitiveParameter] string $password,
        string $email,
        bool $passwordIsHashed,
        ?int $initialSiteId,
    ): string;

    /** @return array{result: 'created'|'login-exists'|'email-exists'|'login-is-email'|'email-is-login', token?: string} */
    public function invite(
        string $login,
        string $email,
        int $initialSiteId,
        int $expiryDays,
        string $inviter,
    ): array;
}
