<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

use App\Matomo\Config\InstallationConfig;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Options\OptionRepository;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseComplianceStatusProvider implements ComplianceStatusProvider
{
    public function __construct(
        private ConnectionInterface $connection,
        private OptionRepository $options,
        private CompliancePolicyStateRepository $policies,
        private InstallationConfig $configuration,
        private MatomoTranslator $translator,
    ) {}

    public function status(?int $idSite, string $language): array
    {
        $ipEnabled = $this->resolvedOptionBoolean('ipAnonymizerEnabled', $idSite, true)
            || $this->enforced('PrivacyManager', 'IPAnonymisation', $idSite);
        $maskLength = $ipEnabled ? $this->resolvedOptionInteger('ipAddressMaskLength', $idSite, 2) : 0;
        if ($this->enforced('PrivacyManager', 'IpAddressMaskLength', $idSite)) {
            $maskLength = max(2, $maskLength);
        }

        $retention = (int) ($this->options->value('delete_logs_older_than')
            ?? $this->configuration->deleteLogsOlderThan());
        $referrer = $this->resolvedOption('anonymizeReferrer', $idSite, '');
        if ($this->enforced('PrivacyManager', 'ReferrerAnonymisation', $idSite)) {
            $referrer = $this->strictestReferrer($referrer, 'exclude_path');
        }

        $hasEcommerce = $this->hasEcommerceEnabledSite($idSite);
        $ecommerceEnforced = $this->enforced('Ecommerce', 'EcommerceRestricted', $idSite);

        $requirements = [
            $this->requirement(
                $this->translate('DevicesDetection_DeviceModelDetectionDisabled', $language),
                $this->enforced('DevicesDetection', 'DeviceModelDetectionDisabled', $idSite),
                $this->translate('DevicesDetection_DeviceModelDetectionDisabledRequirementNote', $language),
            ),
            $this->requirement(
                $this->translate('DevicesDetection_OnlyMajorVersionsSettingTitle', $language),
                $this->enforced('DevicesDetection', 'OnlyMajorVersions', $idSite),
                $this->translate('DevicesDetection_OnlyMajorVersionsSettingRequirementNote', $language),
            ),
            $this->requirement(
                $this->translate('Ecommerce_EcommercePolicySettingTitle', $language),
                ! $hasEcommerce || $ecommerceEnforced,
                $this->ecommerceNote($idSite, $language, $hasEcommerce, $ecommerceEnforced),
            ),
            $this->requirement(
                $this->translate('Ecommerce_OrderIdAnonymizationSettingTitle', $language),
                $this->resolvedOptionBoolean('anonymizeOrderId', $idSite)
                    || $this->enforced('Ecommerce', 'OrderIdAnonymization', $idSite),
                $this->translate('Ecommerce_OrderIdAnonymizationSettingRequirementNote', $language),
            ),
            $this->requirement(
                $this->translate('SitesManager_FilterPIIParametersSettingTitle', $language),
                $this->options->value('SitesManager_ExcludeTypeQueryParamsGlobal') === 'matomo_recommended_pii'
                    || $this->enforced('SitesManager', 'FilterPIIParameters', $idSite),
                $this->translate('SitesManager_FilterPiiParametersSettingRequirementNote', $language),
            ),
            $this->requirement(
                $this->translate('Live_DisableVisitsLogAndProfile', $language),
                $this->resolvedSettingBoolean('Live', 'disable_visitor_log', $idSite)
                    || $this->enforced('Live', 'VisitorLogDisabled', $idSite),
                $this->translate('Live_VisitorLogPolicySettingRequirementNote', $language),
            ),
            $this->requirement(
                $this->translate('PrivacyManager_CampaignParameterValuesMaskedSettingTitle', $language),
                $this->siteBooleanOrAny('PrivacyManager', 'campaign_parameter_values_masked', $idSite)
                    || $this->enforced('PrivacyManager', 'CampaignParameterValuesMasked', $idSite),
                $this->translate('PrivacyManager_CampaignParameterValuesMaskedSettingRequirementNote', $language),
            ),
            $this->requirement(
                $this->translate('PrivacyManager_SegmentedDataRoundingSettingTitle', $language),
                $this->enforced('PrivacyManager', 'DataRoundingEnabled', $idSite),
                $this->translate('PrivacyManager_SegmentedDataRoundingSettingRequirementNote', $language),
            ),
            $this->requirement(
                $this->translate('PrivacyManager_AnonymizeIpPolicySettingTitle', $language),
                $ipEnabled,
                $this->translate('PrivacyManager_AnonymizeIpPolicySettingRequirementNote', $language),
            ),
            $this->requirement(
                $this->translate('PrivacyManager_AnonymizeIpMaskLengthSettingTitle', $language),
                $maskLength >= 2,
                $this->translate('PrivacyManager_AnonymizeIpMaskLengthSettingRequirementNote', $language, [2, $maskLength]),
            ),
            $this->requirement(
                $this->translate('PrivacyManager_ReferrerAnonymizationSettingTitle', $language),
                in_array($referrer, ['exclude_path', 'exclude_all'], true),
                $this->translate('PrivacyManager_ReferrerAnonymizationSettingRequirementNote', $language),
            ),
            $this->requirement(
                $this->translate('PrivacyManager_RetentionPeriodPolicySettingTitle', $language),
                $retention <= 759,
                $this->translate('PrivacyManager_RetentionPeriodPolicySettingRequirementNote', $language, [$retention]),
            ),
            $this->requirement(
                $this->translate('SegmentEditor_LimitSegmentsSettingTitle', $language),
                $this->enforced('SegmentEditor', 'LimitSegments', $idSite),
                $this->translate('SegmentEditor_LimitSegmentsSettingRequirementNote', $language),
            ),
            $this->requirement(
                $this->translate('Resolution_ScreenResolutionDetectionDisabled', $language),
                $this->enforced('Resolution', 'ScreenResolutionDetectionDisabled', $idSite),
                $this->translate('Resolution_ScreenResolutionDetectionDisabledRequirementNote', $language),
            ),
            $this->requirement(
                $this->translate('UserId_UserIdDisabledSettingTitle', $language),
                $this->siteBooleanOrAny('UserId', 'user_id_disabled', $idSite)
                    || $this->enforced('UserId', 'UserIdDisabled', $idSite),
                $this->translate('UserId_UserIdDisabledSettingRequirementNote', $language),
            ),
            $this->requirement(
                $this->translate('General_ThirdPartyCookieSettingTitle', $language),
                ! $this->configuration->thirdPartyCookiesEnabled($idSite),
                $this->translate('General_ThirdPartyCookieSettingNote', $language),
            ),
            [
                'name' => $this->translate('General_ComplianceCNILUnknownSettingOptOutTitle', $language),
                'value' => 'unknown',
                'notes' => $this->translate('General_ComplianceCNILUnknownSettingOptOutNotes', $language, ['', '']),
            ],
        ];

        return [
            'complianceModeEnforced' => $this->policies->active($idSite),
            'complianceConfigControlled' => $this->policies->configControlled(),
            'complianceRequirements' => $requirements,
        ];
    }

    /** @return array{name: string, value: string, notes: string} */
    private function requirement(string $name, bool $compliant, string $notes): array
    {
        return [
            'name' => $name,
            'value' => $compliant ? 'compliant' : 'non_compliant',
            'notes' => $notes,
        ];
    }

    /** @param list<bool|int|string> $arguments */
    private function translate(string $key, string $language, array $arguments = []): string
    {
        return $this->translator->translate($key, $language, $arguments);
    }

    private function ecommerceNote(
        ?int $idSite,
        string $language,
        bool $hasEcommerce,
        bool $enforced,
    ): string {
        if (! $hasEcommerce) {
            return $this->translate(
                $idSite === null
                    ? 'Ecommerce_EcommercePolicySettingCompliantAll'
                    : 'Ecommerce_EcommercePolicySettingCompliantSingle',
                $language,
            );
        }

        if (! $enforced) {
            return $this->translate('Ecommerce_EcommercePolicySettingNonCompliantNote', $language);
        }

        $query = ['module' => 'SitesManager', 'action' => 'index'];
        if ($idSite !== null) {
            $query['idSite'] = (string) $idSite;
        }

        $link = 'index.php?'.http_build_query($query);

        return $this->translate(
            'Ecommerce_EcommercePolicySettingRequirementNote',
            $language,
            ['<a href="'.$link.'">', '</a>'],
        );
    }

    private function enforced(string $plugin, string $setting, ?int $idSite): bool
    {
        return $this->policies->settingEnforced($plugin, $setting, $idSite);
    }

    private function resolvedOptionBoolean(string $name, ?int $idSite, bool $default = false): bool
    {
        return filter_var($this->resolvedOption($name, $idSite, $default ? '1' : '0'), FILTER_VALIDATE_BOOL);
    }

    private function resolvedOptionInteger(string $name, ?int $idSite, int $default): int
    {
        return (int) $this->resolvedOption($name, $idSite, (string) $default);
    }

    private function resolvedOption(string $name, ?int $idSite, string $default): string
    {
        if ($idSite !== null) {
            $siteValue = $this->options->value(sprintf('PrivacyManager.idSite(%d).%s', $idSite, $name));
            if ($siteValue !== null) {
                return $siteValue;
            }
        }

        return $this->options->value('PrivacyManager.'.$name) ?? $default;
    }

    private function resolvedSettingBoolean(string $plugin, string $setting, ?int $idSite): bool
    {
        $system = $this->storedBoolean('plugin_setting', null, $plugin, $setting) ?? false;
        $site = $idSite === null
            ? false
            : ($this->storedBoolean('site_setting', $idSite, $plugin, $setting) ?? false);

        return $system || $site;
    }

    private function siteBooleanOrAny(string $plugin, string $setting, ?int $idSite): bool
    {
        if ($idSite !== null) {
            return $this->storedBoolean('site_setting', $idSite, $plugin, $setting) ?? false;
        }

        return $this->connection->table('site_setting')
            ->where('plugin_name', $plugin)
            ->where('setting_name', $setting)
            ->whereIn('setting_value', ['1', 'true'])
            ->exists();
    }

    private function storedBoolean(
        string $table,
        ?int $idSite,
        string $plugin,
        string $setting,
    ): ?bool {
        $query = $this->connection->table($table)
            ->where('plugin_name', $plugin)
            ->where('setting_name', $setting);
        if ($idSite === null) {
            $query->where('user_login', '');
        } else {
            $query->where('idsite', $idSite);
        }

        $value = $query->value('setting_value');
        if (! is_scalar($value)) {
            return null;
        }

        return match (strtolower((string) $value)) {
            '1', 'true' => true,
            '0', 'false', '' => false,
            default => null,
        };
    }

    private function hasEcommerceEnabledSite(?int $idSite): bool
    {
        $query = $this->connection->table('site')->where('ecommerce', 1);

        return $idSite === null ? $query->exists() : $query->where('idsite', $idSite)->exists();
    }

    private function strictestReferrer(string $first, string $second): string
    {
        $options = ['', 'exclude_query', 'exclude_path', 'exclude_all'];
        $firstPosition = array_search($first, $options, true);
        $secondPosition = array_search($second, $options, true);

        return ($firstPosition !== false ? $firstPosition : 0) > ($secondPosition !== false ? $secondPosition : 0)
            ? $first : $second;
    }
}
