<?php

declare(strict_types=1);

namespace App\Matomo\Live;

use App\Matomo\Archiving\VisitSegmentApplicator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;

final readonly class DatabaseLiveVisitorIdentityRepository implements LiveVisitorIdentityRepository
{
    public function __construct(
        private Connection $connection,
        private VisitSegmentApplicator $segments,
    ) {}

    public function mostRecentVisitorId(int $siteId, ?string $segment): string|false
    {
        $query = $this->connection->table('log_visit')
            ->where('log_visit.idsite', $siteId)
            ->orderByDesc('log_visit.visit_last_action_time')
            ->orderByDesc('log_visit.idvisit');
        if (! $this->segments->apply($query, $segment, $siteId)) {
            throw new \InvalidArgumentException('The requested segment is not supported.');
        }

        $visitorId = $query->value('log_visit.idvisitor');

        return is_string($visitorId) && $visitorId !== '' ? bin2hex($visitorId) : false;
    }

    public function mostRecentVisitDateTime(
        array $siteIds,
        ?string $startDateTime,
        ?string $endDateTime,
    ): string {
        $query = $this->connection->table('log_visit')->whereIn('idsite', $siteIds);
        if ($startDateTime !== null) {
            $query->where('visit_last_action_time', '>=', $startDateTime);
        }

        if ($endDateTime !== null) {
            $query->where('visit_last_action_time', '<=', $endDateTime);
        }

        $value = $query->orderByDesc('visit_last_action_time')->value('visit_last_action_time');

        return is_string($value) ? $value : '';
    }

    public function adjacentVisitorId(
        int $siteId,
        string $visitorId,
        string $latestVisitTime,
        ?string $segment,
        bool $next,
    ): string|false {
        $binary = hex2bin($visitorId);
        if ($binary === false) {
            return false;
        }

        $date = CarbonImmutable::parse($latestVisitTime, 'UTC');
        $visits = $this->connection->table('log_visit')
            ->where('log_visit.idsite', $siteId)
            ->where('log_visit.idvisitor', '<>', $binary)
            ->whereBetween('log_visit.visit_last_action_time', [
                $date->subDay()->toDateTimeString(),
                $date->addDay()->toDateTimeString(),
            ]);
        if (! $this->segments->apply($visits, $segment, $siteId)) {
            throw new \InvalidArgumentException('The requested segment is not supported.');
        }

        $visits->select('log_visit.idvisitor')
            ->selectRaw('MAX(log_visit.visit_last_action_time) AS visit_last_action_time')
            ->groupBy('log_visit.idvisitor');
        $query = $this->connection->query()->fromSub($visits, 'adjacent');
        $query->where('adjacent.visit_last_action_time', $next ? '<=' : '>=', $latestVisitTime)
            ->orderBy('adjacent.visit_last_action_time', $next ? 'desc' : 'asc');
        $value = $query->value('adjacent.idvisitor');

        return is_string($value) && $value !== '' ? bin2hex($value) : false;
    }
}
