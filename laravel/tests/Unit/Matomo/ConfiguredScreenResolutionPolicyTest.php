<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Reporting\ConfiguredScreenResolutionPolicy;
use App\Matomo\Settings\PolicySettingRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfiguredScreenResolutionPolicyTest extends TestCase
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
            (new ConfiguredScreenResolutionPolicy($settings, $configuredCnil))->detectionDisabled(7),
        );
    }

    /** @return iterable<string, array{?bool, array<string, bool|null>, array<string, bool|null>, bool}> */
    public static function states(): iterable
    {
        $enforcement = 'Resolution.ScreenResolutionDetectionDisabled_policy_enforced';
        $cnil = 'CnilPolicy.cnil_v1_policy_enabled';

        yield 'config pin enables regardless of stored opt-out' => [true, [$enforcement => false], [], true];
        yield 'config pin disables regardless of stored enforcement' => [false, [$enforcement => true], [], false];
        yield 'instance enforcement enables' => [null, [$enforcement => true], [], true];
        yield 'site enforcement enables' => [null, [], ["7.{$enforcement}" => true], true];
        yield 'instance opt-out suppresses active policy' => [null, [$enforcement => false, $cnil => true], [], false];
        yield 'site opt-out suppresses active policy' => [null, [$cnil => true], ["7.{$enforcement}" => false], false];
        yield 'instance policy enables' => [null, [$cnil => true], [], true];
        yield 'site policy enables' => [null, [], ["7.{$cnil}" => true], true];
        yield 'no state disables' => [null, [], [], false];
    }
}
