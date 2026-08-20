<?php

declare(strict_types=1);

namespace App\Matomo\CoreAdmin\Events;

final class ConfigurationSaving
{
    /** @param array<string, mixed> $configuration */
    public function __construct(public array $configuration) {}
}
