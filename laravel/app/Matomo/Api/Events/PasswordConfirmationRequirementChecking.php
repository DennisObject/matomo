<?php

declare(strict_types=1);

namespace App\Matomo\Api\Events;

final class PasswordConfirmationRequirementChecking
{
    public function __construct(
        public readonly string $login,
        public bool $required = true,
    ) {}
}
