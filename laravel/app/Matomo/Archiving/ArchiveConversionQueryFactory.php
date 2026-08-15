<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Archiving\Events\ArchiveConversionsQueryBuilding;
use App\Matomo\Reporting\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use InvalidArgumentException;

final readonly class ArchiveConversionQueryFactory
{
    public function __construct(
        private Connection $connection,
        private ConversionSegmentApplicator $segments,
        private Dispatcher $events,
    ) {}

    public function make(
        ArchiveReportRequest $request,
        ReportingPeriod $period,
        string $timezone,
    ): Builder {
        $start = CarbonImmutable::parse($period->startDate, $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($period->endDate, $timezone)->addDay()->startOfDay()->utc();
        $query = $this->connection
            ->table('log_conversion')
            ->join('log_visit', static function (JoinClause $join): void {
                $join->on('log_visit.idsite', '=', 'log_conversion.idsite')
                    ->on('log_visit.idvisit', '=', 'log_conversion.idvisit');
            })
            ->where('log_conversion.idsite', $request->siteId)
            ->where('log_conversion.server_time', '>=', $start->toDateTimeString())
            ->where('log_conversion.server_time', '<', $end->toDateTimeString());
        $building = new ArchiveConversionsQueryBuilding($request, $period, $query);
        $building->segmentApplied = $this->segments->applyToConversions(
            $query,
            $request->segment,
            $request->siteId,
        );
        $this->events->dispatch($building);

        if (! $building->segmentApplied) {
            throw new InvalidArgumentException(
                "The segment '{$request->segment}' cannot be archived because no segment handler supports it.",
            );
        }

        return $query;
    }
}
