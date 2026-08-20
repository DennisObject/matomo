<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\UserChanges\DatabaseUserChangeReadRepository;
use App\Matomo\UserChanges\Events\ChangesFiltering;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DatabaseUserChangeReadRepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_marks_the_highest_recent_visible_change_as_read(): void
    {
        CarbonImmutable::setTestNow('2026-08-15 18:37:09');
        $connection = $this->connection();
        $connection->getSchemaBuilder()->create('user', static function (Blueprint $table): void {
            $table->string('login')->primary();
            $table->unsignedInteger('idchange_last_viewed')->nullable();
        });
        $connection->getSchemaBuilder()->create('changes', static function (Blueprint $table): void {
            $table->unsignedInteger('idchange')->primary();
            $table->dateTime('created_time');
            $table->string('plugin_name');
            $table->string('title')->nullable();
        });
        $connection->table('user')->insert([
            ['login' => 'alice', 'idchange_last_viewed' => null],
            ['login' => 'bob', 'idchange_last_viewed' => null],
        ]);
        $connection->table('changes')->insert([
            $this->change(1, '2026-08-01 00:00:00', 'First'),
            $this->change(2, '2026-08-02 00:00:00', 'Second'),
            $this->change(3, '2026-08-03 00:00:00', 'Filtered'),
            $this->change(99, '2025-01-01 00:00:00', 'Old'),
            $this->change(100, '2026-08-04 00:00:00', null),
        ]);
        Event::listen(ChangesFiltering::class, static function (ChangesFiltering $event): void {
            $event->changes = array_values(array_filter(
                $event->changes,
                static fn (array $change): bool => ($change['idchange'] ?? null) !== 3,
            ));
        });
        $repository = new DatabaseUserChangeReadRepository(
            $connection,
            $this->app->make(Dispatcher::class),
        );

        $this->assertTrue($repository->markAllRead('alice'));
        $this->assertSame(
            2,
            (int) $connection->table('user')->where('login', 'alice')->value('idchange_last_viewed'),
        );
        $this->assertNull(
            $connection->table('user')->where('login', 'bob')->value('idchange_last_viewed'),
        );
        $this->assertFalse($repository->markAllRead('missing'));

        $connection->getSchemaBuilder()->drop('changes');
        $this->assertTrue($repository->markAllRead('bob'));
        $this->assertNull(
            $connection->table('user')->where('login', 'bob')->value('idchange_last_viewed'),
        );
    }

    private function connection(): Connection
    {
        config()->set('database.connections.matomo_user_changes_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_user_changes_test');

        return $databases->connection('matomo_user_changes_test');
    }

    /** @return array{idchange: int, created_time: string, plugin_name: string, title: string|null} */
    private function change(int $id, string $createdTime, ?string $title): array
    {
        return [
            'idchange' => $id,
            'created_time' => $createdTime,
            'plugin_name' => 'CoreHome',
            'title' => $title,
        ];
    }
}
