<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Geolocation\DatabaseServerVariableMapping;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseServerVariableMappingTest extends TestCase
{
    public function test_reads_custom_geoip_variables_with_the_matomo_table_prefix(): void
    {
        config()->set('database.connections.matomo_geoip_settings_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_geoip_settings_test');

        $connection = $databases->connection('matomo_geoip_settings_test');
        $connection->getSchemaBuilder()->create('plugin_setting', function (Blueprint $table): void {
            $table->string('plugin_name');
            $table->string('user_login')->default('');
            $table->string('setting_name');
            $table->text('setting_value');
        });
        $connection->table('plugin_setting')->insert([
            'plugin_name' => 'GeoIp2',
            'user_login' => '',
            'setting_name' => 'geoip2var_country_code',
            'setting_value' => 'CUSTOM_COUNTRY',
        ]);
        $mapping = new DatabaseServerVariableMapping(
            $connection,
            ['country_code' => 'MM_COUNTRY_CODE', 'city_name' => 'MM_CITY_NAME'],
        );

        $this->assertSame([
            'country_code' => 'CUSTOM_COUNTRY',
            'city_name' => 'MM_CITY_NAME',
        ], $mapping->variables());
    }
}
