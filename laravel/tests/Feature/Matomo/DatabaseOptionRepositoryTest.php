<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Options\DatabaseOptionRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseOptionRepositoryTest extends TestCase
{
    public function test_reads_an_option_with_the_matomo_table_prefix(): void
    {
        config()->set('database.connections.matomo_options_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_options_test');

        $connection = $databases->connection('matomo_options_test');
        $connection->getSchemaBuilder()->create('option', function (Blueprint $table): void {
            $table->string('option_name')->primary();
            $table->text('option_value');
            $table->boolean('autoload')->default(false);
        });
        $connection->table('option')->insert([
            'option_name' => 'SitesManager_DefaultTimezone',
            'option_value' => 'Europe/Paris',
        ]);
        $options = new DatabaseOptionRepository($connection);

        $this->assertSame('Europe/Paris', $options->value('SitesManager_DefaultTimezone'));
        $this->assertNull($options->value('missing'));

        $options->set('SitesManager_DefaultTimezone', 'America/Toronto', true);
        $options->set('new-option', 'new-value');

        $this->assertSame('America/Toronto', $options->value('SitesManager_DefaultTimezone'));
        $this->assertSame('new-value', $options->value('new-option'));
        $this->assertSame(1, $connection->table('option')
            ->where('option_name', 'SitesManager_DefaultTimezone')
            ->value('autoload'));
    }
}
