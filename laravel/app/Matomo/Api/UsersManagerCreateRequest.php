<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class UsersManagerCreateRequest
{
    public function __construct(
        public string $login,
        #[\SensitiveParameter]
        public ?string $password,
        public string $email,
        public bool $passwordIsHashed,
        public ?int $initialSiteId,
        public ?int $expiryDays,
        #[\SensitiveParameter]
        public ?string $passwordConfirmation,
    ) {}
}
