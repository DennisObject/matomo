<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class UsersManagerSecurityMutationRequest
{
    public function __construct(
        public string $login,
        public ?bool $superuserEnabled,
        #[\SensitiveParameter]
        public ?string $passwordConfirmation,
    ) {}
}
