<?php

declare(strict_types=1);

namespace App\Matomo\Database;

use Illuminate\Database\Connection;

final readonly class MatomoDatabase
{
    public function __construct(private Connection $connection) {}

    public function connection(): Connection
    {
        return $this->connection;
    }
}
