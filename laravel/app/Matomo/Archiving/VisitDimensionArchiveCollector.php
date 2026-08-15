<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use stdClass;

final readonly class VisitDimensionArchiveCollector
{
    private const int STANDARD_ROW_LIMIT = 500;

    private const int USER_ID_ROW_LIMIT = 50_000;

    /** @var list<string> */
    private const array METRICS = [
        'nb_uniq_visitors',
        'nb_visits',
        'nb_actions',
        'nb_users',
        'max_actions',
        'sum_visit_length',
        'bounce_count',
        'nb_visits_converted',
    ];

    /**
     * @var array<string, array{
     *     plugin: string,
     *     reports: list<string>,
     *     columns: list<string>,
     *     kind?: 'concat-null'|'hour-local'|'hour-server'|'os-version'|'resolution'|'user-id',
     *     limit?: int
     * }>
     */
    private const array RECORDS = [
        'VisitTime_serverTime' => [
            'plugin' => 'VisitTime',
            'reports' => ['VisitTime.getVisitInformationPerServerTime'],
            'columns' => ['visit_first_action_time'],
            'kind' => 'hour-server',
        ],
        'VisitTime_localTime' => [
            'plugin' => 'VisitTime',
            'reports' => ['VisitTime.getVisitInformationPerLocalTime'],
            'columns' => ['visitor_localtime'],
            'kind' => 'hour-local',
        ],
        'Resolution_resolution' => [
            'plugin' => 'Resolution',
            'reports' => ['Resolution.getResolution'],
            'columns' => ['config_resolution'],
            'kind' => 'resolution',
        ],
        'Resolution_configuration' => [
            'plugin' => 'Resolution',
            'reports' => ['Resolution.getConfiguration'],
            'columns' => ['config_os', 'config_browser_name', 'config_resolution'],
            'kind' => 'concat-null',
        ],
        'UserId_users' => [
            'plugin' => 'UserId',
            'reports' => ['UserId.getUsers'],
            'columns' => ['user_id'],
            'kind' => 'user-id',
            'limit' => self::USER_ID_ROW_LIMIT,
        ],
        'Provider_hostnameExt' => [
            'plugin' => 'Provider',
            'reports' => ['Provider.getProvider'],
            'columns' => ['location_provider'],
        ],
        'DevicesDetection_types' => [
            'plugin' => 'DevicesDetection',
            'reports' => ['DevicesDetection.getType'],
            'columns' => ['config_device_type'],
        ],
        'DevicesDetection_brands' => [
            'plugin' => 'DevicesDetection',
            'reports' => ['DevicesDetection.getBrand'],
            'columns' => ['config_device_brand'],
        ],
        'DevicesDetection_models' => [
            'plugin' => 'DevicesDetection',
            'reports' => ['DevicesDetection.getModel'],
            'columns' => ['config_device_brand', 'config_device_model'],
            'kind' => 'concat-null',
        ],
        'DevicesDetection_os' => [
            'plugin' => 'DevicesDetection',
            'reports' => ['DevicesDetection.getOsFamilies'],
            'columns' => ['config_os'],
        ],
        'DevicesDetection_osVersions' => [
            'plugin' => 'DevicesDetection',
            'reports' => ['DevicesDetection.getOsVersions'],
            'columns' => ['config_os', 'config_os_version'],
            'kind' => 'os-version',
        ],
        'DevicesDetection_browsers' => [
            'plugin' => 'DevicesDetection',
            'reports' => ['DevicesDetection.getBrowsers'],
            'columns' => ['config_browser_name'],
        ],
        'DevicesDetection_browserEngines' => [
            'plugin' => 'DevicesDetection',
            'reports' => ['DevicesDetection.getBrowserEngines'],
            'columns' => ['config_browser_engine'],
        ],
        'DevicesDetection_browserVersions' => [
            'plugin' => 'DevicesDetection',
            'reports' => ['DevicesDetection.getBrowserVersions'],
            'columns' => ['config_browser_name', 'config_browser_version'],
            'kind' => 'concat-null',
        ],
    ];

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

        foreach (self::RECORDS as $recordName => $definition) {
            if (! $this->requested($event, $definition['plugin'], $definition['reports'])
                || ! $this->columnsExist($definition['columns'])) {
                continue;
            }

            $rows = $event->period->label === 'day'
                ? $this->dayRows($event, $definition, $timezone)
                : $this->parentRows($event, $recordName);
            $event->records->addBlob($recordName, serialize($rows));
        }
    }

    /**
     * @param  array{plugin: string, reports: list<string>, columns: list<string>, kind?: string, limit?: int}  $definition
     * @return list<array{0: array<string, float|int|string|null>, 1: array<string, float|int|string|null>}>
     */
    private function dayRows(
        ArchiveReportsCollecting $event,
        array $definition,
        string $timezone,
    ): array {
        $query = $this->visitQueries->make($event->request, $event->period, $timezone);
        $kind = $definition['kind'] ?? '';
        $this->selectDimensions($query, $definition['columns'], $kind);
        $query->selectRaw($this->metricSelect());

        if ($kind === 'user-id') {
            $query->whereNotNull('log_visit.user_id')
                ->where('log_visit.user_id', '!=', '')
                ->selectRaw('MIN(idvisitor) AS visitor_id');
        }

        $rows = [];
        $summary = [];
        $limit = $definition['limit'] ?? self::STANDARD_ROW_LIMIT;
        $query->orderByDesc('nb_visits');

        foreach (array_keys($definition['columns']) as $index) {
            $query->orderBy('dimension_'.$index);
        }

        foreach ($query->cursor() as $result) {
            $label = $this->label($result, $definition['columns'], $kind, $event->period, $timezone);

            if ($label === null) {
                continue;
            }

            $metadata = [];

            if ($kind === 'user-id' && is_string($result->visitor_id ?? null)) {
                $metadata['idvisitor'] = strtolower(bin2hex($result->visitor_id));
            }

            $this->mergeBoundedRow(
                $rows,
                $summary,
                $label,
                $this->metrics($result),
                $metadata,
                $limit,
            );
        }

        if ($summary !== []) {
            $rows += $summary;
        }

        if (str_starts_with($kind, 'hour-')) {
            $this->ensureHours($rows, false);
        }

        return $this->serializableRows(
            $rows,
            $limit,
            str_starts_with($kind, 'hour-'),
        );
    }

    /**
     * @return list<array{0: array<string, float|int|string|null>, 1: array<string, float|int|string|null>}>
     */
    private function parentRows(ArchiveReportsCollecting $event, string $recordName): array
    {
        $children = $this->subperiods->children($event->period);
        $archives = $this->blobs->rows(
            [$event->request->siteId],
            $children,
            $this->segments->resolve($event->request->segment),
            $recordName,
        )[$event->request->siteId] ?? [];
        $rows = [];

        foreach ($children as $child) {
            foreach ($archives[$child->rangeKey()] ?? [] as $row) {
                $label = $row['columns']['label'] ?? null;

                if (! is_float($label) && ! is_int($label) && ! is_string($label)) {
                    continue;
                }

                $this->mergeRow($rows, $label, $row['columns'], $row['metadata'], true);
            }
        }

        $definition = self::RECORDS[$recordName];

        if (str_starts_with($definition['kind'] ?? '', 'hour-')) {
            $this->ensureHours($rows, true);
        }

        return $this->serializableRows(
            $rows,
            $definition['limit'] ?? self::STANDARD_ROW_LIMIT,
            str_starts_with($definition['kind'] ?? '', 'hour-'),
        );
    }

    /**
     * @param  list<string>  $columns
     */
    private function selectDimensions(Builder $query, array $columns, string $kind): void
    {
        if (str_starts_with($kind, 'hour-')) {
            $this->selectHourDimension($query, $columns[0]);

            return;
        }

        foreach ($columns as $index => $column) {
            $query->addSelect("log_visit.{$column} AS dimension_{$index}");
        }

        $query->groupBy(array_map(
            static fn (string $column): string => 'log_visit.'.$column,
            $columns,
        ));
    }

    private function selectHourDimension(Builder $query, string $column): void
    {
        if ($this->connection->getDriverName() === 'sqlite') {
            if ($column === 'visitor_localtime') {
                $query->selectRaw("CAST(strftime('%H', visitor_localtime) AS INTEGER) AS dimension_0")
                    ->groupByRaw("CAST(strftime('%H', visitor_localtime) AS INTEGER)");

                return;
            }

            $query->selectRaw("CAST(strftime('%H', visit_first_action_time) AS INTEGER) AS dimension_0")
                ->groupByRaw("CAST(strftime('%H', visit_first_action_time) AS INTEGER)");

            return;
        }

        if ($column === 'visitor_localtime') {
            $query->selectRaw('HOUR(visitor_localtime) AS dimension_0')
                ->groupByRaw('HOUR(visitor_localtime)');

            return;
        }

        $query->selectRaw('HOUR(visit_first_action_time) AS dimension_0')
            ->groupByRaw('HOUR(visit_first_action_time)');
    }

    /** @return literal-string */
    private function metricSelect(): string
    {
        return implode(', ', [
            'COUNT(DISTINCT idvisitor) AS nb_uniq_visitors',
            'COUNT(*) AS nb_visits',
            'COALESCE(SUM(visit_total_actions), 0) AS nb_actions',
            'COUNT(DISTINCT user_id) AS nb_users',
            'COALESCE(MAX(visit_total_actions), 0) AS max_actions',
            'COALESCE(SUM(visit_total_time), 0) AS sum_visit_length',
            'COALESCE(SUM(CASE WHEN visit_total_actions IN (0, 1) THEN 1 ELSE 0 END), 0) AS bounce_count',
            'COALESCE(SUM(CASE WHEN visit_goal_converted = 1 THEN 1 ELSE 0 END), 0) AS nb_visits_converted',
        ]);
    }

    /**
     * @param  list<string>  $columns
     */
    private function label(
        stdClass $row,
        array $columns,
        string $kind,
        ReportingPeriod $period,
        string $timezone,
    ): int|string|null {
        $values = [];

        foreach (array_keys($columns) as $index) {
            $value = $row->{'dimension_'.$index} ?? null;
            $values[] = is_float($value) || is_int($value) || is_string($value) ? $value : null;
        }

        if (str_starts_with($kind, 'hour-')) {
            $hour = (int) ($values[0] ?? 0);

            if ($kind === 'hour-server') {
                return (int) CarbonImmutable::parse(
                    $period->startDate.' '.str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':00:00',
                    'UTC',
                )->setTimezone($timezone)->format('G');
            }

            return $hour;
        }

        if (($kind === 'concat-null' && in_array(null, $values, true))
            || ($kind === 'os-version' && ($values[0] ?? null) === null)) {
            $label = '';
        } else {
            $label = count($values) === 1
                ? ($values[0] ?? '')
                : implode(';', array_map(
                    static fn (float|int|string|null $value): string => (string) ($value ?? ''),
                    $values,
                ));
        }

        if ($kind === 'resolution' && strlen((string) $label) <= 5) {
            return null;
        }

        if (is_float($label)) {
            return floor($label) === $label ? (int) $label : (string) $label;
        }

        return $label;
    }

    /** @return array<string, int|float> */
    private function metrics(stdClass $row): array
    {
        $metrics = [];

        foreach (self::METRICS as $metric) {
            $metrics[$metric] = $this->numeric($row->{$metric} ?? null);
        }

        return $metrics;
    }

    /**
     * @param  array<string, array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $rows
     * @param  array<string, float|int|string|null>  $columns
     * @param  array<string, float|int|string|null>  $metadata
     */
    private function mergeRow(
        array &$rows,
        float|int|string $label,
        array $columns,
        array $metadata,
        bool $parent,
    ): void {
        $key = $this->rowKey($label);
        $rows[$key] ??= [
            'columns' => ['label' => $label],
            'metadata' => $metadata,
        ];

        foreach ($columns as $metric => $value) {
            if ($metric === 'label' || (! is_float($value) && ! is_int($value))) {
                continue;
            }

            $target = $parent ? match ($metric) {
                'nb_uniq_visitors' => 'sum_daily_nb_uniq_visitors',
                'nb_users' => 'sum_daily_nb_users',
                default => $metric,
            } : $metric;
            $existing = $rows[$key]['columns'][$target] ?? 0;
            $rows[$key]['columns'][$target] = $this->numeric($target === 'max_actions'
                ? max((float) $existing, $value)
                : (float) $existing + $value);
        }
    }

    /**
     * @param  array<string, array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $rows
     * @param  array<string, array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $summary
     * @param  array<string, float|int|string|null>  $columns
     * @param  array<string, float|int|string|null>  $metadata
     */
    private function mergeBoundedRow(
        array &$rows,
        array &$summary,
        float|int|string $label,
        array $columns,
        array $metadata,
        int $limit,
    ): void {
        $key = $this->rowKey($label);

        if (isset($rows[$key]) || ($summary === [] && count($rows) < $limit)) {
            $this->mergeRow($rows, $label, $columns, $metadata, false);

            return;
        }

        if ($summary === []) {
            $lastKey = array_key_last($rows);

            if ($lastKey !== null) {
                $last = $rows[$lastKey];
                unset($rows[$lastKey]);
                $this->mergeRow($summary, -1, $last['columns'], [], false);
            }
        }

        $this->mergeRow($summary, -1, $columns, [], false);
    }

    private function rowKey(float|int|string $label): string
    {
        return get_debug_type($label).':'.$label;
    }

    /**
     * @param  array<string, array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $rows
     * @return list<array{0: array<string, float|int|string|null>, 1: array<string, float|int|string|null>}>
     */
    private function serializableRows(array $rows, int $limit, bool $sortByLabel): array
    {
        $summary = [];

        foreach ($rows as $key => $row) {
            if (in_array($row['columns']['label'] ?? null, [-1, '-1'], true)) {
                $this->mergeRow($summary, -1, $row['columns'], [], false);
                unset($rows[$key]);
            }
        }

        $rows = array_values($rows);
        usort($rows, static function (array $left, array $right) use ($sortByLabel): int {
            if ($sortByLabel) {
                return (int) ($left['columns']['label'] ?? 0)
                    <=> (int) ($right['columns']['label'] ?? 0);
            }

            $visits = (float) ($right['columns']['nb_visits'] ?? 0)
                <=> (float) ($left['columns']['nb_visits'] ?? 0);

            return $visits !== 0
                ? $visits
                : (string) ($left['columns']['label'] ?? '')
                    <=> (string) ($right['columns']['label'] ?? '');
        });

        if (count($rows) + ($summary === [] ? 0 : 1) > $limit) {
            $remainder = array_splice($rows, $limit - 1);

            foreach ($remainder as $row) {
                $this->mergeRow($summary, -1, $row['columns'], [], false);
            }
        }

        if ($summary !== []) {
            $rows[] = array_values($summary)[0];
        }

        return array_map(
            static fn (array $row): array => [$row['columns'], $row['metadata']],
            $rows,
        );
    }

    /**
     * @param  array<string, array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $rows
     */
    private function ensureHours(array &$rows, bool $parent): void
    {
        $emptyMetrics = array_fill_keys(self::METRICS, 0);

        for ($hour = 0; $hour < 24; $hour++) {
            $this->mergeRow($rows, $hour, $emptyMetrics, [], $parent);
        }
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

    private function numeric(mixed $value): int|float
    {
        if (! is_numeric($value)) {
            return 0;
        }

        $number = round((float) $value, 2);

        return floor($number) === $number ? (int) $number : $number;
    }
}
