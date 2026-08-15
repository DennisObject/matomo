<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Users\DatabaseMutableUserSiteAccessRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabaseMutableUserSiteAccessRepositoryTest extends TestCase
{
    public function test_missing_role_does_not_partially_add_capabilities(): void
    {
        $connection = $this->connection();
        $access = new DatabaseMutableUserSiteAccessRepository($connection);

        $this->assertSame(8, $access->addCapabilities('alice', [7, 8], ['manage_tags' => []]));
        $this->assertFalse($connection->table('access')->where('access', 'manage_tags')->exists());
    }

    public function test_capability_included_in_role_is_not_stored(): void
    {
        $connection = $this->connection();
        $connection->table('access')->insert(['login' => 'alice', 'idsite' => 8, 'access' => 'admin']);
        $access = new DatabaseMutableUserSiteAccessRepository($connection);

        $this->assertNull($access->addCapabilities('alice', [7, 8], ['manage_tags' => ['admin']]));
        $this->assertSame(1, $connection->table('access')->where('access', 'manage_tags')->count());
    }

    private function connection(): ConnectionInterface
    {
        $connection = $this->app->make(ConnectionInterface::class);
        $schema = $connection->getSchemaBuilder();
        $schema->create('user', static function (Blueprint $table): void {
            $table->string('login');
            $table->unsignedTinyInteger('superuser_access')->default(0);
        });
        $schema->create('access', static function (Blueprint $table): void {
            $table->string('login');
            $table->unsignedInteger('idsite');
            $table->string('access');
        });
        $connection->table('user')->insert(['login' => 'alice', 'superuser_access' => 0]);
        $connection->table('access')->insert(['login' => 'alice', 'idsite' => 7, 'access' => 'view']);

        return $connection;
    }
}
