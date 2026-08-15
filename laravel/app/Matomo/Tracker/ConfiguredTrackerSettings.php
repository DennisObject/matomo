<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use App\Matomo\Config\InstallationConfig;

final readonly class ConfiguredTrackerSettings implements TrackerSettings
{
    public function __construct(private InstallationConfig $configuration) {}

    public function campaignNameParameters(): array
    {
        return $this->configuration->campaignNameParameters();
    }

    public function campaignKeywordParameters(): array
    {
        return $this->configuration->campaignKeywordParameters();
    }
}
