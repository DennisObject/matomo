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

    public function test_inviter_can_generate_separate_link_token(): void
    {
        $connection = $this->connection();
        $users = new DatabaseMutableUserRepository($connection, 'salt');
        $users->invite('alice', 'alice@example.test', 7, 7, 'admin');

        $result = $users->renewInvitation('alice', 30, true, 'admin', false);

        $this->assertSame('updated', $result['result']);
        $token = $result['token'] ?? '';
        $stored = $connection->table('user')->where('login', 'alice')->value('invite_link_token');
        $this->assertSame(hash('sha512', $token.'salt'), $stored);
        $this->assertNotNull($connection->table('user')->where('login', 'alice')->value('invite_token'));
    }

    public function test_other_admin_cannot_rotate_invite_token(): void
    {
        $connection = $this->connection();
        $users = new DatabaseMutableUserRepository($connection, 'salt');
        $created = $users->invite('alice', 'alice@example.test', 7, 7, 'admin');

        $this->assertSame('denied', $users->renewInvitation('alice', 30, false, 'other', false)['result']);
        $this->assertSame(
            hash('sha512', ($created['token'] ?? '').'salt'),
            $connection->table('user')->where('login', 'alice')->value('invite_token'),
        );
    }

    public function test_only_superuser_is_protected_and_access_is_removed_on_grant(): void
    {
        $connection = $this->connection();
        $connection->table('user')->insert([
            'login' => 'admin', 'password' => '', 'email' => 'admin@example.test',
            'date_registered' => '2026-01-01 00:00:00', 'superuser_access' => 1,
            'ts_password_modified' => '2026-01-01 00:00:00',
        ]);
        $users = new DatabaseMutableUserRepository($connection, 'salt');

        $this->assertSame('only-superuser', $users->setSuperuser('admin', false));
        $users->create('alice', 'secret1', 'alice@example.test', false, 7);
        $this->assertSame('updated', $users->setSuperuser('alice', true));
        $this->assertFalse($connection->table('access')->where('login', 'alice')->exists());
    }

    public function test_logout_removes_matching_sessions_only(): void
    {
        $connection = $this->connection();
        $users = new DatabaseMutableUserRepository($connection, 'salt');
        $users->create('alice', 'secret1', 'alice@example.test', false, null);

        $marker = 's:9:"user.name";s:5:"alice"';
        $connection->table('session')->insert([
            ['id' => 'alice', 'data' => base64_encode($marker)],
            ['id' => 'other', 'data' => base64_encode('unrelated')],
        ]);

        $this->assertTrue($users->deleteSessions('alice'));
        $this->assertFalse($connection->table('session')->where('id', 'alice')->exists());
        $this->assertTrue($connection->table('session')->where('id', 'other')->exists());
    }

    public function test_pending_email_update_rotates_invite_and_password(): void
    {
        $connection = $this->connection();
        $users = new DatabaseMutableUserRepository($connection, 'salt');
        $users->invite('alice', 'alice@example.test', 7, 7, 'admin');

        $result = $users->update('alice', 'newpass', 'new@example.test', false, 7);

        $this->assertSame('updated', $result['result']);
        $token = $result['inviteToken'] ?? null;
        $this->assertIsString($token);
        $user = $connection->table('user')->where('login', 'alice');
        $this->assertSame('new@example.test', $user->value('email'));
        $password = $user->value('password');
        $this->assertIsString($password);
        $this->assertTrue(password_verify(md5('newpass'), $password));
        $this->assertSame(hash('sha512', $token.'salt'), $user->value('invite_token'));
    }

    public function test_delete_removes_user_owned_security_data(): void
    {
        $connection = $this->connection();
        $users = new DatabaseMutableUserRepository($connection, 'salt');
        $users->create('alice', 'secret1', 'alice@example.test', false, 7);
        $connection->table('user_token_auth')->insert(['login' => 'alice']);
        $connection->table('plugin_setting')->insert(['user_login' => 'alice']);
        $connection->table('option')->insert(['option_name' => 'Feedback.nextFeedbackReminder.alice']);

        $this->assertSame('deleted', $users->delete('alice', 'admin', true));
        $this->assertFalse($connection->table('user')->where('login', 'alice')->exists());
        $this->assertFalse($connection->table('access')->where('login', 'alice')->exists());
        $this->assertFalse($connection->table('user_token_auth')->where('login', 'alice')->exists());
        $this->assertFalse($connection->table('plugin_setting')->where('user_login', 'alice')->exists());
        $this->assertFalse($connection->table('option')->where('option_name', 'like', '%.alice')->exists());
    }

    public function test_invitation_renewal_requires_the_exact_login(): void
    {
        $connection = $this->connection();
        $users = new DatabaseMutableUserRepository($connection, 'salt');
        $users->invite('alice', 'alice@example.test', 7, 7, 'admin');

        $this->assertSame(
            'not-pending',
            $users->renewInvitation('alice@example.test', 30, false, 'admin', true)['result'],
        );
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
        $schema->create('session', static function (Blueprint $table): void {
            $table->string('id');
            $table->text('data');
        });
        $schema->create('user_token_auth', static function (Blueprint $table): void {
            $table->string('login');
        });
        $schema->create('plugin_setting', static function (Blueprint $table): void {
            $table->string('user_login');
        });
        $schema->create('option', static function (Blueprint $table): void {
            $table->string('option_name');
        });

        return $connection;
    }
}
