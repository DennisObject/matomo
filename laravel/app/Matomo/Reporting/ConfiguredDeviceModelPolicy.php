<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Settings\PolicySettingRepository;

final readonly class ConfiguredDeviceModelPolicy implements DeviceModelPolicy
{
    private const string CNIL_PLUGIN = 'CnilPolicy';

    private const string CNIL_SETTING = 'cnil_v1_policy_enabled';

    private const string DEVICES_PLUGIN = 'DevicesDetection';

    private const string ENFORCEMENT_SETTING = 'DeviceModelDetectionDisabled_policy_enforced';

    public function __construct(
        private PolicySettingRepository $settings,
        private ?bool $configuredCnilPolicy,
    ) {}

    public function detectionDisabled(int $idSite): bool
    {
        if ($this->configuredCnilPolicy !== null) {
            return $this->configuredCnilPolicy;
        }

        $instanceState = $this->settings->systemBoolean(
            self::DEVICES_PLUGIN,
            self::ENFORCEMENT_SETTING,
        );
        $siteState = $this->settings->siteBoolean(
            $idSite,
            self::DEVICES_PLUGIN,
            self::ENFORCEMENT_SETTING,
        );

        if ($instanceState === true || $siteState === true) {
            return true;
        }

        if ($instanceState === false || $siteState === false) {
            return false;
        }

        return $this->settings->systemBoolean(self::CNIL_PLUGIN, self::CNIL_SETTING) === true
            || $this->settings->siteBoolean($idSite, self::CNIL_PLUGIN, self::CNIL_SETTING) === true;
    }
}
