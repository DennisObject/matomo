<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class UsersManagerInviteMaintenanceRequest
{
    public function __construct(
        public string $login,
        public int $expiryDays,
        #[\SensitiveParameter]
        public ?string $passwordConfirmation,
    ) {}
}
