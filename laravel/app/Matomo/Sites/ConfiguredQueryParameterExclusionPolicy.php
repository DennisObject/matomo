<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

use App\Matomo\Options\OptionRepository;
use App\Matomo\Settings\PolicySettingRepository;

final readonly class ConfiguredQueryParameterExclusionPolicy implements QueryParameterExclusionPolicy
{
    private const string COMMON = 'common_session_parameters';

    private const string CUSTOM = 'custom';

    private const string RECOMMENDED_PII = 'matomo_recommended_pii';

    private const string TYPE_OPTION = 'SitesManager_ExcludeTypeQueryParamsGlobal';

    private const string CUSTOM_PARAMETERS_OPTION = 'SitesManager_ExcludedQueryParameters';

    private const string CNIL_PLUGIN = 'CnilPolicy';

    private const string CNIL_SETTING = 'cnil_v1_policy_enabled';

    private const string SITES_MANAGER_PLUGIN = 'SitesManager';

    private const string FILTER_ENFORCEMENT_SETTING = 'FilterPIIParameters_policy_enforced';

    /**
     * @param  list<string>  $recommendedPiiParameters
     */
    public function __construct(
        private OptionRepository $options,
        private PolicySettingRepository $settings,
        private ?bool $configuredCnilPolicy,
        private ?bool $configuredFilterPiiEnforcement,
        private array $recommendedPiiParameters,
    ) {}

    public function type(?int $idSite = null): string
    {
        $type = $this->resolvedType($idSite);

        if ($type !== null && $this->hasValue($type)) {
            return $type;
        }

        $customParameters = $this->options->value(self::CUSTOM_PARAMETERS_OPTION);

        return $customParameters !== null && $this->hasValue($customParameters)
            ? self::CUSTOM
            : self::COMMON;
    }

    public function parameters(?int $idSite = null): string
    {
        return match ($this->type($idSite)) {
            self::COMMON => '',
            self::RECOMMENDED_PII => implode(',', $this->recommendedPiiParameters),
            default => $this->options->value(self::CUSTOM_PARAMETERS_OPTION) ?? '',
        };
    }

    private function resolvedType(?int $idSite): ?string
    {
        if ($this->filterIsEnforced($idSite)) {
            return self::RECOMMENDED_PII;
        }

        return $this->options->value(self::TYPE_OPTION);
    }

    private function filterIsEnforced(?int $idSite): bool
    {
        if ($this->configuredCnilPolicy !== null) {
            return $this->configuredCnilPolicy;
        }

        $instanceState = $this->configuredFilterPiiEnforcement
            ?? $this->settings->systemBoolean(
                self::SITES_MANAGER_PLUGIN,
                self::FILTER_ENFORCEMENT_SETTING,
            );
        $siteState = $idSite === null
            ? null
            : $this->settings->siteBoolean(
                $idSite,
                self::SITES_MANAGER_PLUGIN,
                self::FILTER_ENFORCEMENT_SETTING,
            );

        if ($instanceState === true || $siteState === true) {
            return true;
        }

        if ($instanceState === false || $siteState === false) {
            return false;
        }

        return $this->cnilPolicyIsActive($idSite);
    }

    private function cnilPolicyIsActive(?int $idSite): bool
    {
        if ($this->settings->systemBoolean(self::CNIL_PLUGIN, self::CNIL_SETTING) === true) {
            return true;
        }

        return $idSite !== null
            && $this->settings->siteBoolean($idSite, self::CNIL_PLUGIN, self::CNIL_SETTING) === true;
    }

    private function hasValue(string $value): bool
    {
        return $value !== '' && $value !== '0';
    }
}
