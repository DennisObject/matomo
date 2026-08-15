<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class UsersManagerIdentityRequest
{
    public function __construct(
        public ?string $userLogin,
        public ?string $userEmail,
    ) {}
}
