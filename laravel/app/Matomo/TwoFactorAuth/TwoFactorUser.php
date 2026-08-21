<?php

declare(strict_types=1);

namespace App\Matomo\TwoFactorAuth;

interface TwoFactorUser
{
    public function isEnabled(string $login): bool;
}
