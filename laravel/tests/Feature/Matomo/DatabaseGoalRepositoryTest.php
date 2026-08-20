<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Api\GoalDefinition;
use App\Matomo\Goals\DatabaseGoalRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseGoalRepositoryTest extends TestCase
{
    public function test_creates_reads_updates_and_deletes_site_scoped_goals(): void
    {
        $connection = $this->app->make(DatabaseManager::class)->connection();
        $this->createTables($connection);
        $connection->table('goal')->insert([
            'idsite' => 7,
            'idgoal' => 2,
            ...$this->definition('Existing')->storedValues(true),
        ]);
        $repository = new DatabaseGoalRepository($connection);

        $goalId = $repository->create(7, $this->definition('New &amp; useful'));

        $this->assertSame(3, $goalId);
        $this->assertSame('New & useful', $repository->findActive(7, 3)['name'] ?? null);
        $repository->update(7, 3, $this->definition('Manual', 'manually', '', ''));
        $manual = $repository->findActive(7, 3);
        $this->assertNotNull($manual);
        $this->assertSame('Manual', $manual['name'] ?? null);
        $this->assertArrayNotHasKey('pattern', $manual);
        $this->assertArrayNotHasKey('pattern_type', $manual);
        $this->assertArrayNotHasKey('case_sensitive', $manual);

        $connection->table('log_conversion')->insert([
            ['idvisit' => 10, 'idsite' => 7, 'idgoal' => 3],
            ['idvisit' => 11, 'idsite' => 7, 'idgoal' => 3],
            ['idvisit' => 12, 'idsite' => 8, 'idgoal' => 3],
            ['idvisit' => 13, 'idsite' => 7, 'idgoal' => 2],
        ]);

        $repository->delete(7, 3);

        $this->assertNull($repository->findActive(7, 3));
        $this->assertSame(2, $connection->table('log_conversion')->count());
        $this->assertSame([2], array_values(array_unique(array_map(
            intval(...),
            array_column($repository->activeForSites([7]), 'idgoal'),
        ))));
    }

    private function createTables(Connection $connection): void
    {
        $connection->getSchemaBuilder()->create('goal', static function (Blueprint $table): void {
            $table->integer('idsite');
            $table->integer('idgoal');
            $table->string('name');
            $table->string('description')->default('');
            $table->string('match_attribute');
            $table->string('pattern');
            $table->string('pattern_type');
            $table->boolean('case_sensitive');
            $table->boolean('allow_multiple');
            $table->double('revenue');
            $table->boolean('deleted')->default(false);
            $table->boolean('event_value_as_revenue')->default(false);
            $table->primary(['idsite', 'idgoal']);
        });
        $connection->getSchemaBuilder()->create('log_conversion', static function (Blueprint $table): void {
            $table->integer('idvisit');
            $table->integer('idsite');
            $table->integer('idgoal');
        });
    }

    private function definition(
        string $name,
        string $matchAttribute = 'url',
        string $pattern = 'https://example.test',
        string $patternType = 'exact',
    ): GoalDefinition {
        return new GoalDefinition(
            name: $name,
            matchAttribute: $matchAttribute,
            pattern: $pattern,
            patternType: $patternType,
            caseSensitive: false,
            revenue: 0,
            allowMultipleConversionsPerVisit: false,
            description: '',
            useEventValueAsRevenue: false,
        );
    }
}
