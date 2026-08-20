<?php

declare(strict_types=1);

namespace App\Matomo\Login;

interface LoginAttemptGuard
{
    public function status(string $ipAddress, string $login): LoginAttemptStatus;

    public function recordFailure(string $ipAddress, string $login): void;
}
