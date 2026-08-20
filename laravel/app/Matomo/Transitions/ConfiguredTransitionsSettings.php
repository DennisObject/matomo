<?php

declare(strict_types=1);

namespace App\Matomo\Transitions;

use App\Matomo\Config\InstallationConfig;

final readonly class ConfiguredTransitionsSettings implements TransitionsSettings
{
    public function __construct(private InstallationConfig $configuration) {}

    public function maxPeriodAllowed(int $siteId): string
    {
        return $this->configuration->transitionsMaxPeriodAllowed($siteId);
    }
}
