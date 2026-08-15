<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\MobileMessaging\DatabaseMobileMessagingSettingsRepository;
use App\Matomo\Options\MutableOptionRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabaseMobileMessagingSettingsRepositoryTest extends TestCase
{
    public function test_round_trips_user_settings_and_reads_legacy_options(): void
    {
        config()->set('database.connections.mobile_messaging_test', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('mobile_messaging_test');

        $connection = $databases->connection('mobile_messaging_test');
        $connection->getSchemaBuilder()->create('plugin_setting', static function (Blueprint $table): void {
            $table->string('plugin_name');
            $table->string('user_login');
            $table->string('setting_name');
            $table->text('setting_value')->nullable();
            $table->boolean('json_encoded')->default(false);
        });
        $options = $this->app->make(MutableOptionRepository::class);
        $repository = new DatabaseMobileMessagingSettingsRepository($connection, $options);
        $settings = [
            'Provider' => 'ASPSMS',
            'APIKey' => ['username' => 'owner', 'password' => 'secret'],
            'PhoneNumbers' => ['+1234567' => ['verified' => true]],
        ];

        $repository->save('alice', $settings);

        $this->assertSame($settings, $repository->read('alice'));
        $this->assertSame(3, $connection->table('plugin_setting')->count());
        $this->assertSame(1, (int) $connection->table('plugin_setting')
            ->where('setting_name', 'APIKey')->value('json_encoded'));

        $options->set('bob_MobileMessagingSettings', json_encode(['Provider' => 'Development'], JSON_THROW_ON_ERROR));
        $this->assertSame(['Provider' => 'Development'], $repository->read('bob'));
    }
}
