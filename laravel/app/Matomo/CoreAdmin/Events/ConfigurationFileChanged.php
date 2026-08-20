<?php

declare(strict_types=1);

namespace App\Matomo\CoreAdmin\Events;

final readonly class ConfigurationFileChanged
{
    public function __construct(public string $path) {}
}
