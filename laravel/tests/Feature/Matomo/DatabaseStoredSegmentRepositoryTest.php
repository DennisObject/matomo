<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Segments\DatabaseStoredSegmentRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseStoredSegmentRepositoryTest extends TestCase
{
    public function test_reads_legacy_rows_and_applies_visibility_filters(): void
    {
        $connection = $this->app->make(DatabaseManager::class)->connection();
        $this->createTable($connection);
        $connection->table('segment')->insert([
            $this->row(1, 'Mine', 'alice', 0, 1),
            $this->row(2, 'Shared', 'root', 1, 1),
            $this->row(3, 'Private', 'bob', 0, 1),
            $this->row(4, 'Other site', 'alice', 0, 2),
            $this->row(5, 'Deleted', 'alice', 0, 1, 1),
            $this->row(6, 'All sites', 'root', 1, 0),
        ]);
        $repository = new DatabaseStoredSegmentRepository(static fn (): Connection => $connection);

        $this->assertSame('Mine', $repository->find(1)['name'] ?? null);
        $this->assertNull($repository->find(99));
        $this->assertSame(
            [6, 1, 2],
            array_column($repository->visible('alice', false, 1), 'idsegment'),
        );
        $this->assertSame(
            [6, 1, 3, 2],
            array_column($repository->visible('alice', true, 1), 'idsegment'),
        );
    }

    private function createTable(Connection $connection): void
    {
        $connection->getSchemaBuilder()->create('segment', static function (Blueprint $table): void {
            $table->increments('idsegment');
            $table->string('name');
            $table->text('definition');
            $table->string('hash');
            $table->string('login');
            $table->unsignedTinyInteger('enable_all_users');
            $table->unsignedInteger('enable_only_idsite')->nullable();
            $table->unsignedTinyInteger('auto_archive');
            $table->dateTime('ts_created')->nullable();
            $table->dateTime('ts_last_edit')->nullable();
            $table->unsignedTinyInteger('deleted');
            $table->unsignedTinyInteger('starred');
            $table->text('starred_by')->nullable();
        });
    }

    /** @return array<string, int|string|null> */
    private function row(
        int $id,
        string $name,
        string $login,
        int $shared,
        int $siteId,
        int $deleted = 0,
    ): array {
        return [
            'idsegment' => $id,
            'name' => $name,
            'definition' => 'browserCode==FF',
            'hash' => 'hash-'.$id,
            'login' => $login,
            'enable_all_users' => $shared,
            'enable_only_idsite' => $siteId,
            'auto_archive' => 0,
            'ts_created' => null,
            'ts_last_edit' => null,
            'deleted' => $deleted,
            'starred' => 0,
            'starred_by' => null,
        ];
    }
}
