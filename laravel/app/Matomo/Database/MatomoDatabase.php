<?php

declare(strict_types=1);

namespace App\Matomo\Database;

use Illuminate\Database\ConnectionInterface;

final readonly class MatomoDatabase
{
    public function __construct(private ConnectionInterface $connection) {}

    public function connection(): ConnectionInterface
    {
        return $this->connection;
    }
}
