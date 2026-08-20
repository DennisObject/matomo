<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

interface SiteRuntimeSettings
{
    public function timezoneSupportEnabled(): bool;

    public function websitesCountToDisplay(): int;

    public function administrationEnabled(): bool;
}
