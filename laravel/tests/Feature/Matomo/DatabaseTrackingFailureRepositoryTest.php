<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\TrackingFailures\DatabaseTrackingFailureRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseTrackingFailureRepositoryTest extends TestCase
{
    public function test_reads_and_deletes_tracking_failures_with_the_matomo_prefix(): void
    {
        config()->set('database.connections.matomo_tracking_failure_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_tracking_failure_test');

        $connection = $databases->connection('matomo_tracking_failure_test');
        $connection->getSchemaBuilder()->create('tracking_failure', static function (Blueprint $table): void {
            $table->integer('idsite');
            $table->integer('idfailure');
            $table->dateTime('date_first_occurred');
            $table->text('request_url');
            $table->primary(['idsite', 'idfailure']);
        });
        $connection->table('tracking_failure')->insert([
            $this->failure(7, 1),
            $this->failure(7, 2),
            $this->failure(8, 1),
            $this->failure(9, 2),
        ]);
        $repository = new DatabaseTrackingFailureRepository($connection);

        $this->assertCount(4, $repository->all());
        $this->assertSame([7, 7, 8], array_column($repository->forSites([7, 8]), 'idsite'));
        $this->assertSame([], $repository->forSites([]));

        $repository->delete(7, 1);
        $this->assertCount(3, $repository->all());
        $repository->deleteForSites([8]);
        $this->assertSame([7, 9], array_column($repository->all(), 'idsite'));
        $repository->deleteForSites([]);
        $this->assertCount(2, $repository->all());
        $repository->deleteAll();
        $this->assertSame([], $repository->all());
    }

    /** @return array{idsite: int, idfailure: int, date_first_occurred: string, request_url: string} */
    private function failure(int $siteId, int $failureId): array
    {
        return [
            'idsite' => $siteId,
            'idfailure' => $failureId,
            'date_first_occurred' => '2026-08-15 18:37:09',
            'request_url' => 'url=https%3A%2F%2Fexample.test',
        ];
    }
}
