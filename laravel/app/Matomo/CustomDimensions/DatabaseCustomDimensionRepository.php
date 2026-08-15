<?php

declare(strict_types=1);

namespace App\Matomo\CustomDimensions;

use Closure;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

final readonly class DatabaseCustomDimensionRepository implements CustomDimensionRepository
{
    /** @param Closure(): Connection $connection */
    public function __construct(private Closure $connection) {}

    public function configuredForSite(int $siteId): array
    {
        $rows = $this->connection()->table('custom_dimensions')
            ->where('idsite', $siteId)
            ->orderBy('idcustomdimension')
            ->get();
        $result = [];

        foreach ($rows as $row) {
            $extractions = json_decode(is_string($row->extractions ?? null) ? $row->extractions : '', true);

            $result[] = [
                'idcustomdimension' => (string) ($row->idcustomdimension ?? ''),
                'idsite' => (string) ($row->idsite ?? ''),
                'name' => is_string($row->name ?? null) ? $row->name : '',
                'description' => is_string($row->description ?? null) ? $row->description : '',
                'index' => (string) ($row->index ?? ''),
                'scope' => is_string($row->scope ?? null) ? $row->scope : '',
                'active' => (bool) ($row->active ?? false),
                'extractions' => is_array($extractions) ? array_values($extractions) : [],
                'case_sensitive' => (bool) ($row->case_sensitive ?? true),
            ];
        }

        return $result;
    }

    public function find(int $siteId, int $dimensionId): ?array
    {
        foreach ($this->configuredForSite($siteId) as $dimension) {
            if ((int) ($dimension['idcustomdimension'] ?? 0) === $dimensionId) {
                return $dimension;
            }
        }

        return null;
    }

    public function installedIndexes(string $scope): array
    {
        $table = match ($scope) {
            'visit' => 'log_visit',
            'action' => 'log_link_visit_action',
            'conversion' => 'log_conversion',
            default => throw new InvalidArgumentException("Unsupported custom-dimension scope '{$scope}'."),
        };
        $indexes = [];

        foreach ($this->connection()->getSchemaBuilder()->getColumnListing($table) as $column) {
            if (preg_match('/^custom_dimension_([1-9][0-9]*)$/D', $column, $matches) === 1) {
                $indexes[] = (int) $matches[1];
            }
        }

        sort($indexes, SORT_NUMERIC);

        return array_values(array_unique($indexes));
    }

    public function create(
        int $siteId,
        string $name,
        string $scope,
        bool $active,
        array $extractions,
        bool $caseSensitive,
        string $description,
    ): int {
        $installed = $this->installedIndexes($scope);

        return $this->connection()->transaction(function () use (
            $siteId,
            $name,
            $scope,
            $active,
            $extractions,
            $caseSensitive,
            $description,
            $installed,
        ): int {
            $rows = $this->connection()->table('custom_dimensions')
                ->where('idsite', $siteId)
                ->lockForUpdate()
                ->get(['idcustomdimension', 'scope', 'index']);
            $used = [];
            $maximumId = 0;

            foreach ($rows as $row) {
                $maximumId = max($maximumId, (int) ($row->idcustomdimension ?? 0));

                if (($row->scope ?? null) === $scope) {
                    $used[] = (int) ($row->index ?? 0);
                }
            }

            $available = array_values(array_diff($installed, $used));

            if ($available === []) {
                throw new RuntimeException(
                    "All Custom Dimensions for website {$siteId} in scope '{$scope}' are already used.",
                );
            }

            $dimensionId = $maximumId + 1;
            $this->connection()->table('custom_dimensions')->insert([
                'idcustomdimension' => $dimensionId,
                'idsite' => $siteId,
                'name' => $name,
                'description' => $description,
                'index' => $available[0],
                'scope' => $scope,
                'active' => $active ? 1 : 0,
                'extractions' => json_encode($extractions, JSON_THROW_ON_ERROR),
                'case_sensitive' => $caseSensitive ? 1 : 0,
            ]);

            return $dimensionId;
        }, 3);
    }

    public function update(
        int $siteId,
        int $dimensionId,
        string $name,
        bool $active,
        array $extractions,
        bool $caseSensitive,
        string $description,
    ): void {
        $updated = $this->connection()->table('custom_dimensions')
            ->where('idsite', $siteId)
            ->where('idcustomdimension', $dimensionId)
            ->update([
                'name' => $name,
                'description' => $description,
                'active' => $active ? 1 : 0,
                'extractions' => json_encode($extractions, JSON_THROW_ON_ERROR),
                'case_sensitive' => $caseSensitive ? 1 : 0,
            ]);

        if ($updated === 0 && $this->find($siteId, $dimensionId) === null) {
            throw new RuntimeException(
                "Custom dimension {$dimensionId} does not exist for website {$siteId}.",
            );
        }
    }

    private function connection(): Connection
    {
        return ($this->connection)();
    }
}
