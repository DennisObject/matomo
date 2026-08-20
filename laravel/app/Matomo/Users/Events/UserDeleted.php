<?php

declare(strict_types=1);

namespace App\Matomo\Users\Events;

final readonly class UserDeleted
{
    public function __construct(public string $login) {}
}
