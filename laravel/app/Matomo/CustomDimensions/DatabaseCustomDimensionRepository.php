<?php

declare(strict_types=1);

namespace App\Matomo\CustomDimensions;

use Closure;
use Illuminate\Database\Connection;
use InvalidArgumentException;

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

    public function installedIndexes(string $scope): array
    {
        $table = match ($scope) {
            'visit' => 'log_visit',
            'action' => 'log_link_visit_action',
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

    private function connection(): Connection
    {
        return ($this->connection)();
    }
}
