<?php

declare(strict_types=1);

namespace App\Matomo\Options;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseOptionRepository implements MutableOptionRepository, OptionRepository
{
    public function __construct(private ConnectionInterface $connection) {}

    public function value(string $name): ?string
    {
        $value = $this->connection
            ->table('option')
            ->where('option_name', $name)
            ->value('option_value');

        return is_int($value) || is_string($value) ? (string) $value : null;
    }

    public function set(string $name, string $value, bool $autoload = false): void
    {
        $updated = $this->connection
            ->table('option')
            ->where('option_name', $name)
            ->update(['option_value' => $value, 'autoload' => $autoload ? 1 : 0]);

        if ($updated === 0) {
            $this->connection->table('option')->insertOrIgnore([
                'option_name' => $name,
                'option_value' => $value,
                'autoload' => $autoload ? 1 : 0,
            ]);
        }
    }

    public function delete(string $name): void
    {
        $this->connection->table('option')->where('option_name', $name)->delete();
    }
}
