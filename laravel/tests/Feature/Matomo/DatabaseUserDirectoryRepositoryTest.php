<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Users\DatabaseUserDirectoryRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabaseUserDirectoryRepositoryTest extends TestCase
{
    public function test_directory_queries_and_shared_site_visibility(): void
    {
        $connection = $this->connection();
        $users = new DatabaseUserDirectoryRepository($connection);

        $this->assertSame(['admin', 'root', 'viewer'], $users->logins());
        $this->assertSame(['viewer'], array_column($users->users(['viewer']), 'login'));
        $this->assertSame('viewer@example.test', $users->user('viewer')['email'] ?? null);
        $this->assertSame('admin', $users->userByEmail('admin@example.test')['login'] ?? null);
        $this->assertSame(['root'], array_column($users->superusers(), 'login'));
        $this->assertEqualsCanonicalizing(
            ['admin', 'viewer'],
            $users->visibleLogins('admin', [7]),
        );
    }

    private function connection(): ConnectionInterface
    {
        $connection = $this->app->make(ConnectionInterface::class);
        $schema = $connection->getSchemaBuilder();
        $schema->create('user', static function (Blueprint $table): void {
            $table->string('login');
            $table->string('email');
            $table->boolean('superuser_access');
            $table->dateTime('date_registered');
        });
        $schema->create('access', static function (Blueprint $table): void {
            $table->string('login');
            $table->unsignedInteger('idsite');
            $table->string('access');
        });
        $connection->table('user')->insert([
            ['login' => 'root', 'email' => 'root@example.test', 'superuser_access' => 1, 'date_registered' => '2020-01-01 00:00:00'],
            ['login' => 'admin', 'email' => 'admin@example.test', 'superuser_access' => 0, 'date_registered' => '2021-01-01 00:00:00'],
            ['login' => 'viewer', 'email' => 'viewer@example.test', 'superuser_access' => 0, 'date_registered' => '2022-01-01 00:00:00'],
        ]);
        $connection->table('access')->insert([
            ['login' => 'admin', 'idsite' => 7, 'access' => 'admin'],
            ['login' => 'viewer', 'idsite' => 7, 'access' => 'view'],
            ['login' => 'root', 'idsite' => 9, 'access' => 'admin'],
        ]);

        return $connection;
    }
}
