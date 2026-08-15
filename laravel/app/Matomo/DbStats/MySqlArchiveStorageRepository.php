<?php

declare(strict_types=1);

namespace App\Matomo\DbStats;

use Closure;
use Illuminate\Database\Connection;
use RuntimeException;

final readonly class MySqlArchiveStorageRepository implements ArchiveStorageRepository
{
    /** @param Closure(): Connection $connection */
    public function __construct(private Closure $connection) {}

    public function rowsByName(string $table, bool $includeBlobSizes): array
    {
        $connection = $this->connection();
        $quotedTable = $connection->getQueryGrammar()->wrapTable($table);
        $extraColumns = $includeBlobSizes
            ? ', SUM(OCTET_LENGTH(value)) AS blob_size, SUM(LENGTH(name)) AS name_size'
            : '';
        $rows = $connection->select(
            "SELECT name AS label, COUNT(*) AS row_count{$extraColumns} FROM {$quotedTable} GROUP BY name",
        );
        $result = [];

        foreach ($rows as $row) {
            $label = $row->label ?? null;

            if (! is_string($label)) {
                continue;
            }

            $result[] = [
                'label' => $label,
                'row_count' => $this->integer($row->row_count ?? null),
                'blob_size' => $this->integer($row->blob_size ?? null),
                'name_size' => $this->integer($row->name_size ?? null),
            ];
        }

        return $result;
    }

    public function columnTypes(string $table): array
    {
        $connection = $this->connection();
        $quotedTable = $connection->getQueryGrammar()->wrapTable($table);
        $types = [];

        foreach ($connection->select("SHOW COLUMNS FROM {$quotedTable}") as $row) {
            $type = $row->Type ?? null;

            if (is_string($type)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    private function connection(): Connection
    {
        $connection = ($this->connection)();

        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('DBStats archive storage summaries require MySQL.');
        }

        return $connection;
    }

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
