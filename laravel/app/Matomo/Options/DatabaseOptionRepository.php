<?php

declare(strict_types=1);

namespace App\Matomo\Options;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseOptionRepository implements OptionRepository
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
}
