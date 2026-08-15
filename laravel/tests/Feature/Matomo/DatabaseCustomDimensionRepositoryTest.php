<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\CustomDimensions\DatabaseCustomDimensionRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use RuntimeException;
use Tests\TestCase;

class DatabaseCustomDimensionRepositoryTest extends TestCase
{
    public function test_creates_and_updates_dimensions_with_prefixed_tables_and_free_slots(): void
    {
        $connection = $this->connection();
        $repository = new DatabaseCustomDimensionRepository(fn (): Connection => $connection);
        $connection->table('custom_dimensions')->insert([
            'idcustomdimension' => 2,
            'idsite' => 7,
            'name' => 'Existing',
            'description' => '',
            'index' => 2,
            'scope' => 'action',
            'active' => 1,
            'extractions' => '[]',
            'case_sensitive' => 1,
        ]);

        $id = $repository->create(
            7,
            'Section',
            'action',
            true,
            [['dimension' => 'url', 'pattern' => '/section/(.+)']],
            false,
            'Page section',
        );

        self::assertSame(3, $id);
        self::assertSame(1, $connection->table('custom_dimensions')
            ->where('idcustomdimension', 3)
            ->value('index'));
        self::assertSame(0, $connection->table('custom_dimensions')
            ->where('idcustomdimension', 3)
            ->value('case_sensitive'));

        $repository->update(7, 3, 'Updated', false, [], true, 'Changed');
        self::assertSame('Updated', $connection->table('custom_dimensions')
            ->where('idcustomdimension', 3)
            ->value('name'));
        self::assertSame('[]', $connection->table('custom_dimensions')
            ->where('idcustomdimension', 3)
            ->value('extractions'));

        $this->expectException(RuntimeException::class);
        $repository->create(7, 'No slot', 'action', true, [], true, '');
    }

    private function connection(): Connection
    {
        config()->set('database.connections.custom_dimensions_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('custom_dimensions_test');

        $connection = $databases->connection('custom_dimensions_test');
        $schema = $connection->getSchemaBuilder();
        $schema->create('custom_dimensions', function (Blueprint $table): void {
            $table->unsignedBigInteger('idcustomdimension');
            $table->unsignedBigInteger('idsite');
            $table->string('name');
            $table->string('description', 1000)->default('');
            $table->unsignedSmallInteger('index');
            $table->string('scope', 10);
            $table->boolean('active')->default(false);
            $table->text('extractions')->default('');
            $table->boolean('case_sensitive')->default(true);
            $table->primary(['idcustomdimension', 'idsite']);
            $table->unique(['idsite', 'scope', 'index']);
        });
        $schema->create('log_visit', function (Blueprint $table): void {
            $table->id();
            $table->string('custom_dimension_1')->nullable();
        });
        $schema->create('log_link_visit_action', function (Blueprint $table): void {
            $table->id();
            $table->string('custom_dimension_1')->nullable();
            $table->string('custom_dimension_2')->nullable();
        });

        return $connection;
    }
}
