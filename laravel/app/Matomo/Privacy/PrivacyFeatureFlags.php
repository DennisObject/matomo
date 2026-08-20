<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

interface PrivacyFeatureFlags
{
    public function granularComplianceEnabled(): bool;
}
