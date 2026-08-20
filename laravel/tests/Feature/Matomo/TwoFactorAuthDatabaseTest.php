<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Api\Events\TwoFactorAuthenticationDisabled;
use App\Matomo\Authentication\DatabasePasswordConfirmationVerifier;
use App\Matomo\TwoFactorAuth\DatabaseTwoFactorAuthenticationResetter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;
use Tests\TestCase;

class TwoFactorAuthDatabaseTest extends TestCase
{
    public function test_verifies_the_legacy_password_hash(): void
    {
        $connection = $this->database();
        $connection->table('user')->insert([
            'login' => 'admin',
            'password' => password_hash(md5('correct'), PASSWORD_DEFAULT),
            'twofactor_secret' => '',
        ]);
        $verifier = new DatabasePasswordConfirmationVerifier($connection);

        $this->assertTrue($verifier->isCorrect('admin', 'correct'));
        $this->assertFalse($verifier->isCorrect('admin', 'wrong'));
        $this->assertFalse($verifier->isCorrect('missing', 'correct'));
    }

    public function test_clears_the_secret_and_recovery_codes_in_one_operation(): void
    {
        $connection = $this->database();
        $connection->table('user')->insert([
            'login' => 'alice',
            'password' => 'unused',
            'twofactor_secret' => 'secret',
        ]);
        $connection->table('twofactor_recovery_code')->insert([
            ['login' => 'alice', 'recovery_code' => 'one'],
            ['login' => 'alice', 'recovery_code' => 'two'],
        ]);
        $events = $this->createMock(Dispatcher::class);
        $events->expects($this->once())->method('dispatch')->with(
            $this->callback(
                static fn (object $event): bool => $event instanceof TwoFactorAuthenticationDisabled
                    && $event->login === 'alice',
            ),
        );
        $resetter = new DatabaseTwoFactorAuthenticationResetter($connection, $events);

        $resetter->reset('alice');

        $this->assertSame('', $connection->table('user')->where('login', 'alice')->value('twofactor_secret'));
        $this->assertSame(0, $connection->table('twofactor_recovery_code')->where('login', 'alice')->count());
    }

    public function test_anonymous_cannot_be_reset(): void
    {
        $connection = $this->database();
        $events = $this->createMock(Dispatcher::class);
        $events->expects($this->never())->method('dispatch');
        $resetter = new DatabaseTwoFactorAuthenticationResetter($connection, $events);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Anonymous cannot use two-factor authentication');

        $resetter->reset('anonymous');
    }

    private function database(): ConnectionInterface
    {
        $connection = $this->app->make(ConnectionInterface::class);
        $schema = $connection->getSchemaBuilder();
        $schema->create('user', static function (Blueprint $table): void {
            $table->string('login')->primary();
            $table->string('password');
            $table->string('twofactor_secret')->default('');
        });
        $schema->create('twofactor_recovery_code', static function (Blueprint $table): void {
            $table->increments('idrecoverycode');
            $table->string('login');
            $table->string('recovery_code');
        });

        return $connection;
    }
}
