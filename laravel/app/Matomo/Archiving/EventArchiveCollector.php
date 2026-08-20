<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Reporting\HierarchicalBlobArchiveRepository;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\JoinClause;
use stdClass;

/**
 * @phpstan-type ArchiveValue float|int|string|null
 * @phpstan-type Columns array<string, ArchiveValue>
 * @phpstan-type ColumnRows array<string, Columns>
 * @phpstan-type ReportRow array{columns: Columns, children: ColumnRows}
 * @phpstan-type Report array<string, ReportRow>
 * @phpstan-type Reports array<string, Report>
 * @phpstan-type SourceRow array{
 *     eventCategory: string,
 *     eventAction: string,
 *     eventName: string,
 *     nb_uniq_visitors: int|float,
 *     nb_visits: int|float,
 *     nb_events: int|float,
 *     sum_event_value: int|float,
 *     nb_events_with_value: int|float,
 *     min_event_value: int|float|null,
 *     max_event_value: int|float|null
 * }
 */
final readonly class EventArchiveCollector
{
    private const int ROW_LIMIT = 500;

    private const int RANKING_QUERY_LIMIT = 50_000;

    private const string EVENT_NAME_NOT_SET = 'Piwik_EventNameNotSet';

    private const string RANKING_SUMMARY = '__mtm_ranking_query_others__';

    /** @var array<string, array{string, string}> */
    private const array RECORDS = [
        'Events_category_action' => ['eventCategory', 'eventAction'],
        'Events_category_name' => ['eventCategory', 'eventName'],
        'Events_action_name' => ['eventAction', 'eventName'],
        'Events_action_category' => ['eventAction', 'eventCategory'],
        'Events_name_action' => ['eventName', 'eventAction'],
        'Events_name_category' => ['eventName', 'eventCategory'],
    ];

    /** @var list<string> */
    private const array REPORTS = [
        'Events.getCategory',
        'Events.getAction',
        'Events.getName',
        'Events.getActionFromCategoryId',
        'Events.getNameFromCategoryId',
        'Events.getCategoryFromActionId',
        'Events.getNameFromActionId',
        'Events.getActionFromNameId',
        'Events.getCategoryFromNameId',
    ];

    public function __construct(
        private Connection $connection,
        private ArchiveActionQueryFactory $actionQueries,
        private ReportingSubperiodFactory $subperiods,
        private SegmentHashResolver $segments,
        private HierarchicalBlobArchiveRepository $blobs,
        private SiteRepository $sites,
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

        $reports = $event->period->label === 'day'
            ? $this->dayReports($event, $timezone)
            : $this->parentReports($event);

        foreach (self::RECORDS as $recordName => $_dimensions) {
            foreach ($this->serializedReport($reports[$recordName] ?? []) as $suffix => $blob) {
                $event->records->addBlob($recordName.$suffix, $blob);
            }
        }
    }

    /** @return Reports */
    private function dayReports(ArchiveReportsCollecting $event, string $timezone): array
    {
        $reports = $this->emptyReports();

        if (! $this->columnsExist()) {
            return $reports;
        }

        foreach ($this->eventRows($event, $timezone) as $source) {
            $metrics = $this->metrics($source);

            foreach (self::RECORDS as $recordName => [$mainDimension, $subDimension]) {
                $mainLabel = $this->mainLabel($source[$mainDimension], $mainDimension);
                $rootKey = $this->rowKey($mainLabel);
                $reports[$recordName][$rootKey] ??= [
                    'columns' => ['label' => $mainLabel],
                    'children' => [],
                ];
                $this->mergeMetrics($reports[$recordName][$rootKey]['columns'], $metrics);
                $subLabel = $source[$subDimension];

                if ($subLabel === '' || $subLabel === '0') {
                    continue;
                }

                $subLabel = $this->storedLabel($subLabel);
                $this->mergeColumnRow(
                    $reports[$recordName][$rootKey]['children'],
                    $subLabel,
                    $metrics,
                );
            }
        }

        return $reports;
    }

    /** @return Reports */
    private function parentReports(ArchiveReportsCollecting $event): array
    {
        $reports = $this->emptyReports();
        $children = $this->subperiods->children($event->period);
        $segmentHash = $this->segments->resolve($event->request->segment);

        foreach (array_keys(self::RECORDS) as $recordName) {
            $archives = $this->blobs->records(
                [$event->request->siteId],
                $children,
                $segmentHash,
                $recordName,
                true,
            )[$event->request->siteId] ?? [];

            foreach ($children as $child) {
                $records = $archives[$child->rangeKey()] ?? [];

                foreach ($records[$recordName] ?? [] as $root) {
                    $label = $this->archiveLabel($root['columns']['label'] ?? null);

                    if ($label === null) {
                        continue;
                    }

                    $rootKey = $this->rowKey($label);
                    $reports[$recordName][$rootKey] ??= [
                        'columns' => ['label' => $label],
                        'children' => [],
                    ];
                    $this->mergeMetrics(
                        $reports[$recordName][$rootKey]['columns'],
                        $root['columns'],
                    );
                    $subtableId = $root['subtableId'];

                    if ($subtableId === null) {
                        continue;
                    }

                    foreach ($records[$recordName.'_'.$subtableId] ?? [] as $subrow) {
                        $subLabel = $this->archiveLabel($subrow['columns']['label'] ?? null);

                        if ($subLabel === null) {
                            continue;
                        }

                        $this->mergeColumnRow(
                            $reports[$recordName][$rootKey]['children'],
                            $subLabel,
                            $subrow['columns'],
                        );
                    }
                }
            }
        }

        return $reports;
    }

    /** @return iterable<SourceRow> */
    private function eventRows(ArchiveReportsCollecting $event, string $timezone): iterable
    {
        $query = $this->actionQueries->make($event->request, $event->period, $timezone)
            ->join('log_action as event_category', static function (JoinClause $join): void {
                $join->on(
                    'event_category.idaction',
                    '=',
                    'log_link_visit_action.idaction_event_category',
                );
            })
            ->join('log_action as event_action', static function (JoinClause $join): void {
                $join->on(
                    'event_action.idaction',
                    '=',
                    'log_link_visit_action.idaction_event_action',
                );
            })
            ->join('log_action as event_name', static function (JoinClause $join): void {
                $join->on('event_name.idaction', '=', 'log_link_visit_action.idaction_name');
            })
            ->whereNotNull('log_link_visit_action.idaction_event_category')
            ->addSelect([
                'event_category.name as event_category',
                'event_action.name as event_action',
                'event_name.name as event_name',
            ])
            ->addSelect($this->metricSelect())
            ->groupBy([
                'log_link_visit_action.idaction_event_category',
                'log_link_visit_action.idaction_event_action',
                'log_link_visit_action.idaction_name',
                'event_category.name',
                'event_action.name',
                'event_name.name',
            ])
            ->orderByDesc('nb_visits')
            ->orderBy('event_name.name');
        $summary = null;
        $position = 0;

        foreach ($query->cursor() as $result) {
            $row = $this->sourceRow($result);

            if ($row === null) {
                continue;
            }

            if ($position++ < self::RANKING_QUERY_LIMIT) {
                yield $row;

                continue;
            }

            if ($summary === null) {
                $summary = $row;
                $summary['eventCategory'] = self::RANKING_SUMMARY;
                $summary['eventAction'] = self::RANKING_SUMMARY;
                $summary['eventName'] = self::RANKING_SUMMARY;

                continue;
            }

            $this->mergeRankingMetrics($summary, $row);
        }

        if ($summary !== null) {
            yield $summary;
        }
    }

    private function metricSelect(): TrustedSegmentSqlExpression
    {
        $grammar = $this->connection->getQueryGrammar();
        $idVisitor = $grammar->wrap('log_visit.idvisitor');
        $idVisit = $grammar->wrap('log_link_visit_action.idvisit');
        $value = $grammar->wrap('log_link_visit_action.custom_float');

        return new TrustedSegmentSqlExpression(implode(', ', [
            "COUNT(DISTINCT {$idVisit}) AS nb_visits",
            "COUNT(DISTINCT {$idVisitor}) AS nb_uniq_visitors",
            'COUNT(*) AS nb_events',
            "SUM(CASE WHEN {$value} IS NULL THEN 0 ELSE {$value} END) AS sum_event_value",
            "SUM(CASE WHEN {$value} IS NULL THEN 0 ELSE 1 END) AS nb_events_with_value",
            "MIN({$value}) AS min_event_value",
            "MAX({$value}) AS max_event_value",
        ]));
    }

    /** @return SourceRow|null */
    private function sourceRow(stdClass $result): ?array
    {
        $category = $this->text($result->event_category ?? null);
        $action = $this->text($result->event_action ?? null);
        $name = $this->text($result->event_name ?? null);

        if ($category === null || $action === null || $name === null) {
            return null;
        }

        return [
            'eventCategory' => $category,
            'eventAction' => $action,
            'eventName' => $name,
            'nb_uniq_visitors' => $this->numeric($result->nb_uniq_visitors ?? null),
            'nb_visits' => $this->numeric($result->nb_visits ?? null),
            'nb_events' => $this->numeric($result->nb_events ?? null),
            'sum_event_value' => $this->numeric($result->sum_event_value ?? null),
            'nb_events_with_value' => $this->numeric(
                $result->nb_events_with_value ?? null,
            ),
            'min_event_value' => $this->nullableNumeric($result->min_event_value ?? null),
            'max_event_value' => $this->nullableNumeric($result->max_event_value ?? null),
        ];
    }

    /** @param SourceRow $row
     * @return Columns
     */
    private function metrics(array $row): array
    {
        return [
            'nb_uniq_visitors' => $row['nb_uniq_visitors'],
            'nb_visits' => $row['nb_visits'],
            'nb_events' => $row['nb_events'],
            'nb_events_with_value' => $row['nb_events_with_value'],
            'sum_event_value' => $row['sum_event_value'],
            'min_event_value' => $row['min_event_value'],
            'max_event_value' => $row['max_event_value'],
        ];
    }

    /** @param SourceRow $summary
     * @param  SourceRow  $row
     */
    private function mergeRankingMetrics(array &$summary, array $row): void
    {
        foreach (['nb_visits', 'nb_events', 'sum_event_value', 'nb_events_with_value'] as $metric) {
            $summary[$metric] = $this->numeric($summary[$metric] + $row[$metric]);
        }

        $summary['min_event_value'] = $this->minimum(
            $summary['min_event_value'],
            $row['min_event_value'],
        );
        $summary['max_event_value'] = $this->maximum(
            $summary['max_event_value'],
            $row['max_event_value'],
        );
    }

    /** @param Columns $target
     * @param  Columns  $metrics
     */
    private function mergeMetrics(array &$target, array $metrics): void
    {
        foreach ($metrics as $metric => $value) {
            if ($metric === 'label') {
                continue;
            }

            if ($metric === 'min_event_value') {
                $target[$metric] = $this->minimum($target[$metric] ?? null, $value);

                continue;
            }

            if ($metric === 'max_event_value') {
                $target[$metric] = $this->maximum($target[$metric] ?? null, $value);

                continue;
            }

            if (is_float($value) || is_int($value)) {
                $target[$metric] = $this->numeric(
                    (float) ($target[$metric] ?? 0) + $value,
                );
            }
        }
    }

    /** @param ColumnRows $rows
     * @param  Columns  $metrics
     */
    private function mergeColumnRow(array &$rows, float|int|string $label, array $metrics): void
    {
        $key = $this->rowKey($label);
        $rows[$key] ??= ['label' => $label];
        $this->mergeMetrics($rows[$key], $metrics);
    }

    /**
     * @param  Report  $report
     * @return array<string, string>
     */
    private function serializedReport(array $report): array
    {
        $report = $this->truncateReport($report);
        $root = [];
        $serialized = [];
        $subtableId = 0;

        foreach ($report as $row) {
            $children = $this->truncateColumnRows($row['children']);
            $id = null;

            if ($children !== []) {
                $id = ++$subtableId;
                $serialized['_'.$id] = serialize(array_map(
                    static fn (array $columns): array => [
                        0 => $columns,
                        1 => [],
                        3 => null,
                    ],
                    array_values($children),
                ));
            }

            $root[] = [
                0 => $row['columns'],
                1 => [],
                3 => $id,
            ];
        }

        return ['' => serialize($root), ...$serialized];
    }

    /** @param Report $report
     * @return Report
     */
    private function truncateReport(array $report): array
    {
        if (count($report) <= self::ROW_LIMIT) {
            return $report;
        }

        $summary = null;

        foreach ($report as $key => $row) {
            if ($this->isSummary($row['columns']['label'] ?? null)) {
                $summary = $row['columns'];
                unset($report[$key]);
            }
        }

        uasort($report, $this->reportSorter(...));
        $kept = array_slice($report, 0, self::ROW_LIMIT - 1, true);
        $remainder = array_slice($report, self::ROW_LIMIT - 1, null, true);
        $summary ??= ['label' => -1];

        foreach ($remainder as $row) {
            $this->mergeMetrics($summary, $row['columns']);
        }

        $kept[$this->rowKey(-1)] = ['columns' => $summary, 'children' => []];

        return $kept;
    }

    /** @param ColumnRows $rows
     * @return ColumnRows
     */
    private function truncateColumnRows(array $rows): array
    {
        if (count($rows) <= self::ROW_LIMIT) {
            return $rows;
        }

        $summary = null;

        foreach ($rows as $key => $row) {
            if ($this->isSummary($row['label'] ?? null)) {
                $summary = $row;
                unset($rows[$key]);
            }
        }

        uasort($rows, $this->columnSorter(...));
        $kept = array_slice($rows, 0, self::ROW_LIMIT - 1, true);
        $remainder = array_slice($rows, self::ROW_LIMIT - 1, null, true);
        $summary ??= ['label' => -1];

        foreach ($remainder as $row) {
            $this->mergeMetrics($summary, $row);
        }

        $kept[$this->rowKey(-1)] = $summary;

        return $kept;
    }

    /** @param ReportRow $left
     * @param  ReportRow  $right
     */
    private function reportSorter(array $left, array $right): int
    {
        return $this->columnsSorter($left['columns'], $right['columns']);
    }

    /** @param Columns $left
     * @param  Columns  $right
     */
    private function columnSorter(array $left, array $right): int
    {
        return $this->columnsSorter($left, $right);
    }

    /** @param Columns $left
     * @param  Columns  $right
     */
    private function columnsSorter(array $left, array $right): int
    {
        $visits = (float) ($right['nb_visits'] ?? 0) <=> (float) ($left['nb_visits'] ?? 0);

        return $visits !== 0
            ? $visits
            : strnatcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
    }

    /** @return Reports */
    private function emptyReports(): array
    {
        return array_fill_keys(array_keys(self::RECORDS), []);
    }

    private function requested(ArchiveReportsCollecting $event): bool
    {
        if ($event->request->plugin !== null) {
            return $event->request->plugin === 'Events';
        }

        return $event->request->reports === []
            || array_intersect($event->request->reports, self::REPORTS) !== [];
    }

    private function columnsExist(): bool
    {
        return $this->connection->getSchemaBuilder()->hasColumns('log_link_visit_action', [
            'idsite',
            'idvisit',
            'idaction_name',
            'idaction_event_category',
            'idaction_event_action',
            'custom_float',
            'server_time',
        ]) && $this->connection->getSchemaBuilder()->hasColumns('log_action', [
            'idaction',
            'name',
        ]) && $this->connection->getSchemaBuilder()->hasColumns('log_visit', [
            'idsite',
            'idvisit',
            'idvisitor',
        ]);
    }

    private function mainLabel(string $label, string $dimension): int|string
    {
        if ($label === self::RANKING_SUMMARY) {
            return -1;
        }

        if ($dimension === 'eventName' && ($label === '' || $label === '0')) {
            return self::EVENT_NAME_NOT_SET;
        }

        return $label;
    }

    private function storedLabel(string $label): int|string
    {
        return $label === self::RANKING_SUMMARY ? -1 : $label;
    }

    private function archiveLabel(mixed $label): float|int|string|null
    {
        return is_float($label) || is_int($label) || is_string($label) ? $label : null;
    }

    private function isSummary(mixed $label): bool
    {
        return in_array($label, [-1, '-1'], true);
    }

    private function rowKey(float|int|string $label): string
    {
        return get_debug_type($label).':'.$label;
    }

    private function text(mixed $value): ?string
    {
        return is_float($value) || is_int($value) || is_string($value)
            ? (string) $value
            : null;
    }

    private function numeric(mixed $value): int|float
    {
        if (! is_numeric($value)) {
            return 0;
        }

        $number = round((float) $value, 2);

        return floor($number) === $number ? (int) $number : $number;
    }

    private function nullableNumeric(mixed $value): int|float|null
    {
        return is_numeric($value) ? $this->numeric($value) : null;
    }

    private function minimum(mixed $left, mixed $right): int|float|null
    {
        $left = $this->nullableNumeric($left);
        $right = $this->nullableNumeric($right);

        if ($left === null) {
            return $right;
        }

        return $right === null ? $left : min($left, $right);
    }

    private function maximum(mixed $left, mixed $right): int|float|null
    {
        $left = $this->nullableNumeric($left);
        $right = $this->nullableNumeric($right);

        if ($left === null) {
            return $right;
        }

        return $right === null ? $left : max($left, $right);
    }
}
