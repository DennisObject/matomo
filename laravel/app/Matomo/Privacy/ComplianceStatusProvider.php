<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

interface ComplianceStatusProvider
{
    /**
     * @return array{
     *     complianceModeEnforced: bool,
     *     complianceConfigControlled: bool,
     *     complianceRequirements: list<array{name: string, value: string, notes: string}>
     * }
     */
    public function status(?int $idSite): array;
}
