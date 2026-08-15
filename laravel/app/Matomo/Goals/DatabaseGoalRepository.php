<?php

declare(strict_types=1);

namespace App\Matomo\Goals;

use App\Matomo\Api\GoalDefinition;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseGoalRepository implements GoalRepository
{
    private const int DELETE_BATCH_SIZE = 100_000;

    public function __construct(private ConnectionInterface $connection) {}

    public function findActive(int $siteId, int $goalId): ?array
    {
        $goal = $this->connection
            ->table('goal')
            ->where('idsite', $siteId)
            ->where('idgoal', $goalId)
            ->where('deleted', 0)
            ->first();

        return $goal === null ? null : $this->record($goal);
    }

    public function activeForSites(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        return array_values($this->connection
            ->table('goal')
            ->whereIn('idsite', $siteIds)
            ->where('deleted', 0)
            ->get()
            ->map(fn (object $goal): array => $this->record($goal))
            ->all());
    }

    public function create(int $siteId, GoalDefinition $goal): int
    {
        return $this->connection->transaction(function () use ($siteId, $goal): int {
            $maximum = $this->connection
                ->table('goal')
                ->where('idsite', $siteId)
                ->lockForUpdate()
                ->max('idgoal');
            $goalId = is_numeric($maximum) ? (int) $maximum + 1 : 1;

            $this->connection->table('goal')->insert([
                'idsite' => $siteId,
                'idgoal' => $goalId,
                ...$goal->storedValues(true),
            ]);

            return $goalId;
        });
    }

    public function update(int $siteId, int $goalId, GoalDefinition $goal): void
    {
        $this->connection
            ->table('goal')
            ->where('idsite', $siteId)
            ->where('idgoal', $goalId)
            ->update($goal->storedValues());
    }

    public function delete(int $siteId, int $goalId): void
    {
        $this->connection
            ->table('goal')
            ->where('idsite', $siteId)
            ->where('idgoal', $goalId)
            ->update(['deleted' => 1]);

        do {
            $visitIds = $this->connection
                ->table('log_conversion')
                ->where('idsite', $siteId)
                ->where('idgoal', $goalId)
                ->orderBy('idvisit')
                ->limit(self::DELETE_BATCH_SIZE)
                ->pluck('idvisit')
                ->all();

            if ($visitIds !== []) {
                $this->connection
                    ->table('log_conversion')
                    ->where('idsite', $siteId)
                    ->where('idgoal', $goalId)
                    ->whereIn('idvisit', $visitIds)
                    ->delete();
            }
        } while (count($visitIds) === self::DELETE_BATCH_SIZE);
    }

    /** @return array<string, float|int|string> */
    private function record(object $goal): array
    {
        $record = [];

        foreach (get_object_vars($goal) as $name => $value) {
            if (is_string($name) && (is_float($value) || is_int($value) || is_string($value))) {
                $record[$name] = in_array($name, ['name', 'description', 'pattern_type', 'pattern'], true)
                    ? htmlspecialchars_decode((string) $value, ENT_QUOTES | ENT_HTML401)
                    : $value;
            }
        }

        if (($record['match_attribute'] ?? null) === 'manually') {
            unset($record['pattern'], $record['pattern_type'], $record['case_sensitive']);
        }

        return $record;
    }
}
