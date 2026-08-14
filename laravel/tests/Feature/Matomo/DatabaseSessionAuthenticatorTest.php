<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Authentication\DatabaseApiAccessAuthorizer;
use App\Matomo\Authentication\DatabaseSessionAuthenticator;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseSessionAuthenticatorTest extends TestCase
{
    private const string SALT = 'test-salt';

    private const int SESSION_LIFETIME = 1_209_600;

    private const int IDLE_TIMEOUT = 3_600;

    private ConnectionInterface $connection;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-14 10:00:00 UTC');
        config()->set('database.connections.matomo_session_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);

        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_session_test');

        $this->connection = $databases->connection('matomo_session_test');

        $schema = $this->connection->getSchemaBuilder();
        $schema->create('user', function (Blueprint $table): void {
            $table->string('login')->primary();
            $table->boolean('superuser_access')->default(false);
            $table->dateTime('ts_password_modified')->nullable();
        });
        $schema->create('user_token_auth', function (Blueprint $table): void {
            $table->id('idusertokenauth');
            $table->string('login');
            $table->string('password')->unique();
            $table->dateTime('date_expired')->nullable();
            $table->boolean('secure_only')->default(false);
            $table->dateTime('last_used')->nullable();
        });
        $schema->create('site', function (Blueprint $table): void {
            $table->unsignedInteger('idsite')->primary();
        });
        $schema->create('access', function (Blueprint $table): void {
            $table->string('login');
            $table->unsignedInteger('idsite');
            $table->string('access');
        });
        $schema->create('session', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->integer('modified');
            $table->integer('lifetime');
            $table->text('data');
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_temporary_session_token_is_authorized_and_refreshed(): void
    {
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $this->addViewer('viewer', $now - 1_000);
        $this->addSession('session-id', 'viewer', 'session-token', $now - 500, $now + 100);

        $this->assertTrue($this->authorizer()->hasSomeViewAccess(
            new ApiAuthentication('session-token', true, true, 'session-id'),
        ));

        $stored = $this->connection->table('session')->first();
        $this->assertNotNull($stored);
        $this->assertSame($now, $stored->modified);
        $this->assertSame(self::SESSION_LIFETIME, $stored->lifetime);

        $session = unserialize($stored->data, ['allowed_classes' => false]);
        $this->assertIsArray($session);
        $this->assertSame($now + self::IDLE_TIMEOUT, $session['session.info']['expiration']);
    }

    public function test_expired_or_password_invalidated_session_is_rejected(): void
    {
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $this->addViewer('expired-user', $now - 1_000);
        $this->addSession('expired-id', 'expired-user', 'expired-token', $now - 500, $now - 1);
        $this->addViewer('changed-user', $now - 100);
        $this->addSession('changed-id', 'changed-user', 'changed-token', $now - 500, $now + 100);

        $this->assertFalse($this->authorizer()->hasSomeViewAccess(
            new ApiAuthentication('expired-token', true, true, 'expired-id'),
        ));
        $this->assertFalse($this->authorizer()->hasSomeViewAccess(
            new ApiAuthentication('changed-token', true, true, 'changed-id'),
        ));
    }

    public function test_failed_session_check_falls_back_to_persistent_token(): void
    {
        $this->addViewer('viewer', CarbonImmutable::now('UTC')->subHour()->getTimestamp());
        $this->connection->table('user_token_auth')->insert([
            'login' => 'viewer',
            'password' => hash('sha512', 'persistent-token'.self::SALT),
            'date_expired' => null,
            'secure_only' => false,
            'last_used' => null,
        ]);

        $this->assertTrue($this->authorizer()->hasSomeViewAccess(
            new ApiAuthentication('persistent-token', true, true, 'missing-session'),
        ));
    }

    private function authorizer(): DatabaseApiAccessAuthorizer
    {
        return new DatabaseApiAccessAuthorizer(
            connection: $this->connection,
            salt: self::SALT,
            onlyAllowSecureTokens: false,
            sessions: new DatabaseSessionAuthenticator(
                connection: $this->connection,
                salt: self::SALT,
                sessionLifetime: self::SESSION_LIFETIME,
                idleTimeout: self::IDLE_TIMEOUT,
            ),
        );
    }

    private function addViewer(string $login, int $passwordChangedAt): void
    {
        $this->connection->table('user')->insert([
            'login' => $login,
            'superuser_access' => false,
            'ts_password_modified' => CarbonImmutable::createFromTimestampUTC($passwordChangedAt)->toDateTimeString(),
        ]);
        $this->connection->table('site')->insertOrIgnore(['idsite' => 1]);
        $this->connection->table('access')->insert([
            'login' => $login,
            'idsite' => 1,
            'access' => 'view',
        ]);
    }

    private function addSession(
        #[\SensitiveParameter]
        string $sessionId,
        string $login,
        #[\SensitiveParameter]
        string $token,
        int $startedAt,
        int $expiresAt,
    ): void {
        $this->connection->table('session')->insert([
            'id' => hash('sha512', $sessionId.self::SALT),
            'modified' => CarbonImmutable::now('UTC')->subMinute()->getTimestamp(),
            'lifetime' => self::SESSION_LIFETIME,
            'data' => serialize([
                'user.name' => $login,
                'user.token_auth_temp' => $token,
                'session.info' => [
                    'ts' => $startedAt,
                    'remembered' => false,
                    'expiration' => $expiresAt,
                ],
            ]),
        ]);
    }
}
