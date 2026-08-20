<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use Illuminate\Database\Connection;

final readonly class DatabaseSegmentHashResolver implements SegmentHashResolver
{
    public function __construct(private Connection $connection) {}

    public function resolve(?string $segment): string
    {
        if ($segment === null || $segment === '') {
            return '';
        }

        $decoded = urldecode($segment);
        $definitions = array_values(array_unique([$segment, $decoded, urlencode($segment)]));

        if (! $this->connection->getSchemaBuilder()->hasTable('segment')) {
            return md5($decoded);
        }

        $record = $this->connection
            ->table('segment')
            ->select(['hash'])
            ->whereIn('definition', $definitions)
            ->whereNotNull('hash')
            ->first();
        $hash = $record?->hash;

        return is_string($hash) && preg_match('/^[a-f0-9]{32}$/Di', $hash) === 1
            ? strtolower($hash)
            : md5($decoded);
    }
}
