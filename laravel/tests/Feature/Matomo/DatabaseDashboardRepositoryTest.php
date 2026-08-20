<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Dashboard\DatabaseDashboardRecipientPolicy;
use App\Matomo\Dashboard\DatabaseDashboardRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseDashboardRepositoryTest extends TestCase
{
    public function test_reads_creates_updates_and_deletes_legacy_dashboard_rows(): void
    {
        $connection = $this->connection();
        $this->createDashboardTable($connection);
        $connection->table('user_dashboard')->insert([
            'login' => 'alice',
            'iddashboard' => 2,
            'name' => 'Existing',
            'layout' => 'old',
        ]);
        $repository = new DatabaseDashboardRepository($connection);

        $this->assertSame(3, $repository->create('alice', 'New', 'new-layout'));
        $this->assertSame(1, $repository->create('bob', 'First', 'bob-layout'));
        $this->assertSame('new-layout', $repository->layout('alice', 3));

        $repository->updateLayout('alice', 3, 'reset');
        $repository->updateLayout('alice', 8, 'inserted');
        $repository->delete('alice', 2);

        $this->assertSame([
            ['iddashboard' => 3, 'name' => 'New', 'layout' => 'reset'],
            ['iddashboard' => 8, 'name' => null, 'layout' => 'inserted'],
        ], $repository->all('alice'));
        $this->assertNull($repository->layout('alice', 2));
    }

    public function test_recipient_policy_matches_superuser_self_admin_and_invite_visibility(): void
    {
        $connection = $this->connection();
        $connection->getSchemaBuilder()->create('user', static function (Blueprint $table): void {
            $table->string('login')->primary();
            $table->string('invite_token')->nullable();
            $table->string('invited_by')->nullable();
        });
        $connection->getSchemaBuilder()->create('access', static function (Blueprint $table): void {
            $table->string('login');
            $table->unsignedInteger('idsite');
            $table->string('access');
        });
        $connection->table('user')->insert([
            ['login' => 'alice', 'invite_token' => null, 'invited_by' => null],
            ['login' => 'bob', 'invite_token' => null, 'invited_by' => null],
            ['login' => 'carol', 'invite_token' => 'pending', 'invited_by' => 'other'],
            ['login' => 'dave', 'invite_token' => 'pending', 'invited_by' => 'alice'],
        ]);
        $connection->table('access')->insert([
            ['login' => 'bob', 'idsite' => 4, 'access' => 'view'],
            ['login' => 'carol', 'idsite' => 4, 'access' => 'admin'],
            ['login' => 'dave', 'idsite' => 4, 'access' => 'write'],
        ]);
        $authentication = new ApiAuthentication('token', true, false, null);
        $admin = $this->createStub(ApiAccessAuthorizer::class);
        $admin->method('authenticatedLogin')->willReturn('alice');
        $admin->method('siteIdsWithRole')->with($authentication, SiteAccessRole::Admin)->willReturn([4]);
        $policy = new DatabaseDashboardRecipientPolicy($connection, $admin);

        $this->assertTrue($policy->canCopyTo($authentication, 'alice'));
        $this->assertTrue($policy->canCopyTo($authentication, 'bob'));
        $this->assertFalse($policy->canCopyTo($authentication, 'carol'));
        $this->assertTrue($policy->canCopyTo($authentication, 'dave'));
        $this->assertFalse($policy->canCopyTo($authentication, 'missing'));

        $superuser = $this->createStub(ApiAccessAuthorizer::class);
        $superuser->method('hasSuperUserAccess')->willReturn(true);
        $superPolicy = new DatabaseDashboardRecipientPolicy($connection, $superuser);
        $this->assertTrue($superPolicy->canCopyTo($authentication, 'carol'));
    }

    private function connection(): Connection
    {
        return $this->app->make(DatabaseManager::class)->connection();
    }

    private function createDashboardTable(Connection $connection): void
    {
        $connection->getSchemaBuilder()->create('user_dashboard', static function (Blueprint $table): void {
            $table->string('login');
            $table->unsignedInteger('iddashboard');
            $table->string('name')->nullable();
            $table->text('layout');
            $table->primary(['login', 'iddashboard']);
        });
    }
}
