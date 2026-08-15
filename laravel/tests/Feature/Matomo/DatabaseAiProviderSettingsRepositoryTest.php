<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\AiProviders\AiProviderStoredSettings;
use App\Matomo\AiProviders\DatabaseAiProviderSettingsRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseAiProviderSettingsRepositoryTest extends TestCase
{
    public function test_round_trips_all_settings_and_keeps_credentials_json_encoded(): void
    {
        config()->set('database.connections.ai_provider_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('ai_provider_test');

        $connection = $databases->connection('ai_provider_test');
        $connection->getSchemaBuilder()->create('plugin_setting', function (Blueprint $table): void {
            $table->string('plugin_name');
            $table->string('user_login');
            $table->string('setting_name');
            $table->text('setting_value');
            $table->boolean('json_encoded')->default(false);
            $table->unique(['plugin_name', 'user_login', 'setting_name']);
        });
        $repository = new DatabaseAiProviderSettingsRepository($connection);
        $settings = new AiProviderStoredSettings(
            'custom-provider',
            'thinking',
            ['custom-provider' => [
                'apiKey' => 'key-&<>',
                'endpointUrl' => 'https://llm.example/v1',
                'model' => 'model-a',
                'useFipsEndpoint' => false,
            ]],
        );

        $repository->save($settings);

        $this->assertEquals($settings, $repository->read());
        $rows = $connection->table('plugin_setting')->orderBy('setting_name')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(
            1,
            (int) $rows->firstWhere('setting_name', 'providerCredentials')?->json_encoded,
        );
        $this->assertSame(
            0,
            (int) $rows->firstWhere('setting_name', 'defaultProvider')?->json_encoded,
        );
    }

    public function test_ignores_malformed_stored_credentials(): void
    {
        config()->set('database.connections.ai_provider_invalid_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('ai_provider_invalid_test');

        $connection = $databases->connection('ai_provider_invalid_test');
        $connection->getSchemaBuilder()->create('plugin_setting', function (Blueprint $table): void {
            $table->string('plugin_name');
            $table->string('user_login');
            $table->string('setting_name');
            $table->text('setting_value');
            $table->boolean('json_encoded')->default(false);
        });
        $connection->table('plugin_setting')->insert([
            'plugin_name' => 'AIProviders',
            'user_login' => '',
            'setting_name' => 'providerCredentials',
            'setting_value' => '{bad-json',
            'json_encoded' => 1,
        ]);

        $this->assertSame([], (new DatabaseAiProviderSettingsRepository($connection))->read()->providerCredentials);
    }
}
