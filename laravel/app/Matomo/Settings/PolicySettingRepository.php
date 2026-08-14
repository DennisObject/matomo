<?php

declare(strict_types=1);

namespace App\Matomo\Settings;

interface PolicySettingRepository
{
    public function systemBoolean(string $pluginName, string $settingName): ?bool;

    public function siteBoolean(int $idSite, string $pluginName, string $settingName): ?bool;
}
