<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

final readonly class CnilGranularComplianceSettingsProvider implements GranularComplianceSettingsProvider
{
    /** @var list<array{id: string, plugin: string, setting: string}> */
    private const array SETTINGS = [
        ['id' => 'DevicesDetection.DeviceModelDetectionDisabled', 'plugin' => 'DevicesDetection', 'setting' => 'DeviceModelDetectionDisabled'],
        ['id' => 'DevicesDetection.OnlyMajorVersions', 'plugin' => 'DevicesDetection', 'setting' => 'OnlyMajorVersions'],
        ['id' => 'Ecommerce.EcommerceRestricted', 'plugin' => 'Ecommerce', 'setting' => 'EcommerceRestricted'],
        ['id' => 'Ecommerce.OrderIdAnonymization', 'plugin' => 'Ecommerce', 'setting' => 'OrderIdAnonymization'],
        ['id' => 'SitesManager.FilterPIIParameters', 'plugin' => 'SitesManager', 'setting' => 'FilterPIIParameters'],
        ['id' => 'Live.VisitorLogDisabled', 'plugin' => 'Live', 'setting' => 'VisitorLogDisabled'],
        ['id' => 'PrivacyManager.CampaignParameterValuesMasked', 'plugin' => 'PrivacyManager', 'setting' => 'CampaignParameterValuesMasked'],
        ['id' => 'PrivacyManager.DataRoundingEnabled', 'plugin' => 'PrivacyManager', 'setting' => 'DataRoundingEnabled'],
        ['id' => 'PrivacyManager.IPAnonymisation', 'plugin' => 'PrivacyManager', 'setting' => 'IPAnonymisation'],
        ['id' => 'PrivacyManager.IpAddressMaskLength', 'plugin' => 'PrivacyManager', 'setting' => 'IpAddressMaskLength'],
        ['id' => 'PrivacyManager.ReferrerAnonymisation', 'plugin' => 'PrivacyManager', 'setting' => 'ReferrerAnonymisation'],
        ['id' => 'PrivacyManager.ReportRetention', 'plugin' => 'PrivacyManager', 'setting' => 'ReportRetention'],
        ['id' => 'SegmentEditor.LimitSegments', 'plugin' => 'SegmentEditor', 'setting' => 'LimitSegments'],
        ['id' => 'Resolution.ScreenResolutionDetectionDisabled', 'plugin' => 'Resolution', 'setting' => 'ScreenResolutionDetectionDisabled'],
        ['id' => 'UserId.UserIdDisabled', 'plugin' => 'UserId', 'setting' => 'UserIdDisabled'],
    ];

    public function __construct(
        private ComplianceStatusProvider $status,
        private CompliancePolicyStateRepository $policies,
        private CompliancePolicyCatalog $catalog,
    ) {}

    public function settings(?int $idSite): array
    {
        $status = $this->status->status($idSite);
        $requirements = $status['complianceRequirements'];
        $settings = [];
        $allEnforced = true;

        foreach (self::SETTINGS as $index => $definition) {
            $requirement = $requirements[$index];
            $enforced = $this->policies->settingEnforced(
                $definition['plugin'],
                $definition['setting'],
                $idSite,
            );
            $allEnforced = $allEnforced && $enforced;
            $settings[] = [
                'id' => $definition['id'],
                'name' => $requirement['name'],
                'whatItDoes' => '',
                'impact' => '',
                'status' => $requirement['value'] === 'non_compliant'
                    ? 'non_compliant'
                    : ($enforced ? 'enforced' : 'compliant'),
                'enforced' => $enforced,
                'toggleable' => true,
                'section' => 'settings',
            ];
        }

        $thirdPartyCookies = $requirements[15];
        $settings[] = [
            'id' => 'Core.ThirdPartyCookies',
            'name' => $thirdPartyCookies['name'],
            'whatItDoes' => '',
            'impact' => '',
            'status' => $thirdPartyCookies['value'] === 'compliant' ? 'on_by_default' : 'non_compliant',
            'enforced' => null,
            'toggleable' => false,
            'section' => 'external',
        ];
        $optOut = $requirements[16];
        $settings[] = [
            'id' => 'cnil_v1.optOut',
            'name' => $optOut['name'],
            'whatItDoes' => $optOut['notes'],
            'impact' => '',
            'status' => 'manual',
            'enforced' => null,
            'toggleable' => false,
            'section' => 'external',
        ];

        $policy = $this->catalog->all()[0];

        return [
            'policy' => $policy['id'],
            'title' => $policy['title'],
            'description' => $policy['description'],
            'configControlled' => $this->policies->configControlled(),
            'policyEnforced' => $allEnforced,
            'settings' => $settings,
        ];
    }
}
