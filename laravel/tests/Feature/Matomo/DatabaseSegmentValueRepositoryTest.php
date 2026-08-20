<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Segments\DatabaseSegmentValueRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabaseSegmentValueRepositoryTest extends TestCase
{
    public function test_returns_ranked_recent_values_for_the_requested_site(): void
    {
        CarbonImmutable::setTestNow('2026-08-20 12:00:00 UTC');

        try {
            $connection = $this->connection();
            $connection->table('log_visit')->insert([
                ['idsite' => 7, 'visit_last_action_time' => '2026-08-20 11:00:00', 'config_browser_name' => 'FF', 'visit_goal_converted' => 1],
                ['idsite' => 7, 'visit_last_action_time' => '2026-08-19 11:00:00', 'config_browser_name' => 'FF', 'visit_goal_converted' => 1],
                ['idsite' => 7, 'visit_last_action_time' => '2026-08-18 11:00:00', 'config_browser_name' => 'CH', 'visit_goal_converted' => 0],
                ['idsite' => 7, 'visit_last_action_time' => '2026-08-17 11:00:00', 'config_browser_name' => 'CH', 'visit_goal_converted' => 1],
                ['idsite' => 7, 'visit_last_action_time' => '2026-08-16 11:00:00', 'config_browser_name' => 'SF', 'visit_goal_converted' => 0],
                ['idsite' => 7, 'visit_last_action_time' => '2026-08-15 11:00:00', 'config_browser_name' => '', 'visit_goal_converted' => null],
                ['idsite' => 7, 'visit_last_action_time' => '2026-05-01 11:00:00', 'config_browser_name' => 'OP', 'visit_goal_converted' => 1],
                ['idsite' => 8, 'visit_last_action_time' => '2026-08-20 11:00:00', 'config_browser_name' => 'IE', 'visit_goal_converted' => 1],
            ]);
            $repository = new DatabaseSegmentValueRepository($connection);

            $this->assertTrue($repository->supports('browserCode'));
            $this->assertFalse($repository->supports('pageTitle'));
            $this->assertSame(['CH', 'FF'], $repository->mostFrequent(7, 'browserCode', 2));
            $this->assertSame(['1', '0'], $repository->mostFrequent(7, 'visitConverted', 30));
            $this->assertSame([], $repository->mostFrequent(7, 'pageTitle', 30));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function connection(): Connection
    {
        $connection = $this->app->make('db')->connection();
        $schema = $connection->getSchemaBuilder();
        $schema->dropIfExists('log_visit');
        $schema->create('log_visit', static function (Blueprint $table): void {
            $table->integer('idsite');
            $table->dateTime('visit_last_action_time');
            $table->string('config_browser_name')->nullable();
            $table->integer('visit_goal_converted')->nullable();
        });

        return $connection;
    }
}
