<?php

declare(strict_types=1);

namespace App\Matomo\CoreAdmin;

use App\Matomo\Config\InstallationConfig;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Options\MutableOptionRepository;

final readonly class ConfiguredCoreAdminSettings implements CoreAdminSettings
{
    private const string BROWSER_TRIGGER_OPTION = 'enableBrowserTriggerArchiving';

    private const string TODAY_TIME_TO_LIVE_OPTION = 'todayArchiveTimeToLive';

    public function __construct(
        private InstallationConfig $installation,
        private MutableOptionRepository $options,
        private TrackerCacheInvalidator $trackerCache,
        private TrustedHostConfiguration $trustedHosts,
    ) {}

    public function generalSettingsAdminEnabled(): bool
    {
        return $this->installation->generalSettingsAdminEnabled();
    }

    public function configureArchiving(bool $browserTriggerEnabled, int $todayTimeToLive): void
    {
        $this->options->set(self::BROWSER_TRIGGER_OPTION, $browserTriggerEnabled ? '1' : '0', true);
        $this->options->set(self::TODAY_TIME_TO_LIVE_OPTION, (string) $todayTimeToLive, true);

        $this->trackerCache->clearGeneral();
    }

    public function replaceTrustedHosts(array $hosts): void
    {
        $hosts = array_values(array_filter($hosts, static fn (string $host): bool => $host !== ''));

        if ($hosts !== []) {
            $this->trustedHosts->replace($hosts);
        }
    }
}
