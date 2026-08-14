<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\DatabaseVersionAccessAuthorizer;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseVersionAccessAuthorizerTest extends TestCase
{
    private const string SALT = 'test-salt';

    private ConnectionInterface $connection;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.matomo_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);

        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_test');

        $this->connection = $databases->connection('matomo_test');

        $schema = $this->connection->getSchemaBuilder();
        $schema->create('user', function (Blueprint $table): void {
            $table->string('login')->primary();
            $table->boolean('superuser_access')->default(false);
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
    }

    public function test_valid_view_token_is_authorized_and_records_use(): void
    {
        $this->addUser('viewer');
        $this->addSiteAccess('viewer', 'view');
        $tokenId = $this->addToken('viewer', 'view-token');

        $this->assertTrue($this->authorizer()->hasSomeViewAccess('view-token', false));
        $this->assertNotNull(
            $this->connection->table('user_token_auth')->where('idusertokenauth', $tokenId)->value('last_used'),
        );
    }

    public function test_superuser_does_not_need_site_access(): void
    {
        $this->addUser('root', true);
        $this->addToken('root', 'root-token');

        $this->assertTrue($this->authorizer()->hasSomeViewAccess('root-token', true));
    }

    public function test_secure_only_token_is_rejected_from_query_and_accepted_from_post(): void
    {
        $this->addUser('viewer');
        $this->addSiteAccess('viewer', 'view');
        $this->addToken('viewer', 'secure-token', secureOnly: true);

        $this->assertFalse($this->authorizer()->hasSomeViewAccess('secure-token', false));
        $this->assertTrue($this->authorizer()->hasSomeViewAccess('secure-token', true));
    }

    public function test_global_secure_token_rule_rejects_query_token(): void
    {
        $this->addUser('viewer');
        $this->addSiteAccess('viewer', 'view');
        $this->addToken('viewer', 'token');

        $this->assertFalse($this->authorizer(onlyAllowSecureTokens: true)->hasSomeViewAccess('token', false));
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->addUser('viewer');
        $this->addSiteAccess('viewer', 'view');
        $this->addToken('viewer', 'expired-token', expiredAt: '2000-01-01 00:00:00');

        $this->assertFalse($this->authorizer()->hasSomeViewAccess('expired-token', true));
    }

    public function test_anonymous_user_can_use_public_site_access(): void
    {
        $this->addUser('anonymous');
        $this->addSiteAccess('anonymous', 'view');

        $this->assertTrue($this->authorizer()->hasSomeViewAccess(null, false));
    }

    private function authorizer(bool $onlyAllowSecureTokens = false): DatabaseVersionAccessAuthorizer
    {
        return new DatabaseVersionAccessAuthorizer(
            connection: $this->connection,
            salt: self::SALT,
            onlyAllowSecureTokens: $onlyAllowSecureTokens,
        );
    }

    private function addUser(string $login, bool $superuser = false): void
    {
        $this->connection->table('user')->insert([
            'login' => $login,
            'superuser_access' => $superuser,
        ]);
    }

    private function addSiteAccess(string $login, string $access): void
    {
        $this->connection->table('site')->insertOrIgnore(['idsite' => 1]);
        $this->connection->table('access')->insert([
            'login' => $login,
            'idsite' => 1,
            'access' => $access,
        ]);
    }

    private function addToken(
        string $login,
        #[\SensitiveParameter]
        string $token,
        bool $secureOnly = false,
        ?string $expiredAt = null,
    ): int {
        return $this->connection->table('user_token_auth')->insertGetId([
            'login' => $login,
            'password' => hash('sha512', $token.self::SALT),
            'date_expired' => $expiredAt,
            'secure_only' => $secureOnly,
            'last_used' => null,
        ]);
    }
}
