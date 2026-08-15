<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Archiving\ArchiveInvalidationManager;
use App\Matomo\Privacy\DatabaseDataSubjectRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Tests\TestCase;

final class DatabaseDataSubjectRepositoryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->app->make('db')->connection();
        $schema = $this->connection->getSchemaBuilder();
        foreach (['log_conversion_item', 'log_conversion', 'log_link_visit_action', 'log_visit', 'log_action'] as $table) {
            $schema->dropIfExists($table);
        }

        $schema->create('log_visit', static function (Blueprint $table): void {
            $table->integer('idvisit')->primary();
            $table->integer('idsite');
            $table->dateTime('visit_last_action_time');
            $table->binary('idvisitor')->nullable();
        });
        $schema->create('log_link_visit_action', static function (Blueprint $table): void {
            $table->integer('idlink_va')->primary();
            $table->integer('idvisit');
            $table->integer('idsite');
            $table->integer('idaction_url')->nullable();
        });
        foreach (['log_conversion', 'log_conversion_item'] as $name) {
            $schema->create($name, static function (Blueprint $table): void {
                $table->increments('row_id');
                $table->integer('idvisit');
                $table->integer('idsite');
            });
        }

        $schema->create('log_action', static function (Blueprint $table): void {
            $table->integer('idaction')->primary();
            $table->string('name');
        });

        $this->connection->table('log_visit')->insert([
            ['idvisit' => 11, 'idsite' => 2, 'visit_last_action_time' => '2026-08-03 12:00:00', 'idvisitor' => "\xFF\x01"],
            ['idvisit' => 12, 'idsite' => 2, 'visit_last_action_time' => '2026-08-04 12:00:00', 'idvisitor' => 'safe'],
        ]);
        $this->connection->table('log_link_visit_action')->insert([
            ['idlink_va' => 21, 'idvisit' => 11, 'idsite' => 2, 'idaction_url' => 31],
            ['idlink_va' => 22, 'idvisit' => 12, 'idsite' => 2, 'idaction_url' => 32],
        ]);
        $this->connection->table('log_conversion')->insert(['idvisit' => 11, 'idsite' => 2]);
        $this->connection->table('log_conversion_item')->insert(['idvisit' => 11, 'idsite' => 2]);
        $this->connection->table('log_action')->insert([
            ['idaction' => 31, 'name' => 'requested'],
            ['idaction' => 32, 'name' => 'other'],
        ]);
    }

    public function test_export_includes_related_rows_and_action_names_only(): void
    {
        $repository = $this->repository($this->createStub(ArchiveInvalidationManager::class));

        $data = $repository->export([['idsite' => 2, 'idvisit' => 11]]);

        $this->assertSame('ff01', $data['log_visit'][0]['idvisitor']);
        $this->assertSame([31], array_column($data['log_action'], 'idaction'));
        $this->assertCount(1, $data['log_conversion']);
        $this->assertCount(1, $data['log_conversion_item']);
        $this->assertCount(1, $data['log_link_visit_action']);
    }

    public function test_delete_removes_only_requested_visit_and_invalidates_its_archive(): void
    {
        $invalidations = $this->createMock(ArchiveInvalidationManager::class);
        $invalidations->expects($this->once())->method('invalidate')
            ->with([2], ['2026-08-03'], null, null, false, true)->willReturn([]);
        $repository = $this->repository($invalidations);

        $deleted = $repository->delete([['idsite' => 2, 'idvisit' => 11]]);

        $this->assertSame(1, $deleted['log_visit']);
        $this->assertSame([12], $this->connection->table('log_visit')->pluck('idvisit')->all());
        $this->assertSame([22], $this->connection->table('log_link_visit_action')->pluck('idlink_va')->all());
        $this->assertSame(0, $this->connection->table('log_conversion')->count());
        $this->assertSame(0, $this->connection->table('log_conversion_item')->count());
        $this->assertSame(2, $this->connection->table('log_action')->count());
    }

    private function repository(ArchiveInvalidationManager $invalidations): DatabaseDataSubjectRepository
    {
        return new DatabaseDataSubjectRepository(
            $this->connection,
            new Dispatcher($this->app),
            $invalidations,
        );
    }
}
