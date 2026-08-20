<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class UsersManagerTokenRequest
{
    public function __construct(
        public string $login,
        #[\SensitiveParameter]
        public string $passwordConfirmation,
        public string $description,
        public ?string $expireDate,
        public int $expireHours,
        public bool $secureOnly,
    ) {}
}
