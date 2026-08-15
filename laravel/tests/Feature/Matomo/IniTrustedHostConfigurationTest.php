<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\CoreAdmin\Events\ConfigurationFileChanged;
use App\Matomo\CoreAdmin\Events\ConfigurationSaving;
use App\Matomo\CoreAdmin\IniTrustedHostConfiguration;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class IniTrustedHostConfigurationTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'matomo-trusted-hosts-') ?: '';
        $this->assertNotSame('', $this->path);
        file_put_contents($this->path, <<<'INI'
; <?php exit; ?> DO NOT REMOVE THIS LINE
[database]
password = "secret"

[General]
salt = "test-salt"
trusted_hosts[] = "old.example"

[Extra]
kept = "yes"
INI);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    public function test_safely_rewrites_hosts_and_preserves_extension_changes(): void
    {
        $changedPath = null;
        Event::listen(ConfigurationSaving::class, static function (ConfigurationSaving $event): void {
            $event->configuration['Extra']['added'] = 'by-extension';
        });
        Event::listen(
            ConfigurationFileChanged::class,
            static function (ConfigurationFileChanged $event) use (&$changedPath): void {
                $changedPath = $event->path;
            },
        );
        $configuration = new IniTrustedHostConfiguration(
            $this->path,
            $this->app->make(Dispatcher::class),
        );

        $configuration->replace(['analytics.example', 'reports.example:8443']);

        $contents = file_get_contents($this->path);
        $this->assertIsString($contents);
        $this->assertStringStartsWith('; <?php exit; ?> DO NOT REMOVE THIS LINE', $contents);
        $parsed = parse_ini_file($this->path, true, INI_SCANNER_RAW);
        $this->assertIsArray($parsed);
        $this->assertSame(
            ['analytics.example', 'reports.example:8443'],
            $parsed['General']['trusted_hosts'] ?? null,
        );
        $this->assertSame('secret', $parsed['database']['password'] ?? null);
        $this->assertSame('yes', $parsed['Extra']['kept'] ?? null);
        $this->assertSame('by-extension', $parsed['Extra']['added'] ?? null);
        $this->assertSame($this->path, $changedPath);
    }
}
