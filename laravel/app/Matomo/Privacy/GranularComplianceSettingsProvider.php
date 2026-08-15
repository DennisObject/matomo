<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

interface GranularComplianceSettingsProvider
{
    /** @return array<string, mixed> */
    public function settings(?int $idSite): array;
}
