<?php

declare(strict_types=1);

namespace App\Matomo\Live;

use App\Matomo\Archiving\VisitSegmentApplicator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

final readonly class DatabaseLiveCounterRepository implements LiveCounterRepository
{
    public function __construct(
        private Connection $connection,
        private VisitSegmentApplicator $segments,
    ) {}

    public function counters(array $siteIds, int $lastMinutes, ?string $segment): array
    {
        $cutoff = CarbonImmutable::now('UTC')->subMinutes($lastMinutes)->toDateTimeString();
        $visits = $this->visitQuery($siteIds, $cutoff, $segment);
        $visitCount = (clone $visits)->count('log_visit.visit_last_action_time');
        $visitorCount = (clone $visits)->distinct()->count('log_visit.idvisitor');

        return [
            'visits' => $visitCount,
            'actions' => $this->relatedCount('log_link_visit_action', $siteIds, $cutoff, $segment),
            'visitors' => $visitorCount,
            'visitsConverted' => $this->relatedCount('log_conversion', $siteIds, $cutoff, $segment),
        ];
    }

    /** @param list<int> $siteIds */
    private function visitQuery(array $siteIds, string $cutoff, ?string $segment): Builder
    {
        $query = $this->connection->table('log_visit')
            ->whereIn('log_visit.idsite', $siteIds)
            ->where('log_visit.visit_last_action_time', '>=', $cutoff);
        if (! $this->segments->apply($query, $segment)) {
            throw new \InvalidArgumentException('The requested segment is not supported.');
        }

        return $query;
    }

    /** @param list<int> $siteIds */
    private function relatedCount(string $table, array $siteIds, string $cutoff, ?string $segment): int
    {
        if (! $this->connection->getSchemaBuilder()->hasTable($table)) {
            return 0;
        }

        $visits = $this->visitQuery($siteIds, '1970-01-01 00:00:00', $segment)
            ->select('log_visit.idvisit');

        return $this->connection->table($table)
            ->whereIn($table.'.idsite', $siteIds)
            ->where($table.'.server_time', '>=', $cutoff)
            ->whereIn($table.'.idvisit', $visits)
            ->count();
    }
}
