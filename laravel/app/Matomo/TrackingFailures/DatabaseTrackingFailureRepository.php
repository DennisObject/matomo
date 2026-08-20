<?php

declare(strict_types=1);

namespace App\Matomo\TrackingFailures;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseTrackingFailureRepository implements TrackingFailureRepository
{
    public function __construct(private ConnectionInterface $connection) {}

    public function all(): array
    {
        return $this->rows(array_values(
            $this->connection->table('tracking_failure')->get()->all(),
        ));
    }

    public function forSites(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        return $this->rows(array_values(
            $this->connection
                ->table('tracking_failure')
                ->whereIn('idsite', $siteIds)
                ->get()
                ->all(),
        ));
    }

    public function deleteAll(): void
    {
        $this->connection->table('tracking_failure')->delete();
    }

    public function deleteForSites(array $siteIds): void
    {
        if ($siteIds !== []) {
            $this->connection->table('tracking_failure')->whereIn('idsite', $siteIds)->delete();
        }
    }

    public function delete(int $siteId, int|string $failureId): void
    {
        $this->connection
            ->table('tracking_failure')
            ->where('idsite', $siteId)
            ->where('idfailure', $failureId)
            ->delete();
    }

    /**
     * @param  list<object>  $records
     * @return list<array<string, int|string>>
     */
    private function rows(array $records): array
    {
        $rows = [];

        foreach ($records as $record) {
            $row = [];

            foreach (get_object_vars($record) as $name => $value) {
                if (in_array($name, ['idsite', 'idfailure'], true)
                    && (is_int($value) || is_string($value))) {
                    $row[$name] = (int) $value;
                } elseif (is_int($value) || is_string($value)) {
                    $row[$name] = $value;
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
