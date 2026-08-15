<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use stdClass;

final readonly class VisitAggregateArchiveCollector
{
    /** @var list<array{int, int}|array{int}> */
    private const array TIME_RANGES = [
        [0, 10],
        [11, 30],
        [31, 60],
        [60, 120],
        [120, 240],
        [240, 420],
        [420, 600],
        [600, 900],
        [900, 1_800],
        [1_800],
    ];

    /** @var list<array{int, int}|array{int}> */
    private const array PAGE_RANGES = [
        [1, 1],
        [2, 2],
        [3, 3],
        [4, 4],
        [5, 5],
        [6, 7],
        [8, 10],
        [11, 14],
        [15, 20],
        [20],
    ];

    /** @var list<array{int, int}|array{int}> */
    private const array VISIT_COUNT_RANGES = [
        [1, 1],
        [2, 2],
        [3, 3],
        [4, 4],
        [5, 5],
        [6, 6],
        [7, 7],
        [8, 8],
        [9, 14],
        [15, 25],
        [26, 50],
        [51, 100],
        [101, 200],
        [200],
    ];

    /** @var list<array{int, int}|array{int}> */
    private const array DAYS_SINCE_LAST_RANGES = [
        [0, 0],
        [1, 1],
        [2, 2],
        [3, 3],
        [4, 4],
        [5, 5],
        [6, 6],
        [7, 7],
        [8, 14],
        [15, 30],
        [31, 60],
        [61, 120],
        [121, 364],
        [364],
    ];

    /** @var array<string, string> */
    private const array INTEREST_REPORTS = [
        'VisitorInterest_timeGap' => 'VisitorInterest.getNumberOfVisitsPerVisitDuration',
        'VisitorInterest_pageGap' => 'VisitorInterest.getNumberOfVisitsPerPage',
        'VisitorInterest_visitsByVisitCount' => 'VisitorInterest.getNumberOfVisitsByVisitCount',
        'VisitorInterest_daysSinceLastVisit' => 'VisitorInterest.getNumberOfVisitsByDaysSinceLast',
    ];

    /** @var array<string, string> */
    private const array DEVICE_PLUGIN_COLUMNS = [
        'config_cookie' => 'cookie',
        'config_flash' => 'flash',
        'config_java' => 'java',
        'config_pdf' => 'pdf',
        'config_quicktime' => 'quicktime',
        'config_realplayer' => 'realplayer',
        'config_silverlight' => 'silverlight',
        'config_windowsmedia' => 'windowsmedia',
    ];

    private const string INTEREST_SELECT = <<<'SQL'
        COALESCE(SUM(CASE WHEN visit_total_time BETWEEN 0 AND 10 THEN 1 ELSE 0 END), 0) AS time_0,
        COALESCE(SUM(CASE WHEN visit_total_time BETWEEN 11 AND 30 THEN 1 ELSE 0 END), 0) AS time_1,
        COALESCE(SUM(CASE WHEN visit_total_time BETWEEN 31 AND 60 THEN 1 ELSE 0 END), 0) AS time_2,
        COALESCE(SUM(CASE WHEN visit_total_time BETWEEN 60 AND 120 THEN 1 ELSE 0 END), 0) AS time_3,
        COALESCE(SUM(CASE WHEN visit_total_time BETWEEN 120 AND 240 THEN 1 ELSE 0 END), 0) AS time_4,
        COALESCE(SUM(CASE WHEN visit_total_time BETWEEN 240 AND 420 THEN 1 ELSE 0 END), 0) AS time_5,
        COALESCE(SUM(CASE WHEN visit_total_time BETWEEN 420 AND 600 THEN 1 ELSE 0 END), 0) AS time_6,
        COALESCE(SUM(CASE WHEN visit_total_time BETWEEN 600 AND 900 THEN 1 ELSE 0 END), 0) AS time_7,
        COALESCE(SUM(CASE WHEN visit_total_time BETWEEN 900 AND 1800 THEN 1 ELSE 0 END), 0) AS time_8,
        COALESCE(SUM(CASE WHEN visit_total_time > 1800 THEN 1 ELSE 0 END), 0) AS time_9,
        COALESCE(SUM(CASE WHEN visit_total_actions BETWEEN 1 AND 1 THEN 1 ELSE 0 END), 0) AS page_0,
        COALESCE(SUM(CASE WHEN visit_total_actions BETWEEN 2 AND 2 THEN 1 ELSE 0 END), 0) AS page_1,
        COALESCE(SUM(CASE WHEN visit_total_actions BETWEEN 3 AND 3 THEN 1 ELSE 0 END), 0) AS page_2,
        COALESCE(SUM(CASE WHEN visit_total_actions BETWEEN 4 AND 4 THEN 1 ELSE 0 END), 0) AS page_3,
        COALESCE(SUM(CASE WHEN visit_total_actions BETWEEN 5 AND 5 THEN 1 ELSE 0 END), 0) AS page_4,
        COALESCE(SUM(CASE WHEN visit_total_actions BETWEEN 6 AND 7 THEN 1 ELSE 0 END), 0) AS page_5,
        COALESCE(SUM(CASE WHEN visit_total_actions BETWEEN 8 AND 10 THEN 1 ELSE 0 END), 0) AS page_6,
        COALESCE(SUM(CASE WHEN visit_total_actions BETWEEN 11 AND 14 THEN 1 ELSE 0 END), 0) AS page_7,
        COALESCE(SUM(CASE WHEN visit_total_actions BETWEEN 15 AND 20 THEN 1 ELSE 0 END), 0) AS page_8,
        COALESCE(SUM(CASE WHEN visit_total_actions > 20 THEN 1 ELSE 0 END), 0) AS page_9,
        COALESCE(SUM(CASE WHEN visitor_count_visits BETWEEN 1 AND 1 THEN 1 ELSE 0 END), 0) AS count_0,
        COALESCE(SUM(CASE WHEN visitor_count_visits BETWEEN 2 AND 2 THEN 1 ELSE 0 END), 0) AS count_1,
        COALESCE(SUM(CASE WHEN visitor_count_visits BETWEEN 3 AND 3 THEN 1 ELSE 0 END), 0) AS count_2,
        COALESCE(SUM(CASE WHEN visitor_count_visits BETWEEN 4 AND 4 THEN 1 ELSE 0 END), 0) AS count_3,
        COALESCE(SUM(CASE WHEN visitor_count_visits BETWEEN 5 AND 5 THEN 1 ELSE 0 END), 0) AS count_4,
        COALESCE(SUM(CASE WHEN visitor_count_visits BETWEEN 6 AND 6 THEN 1 ELSE 0 END), 0) AS count_5,
        COALESCE(SUM(CASE WHEN visitor_count_visits BETWEEN 7 AND 7 THEN 1 ELSE 0 END), 0) AS count_6,
        COALESCE(SUM(CASE WHEN visitor_count_visits BETWEEN 8 AND 8 THEN 1 ELSE 0 END), 0) AS count_7,
        COALESCE(SUM(CASE WHEN visitor_count_visits BETWEEN 9 AND 14 THEN 1 ELSE 0 END), 0) AS count_8,
        COALESCE(SUM(CASE WHEN visitor_count_visits BETWEEN 15 AND 25 THEN 1 ELSE 0 END), 0) AS count_9,
        COALESCE(SUM(CASE WHEN visitor_count_visits BETWEEN 26 AND 50 THEN 1 ELSE 0 END), 0) AS count_10,
        COALESCE(SUM(CASE WHEN visitor_count_visits BETWEEN 51 AND 100 THEN 1 ELSE 0 END), 0) AS count_11,
        COALESCE(SUM(CASE WHEN visitor_count_visits BETWEEN 101 AND 200 THEN 1 ELSE 0 END), 0) AS count_12,
        COALESCE(SUM(CASE WHEN visitor_count_visits > 200 THEN 1 ELSE 0 END), 0) AS count_13,
        COALESCE(SUM(CASE WHEN visitor_returning = 0 THEN 1 ELSE 0 END), 0) AS days_new,
        COALESCE(SUM(CASE WHEN FLOOR(visitor_seconds_since_last / 86400) BETWEEN 0 AND 0 AND visitor_returning = 1 THEN 1 ELSE 0 END), 0) AS days_0,
        COALESCE(SUM(CASE WHEN FLOOR(visitor_seconds_since_last / 86400) BETWEEN 1 AND 1 AND visitor_returning = 1 THEN 1 ELSE 0 END), 0) AS days_1,
        COALESCE(SUM(CASE WHEN FLOOR(visitor_seconds_since_last / 86400) BETWEEN 2 AND 2 AND visitor_returning = 1 THEN 1 ELSE 0 END), 0) AS days_2,
        COALESCE(SUM(CASE WHEN FLOOR(visitor_seconds_since_last / 86400) BETWEEN 3 AND 3 AND visitor_returning = 1 THEN 1 ELSE 0 END), 0) AS days_3,
        COALESCE(SUM(CASE WHEN FLOOR(visitor_seconds_since_last / 86400) BETWEEN 4 AND 4 AND visitor_returning = 1 THEN 1 ELSE 0 END), 0) AS days_4,
        COALESCE(SUM(CASE WHEN FLOOR(visitor_seconds_since_last / 86400) BETWEEN 5 AND 5 AND visitor_returning = 1 THEN 1 ELSE 0 END), 0) AS days_5,
        COALESCE(SUM(CASE WHEN FLOOR(visitor_seconds_since_last / 86400) BETWEEN 6 AND 6 AND visitor_returning = 1 THEN 1 ELSE 0 END), 0) AS days_6,
        COALESCE(SUM(CASE WHEN FLOOR(visitor_seconds_since_last / 86400) BETWEEN 7 AND 7 AND visitor_returning = 1 THEN 1 ELSE 0 END), 0) AS days_7,
        COALESCE(SUM(CASE WHEN FLOOR(visitor_seconds_since_last / 86400) BETWEEN 8 AND 14 AND visitor_returning = 1 THEN 1 ELSE 0 END), 0) AS days_8,
        COALESCE(SUM(CASE WHEN FLOOR(visitor_seconds_since_last / 86400) BETWEEN 15 AND 30 AND visitor_returning = 1 THEN 1 ELSE 0 END), 0) AS days_9,
        COALESCE(SUM(CASE WHEN FLOOR(visitor_seconds_since_last / 86400) BETWEEN 31 AND 60 AND visitor_returning = 1 THEN 1 ELSE 0 END), 0) AS days_10,
        COALESCE(SUM(CASE WHEN FLOOR(visitor_seconds_since_last / 86400) BETWEEN 61 AND 120 AND visitor_returning = 1 THEN 1 ELSE 0 END), 0) AS days_11,
        COALESCE(SUM(CASE WHEN FLOOR(visitor_seconds_since_last / 86400) BETWEEN 121 AND 364 AND visitor_returning = 1 THEN 1 ELSE 0 END), 0) AS days_12,
        COALESCE(SUM(CASE WHEN FLOOR(visitor_seconds_since_last / 86400) > 364 AND visitor_returning = 1 THEN 1 ELSE 0 END), 0) AS days_13
        SQL;

    public function __construct(
        private Connection $connection,
        private ArchiveVisitQueryFactory $visitQueries,
        private ReportingSubperiodFactory $subperiods,
        private SegmentHashResolver $segments,
        private BlobArchiveRepository $blobs,
        private SiteRepository $sites,
    ) {}

    public function __invoke(ArchiveReportsCollecting $event): void
    {
        $timezone = $this->sites->timezone($event->request->siteId);

        if ($timezone === null) {
            return;
        }

        $interestRecords = array_keys(array_filter(
            self::INTEREST_REPORTS,
            fn (string $report): bool => $this->requested($event, 'VisitorInterest', [$report]),
        ));

        if ($interestRecords !== [] && $this->interestColumnsExist()) {
            $records = $event->period->label === 'day'
                ? $this->dayInterestRecords($event, $timezone)
                : $this->parentRecords($event, $interestRecords);

            foreach ($interestRecords as $recordName) {
                $event->records->addBlob($recordName, serialize($records[$recordName] ?? []));
            }
        }

        if (! $this->requested($event, 'DevicePlugins', ['DevicePlugins.getPlugin'])) {
            return;
        }

        $pluginColumns = array_intersect_key(
            self::DEVICE_PLUGIN_COLUMNS,
            array_flip(array_filter(
                array_keys(self::DEVICE_PLUGIN_COLUMNS),
                fn (string $column): bool => $this->columnsExist([$column]),
            )),
        );

        if ($pluginColumns === []) {
            return;
        }

        $rows = $event->period->label === 'day'
            ? $this->dayDevicePluginRows($event, $timezone, $pluginColumns)
            : ($this->parentRecords($event, ['DevicePlugins_plugin'])['DevicePlugins_plugin'] ?? []);
        $event->records->addBlob('DevicePlugins_plugin', serialize($rows));
    }

    /** @return array<string, list<array{0: array{label: string, nb_visits: int}, 1: array{}}>> */
    private function dayInterestRecords(ArchiveReportsCollecting $event, string $timezone): array
    {
        $row = $this->visitQueries
            ->make($event->request, $event->period, $timezone)
            ->selectRaw(self::INTEREST_SELECT)
            ->first();

        if (! $row instanceof stdClass) {
            return [];
        }

        $records = [];
        $definitions = [
            'VisitorInterest_timeGap' => ['time', self::TIME_RANGES],
            'VisitorInterest_pageGap' => ['page', self::PAGE_RANGES],
            'VisitorInterest_visitsByVisitCount' => ['count', self::VISIT_COUNT_RANGES],
            'VisitorInterest_daysSinceLastVisit' => ['days', self::DAYS_SINCE_LAST_RANGES],
        ];

        foreach ($definitions as $recordName => [$prefix, $ranges]) {
            $rows = [];

            if ($recordName === 'VisitorInterest_daysSinceLastVisit') {
                $rows[] = [
                    ['label' => 'General_NewVisits', 'nb_visits' => $this->integer($row->days_new ?? null)],
                    [],
                ];
            }

            foreach ($ranges as $index => $range) {
                $rows[] = [
                    [
                        'label' => $this->rangeLabel($range),
                        'nb_visits' => $this->integer($row->{$prefix.'_'.$index} ?? null),
                    ],
                    [],
                ];
            }

            $records[$recordName] = $rows;
        }

        return $records;
    }

    /**
     * @param  array<string, string>  $columns
     * @return list<array{0: array{label: string, nb_visits: int}, 1: array{}}>
     */
    private function dayDevicePluginRows(
        ArchiveReportsCollecting $event,
        string $timezone,
        array $columns,
    ): array {
        $query = $this->visitQueries->make($event->request, $event->period, $timezone);

        foreach (array_keys($columns) as $column) {
            $this->addDevicePluginSelect($query, $column);
        }

        $row = $query->first();

        if (! $row instanceof stdClass) {
            return [];
        }

        $rows = [];

        foreach ($columns as $column => $label) {
            $rows[] = [
                ['label' => $label, 'nb_visits' => $this->integer($row->{$column} ?? null)],
                [],
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $recordNames
     * @return array<string, list<array{0: array{label: float|int|string, nb_visits: int|float}, 1: array<string, float|int|string|null>}>>
     */
    private function parentRecords(ArchiveReportsCollecting $event, array $recordNames): array
    {
        $children = $this->subperiods->children($event->period);
        $segmentHash = $this->segments->resolve($event->request->segment);
        $records = [];

        foreach ($recordNames as $recordName) {
            $archives = $this->blobs->rows(
                [$event->request->siteId],
                $children,
                $segmentHash,
                $recordName,
            )[$event->request->siteId] ?? [];
            $rows = [];

            foreach ($children as $child) {
                foreach ($archives[$child->rangeKey()] ?? [] as $row) {
                    $label = $row['columns']['label'] ?? null;
                    $visits = $row['columns']['nb_visits'] ?? null;

                    if ((! is_float($label) && ! is_int($label) && ! is_string($label))
                        || (! is_float($visits) && ! is_int($visits))) {
                        continue;
                    }

                    $key = get_debug_type($label).':'.$label;
                    $rows[$key] ??= [
                        ['label' => $label, 'nb_visits' => 0],
                        $row['metadata'],
                    ];
                    $rows[$key][0]['nb_visits'] += $visits;
                }
            }

            $records[$recordName] = array_values($rows);
        }

        return $records;
    }

    private function addDevicePluginSelect(Builder $query, string $column): void
    {
        match ($column) {
            'config_cookie' => $query->selectRaw(
                'COALESCE(SUM(CASE WHEN config_cookie = 1 THEN 1 ELSE 0 END), 0) AS config_cookie',
            ),
            'config_flash' => $query->selectRaw(
                'COALESCE(SUM(CASE WHEN config_flash = 1 THEN 1 ELSE 0 END), 0) AS config_flash',
            ),
            'config_java' => $query->selectRaw(
                'COALESCE(SUM(CASE WHEN config_java = 1 THEN 1 ELSE 0 END), 0) AS config_java',
            ),
            'config_pdf' => $query->selectRaw(
                'COALESCE(SUM(CASE WHEN config_pdf = 1 THEN 1 ELSE 0 END), 0) AS config_pdf',
            ),
            'config_quicktime' => $query->selectRaw(
                'COALESCE(SUM(CASE WHEN config_quicktime = 1 THEN 1 ELSE 0 END), 0) AS config_quicktime',
            ),
            'config_realplayer' => $query->selectRaw(
                'COALESCE(SUM(CASE WHEN config_realplayer = 1 THEN 1 ELSE 0 END), 0) AS config_realplayer',
            ),
            'config_silverlight' => $query->selectRaw(
                'COALESCE(SUM(CASE WHEN config_silverlight = 1 THEN 1 ELSE 0 END), 0) AS config_silverlight',
            ),
            'config_windowsmedia' => $query->selectRaw(
                'COALESCE(SUM(CASE WHEN config_windowsmedia = 1 THEN 1 ELSE 0 END), 0) AS config_windowsmedia',
            ),
            default => throw new InvalidArgumentException(
                "The device plug-in archive column '{$column}' is not supported.",
            ),
        };
    }

    /** @param array{int, int}|array{int} $range */
    private function rangeLabel(array $range): string
    {
        return count($range) === 2
            ? $range[0].'-'.$range[1]
            : ($range[0] + 1).'%2B';
    }

    private function interestColumnsExist(): bool
    {
        return $this->columnsExist([
            'visit_total_time',
            'visit_total_actions',
            'visitor_count_visits',
            'visitor_returning',
            'visitor_seconds_since_last',
        ]);
    }

    /** @param list<string> $reports */
    private function requested(ArchiveReportsCollecting $event, string $plugin, array $reports): bool
    {
        if ($event->request->plugin !== null && $event->request->plugin !== $plugin) {
            return false;
        }

        return $event->request->reports === []
            || array_intersect($event->request->reports, $reports) !== [];
    }

    /** @param list<string> $columns */
    private function columnsExist(array $columns): bool
    {
        return $this->connection->getSchemaBuilder()->hasColumns('log_visit', $columns);
    }

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
