<?php

declare(strict_types=1);

namespace App\Matomo\Users\Events;

final readonly class UserInvited
{
    public function __construct(public string $login, public string $email) {}
}
