<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\ProfessionalServices\DatabasePromoWidgetDismissalRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabasePromoWidgetDismissalRepositoryTest extends TestCase
{
    public function test_preserves_existing_widget_timestamps_and_updates_selected_widget(): void
    {
        config()->set('database.connections.promo_widget_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('promo_widget_test');

        $connection = $databases->connection('promo_widget_test');
        $connection->getSchemaBuilder()->create('plugin_setting', function (Blueprint $table): void {
            $table->string('plugin_name');
            $table->string('user_login');
            $table->string('setting_name');
            $table->text('setting_value');
            $table->boolean('json_encoded')->default(false);
            $table->unique(['plugin_name', 'user_login', 'setting_name']);
        });
        $connection->table('plugin_setting')->insert([
            'plugin_name' => 'ProfessionalServices',
            'user_login' => 'alice',
            'setting_name' => 'dismissedWidgets',
            'setting_value' => '{"PromoFunnels":100}',
            'json_encoded' => 1,
        ]);
        $repository = new DatabasePromoWidgetDismissalRepository($connection);

        $repository->dismiss('alice', 'PromoHeatmaps', 200);

        $value = $connection->table('plugin_setting')->value('setting_value');
        $this->assertSame(
            ['PromoFunnels' => 100, 'PromoHeatmaps' => 200],
            json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR),
        );
    }
}
