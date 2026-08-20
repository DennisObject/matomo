<?php

declare(strict_types=1);

namespace App\Matomo\Marketplace;

interface MarketplaceService
{
    public function createAccount(string $email): void;

    public function deleteLicenseKey(): void;

    public function requestTrial(string $pluginName, string $login): void;

    public function startFreeTrial(string $pluginName): void;

    public function saveLicenseKey(#[\SensitiveParameter] string $licenseKey): void;
}
