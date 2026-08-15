<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseAnonymizableColumnProvider implements AnonymizableColumnProvider
{
    /** @var list<string> */
    private const array BLOCKED = [
        'idvisit', 'idvisitor', 'idsite', 'visit_last_action_time', 'config_id', 'location_ip',
        'idlink_va', 'server_time', 'idgoal', 'buster', 'idorder',
    ];

    public function __construct(private ConnectionInterface $connection) {}

    public function forTable(string $table): array
    {
        $columns = $this->connection->select('SHOW COLUMNS FROM `'.$table.'`');
        $result = [];
        foreach ($columns as $column) {
            $values = get_object_vars($column);
            $name = $values['Field'] ?? null;
            if (! is_string($name) || in_array($name, self::BLOCKED, true)) {
                continue;
            }

            $nullable = strtoupper((string) ($values['Null'] ?? 'NO')) === 'YES';
            $hasDefault = array_key_exists('Default', $values);
            if (! $nullable && $hasDefault && $values['Default'] === null) {
                continue;
            }

            if (! $hasDefault && ! $nullable) {
                continue;
            }

            $result[$name] = [
                'column_name' => $name,
                'default_value' => $hasDefault ? $values['Default'] : null,
            ];
        }

        ksort($result);

        return array_values($result);
    }
}
