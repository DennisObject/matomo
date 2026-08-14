<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Options\OptionRepository;
use App\Matomo\Settings\PolicySettingRepository;
use App\Matomo\Sites\ConfiguredQueryParameterExclusionPolicy;
use PHPUnit\Framework\TestCase;

class ConfiguredQueryParameterExclusionPolicyTest extends TestCase
{
    public function test_configured_cnil_policy_enforces_recommended_parameters(): void
    {
        $policy = $this->policy(
            options: ['SitesManager_ExcludeTypeQueryParamsGlobal' => 'custom'],
            configuredCnilPolicy: true,
        );

        $this->assertSame('matomo_recommended_pii', $policy->type());
        $this->assertSame('email,password', $policy->parameters());
    }

    public function test_explicit_filter_state_overrides_a_saved_cnil_policy(): void
    {
        $policy = $this->policy(
            options: [
                'SitesManager_ExcludeTypeQueryParamsGlobal' => 'custom',
                'SitesManager_ExcludedQueryParameters' => 'token,session',
            ],
            systemSettings: [
                'SitesManager.FilterPIIParameters_policy_enforced' => false,
                'CnilPolicy.cnil_v1_policy_enabled' => true,
            ],
        );

        $this->assertSame('custom', $policy->type());
        $this->assertSame('token,session', $policy->parameters());
    }

    public function test_site_cnil_policy_only_changes_that_site(): void
    {
        $policy = $this->policy(
            siteSettings: ['7.CnilPolicy.cnil_v1_policy_enabled' => true],
        );

        $this->assertSame('common_session_parameters', $policy->type());
        $this->assertSame('matomo_recommended_pii', $policy->type(7));
    }

    /**
     * @param  array<string, string>  $options
     * @param  array<string, bool>  $systemSettings
     * @param  array<string, bool>  $siteSettings
     */
    private function policy(
        array $options = [],
        array $systemSettings = [],
        array $siteSettings = [],
        ?bool $configuredCnilPolicy = null,
        ?bool $configuredFilterPiiEnforcement = null,
    ): ConfiguredQueryParameterExclusionPolicy {
        $optionRepository = new class($options) implements OptionRepository
        {
            /**
             * @param  array<string, string>  $values
             */
            public function __construct(private array $values) {}

            public function value(string $name): ?string
            {
                return $this->values[$name] ?? null;
            }
        };
        $settingRepository = new class($systemSettings, $siteSettings) implements PolicySettingRepository
        {
            /**
             * @param  array<string, bool>  $systemValues
             * @param  array<string, bool>  $siteValues
             */
            public function __construct(
                private array $systemValues,
                private array $siteValues,
            ) {}

            public function systemBoolean(string $pluginName, string $settingName): ?bool
            {
                return $this->systemValues["{$pluginName}.{$settingName}"] ?? null;
            }

            public function siteBoolean(int $idSite, string $pluginName, string $settingName): ?bool
            {
                return $this->siteValues["{$idSite}.{$pluginName}.{$settingName}"] ?? null;
            }
        };

        return new ConfiguredQueryParameterExclusionPolicy(
            options: $optionRepository,
            settings: $settingRepository,
            configuredCnilPolicy: $configuredCnilPolicy,
            configuredFilterPiiEnforcement: $configuredFilterPiiEnforcement,
            recommendedPiiParameters: ['email', 'password'],
        );
    }
}
