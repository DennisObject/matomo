<?php

declare(strict_types=1);

namespace App\Matomo\Users;

use App\Matomo\Config\InstallationConfig;

final readonly class ConfiguredUserPreferenceDefaults implements UserPreferenceDefaults
{
    public function __construct(private InstallationConfig $installation) {}

    public function reportDate(): string
    {
        return $this->installation->defaultReportDate();
    }
}
