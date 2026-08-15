<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Sites\DatabaseSiteRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseSiteRepositoryTest extends TestCase
{
    public function test_site_lifecycle_writes_are_atomic_and_protect_the_last_site(): void
    {
        config()->set('database.connections.matomo_site_lifecycle_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_site_lifecycle_test');

        $connection = $databases->connection('matomo_site_lifecycle_test');
        $connection->getSchemaBuilder()->create('site', function (Blueprint $table): void {
            $table->increments('idsite');
            $table->string('name');
            $table->string('main_url');
            $table->string('timezone')->default('UTC');
            $table->string('currency')->default('USD');
            $table->string('group')->default('');
            $table->string('type')->default('website');
            $table->boolean('ecommerce')->default(false);
            $table->string('excluded_referrers')->default('');
            $table->string('excluded_parameters')->default('');
        });
        $connection->getSchemaBuilder()->create('site_url', function (Blueprint $table): void {
            $table->unsignedInteger('idsite');
            $table->string('url');
        });
        $connection->getSchemaBuilder()->create('site_setting', function (Blueprint $table): void {
            $table->unsignedInteger('idsite');
            $table->string('plugin_name');
            $table->string('setting_name');
            $table->text('setting_value');
            $table->boolean('json_encoded')->default(false);
            $table->unique(['idsite', 'plugin_name', 'setting_name']);
        });
        $connection->getSchemaBuilder()->create('archive_invalidations', function (Blueprint $table): void {
            $table->unsignedInteger('idsite');
        });
        $sites = new DatabaseSiteRepository($connection);
        $first = $sites->create(
            ['name' => 'First', 'main_url' => 'https://first.test'],
            ['https://first.test', 'https://alias.test'],
            ['Live' => [['name' => 'disabled', 'value' => true]]],
        );

        $this->assertSame(1, $first);
        $this->assertSame(['https://first.test', 'https://alias.test'], $sites->urls($first));
        $this->assertSame('1', $connection->table('site_setting')->value('setting_value'));
        $this->assertSame('last-site', $sites->delete($first));

        $second = $sites->create(
            ['name' => 'Second', 'main_url' => 'https://second.test'],
            ['https://second.test'],
            [],
        );
        $this->assertTrue($sites->update(
            $first,
            ['name' => 'Updated'],
            ['https://updated.test'],
            ['Live' => [['name' => 'disabled', 'value' => false]]],
        ));
        $this->assertSame('Updated', $sites->details($first)['name']);
        $this->assertSame(['https://first.test'], $sites->urls($first));
        $this->assertSame('deleted', $sites->delete($second));
        $this->assertSame('not-found', $sites->delete($second));
    }

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
            $table->string('main_url');
            $table->string('timezone');
            $table->string('excluded_referrers')->default('');
            $table->string('excluded_parameters')->default('');
            $table->string('name');
            $table->string('currency');
            $table->string('type')->default('website');
            $table->boolean('ecommerce')->default(false);
            $table->string('creator_login')->nullable();
        });
        $connection->getSchemaBuilder()->create('site_url', function (Blueprint $table): void {
            $table->unsignedInteger('idsite');
            $table->string('url');
        });
        $connection->table('site')->insert([
            [
                'idsite' => 3,
                'group' => ' Main ',
                'main_url' => 'https://example.test',
                'timezone' => 'Europe/Paris',
                'excluded_referrers' => 'site.test,shared.test',
                'excluded_parameters' => 'session,token',
                'name' => 'Example',
                'currency' => 'EUR',
                'type' => 'website',
                'ecommerce' => true,
                'creator_login' => 'owner',
            ],
            [
                'idsite' => 8,
                'group' => 'a,b',
                'main_url' => 'https://other.test',
                'timezone' => 'UTC',
                'excluded_referrers' => '',
                'excluded_parameters' => '',
                'name' => 'Other',
                'currency' => 'USD',
                'type' => 'intranet',
                'ecommerce' => false,
                'creator_login' => null,
            ],
        ]);
        $connection->table('site_url')->insert([
            ['idsite' => 3, 'url' => 'https://www.example.test'],
            ['idsite' => 3, 'url' => 'https://example.test/docs'],
        ]);

        $sites = new DatabaseSiteRepository($connection);

        $this->assertSame([3, 8], $sites->allIds());
        $details = $sites->details(3);
        $this->assertSame(3, $details['idsite']);
        $this->assertSame(1, $details['ecommerce']);
        $this->assertSame('owner', $details['creator_login']);
        $this->assertSame([], $sites->details(99));
        $this->assertSame('https://example.test', $sites->mainUrl(3));
        $this->assertNull($sites->mainUrl(99));
        $this->assertSame('Europe/Paris', $sites->timezone(3));
        $this->assertNull($sites->timezone(99));
        $this->assertSame([3, 8], array_keys($sites->allDetails()));
        $this->assertSame([3, 8], array_column($sites->detailsForIds([8, 3]), 'idsite'));
        $this->assertSame([3], array_column($sites->detailsForIds([3, 8], 'Exam'), 'idsite'));
        $this->assertSame([8], array_column($sites->detailsForIds([3, 8], 'other.test'), 'idsite'));
        $this->assertSame([3], array_column($sites->detailsForIds([3, 8], '3'), 'idsite'));
        $this->assertSame([3], array_column($sites->detailsForIds([3, 8], null, 1), 'idsite'));
        $this->assertSame(
            [3],
            array_column($sites->detailsForIds([3, 8], null, null, ['intranet']), 'idsite'),
        );
        $this->assertSame(
            [8],
            array_column($sites->detailsForIds([3, 8], 'Other', null, ['website']), 'idsite'),
        );
        $this->assertSame([], $sites->detailsForIds([]));
        $this->assertSame([3], array_column($sites->detailsInGroup(' Main '), 'idsite'));
        $this->assertSame([], $sites->detailsInGroup('missing'));
        $this->assertSame(['Main', 'a,b'], $sites->groups());
        $this->assertSame([
            'https://example.test',
            'https://www.example.test',
            'https://example.test/docs',
        ], $sites->urls(3));
        $this->assertSame([
            3 => ['https://www.example.test', 'https://example.test/docs'],
        ], $sites->aliasUrlsForIds([3, 8]));
        $this->assertSame([], $sites->aliasUrlsForIds([]));
        $this->assertSame(['Europe/Paris', 'UTC'], $sites->timezones());
        $this->assertSame([3], $sites->idsInTimezones(['Europe/Paris', 'Pacific/Auckland']));
        $this->assertSame([], $sites->idsInTimezones([]));
        $this->assertSame(
            [['idsite' => '3']],
            $sites->idsForUrls(['https://www.example.test'], [3, 8]),
        );
        $this->assertSame([], $sites->idsForUrls(['https://www.example.test'], [8]));
        $this->assertSame(
            ['https://new.example.test', 'https://example.test/new'],
            $sites->replaceAliasUrls(3, ['https://new.example.test', 'https://example.test/new']),
        );
        $this->assertSame([
            'https://example.test',
            'https://new.example.test',
            'https://example.test/new',
        ], $sites->urls(3));
        $this->assertSame('site.test,shared.test', $sites->excludedReferrers(3));
        $this->assertNull($sites->excludedReferrers(99));
        $this->assertSame('session,token', $sites->excludedParameters(3));
        $this->assertNull($sites->excludedParameters(99));
        $this->assertSame([3], $sites->renameGroup(' Main ', 'Renamed'));
        $this->assertSame([3], array_column($sites->detailsInGroup('Renamed'), 'idsite'));
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
