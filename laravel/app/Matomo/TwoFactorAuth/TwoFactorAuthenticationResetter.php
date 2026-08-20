<?php

declare(strict_types=1);

namespace App\Matomo\TwoFactorAuth;

interface TwoFactorAuthenticationResetter
{
    public function reset(string $login): void;
}
