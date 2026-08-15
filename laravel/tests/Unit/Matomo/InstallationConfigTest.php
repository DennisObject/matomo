<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Config\InstallationConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class InstallationConfigTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            unlink($temporaryFile);
        }

        parent::tearDown();
    }

    public function test_loads_the_existing_matomo_database_and_auth_settings(): void
    {
        $configuration = InstallationConfig::fromFile($this->configurationFile(
            tablesPrefix: 'matomo_',
            secureTokens: '1',
        ));

        $this->assertSame('test_database', $configuration->databaseConnection()['database']);
        $this->assertSame('matomo_', $configuration->databaseConnection()['prefix']);
        $this->assertSame('secret-salt', $configuration->salt());
        $this->assertTrue($configuration->onlyAllowSecureTokens());
        $this->assertSame(1_209_600, $configuration->sessionLifetime());
        $this->assertSame(3_600, $configuration->sessionIdleTimeout());
        $this->assertSame(['10.0.0.0/8'], $configuration->loginAllowlistIps());
        $this->assertTrue($configuration->loginAllowlistAppliesToReportingApi());
        $this->assertSame(['HTTP_X_FORWARDED_FOR'], $configuration->proxyClientHeaders());
        $this->assertSame(['10.0.0.1'], $configuration->proxyIps());
        $this->assertTrue($configuration->proxyIpReadLastInList());
        $this->assertFalse($configuration->internetFeaturesEnabled());
        $this->assertSame(['192.168.1.0/24'], $configuration->allowedPrivateEgressRanges());
        $this->assertSame('proxy.example', $configuration->outboundProxyHost());
        $this->assertSame(
            ['direct.example', 'other.example'],
            $configuration->outboundProxyExcludedHosts(),
        );
        $this->assertSame(21, $configuration->websitesCountToDisplay());
        $this->assertSame(['CoreHome', 'SitesManager'], $configuration->activatedPlugins());
        $this->assertSame(['BTC' => 'Bitcoin'], $configuration->customCurrencies());
        $this->assertTrue($configuration->configuredCnilPolicy());
        $this->assertFalse($configuration->configuredFilterPiiEnforcement());
        $this->assertSame(['email', 'password'], $configuration->commonPiiParameters());
        $this->assertSame('fr', $configuration->defaultLanguage());
        $this->assertSame('language_cookie', $configuration->languageCookieName());
        $this->assertSame(['en', 'fr'], $configuration->availableLanguages());
    }

    public function test_rejects_unsafe_table_prefix(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The Matomo database table prefix is invalid.');

        InstallationConfig::fromFile($this->configurationFile(tablesPrefix: 'matomo`; DROP TABLE user;'));
    }

    private function configurationFile(string $tablesPrefix, string $secureTokens = '0'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'matomo-config-');
        $this->assertIsString($path);
        $this->temporaryFiles[] = $path;
        $content = <<<INI
            ; <?php exit; ?> DO NOT REMOVE THIS LINE
            [database]
            host = "database"
            username = "matomo"
            password = "password"
            dbname = "test_database"
            tables_prefix = "{$tablesPrefix}"
            port = 3306
            adapter = "PDO\\MYSQL"
            charset = "utf8mb4"

            [General]
            salt = "secret-salt"
            only_allow_secure_auth_tokens = {$secureTokens}
            login_allowlist_ip[] = "10.0.0.0/8"
            proxy_client_headers[] = "HTTP_X_FORWARDED_FOR"
            proxy_ips[] = "10.0.0.1"
            enable_internet_features = 0
            allowed_private_egress_ranges[] = "192.168.1.0/24"
            autocomplete_min_sites = 9
            site_selector_max_sites = 21
            currencies[BTC] = "Bitcoin"
            default_language = "fr"
            language_cookie_name = "language_cookie"

            [Plugins]
            Plugins[] = "CoreHome"
            Plugins[] = "SitesManager"

            [CnilPolicy]
            cnil_v1_policy_enabled = 1

            [SitesManager]
            FilterPIIParameters_policy_enforced = 0
            CommonPIIParams[] = "email"
            CommonPIIParams[] = "password"

            [Languages]
            Languages[] = "en"
            Languages[] = "fr"

            [proxy]
            host = "proxy.example"
            exclude = "direct.example, other.example"
            INI;

        $this->assertNotFalse(file_put_contents($path, $content));

        return $path;
    }
}
