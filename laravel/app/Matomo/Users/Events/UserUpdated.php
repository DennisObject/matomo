<?php

declare(strict_types=1);

namespace App\Matomo\Users\Events;

final readonly class UserUpdated
{
    public function __construct(
        public string $login,
        public bool $passwordChanged,
        public string $email,
    ) {}
}
