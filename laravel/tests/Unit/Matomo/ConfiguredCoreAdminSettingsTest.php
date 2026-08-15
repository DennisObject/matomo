<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Config\InstallationConfig;
use App\Matomo\CoreAdmin\ConfiguredCoreAdminSettings;
use App\Matomo\CoreAdmin\TrustedHostConfiguration;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Options\MutableOptionRepository;
use PHPUnit\Framework\TestCase;

class ConfiguredCoreAdminSettingsTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = tempnam(sys_get_temp_dir(), 'matomo-core-admin-') ?: '';
        $this->assertNotSame('', $this->configPath);
        file_put_contents($this->configPath, <<<'INI'
[database]
adapter = "pdo\\mysql"
host = "database"
username = "matomo"
password = "secret"
dbname = "matomo"
tables_prefix = "matomo_"

[General]
salt = "testing-salt"
enable_general_settings_admin = 1
INI);
    }

    protected function tearDown(): void
    {
        if (is_file($this->configPath)) {
            unlink($this->configPath);
        }

        parent::tearDown();
    }

    public function test_updates_archive_options_cache_and_trusted_hosts(): void
    {
        $options = new class implements MutableOptionRepository
        {
            /** @var list<array{name: string, value: string, autoload: bool}> */
            public array $writes = [];

            public function value(string $name): ?string
            {
                return null;
            }

            public function set(string $name, string $value, bool $autoload = false): void
            {
                $this->writes[] = compact('name', 'value', 'autoload');
            }
        };
        $cache = new class implements TrackerCacheInvalidator
        {
            public int $clears = 0;

            public function clearGeneral(): void
            {
                $this->clears++;
            }
        };
        $trustedHosts = new class implements TrustedHostConfiguration
        {
            /** @var list<list<string>> */
            public array $writes = [];

            public function replace(array $hosts): void
            {
                $this->writes[] = $hosts;
            }
        };
        $installation = InstallationConfig::fromFile($this->configPath);
        $settings = new ConfiguredCoreAdminSettings(
            $installation,
            $options,
            $cache,
            $trustedHosts,
        );

        $this->assertTrue($settings->generalSettingsAdminEnabled());
        $this->assertSame('/tmp', $installation->temporaryPath());
        $settings->configureArchiving(false, 7200);
        $settings->replaceTrustedHosts(['analytics.example', '', 'reports.example']);
        $settings->replaceTrustedHosts(['']);

        $this->assertSame([
            ['name' => 'enableBrowserTriggerArchiving', 'value' => '0', 'autoload' => true],
            ['name' => 'todayArchiveTimeToLive', 'value' => '7200', 'autoload' => true],
        ], $options->writes);
        $this->assertSame(1, $cache->clears);
        $this->assertSame([
            ['analytics.example', 'reports.example'],
        ], $trustedHosts->writes);
    }
}
