<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Login\DatabaseBruteForceSettings;
use App\Matomo\Login\DatabaseBruteForceUnblocker;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseBruteForceUnblockerTest extends TestCase
{
    public function test_removes_recent_attempts_for_blocked_non_allowlisted_ips_only(): void
    {
        CarbonImmutable::setTestNow('2026-08-14 12:00:00');
        config()->set('database.connections.brute_force_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('brute_force_test');

        $connection = $databases->connection('brute_force_test');
        $connection->getSchemaBuilder()->create('plugin_setting', function (Blueprint $table): void {
            $table->string('plugin_name');
            $table->string('user_login');
            $table->string('setting_name');
            $table->text('setting_value');
            $table->boolean('json_encoded')->default(false);
        });
        $connection->getSchemaBuilder()->create('brute_force_log', function (Blueprint $table): void {
            $table->id('id_brute_force_log');
            $table->string('ip_address');
            $table->dateTime('attempted_at');
            $table->string('login')->nullable();
        });

        foreach (['10.0.0.1', '10.0.0.2'] as $ip) {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $connection->table('brute_force_log')->insert([
                    'ip_address' => $ip,
                    'attempted_at' => '2026-08-14 11:55:00',
                ]);
            }
        }

        $connection->table('brute_force_log')->insert([
            ['ip_address' => '10.0.0.1', 'attempted_at' => '2026-08-14 09:00:00'],
            ['ip_address' => '10.0.0.3', 'attempted_at' => '2026-08-14 11:55:00'],
        ]);
        $unblocker = new DatabaseBruteForceUnblocker(
            $connection,
            new DatabaseBruteForceSettings(
                $connection,
                configuredMaxAttempts: 2,
                configuredTimeRange: 60,
                configuredAllowlist: ['10.0.0.2'],
            ),
        );

        try {
            $this->assertSame(3, $unblocker->unblockCurrentlyBlocked());
            $this->assertSame(
                ['10.0.0.2', '10.0.0.2', '10.0.0.2', '10.0.0.1', '10.0.0.3'],
                $connection->table('brute_force_log')->orderBy('id_brute_force_log')->pluck('ip_address')->all(),
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
