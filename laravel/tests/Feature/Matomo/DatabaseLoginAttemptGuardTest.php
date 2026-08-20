<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Login\BruteForceSettings;
use App\Matomo\Login\DatabaseLoginAttemptGuard;
use App\Matomo\Login\LoginAttemptStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseLoginAttemptGuardTest extends TestCase
{
    public function test_enforces_ip_and_user_limits_and_records_failures(): void
    {
        CarbonImmutable::setTestNow('2026-08-14 12:00:00');
        $connection = $this->database();
        $settings = $this->settings(maxAttempts: 2);
        $guard = new DatabaseLoginAttemptGuard($connection, $settings);

        try {
            $this->assertSame(LoginAttemptStatus::Allowed, $guard->status('10.0.0.1', 'alice'));
            $guard->recordFailure('10.0.0.1', 'alice');
            $guard->recordFailure('10.0.0.1', 'alice');
            $guard->recordFailure('10.0.0.1', 'alice');
            $this->assertSame(LoginAttemptStatus::IpBlocked, $guard->status('10.0.0.1', 'alice'));

            for ($attempt = 0; $attempt < 11; $attempt++) {
                $guard->recordFailure("10.0.1.{$attempt}", 'bob');
            }

            $this->assertSame(LoginAttemptStatus::UserBlocked, $guard->status('10.0.2.1', 'bob'));
            $this->assertSame(14, $connection->table('brute_force_log')->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_blocklist_wins_over_allowlist_and_disabled_protection_does_not_write(): void
    {
        $connection = $this->database();
        $settings = $this->settings(
            maxAttempts: 2,
            enabled: true,
            allowlist: ['10.0.0.0/8'],
            blocklist: ['10.0.0.1'],
        );
        $guard = new DatabaseLoginAttemptGuard($connection, $settings);

        $this->assertSame(LoginAttemptStatus::IpBlocked, $guard->status('10.0.0.1', 'alice'));
        $this->assertSame(LoginAttemptStatus::Allowed, $guard->status('10.0.0.2', 'alice'));

        $disabled = new DatabaseLoginAttemptGuard(
            $connection,
            $this->settings(maxAttempts: 2, enabled: false),
        );
        $disabled->recordFailure('10.0.0.3', 'alice');
        $this->assertSame(0, $connection->table('brute_force_log')->count());
    }

    private function database(): ConnectionInterface
    {
        $connection = $this->app->make(ConnectionInterface::class);
        $connection->getSchemaBuilder()->create('brute_force_log', static function (Blueprint $table): void {
            $table->increments('id_brute_force_log');
            $table->string('ip_address')->nullable();
            $table->dateTime('attempted_at');
            $table->string('login')->nullable();
        });

        return $connection;
    }

    /**
     * @param  list<string>  $allowlist
     * @param  list<string>  $blocklist
     */
    private function settings(
        int $maxAttempts,
        bool $enabled = true,
        array $allowlist = [],
        array $blocklist = [],
    ): BruteForceSettings {
        return new readonly class($maxAttempts, $enabled, $allowlist, $blocklist) implements BruteForceSettings
        {
            /**
             * @param  list<string>  $allowlist
             * @param  list<string>  $blocklist
             */
            public function __construct(
                private int $maxAttempts,
                private bool $enabled,
                private array $allowlist,
                private array $blocklist,
            ) {}

            public function enabled(): bool
            {
                return $this->enabled;
            }

            public function maxAttempts(): int
            {
                return $this->maxAttempts;
            }

            public function timeRangeMinutes(): int
            {
                return 60;
            }

            public function allowlist(): array
            {
                return $this->allowlist;
            }

            public function blocklist(): array
            {
                return $this->blocklist;
            }
        };
    }
}
