<?php

declare(strict_types=1);

namespace App\Matomo\DbStats;

use Closure;
use Illuminate\Database\Connection;

final readonly class MySqlDatabaseMetadataProvider implements DatabaseMetadataProvider
{
    /** @param Closure(): Connection $connection */
    public function __construct(private Closure $connection) {}

    public function tablePrefix(): string
    {
        return $this->connection()->getTablePrefix();
    }

    public function tableStatuses(): array
    {
        $prefix = $this->tablePrefix();
        $statuses = [];

        foreach ($this->connection()->select('SHOW TABLE STATUS') as $row) {
            $name = $row->Name ?? null;

            if (! is_string($name) || ! str_starts_with($name, $prefix)) {
                continue;
            }

            $statuses[] = [
                'name' => $name,
                'dataLength' => $this->integer($row->Data_length ?? null),
                'indexLength' => $this->integer($row->Index_length ?? null),
                'rows' => $this->integer($row->Rows ?? null),
            ];
        }

        return $statuses;
    }

    public function databaseStatus(): array
    {
        $values = [];

        foreach ($this->connection()->select('SHOW STATUS') as $row) {
            $name = $row->Variable_name ?? null;
            $value = $row->Value ?? null;

            if (is_string($name) && (is_float($value) || is_int($value) || is_string($value))) {
                $values[$name] = is_numeric($value) ? $this->integer($value) : $value;
            }
        }

        return [
            'Uptime' => $values['Uptime'] ?? 0,
            'Threads' => $values['Threads_running'] ?? 0,
            'Questions' => $values['Questions'] ?? 0,
            'Slow queries' => $values['Slow_queries'] ?? 0,
            'Flush tables' => $values['Flush_commands'] ?? 0,
            'Open tables' => $values['Open_tables'] ?? 0,
            'Opens' => 'unavailable',
            'Queries per second avg' => 'unavailable',
        ];
    }

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function connection(): Connection
    {
        return ($this->connection)();
    }
}
