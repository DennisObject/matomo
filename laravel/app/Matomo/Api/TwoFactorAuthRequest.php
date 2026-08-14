<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class TwoFactorAuthRequest
{
    public function __construct(
        public string $userLogin,
        #[\SensitiveParameter]
        private string $passwordConfirmation,
    ) {}

    public function passwordConfirmation(): string
    {
        return $this->passwordConfirmation;
    }
}
