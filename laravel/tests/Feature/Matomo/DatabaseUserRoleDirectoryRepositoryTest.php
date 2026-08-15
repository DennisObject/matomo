<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Users\DatabaseUserRoleDirectoryRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabaseUserRoleDirectoryRepositoryTest extends TestCase
{
    public function test_filters_users_and_loads_all_site_access_entries(): void
    {
        $connection = $this->connection();
        $roles = new DatabaseUserRoleDirectoryRepository($connection);

        $result = $roles->filtered(7, 10, 0, 'ali', 'some', 'active', null, 'root', true);

        $this->assertSame(1, $result['total']);
        $this->assertSame('alice', $result['rows'][0]['login']);
        $this->assertEqualsCanonicalizing(['view', 'manage_tags'], $result['rows'][0]['access']);
        $this->assertEqualsCanonicalizing(
            ['view', 'manage_tags'],
            $roles->accessEntries('alice', 7),
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
            $table->string('invite_token')->nullable();
            $table->dateTime('invite_expired_at')->nullable();
            $table->string('invited_by')->nullable();
        });
        $schema->create('access', static function (Blueprint $table): void {
            $table->string('login');
            $table->unsignedInteger('idsite');
            $table->string('access');
        });
        $connection->table('user')->insert([
            ['login' => 'alice', 'email' => 'alice@example.test', 'superuser_access' => 0, 'invite_token' => null, 'invite_expired_at' => null, 'invited_by' => null],
            ['login' => 'bob', 'email' => 'bob@example.test', 'superuser_access' => 0, 'invite_token' => null, 'invite_expired_at' => null, 'invited_by' => null],
        ]);
        $connection->table('access')->insert([
            ['login' => 'alice', 'idsite' => 7, 'access' => 'view'],
            ['login' => 'alice', 'idsite' => 7, 'access' => 'manage_tags'],
            ['login' => 'bob', 'idsite' => 8, 'access' => 'view'],
        ]);

        return $connection;
    }
}
