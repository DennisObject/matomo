<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

use Exception;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseSiteRepository implements SiteRepository
{
    public function __construct(private ConnectionInterface $connection) {}

    public function allIds(): array
    {
        try {
            return array_values(
                $this->connection
                    ->table('site')
                    ->pluck('idsite')
                    ->map(static fn (mixed $idSite): int => (int) $idSite)
                    ->all(),
            );
        } catch (Exception) {
            return [];
        }
    }
}
