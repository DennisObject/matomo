<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Authentication\DatabaseApiAccessAuthorizer;
use App\Matomo\Authentication\Events\UserSiteAccessLoaded;
use App\Matomo\Authentication\SiteAccessRole;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseApiAccessAuthorizerTest extends TestCase
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

        $this->assertSame('viewer', $this->authorizer()->authenticatedLogin(
            $this->authentication('view-token'),
        ));
        $this->assertTrue($this->authorizer()->hasSomeViewAccess($this->authentication('view-token')));
        $this->assertFalse($this->authorizer()->hasSuperUserAccess($this->authentication('view-token')));
        $this->assertNotNull(
            $this->connection->table('user_token_auth')->where('idusertokenauth', $tokenId)->value('last_used'),
        );
    }

    public function test_superuser_does_not_need_site_access(): void
    {
        $this->addUser('root', true);
        $this->addToken('root', 'root-token');

        $this->assertTrue($this->authorizer()->hasSomeViewAccess($this->authentication('root-token', true)));
        $this->assertTrue($this->authorizer()->hasSuperUserAccess($this->authentication('root-token', true)));
    }

    public function test_some_admin_access_requires_an_admin_role_or_superuser(): void
    {
        $this->addUser('viewer');
        $this->addSiteAccess('viewer', 'view');
        $this->addToken('viewer', 'view-token');
        $this->addUser('admin');
        $this->addSiteAccess('admin', 'admin');
        $this->addToken('admin', 'admin-token');
        $this->addUser('root', true);
        $this->addToken('root', 'root-token');
        $authorizer = $this->authorizer();

        $this->assertFalse($authorizer->hasSomeAdminAccess($this->authentication('view-token', true)));
        $this->assertTrue($authorizer->hasSomeAdminAccess($this->authentication('admin-token', true)));
        $this->assertTrue($authorizer->hasSomeAdminAccess($this->authentication('root-token', true)));
    }

    public function test_secure_only_token_is_rejected_from_query_and_accepted_from_post(): void
    {
        $this->addUser('viewer');
        $this->addSiteAccess('viewer', 'view');
        $this->addToken('viewer', 'secure-token', secureOnly: true);

        $this->assertFalse($this->authorizer()->hasSomeViewAccess($this->authentication('secure-token')));
        $this->assertTrue($this->authorizer()->hasSomeViewAccess($this->authentication('secure-token', true)));
    }

    public function test_global_secure_token_rule_rejects_query_token(): void
    {
        $this->addUser('viewer');
        $this->addSiteAccess('viewer', 'view');
        $this->addToken('viewer', 'token');

        $this->assertFalse(
            $this->authorizer(onlyAllowSecureTokens: true)->hasSomeViewAccess($this->authentication('token')),
        );
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->addUser('viewer');
        $this->addSiteAccess('viewer', 'view');
        $this->addToken('viewer', 'expired-token', expiredAt: '2000-01-01 00:00:00');

        $this->assertFalse(
            $this->authorizer()->hasSomeViewAccess($this->authentication('expired-token', true)),
        );
        $this->assertNull(
            $this->authorizer()->authenticatedLogin($this->authentication('expired-token', true)),
        );
    }

    public function test_anonymous_user_can_use_public_site_access(): void
    {
        $this->addUser('anonymous');
        $this->addSiteAccess('anonymous', 'view');

        $this->assertTrue($this->authorizer()->hasSomeViewAccess($this->authentication(null)));
    }

    public function test_returns_exact_role_sites_and_only_admin_sites_for_superusers(): void
    {
        $this->addUser('admin');
        $this->addSiteAccess('admin', 'admin', 1);
        $this->addSiteAccess('admin', 'view', 2);
        $this->addSiteAccess('admin', 'write', 3);
        $this->addToken('admin', 'admin-token');
        $this->addUser('root', true);
        $this->addToken('root', 'root-token');

        $this->assertSame(
            [1],
            $this->authorizer()->siteIdsWithRole(
                $this->authentication('admin-token', true),
                SiteAccessRole::Admin,
            ),
        );
        $this->assertSame(
            [2],
            $this->authorizer()->siteIdsWithRole(
                $this->authentication('admin-token', true),
                SiteAccessRole::View,
            ),
        );
        $this->assertSame(
            [3],
            $this->authorizer()->siteIdsWithRole(
                $this->authentication('admin-token', true),
                SiteAccessRole::Write,
            ),
        );
        $this->assertSame(
            [1, 2, 3],
            $this->authorizer()->siteIdsWithRole(
                $this->authentication('root-token', true),
                SiteAccessRole::Admin,
            ),
        );
        $this->assertSame(
            [],
            $this->authorizer()->siteIdsWithRole(
                $this->authentication('root-token', true),
                SiteAccessRole::View,
            ),
        );
        $this->assertTrue($this->authorizer()->hasViewAccessToSite(
            $this->authentication('admin-token', true),
            1,
        ));
        $this->assertTrue($this->authorizer()->hasViewAccessToSite(
            $this->authentication('admin-token', true),
            2,
        ));
        $this->assertTrue($this->authorizer()->hasViewAccessToSite(
            $this->authentication('admin-token', true),
            3,
        ));
        $this->assertFalse($this->authorizer()->hasViewAccessToSite(
            $this->authentication('admin-token', true),
            4,
        ));
    }

    public function test_access_event_can_apply_migrated_plugin_permissions(): void
    {
        $this->addUser('viewer');
        $this->addSiteAccess('viewer', 'view', 1);
        $this->connection->table('site')->insert(['idsite' => 2]);
        $this->addToken('viewer', 'view-token');
        $events = $this->app->make(Dispatcher::class);
        $events->listen(
            UserSiteAccessLoaded::class,
            static function (UserSiteAccessLoaded $event): void {
                $event->siteIdsByAccess['admin'][] = 2;
            },
        );

        $authorizer = $this->authorizer(events: $events);

        $this->assertTrue($authorizer->hasSomeViewAccess($this->authentication('view-token', true)));
        $this->assertSame(
            [2],
            $authorizer->siteIdsWithRole(
                $this->authentication('view-token', true),
                SiteAccessRole::Admin,
            ),
        );
    }

    public function test_at_least_view_sites_enforce_the_restricted_login_boundary(): void
    {
        $this->addUser('alice');
        $this->addSiteAccess('alice', 'view', 1);
        $this->addSiteAccess('alice', 'write', 2);
        $this->addSiteAccess('alice', 'admin', 3);
        $this->addToken('alice', 'alice-token');
        $this->addUser('bob');
        $this->addSiteAccess('bob', 'admin', 4);
        $this->addToken('bob', 'bob-token');
        $this->addUser('root', true);
        $this->addToken('root', 'root-token');
        $authorizer = $this->authorizer();
        $alice = $this->authentication('alice-token', true);
        $root = $this->authentication('root-token', true);

        $this->assertSame([1, 2, 3], $authorizer->siteIdsWithAtLeastViewAccess($alice));
        $this->assertSame([1, 2, 3], $authorizer->siteIdsWithAtLeastViewAccess($alice, 'bob'));
        $this->assertSame([1, 2, 3, 4], $authorizer->siteIdsWithAtLeastViewAccess($root));
        $this->assertSame([4], $authorizer->siteIdsWithAtLeastViewAccess($root, 'bob'));
        $this->assertSame([], $authorizer->siteIdsWithAtLeastViewAccess($root, 'missing'));
        $this->assertSame([1, 2, 3, 4], $authorizer->siteIdsWithAtLeastViewAccess($root, 'root'));
    }

    private function authorizer(
        bool $onlyAllowSecureTokens = false,
        ?Dispatcher $events = null,
    ): DatabaseApiAccessAuthorizer {
        return new DatabaseApiAccessAuthorizer(
            connection: $this->connection,
            salt: self::SALT,
            onlyAllowSecureTokens: $onlyAllowSecureTokens,
            events: $events,
        );
    }

    private function authentication(?string $token, bool $secure = false): ApiAuthentication
    {
        return new ApiAuthentication($token, $secure, false, null);
    }

    private function addUser(string $login, bool $superuser = false): void
    {
        $this->connection->table('user')->insert([
            'login' => $login,
            'superuser_access' => $superuser,
        ]);
    }

    private function addSiteAccess(string $login, string $access, int $idSite = 1): void
    {
        $this->connection->table('site')->insertOrIgnore(['idsite' => $idSite]);
        $this->connection->table('access')->insert([
            'login' => $login,
            'idsite' => $idSite,
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
