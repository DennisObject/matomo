<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Users\DatabaseUserSiteAccessRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabaseUserSiteAccessRepositoryTest extends TestCase
{
    public function test_access_mapping_queries_preserve_rows_and_site_existence(): void
    {
        $connection = $this->connection();
        $access = new DatabaseUserSiteAccessRepository($connection);

        $this->assertSame(['alice' => [1, 2]], $access->sitesByLogin('view'));
        $this->assertSame(['alice' => 'view'], $access->accessByLogin(1));
        $this->assertSame(['alice'], $access->logins(2, 'view'));
        $this->assertSame([
            ['site' => 1, 'access' => 'view'],
            ['site' => 2, 'access' => 'view'],
        ], $access->forUser('alice'));
    }

    private function connection(): ConnectionInterface
    {
        $connection = $this->app->make(ConnectionInterface::class);
        $schema = $connection->getSchemaBuilder();
        $schema->create('site', static function (Blueprint $table): void {
            $table->unsignedInteger('idsite');
        });
        $schema->create('access', static function (Blueprint $table): void {
            $table->string('login');
            $table->unsignedInteger('idsite');
            $table->string('access');
        });
        $connection->table('site')->insert([['idsite' => 1], ['idsite' => 2]]);
        $connection->table('access')->insert([
            ['login' => 'alice', 'idsite' => 1, 'access' => 'view'],
            ['login' => 'alice', 'idsite' => 2, 'access' => 'view'],
            ['login' => 'bob', 'idsite' => 2, 'access' => 'write'],
            ['login' => 'alice', 'idsite' => 99, 'access' => 'admin'],
        ]);

        return $connection;
    }
}
