<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Users\DatabaseMutableUserRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabaseMutableUserRepositoryTest extends TestCase
{
    public function test_create_hashes_password_and_grants_view_access_atomically(): void
    {
        $connection = $this->connection();
        $users = new DatabaseMutableUserRepository($connection, 'salt');

        $this->assertSame('created', $users->create('alice', 'secret1', 'alice@example.test', false, 7));
        $hash = $connection->table('user')->where('login', 'alice')->value('password');
        $this->assertIsString($hash);
        $this->assertTrue(password_verify(md5('secret1'), $hash));
        $this->assertTrue($connection->table('access')->where([
            'login' => 'alice', 'idsite' => 7, 'access' => 'view',
        ])->exists());
    }

    public function test_invite_only_persists_hashed_token(): void
    {
        $connection = $this->connection();
        $users = new DatabaseMutableUserRepository($connection, 'salt');

        $result = $users->invite('alice', 'alice@example.test', 7, 7, 'admin');
        $this->assertSame('created', $result['result']);
        $token = $result['token'] ?? '';
        $stored = $connection->table('user')->where('login', 'alice')->value('invite_token');
        $this->assertNotSame($token, $stored);
        $this->assertSame(hash('sha512', $token.'salt'), $stored);
    }

    private function connection(): ConnectionInterface
    {
        $connection = $this->app->make(ConnectionInterface::class);
        $schema = $connection->getSchemaBuilder();
        $schema->create('user', static function (Blueprint $table): void {
            $table->string('login');
            $table->string('password');
            $table->string('email');
            $table->dateTime('date_registered');
            $table->unsignedTinyInteger('superuser_access');
            $table->dateTime('ts_password_modified');
            $table->unsignedInteger('idchange_last_viewed')->nullable();
            $table->string('invited_by')->nullable();
            $table->string('invite_token')->nullable();
            $table->string('invite_link_token')->nullable();
            $table->dateTime('invite_expired_at')->nullable();
        });
        $schema->create('access', static function (Blueprint $table): void {
            $table->string('login');
            $table->unsignedInteger('idsite');
            $table->string('access');
        });

        return $connection;
    }
}
