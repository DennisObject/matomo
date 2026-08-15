<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use stdClass;

final readonly class PagePerformanceArchiveCollector
{
    /** @var array<string, array{time: string, hits: string}> */
    private const array DIMENSIONS = [
        'time_network' => [
            'time' => 'PagePerformance_network_time',
            'hits' => 'PagePerformance_network_hits',
        ],
        'time_server' => [
            'time' => 'PagePerformance_servery_time',
            'hits' => 'PagePerformance_server_hits',
        ],
        'time_transfer' => [
            'time' => 'PagePerformance_transfer_time',
            'hits' => 'PagePerformance_transfer_hits',
        ],
        'time_dom_processing' => [
            'time' => 'PagePerformance_domprocessing_time',
            'hits' => 'PagePerformance_domprocessing_hits',
        ],
        'time_dom_completion' => [
            'time' => 'PagePerformance_domcompletion_time',
            'hits' => 'PagePerformance_domcompletion_hits',
        ],
        'time_on_load' => [
            'time' => 'PagePerformance_onload_time',
            'hits' => 'PagePerformance_onload_hits',
        ],
    ];

    private const string PAGE_LOAD_TIME = 'PagePerformance_pageload_time';

    private const string PAGE_LOAD_HITS = 'PagePerformance_pageload_hits';

    private const string PERFORMANCE_SELECT = <<<'SQL'
        COALESCE(SUM(CASE WHEN COALESCE(time_network, 0) > ? AND ? > 0 THEN ? ELSE COALESCE(time_network, 0) END), 0) AS time_network_total,
        COALESCE(SUM(CASE WHEN time_network IS NULL THEN 0 ELSE 1 END), 0) AS time_network_hits,
        COALESCE(SUM(CASE WHEN COALESCE(time_server, 0) > ? AND ? > 0 THEN ? ELSE COALESCE(time_server, 0) END), 0) AS time_server_total,
        COALESCE(SUM(CASE WHEN time_server IS NULL THEN 0 ELSE 1 END), 0) AS time_server_hits,
        COALESCE(SUM(CASE WHEN COALESCE(time_transfer, 0) > ? AND ? > 0 THEN ? ELSE COALESCE(time_transfer, 0) END), 0) AS time_transfer_total,
        COALESCE(SUM(CASE WHEN time_transfer IS NULL THEN 0 ELSE 1 END), 0) AS time_transfer_hits,
        COALESCE(SUM(CASE WHEN COALESCE(time_dom_processing, 0) > ? AND ? > 0 THEN ? ELSE COALESCE(time_dom_processing, 0) END), 0) AS time_dom_processing_total,
        COALESCE(SUM(CASE WHEN time_dom_processing IS NULL THEN 0 ELSE 1 END), 0) AS time_dom_processing_hits,
        COALESCE(SUM(CASE WHEN COALESCE(time_dom_completion, 0) > ? AND ? > 0 THEN ? ELSE COALESCE(time_dom_completion, 0) END), 0) AS time_dom_completion_total,
        COALESCE(SUM(CASE WHEN time_dom_completion IS NULL THEN 0 ELSE 1 END), 0) AS time_dom_completion_hits,
        COALESCE(SUM(CASE WHEN COALESCE(time_on_load, 0) > ? AND ? > 0 THEN ? ELSE COALESCE(time_on_load, 0) END), 0) AS time_on_load_total,
        COALESCE(SUM(CASE WHEN time_on_load IS NULL THEN 0 ELSE 1 END), 0) AS time_on_load_hits,
        COALESCE(SUM(
            CASE WHEN COALESCE(time_network, 0) > ? AND ? > 0 THEN ? ELSE COALESCE(time_network, 0) END
            + CASE WHEN COALESCE(time_server, 0) > ? AND ? > 0 THEN ? ELSE COALESCE(time_server, 0) END
            + CASE WHEN COALESCE(time_transfer, 0) > ? AND ? > 0 THEN ? ELSE COALESCE(time_transfer, 0) END
            + CASE WHEN COALESCE(time_dom_processing, 0) > ? AND ? > 0 THEN ? ELSE COALESCE(time_dom_processing, 0) END
            + CASE WHEN COALESCE(time_dom_completion, 0) > ? AND ? > 0 THEN ? ELSE COALESCE(time_dom_completion, 0) END
            + CASE WHEN COALESCE(time_on_load, 0) > ? AND ? > 0 THEN ? ELSE COALESCE(time_on_load, 0) END
        ), 0) AS page_load_total,
        COUNT(idlink_va) AS page_load_hits
        SQL;

    /**
     * @param  array<string, int>  $caps
     */
    public function __construct(
        private Connection $connection,
        private ArchiveActionQueryFactory $actionQueries,
        private ReportingSubperiodFactory $subperiods,
        private SegmentHashResolver $segments,
        private NumericArchiveRepository $numbers,
        private SiteRepository $sites,
        private array $caps = [],
    ) {}

    public function __invoke(ArchiveReportsCollecting $event): void
    {
        if (! $this->requested($event)) {
            return;
        }

        $timezone = $this->sites->timezone($event->request->siteId);

        if ($timezone === null) {
            return;
        }

        $metrics = $event->period->label === 'day'
            ? $this->dayMetrics($event, $timezone)
            : $this->parentMetrics($event);

        foreach ($metrics as $name => $value) {
            $event->records->addNumeric($name, $value);
        }
    }

    /** @return array<string, int|float> */
    private function dayMetrics(ArchiveReportsCollecting $event, string $timezone): array
    {
        $metrics = $this->emptyMetrics();

        if (! $this->columnsExist()) {
            return $metrics;
        }

        $query = $this->actionQueries->make($event->request, $event->period, $timezone)
            ->join(
                'log_action as performance_url',
                'performance_url.idaction',
                '=',
                'log_link_visit_action.idaction_url',
            )
            ->where(static function (Builder $query): void {
                foreach (array_keys(self::DIMENSIONS) as $column) {
                    $query->orWhereNotNull('log_link_visit_action.'.$column);
                }
            })
            ->selectRaw(self::PERFORMANCE_SELECT, $this->selectBindings());
        $row = $query->first();

        if (! $row instanceof stdClass) {
            return $metrics;
        }

        foreach (self::DIMENSIONS as $column => $records) {
            $metrics[$records['time']] = $this->numeric($row->{$column.'_total'} ?? null);
            $metrics[$records['hits']] = $this->numeric($row->{$column.'_hits'} ?? null);
        }

        $metrics[self::PAGE_LOAD_TIME] = $this->numeric($row->page_load_total ?? null);
        $metrics[self::PAGE_LOAD_HITS] = $this->numeric($row->page_load_hits ?? null);

        return $metrics;
    }

    /** @return array<string, int|float> */
    private function parentMetrics(ArchiveReportsCollecting $event): array
    {
        $metrics = $this->emptyMetrics();
        $children = $this->subperiods->children($event->period);
        $archives = $this->numbers->pluginMetrics(
            [$event->request->siteId],
            $children,
            $this->segments->resolve($event->request->segment),
            array_keys($metrics),
            'PagePerformance',
        )[$event->request->siteId] ?? [];

        foreach ($children as $child) {
            foreach ($metrics as $name => $value) {
                $metrics[$name] = $value + ($archives[$child->rangeKey()][$name] ?? 0);
            }
        }

        return $metrics;
    }

    /** @return array<string, int> */
    private function emptyMetrics(): array
    {
        $metrics = [self::PAGE_LOAD_TIME => 0, self::PAGE_LOAD_HITS => 0];

        foreach (self::DIMENSIONS as $records) {
            $metrics[$records['time']] = 0;
            $metrics[$records['hits']] = 0;
        }

        return $metrics;
    }

    /** @return list<int> */
    private function selectBindings(): array
    {
        $bindings = [];

        for ($pass = 0; $pass < 2; $pass++) {
            foreach (array_keys(self::DIMENSIONS) as $column) {
                $cap = max(0, $this->caps[$column] ?? 0);
                $bindings[] = $cap;
                $bindings[] = $cap;
                $bindings[] = $cap;
            }
        }

        return $bindings;
    }

    private function requested(ArchiveReportsCollecting $event): bool
    {
        if ($event->request->plugin !== null) {
            return $event->request->plugin === 'PagePerformance';
        }

        return $event->request->reports === []
            || in_array('PagePerformance.get', $event->request->reports, true);
    }

    private function columnsExist(): bool
    {
        return $this->connection->getSchemaBuilder()->hasColumns(
            'log_link_visit_action',
            [
                'idsite',
                'idvisit',
                'idaction_url',
                ...array_keys(self::DIMENSIONS),
                'server_time',
            ],
        ) && $this->connection->getSchemaBuilder()->hasColumns('log_action', [
            'idaction',
        ]) && $this->connection->getSchemaBuilder()->hasColumns('log_visit', [
            'idsite',
            'idvisit',
        ]);
    }

    private function numeric(mixed $value): int|float
    {
        if (! is_numeric($value)) {
            return 0;
        }

        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }
}
