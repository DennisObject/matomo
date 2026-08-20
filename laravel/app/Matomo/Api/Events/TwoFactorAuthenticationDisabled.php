<?php

declare(strict_types=1);

namespace App\Matomo\Api\Events;

final readonly class TwoFactorAuthenticationDisabled
{
    public function __construct(public string $login) {}
}
