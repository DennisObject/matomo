<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\ScheduledReports\DatabaseScheduledReportRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabaseScheduledReportRepositoryTest extends TestCase
{
    public function test_persists_decodes_filters_and_soft_deletes_reports(): void
    {
        config()->set('database.connections.scheduled_report_test', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('scheduled_report_test');

        $connection = $databases->connection('scheduled_report_test');
        $connection->getSchemaBuilder()->create('site', static function (Blueprint $table): void {
            $table->integer('idsite')->primary();
        });
        $connection->getSchemaBuilder()->create('report', static function (Blueprint $table): void {
            $table->increments('idreport');
            $table->integer('idsite');
            $table->string('login');
            $table->string('description');
            $table->integer('idsegment')->nullable();
            $table->string('period');
            $table->string('period_param')->nullable();
            $table->integer('hour');
            $table->string('type');
            $table->string('format');
            $table->text('reports');
            $table->text('parameters')->nullable();
            $table->integer('deleted')->default(0);
            $table->integer('evolution_graph_within_period')->default(0);
            $table->integer('evolution_graph_period_n')->default(30);
        });
        $connection->table('site')->insert(['idsite' => 7]);
        $repository = new DatabaseScheduledReportRepository($connection);

        $id = $repository->create([
            'idsite' => 7, 'login' => 'alice', 'description' => 'Overview', 'idsegment' => null,
            'period' => 'week', 'period_param' => null, 'hour' => 8, 'type' => 'email', 'format' => 'html',
            'reports' => ['VisitsSummary_get'], 'parameters' => ['emailMe' => true], 'deleted' => 0,
            'evolution_graph_within_period' => 0, 'evolution_graph_period_n' => 30,
        ]);

        $this->assertSame(1, $id);
        $report = $repository->find(7, 'week', 1, 'alice', null)[0];
        $this->assertSame(['VisitsSummary_get'], $report['reports']);
        $this->assertSame(['emailMe' => true], $report['parameters']);
        $this->assertSame('week', $report['period_param']);

        $repository->update(1, ['deleted' => 1]);
        $this->assertSame([], $repository->find(null, null, null, null, null));
    }
}
