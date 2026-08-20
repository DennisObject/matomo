<?php

declare(strict_types=1);

namespace App\Matomo\Live;

use App\Matomo\Archiving\VisitSegmentApplicator;
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
        $this->segments->apply($query, $segment, $siteId);
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
}
