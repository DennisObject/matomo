<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Users\DatabaseUserPreferenceRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabaseUserPreferenceRepositoryTest extends TestCase
{
    public function test_preferences_round_trip_scalars_json_and_null_deletion(): void
    {
        $connection = $this->connection();
        $repository = new DatabaseUserPreferenceRepository($connection);

        $this->assertSame('Alice', $repository->canonicalLogin('alice'));
        $this->assertNull($repository->canonicalLogin('missing'));
        $repository->set('Alice', 'themeMode', 'dark');
        $repository->set('Alice', 'custom', ['one', 'two']);

        $this->assertSame(['found' => true, 'value' => 'dark'], $repository->get('Alice', 'themeMode'));
        $this->assertSame(['found' => true, 'value' => ['one', 'two']], $repository->get('Alice', 'custom'));
        $this->assertSame(
            ['Alice' => ['themeMode' => 'dark', 'custom' => ['one', 'two']]],
            $repository->forAllUsers(['themeMode', 'custom']),
        );

        $repository->set('Alice', 'themeMode', null);
        $this->assertSame(['found' => false, 'value' => null], $repository->get('Alice', 'themeMode'));
    }

    private function connection(): ConnectionInterface
    {
        $connection = $this->app->make(ConnectionInterface::class);
        $schema = $connection->getSchemaBuilder();
        $schema->create('user', static function (Blueprint $table): void {
            $table->string('login');
        });
        $schema->create('plugin_setting', static function (Blueprint $table): void {
            $table->increments('idplugin_setting');
            $table->string('plugin_name');
            $table->string('user_login');
            $table->string('setting_name');
            $table->text('setting_value');
            $table->boolean('json_encoded')->default(false);
        });
        $connection->table('user')->insert(['login' => 'Alice']);

        return $connection;
    }
}
