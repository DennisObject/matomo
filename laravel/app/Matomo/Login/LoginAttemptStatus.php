<?php

declare(strict_types=1);

namespace App\Matomo\Login;

enum LoginAttemptStatus
{
    case Allowed;
    case IpBlocked;
    case UserBlocked;
}
