<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Users\DatabaseUserIdentityRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabaseUserIdentityRepositoryTest extends TestCase
{
    public function test_identity_lookups_use_canonical_logins_and_exact_emails(): void
    {
        $connection = $this->app->make(ConnectionInterface::class);
        $connection->getSchemaBuilder()->create('user', static function (Blueprint $table): void {
            $table->string('login');
            $table->string('email');
        });
        $connection->table('user')->insert(['login' => 'Alice', 'email' => 'alice@example.test']);
        $users = new DatabaseUserIdentityRepository($connection);

        $this->assertTrue($users->loginExists('alice'));
        $this->assertFalse($users->loginExists('bob'));
        $this->assertTrue($users->emailExists('alice@example.test'));
        $this->assertFalse($users->emailExists('ALICE@example.test'));
        $this->assertSame('Alice', $users->loginForEmail('alice@example.test'));
        $this->assertNull($users->loginForEmail('missing@example.test'));
    }
}
