<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Privacy\DatabaseCompliancePolicyStateRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabaseCompliancePolicyStateRepositoryTest extends TestCase
{
    public function test_site_disable_also_disables_active_instance_policy(): void
    {
        config()->set('database.connections.matomo_compliance_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_compliance_test');

        $connection = $databases->connection('matomo_compliance_test');
        $connection->getSchemaBuilder()->create('plugin_setting', function (Blueprint $table): void {
            $table->string('plugin_name');
            $table->string('user_login')->default('');
            $table->string('setting_name');
            $table->text('setting_value');
            $table->boolean('json_encoded')->default(false);
            $table->unique(['plugin_name', 'user_login', 'setting_name']);
        });
        $connection->getSchemaBuilder()->create('site_setting', function (Blueprint $table): void {
            $table->unsignedInteger('idsite');
            $table->string('plugin_name');
            $table->string('setting_name');
            $table->text('setting_value');
            $table->boolean('json_encoded')->default(false);
            $table->unique(['idsite', 'plugin_name', 'setting_name']);
        });
        $repository = new DatabaseCompliancePolicyStateRepository($connection, null);

        $repository->setActive(null, true);
        $repository->setActive(7, false);

        $this->assertSame('0', $connection->table('plugin_setting')->value('setting_value'));
        $this->assertSame('0', $connection->table('site_setting')->value('setting_value'));
        $this->assertFalse($repository->active(7));
        $this->assertFalse($repository->configControlled());

        $repository->setActive(7, true);
        $this->assertTrue($repository->active(7));
        $this->assertTrue($repository->settingEnforced('DevicesDetection', 'OnlyMajorVersions', 7));

        $connection->table('plugin_setting')->insert([
            'plugin_name' => 'DevicesDetection',
            'user_login' => '',
            'setting_name' => 'OnlyMajorVersions_policy_enforced',
            'setting_value' => '0',
            'json_encoded' => 0,
        ]);
        $this->assertFalse($repository->settingEnforced('DevicesDetection', 'OnlyMajorVersions', 7));

        $configured = new DatabaseCompliancePolicyStateRepository($connection, true);
        $this->assertTrue($configured->active(null));
        $this->assertTrue($configured->configControlled());
        $this->assertTrue($configured->settingEnforced('DevicesDetection', 'OnlyMajorVersions', 7));
    }
}
