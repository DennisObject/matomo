<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Sites\DatabaseSiteRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseSiteRepositoryTest extends TestCase
{
    public function test_reads_integer_site_ids_with_the_matomo_table_prefix(): void
    {
        config()->set('database.connections.matomo_sites_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_sites_test');

        $connection = $databases->connection('matomo_sites_test');
        $connection->getSchemaBuilder()->create('site', function (Blueprint $table): void {
            $table->unsignedInteger('idsite')->primary();
            $table->string('group')->default('');
        });
        $connection->table('site')->insert([
            ['idsite' => 3, 'group' => ' Main '],
            ['idsite' => 8, 'group' => 'a,b'],
        ]);

        $sites = new DatabaseSiteRepository($connection);

        $this->assertSame([3, 8], $sites->allIds());
        $this->assertSame(['Main', 'a,b'], $sites->groups());
    }

    public function test_returns_an_empty_list_before_the_site_table_exists(): void
    {
        config()->set('database.connections.matomo_uninstalled_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_uninstalled_test');

        $this->assertSame(
            [],
            (new DatabaseSiteRepository($databases->connection('matomo_uninstalled_test')))->allIds(),
        );
    }
}
