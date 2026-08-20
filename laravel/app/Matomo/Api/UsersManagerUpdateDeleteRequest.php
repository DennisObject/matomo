<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class UsersManagerUpdateDeleteRequest
{
    public function __construct(
        public string $login,
        #[\SensitiveParameter]
        public ?string $password,
        public ?string $email,
        public bool $passwordIsHashed,
        #[\SensitiveParameter]
        public ?string $passwordConfirmation,
    ) {}
}
