<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

interface CompliancePolicyStateRepository
{
    public function active(?int $idSite): bool;

    public function configControlled(): bool;

    public function settingEnforced(string $plugin, string $setting, ?int $idSite): bool;

    public function setActive(?int $idSite, bool $active): void;
}
