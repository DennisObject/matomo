<?php

declare(strict_types=1);

namespace App\Matomo\TwoFactorAuth;

interface TwoFactorCodeVerifier
{
    public function verify(
        string $login,
        #[\SensitiveParameter]
        string $authCode,
    ): bool;
}
