<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

use App\Matomo\Config\InstallationConfig;
use App\Matomo\Options\OptionRepository;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseComplianceStatusProvider implements ComplianceStatusProvider
{
    public function __construct(
        private ConnectionInterface $connection,
        private OptionRepository $options,
        private CompliancePolicyStateRepository $policies,
        private InstallationConfig $configuration,
    ) {}

    public function status(?int $idSite): array
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

        $requirements = [
            $this->requirement(
                'Device model detection disabled',
                $this->enforced('DevicesDetection', 'DeviceModelDetectionDisabled', $idSite),
                'Device model detection and device model report must be disabled.',
            ),
            $this->requirement(
                'Only Major versions',
                $this->enforced('DevicesDetection', 'OnlyMajorVersions', $idSite),
                'Only Major OS and browser versions are stored.',
            ),
            $this->requirement(
                'Ecommerce Restricted',
                ! $this->hasEcommerceEnabledSite($idSite)
                    || $this->enforced('Ecommerce', 'EcommerceRestricted', $idSite),
                'Ecommerce analytics must be disabled or restricted.',
            ),
            $this->requirement(
                'Ecommerce - Order ID anonymisation',
                $this->resolvedOptionBoolean('anonymizeOrderId', $idSite)
                    || $this->enforced('Ecommerce', 'OrderIdAnonymization', $idSite),
                'Order IDs must be anonymised.',
            ),
            $this->requirement(
                'PII data filtered',
                $this->options->value('SitesManager_ExcludeTypeQueryParamsGlobal') === 'matomo_recommended_pii'
                    || $this->enforced('SitesManager', 'FilterPIIParameters', $idSite),
                'Personally Identifiable Information data must use the recommended exclusion list.',
            ),
            $this->requirement(
                'Turn off Visits log and Visitor profiles',
                $this->resolvedSettingBoolean('Live', 'disable_visitor_log', $idSite)
                    || $this->enforced('Live', 'VisitorLogDisabled', $idSite),
                'Visits log is required to be disabled.',
            ),
            $this->requirement(
                'Campaign parameter values masked',
                $this->siteBooleanOrAny('PrivacyManager', 'campaign_parameter_values_masked', $idSite)
                    || $this->enforced('PrivacyManager', 'CampaignParameterValuesMasked', $idSite),
                'Campaign parameter values must be masked before they are stored.',
            ),
            $this->requirement(
                'Segmented data rounding enabled',
                $this->enforced('PrivacyManager', 'DataRoundingEnabled', $idSite),
                'Segmented count metrics must be rounded to the nearest 10.',
            ),
            $this->requirement(
                'IP Anonymisation Enabled',
                $ipEnabled,
                "Anonymisation of visitors' IP addresses must be enabled.",
            ),
            $this->requirement(
                'IP Address Mask Length',
                $maskLength >= 2,
                sprintf('Must be set to at least 2 byte(s), currently %d byte(s).', $maskLength),
            ),
            $this->requirement(
                'Referrer Anonymisation',
                in_array($referrer, ['exclude_path', 'exclude_all'], true),
                'Only the referrer host or referrer type may be collected.',
            ),
            $this->requirement(
                'Data retention period',
                $retention <= 759,
                sprintf('Retention period is set to %d days.', $retention),
            ),
            $this->requirement(
                'Limit available segments',
                $this->enforced('SegmentEditor', 'LimitSegments', $idSite),
                'Limit the available segments to be compliant.',
            ),
            $this->requirement(
                'Screen resolution detection disabled',
                $this->enforced('Resolution', 'ScreenResolutionDetectionDisabled', $idSite),
                'Screen resolution detection and screen resolution report must be disabled.',
            ),
            $this->requirement(
                'User ID disabled',
                $this->siteBooleanOrAny('UserId', 'user_id_disabled', $idSite)
                    || $this->enforced('UserId', 'UserIdDisabled', $idSite),
                'Collection of User ID while tracking must be disabled.',
            ),
            $this->requirement(
                'Third-party cookies',
                ! $this->configuration->thirdPartyCookiesEnabled($idSite),
                'Third-party cookies must be disabled.',
            ),
            [
                'name' => 'Opt out',
                'value' => 'unknown',
                'notes' => 'Opt out must be manually set up and configured.',
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
