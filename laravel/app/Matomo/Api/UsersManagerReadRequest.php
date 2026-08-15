<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class UsersManagerReadRequest
{
    /** @param list<string> $userLogins */
    public function __construct(
        public array $userLogins,
        public ?string $userLogin,
        public ?string $userEmail,
    ) {}
}
