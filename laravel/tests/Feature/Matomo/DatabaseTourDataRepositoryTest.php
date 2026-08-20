<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Tour\DatabaseTourDataRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseTourDataRepositoryTest extends TestCase
{
    public function test_reads_legacy_progress_and_persists_a_skip_without_replacing_other_state(): void
    {
        $connection = $this->app->make(ConnectionInterface::class);
        $connection->getSchemaBuilder()->create('plugin_setting', static function (Blueprint $table): void {
            $table->increments('idplugin_setting');
            $table->string('plugin_name');
            $table->string('setting_name');
            $table->text('setting_value');
            $table->unsignedTinyInteger('json_encoded')->default(0);
            $table->string('user_login')->default('');
        });
        $connection->table('plugin_setting')->insert([
            'plugin_name' => 'Tour',
            'setting_name' => 'track_data_completed',
            'setting_value' => '1',
            'json_encoded' => 0,
            'user_login' => 'alice',
        ]);
        $repository = new DatabaseTourDataRepository($connection);

        $repository->skip('alice', 'flatten_actions');

        $this->assertSame([
            'track_data_completed' => true,
            'flatten_actions_skipped' => true,
        ], $repository->progress('alice'));
    }
}
