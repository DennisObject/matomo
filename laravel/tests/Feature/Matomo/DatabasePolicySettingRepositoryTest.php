<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Settings\DatabasePolicySettingRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabasePolicySettingRepositoryTest extends TestCase
{
    public function test_reads_system_and_site_boolean_states_with_the_matomo_prefix(): void
    {
        config()->set('database.connections.matomo_policy_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_policy_test');

        $connection = $databases->connection('matomo_policy_test');
        $connection->getSchemaBuilder()->create('plugin_setting', function (Blueprint $table): void {
            $table->string('plugin_name');
            $table->string('user_login')->default('');
            $table->string('setting_name');
            $table->text('setting_value');
            $table->boolean('json_encoded')->default(false);
        });
        $connection->getSchemaBuilder()->create('site_setting', function (Blueprint $table): void {
            $table->unsignedInteger('idsite');
            $table->string('plugin_name');
            $table->string('setting_name');
            $table->text('setting_value');
            $table->boolean('json_encoded')->default(false);
        });
        $connection->table('plugin_setting')->insert([
            [
                'plugin_name' => 'SitesManager',
                'user_login' => '',
                'setting_name' => 'enabled',
                'setting_value' => '1',
                'json_encoded' => 0,
            ],
            [
                'plugin_name' => 'SitesManager',
                'user_login' => '',
                'setting_name' => 'disabled',
                'setting_value' => 'false',
                'json_encoded' => 1,
            ],
        ]);
        $connection->table('site_setting')->insert([
            [
                'idsite' => 7,
                'plugin_name' => 'CnilPolicy',
                'setting_name' => 'disabled',
                'setting_value' => '0',
                'json_encoded' => 0,
            ],
            [
                'idsite' => 7,
                'plugin_name' => 'CnilPolicy',
                'setting_name' => 'enabled',
                'setting_value' => 'true',
                'json_encoded' => 1,
            ],
        ]);
        $settings = new DatabasePolicySettingRepository($connection);

        $this->assertTrue($settings->systemBoolean('SitesManager', 'enabled'));
        $this->assertFalse($settings->systemBoolean('SitesManager', 'disabled'));
        $this->assertNull($settings->systemBoolean('SitesManager', 'missing'));
        $this->assertFalse($settings->siteBoolean(7, 'CnilPolicy', 'disabled'));
        $this->assertTrue($settings->siteBoolean(7, 'CnilPolicy', 'enabled'));
        $this->assertNull($settings->siteBoolean(8, 'CnilPolicy', 'enabled'));
    }
}
