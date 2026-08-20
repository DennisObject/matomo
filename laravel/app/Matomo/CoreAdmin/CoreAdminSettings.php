<?php

declare(strict_types=1);

namespace App\Matomo\CoreAdmin;

interface CoreAdminSettings
{
    public function generalSettingsAdminEnabled(): bool;

    public function configureArchiving(bool $browserTriggerEnabled, int $todayTimeToLive): void;

    /** @param list<string> $hosts */
    public function replaceTrustedHosts(array $hosts): void;
}
