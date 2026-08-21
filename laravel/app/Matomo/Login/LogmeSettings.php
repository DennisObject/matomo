<?php

declare(strict_types=1);

namespace App\Matomo\Login;

final readonly class LogmeSettings
{
    public function __construct(public bool $enabled = false) {}
}
