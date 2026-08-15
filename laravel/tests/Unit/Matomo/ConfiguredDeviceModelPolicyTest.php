<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Reporting\ConfiguredDeviceModelPolicy;
use App\Matomo\Settings\PolicySettingRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfiguredDeviceModelPolicyTest extends TestCase
{
    /**
     * @param  array<string, bool|null>  $system
     * @param  array<string, bool|null>  $site
     */
    #[DataProvider('states')]
    public function test_resolves_policy_precedence(
        ?bool $configuredCnil,
        array $system,
        array $site,
        bool $expected,
    ): void {
        $settings = new class($system, $site) implements PolicySettingRepository
        {
            /**
             * @param  array<string, bool|null>  $system
             * @param  array<string, bool|null>  $site
             */
            public function __construct(private array $system, private array $site) {}

            public function systemBoolean(string $pluginName, string $settingName): ?bool
            {
                return $this->system["{$pluginName}.{$settingName}"] ?? null;
            }

            public function siteBoolean(int $idSite, string $pluginName, string $settingName): ?bool
            {
                return $this->site["{$idSite}.{$pluginName}.{$settingName}"] ?? null;
            }
        };

        $this->assertSame(
            $expected,
            (new ConfiguredDeviceModelPolicy($settings, $configuredCnil))->detectionDisabled(7),
        );
    }

    /** @return iterable<string, array{?bool, array<string, bool|null>, array<string, bool|null>, bool}> */
    public static function states(): iterable
    {
        $enforcement = 'DevicesDetection.DeviceModelDetectionDisabled_policy_enforced';
        $cnil = 'CnilPolicy.cnil_v1_policy_enabled';

        yield 'configuration enables' => [true, [$enforcement => false], [], true];
        yield 'configuration disables' => [false, [$enforcement => true], [], false];
        yield 'instance enforcement enables' => [null, [$enforcement => true], [], true];
        yield 'site opt-out suppresses policy' => [null, [$cnil => true], ["7.{$enforcement}" => false], false];
        yield 'site policy enables' => [null, [], ["7.{$cnil}" => true], true];
        yield 'no state disables' => [null, [], [], false];
    }
}
