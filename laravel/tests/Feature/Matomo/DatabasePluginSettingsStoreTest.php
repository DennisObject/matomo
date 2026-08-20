<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Plugins\DatabasePluginSettingsStore;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabasePluginSettingsStoreTest extends TestCase
{
    public function test_replaces_and_decodes_plugin_settings_in_legacy_table_shape(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();
        $schema->create('plugin_setting', static function (Blueprint $table): void {
            $table->string('plugin_name');
            $table->string('user_login');
            $table->string('setting_name');
            $table->text('setting_value')->nullable();
            $table->boolean('json_encoded')->default(false);
        });
        $store = new DatabasePluginSettingsStore($this->app['db']->connection());
        $store->replace('Demo', 'alice', ['rows' => 25, 'columns' => ['visits', 'actions']]);

        $this->assertSame(['rows' => '25', 'columns' => ['visits', 'actions']], $store->values('Demo', 'alice'));
        $store->replace('Demo', 'alice', ['rows' => 50]);
        $this->assertSame(['rows' => '50'], $store->values('Demo', 'alice'));
    }
}
