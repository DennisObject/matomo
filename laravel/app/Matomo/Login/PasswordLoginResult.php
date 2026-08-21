<?php

declare(strict_types=1);

namespace App\Matomo\Login;

final readonly class PasswordLoginResult
{
    private function __construct(
        public bool $successful,
        public ?string $login,
        public string $error,
        public int $status,
    ) {}

    public static function success(string $login): self
    {
        return new self(true, $login, '', 200);
    }

    public static function failed(string $error, int $status = 403): self
    {
        return new self(false, null, $error, $status);
    }
}
