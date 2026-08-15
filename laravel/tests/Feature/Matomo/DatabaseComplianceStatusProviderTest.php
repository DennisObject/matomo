<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Config\InstallationConfig;
use App\Matomo\Options\OptionRepository;
use App\Matomo\Privacy\CompliancePolicyStateRepository;
use App\Matomo\Privacy\DatabaseComplianceStatusProvider;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabaseComplianceStatusProviderTest extends TestCase
{
    private string $configurationPath;

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam('/dev/shm', 'matomo-compliance-');
        $this->assertIsString($path);
        $this->configurationPath = $path;
        $this->assertNotFalse(file_put_contents($path, <<<'INI'
            [database]
            adapter = "PDO\MYSQL"
            tables_prefix = "matomo_"
            dbname = "test"
            username = "test"
            password = "test"

            [General]
            salt = "test-salt"

            [Tracker]
            use_third_party_id_cookie = 0

            [Deletelogs]
            delete_logs_older_than = 180
            INI));
    }

    protected function tearDown(): void
    {
        unlink($this->configurationPath);

        parent::tearDown();
    }

    public function test_enforced_policy_reports_all_machine_checked_requirements_as_compliant(): void
    {
        $provider = $this->provider([], true);

        $result = $provider->status(7);
        $requirements = $result['complianceRequirements'];

        $this->assertTrue($result['complianceModeEnforced']);
        $this->assertFalse($result['complianceConfigControlled']);
        $this->assertCount(17, $requirements);
        $this->assertSame(
            array_fill(0, 16, 'compliant'),
            array_column(array_slice($requirements, 0, 16), 'value'),
        );
        $this->assertSame('unknown', $requirements[16]['value']);
    }

    public function test_disabled_ip_anonymisation_makes_saved_mask_length_non_compliant(): void
    {
        $provider = $this->provider([
            'PrivacyManager.ipAnonymizerEnabled' => '0',
            'PrivacyManager.ipAddressMaskLength' => '4',
        ], false);

        $requirements = $provider->status(null)['complianceRequirements'];
        $byName = array_column($requirements, null, 'name');

        $this->assertSame('non_compliant', $byName['IP Anonymisation Enabled']['value']);
        $this->assertSame('non_compliant', $byName['IP Address Mask Length']['value']);
        $this->assertStringContainsString('currently 0 byte(s)', $byName['IP Address Mask Length']['notes']);
    }

    /** @param array<string, string> $optionValues */
    private function provider(array $optionValues, bool $enforced): DatabaseComplianceStatusProvider
    {
        config()->set('database.connections.matomo_compliance_status_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_compliance_status_test');

        $connection = $databases->connection('matomo_compliance_status_test');
        $connection->getSchemaBuilder()->create('site', function (Blueprint $table): void {
            $table->unsignedInteger('idsite');
            $table->boolean('ecommerce')->default(false);
        });
        $connection->getSchemaBuilder()->create('site_setting', function (Blueprint $table): void {
            $table->unsignedInteger('idsite');
            $table->string('plugin_name');
            $table->string('setting_name');
            $table->text('setting_value');
        });
        $connection->getSchemaBuilder()->create('plugin_setting', function (Blueprint $table): void {
            $table->string('plugin_name');
            $table->string('user_login')->default('');
            $table->string('setting_name');
            $table->text('setting_value');
        });
        $options = new readonly class($optionValues) implements OptionRepository
        {
            /** @param array<string, string> $values */
            public function __construct(private array $values) {}

            public function value(string $name): ?string
            {
                return $this->values[$name] ?? null;
            }
        };
        $policies = new readonly class($enforced) implements CompliancePolicyStateRepository
        {
            public function __construct(private bool $enforced) {}

            public function active(?int $idSite): bool
            {
                return $this->enforced;
            }

            public function configControlled(): bool
            {
                return false;
            }

            public function settingEnforced(string $plugin, string $setting, ?int $idSite): bool
            {
                return $this->enforced;
            }

            public function setActive(?int $idSite, bool $active): void {}
        };

        return new DatabaseComplianceStatusProvider(
            $connection,
            $options,
            $policies,
            InstallationConfig::fromFile($this->configurationPath),
        );
    }
}
