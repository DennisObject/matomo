<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

use App\Matomo\Config\InstallationConfig;

final readonly class ConfiguredPrivacyFeatureFlags implements PrivacyFeatureFlags
{
    public function __construct(private InstallationConfig $configuration) {}

    public function granularComplianceEnabled(): bool
    {
        return $this->configuration->granularPrivacyComplianceEnabled();
    }
}
