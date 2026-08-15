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
            foreach ($reports[$recordName]->serialized(
                self::ROW_LIMIT,
                self::ROW_LIMIT,
                'nb_visits',
            ) as $suffix => $blob) {
                $event->records->addBlob($recordName.$suffix, $blob);
            }
        }
    }

    /** @return array<string, HierarchicalArchiveTable> */
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
                $reports[$recordName]->mergeRoot($mainLabel, $metrics);
                $subLabel = $source[$subDimension];

                if ($subLabel === '' || $subLabel === '0') {
                    continue;
                }

                $subLabel = $this->storedLabel($subLabel);
                $reports[$recordName]->mergeChild(
                    $mainLabel,
                    $subLabel,
                    $metrics,
                );
            }
        }

        return $reports;
    }

    /** @return array<string, HierarchicalArchiveTable> */
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

                    $reports[$recordName]->mergeRoot($label, $root['columns']);
                    $subtableId = $root['subtableId'];

                    if ($subtableId === null) {
                        continue;
                    }

                    foreach ($records[$recordName.'_'.$subtableId] ?? [] as $subrow) {
                        $subLabel = $this->archiveLabel($subrow['columns']['label'] ?? null);

                        if ($subLabel === null) {
                            continue;
                        }

                        $reports[$recordName]->mergeChild(
                            $label,
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
     * @return array<string, float|int|null>
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

    /** @return array<string, HierarchicalArchiveTable> */
    private function emptyReports(): array
    {
        $reports = [];

        foreach (array_keys(self::RECORDS) as $recordName) {
            $reports[$recordName] = new HierarchicalArchiveTable([
                'min_event_value' => 'min',
                'max_event_value' => 'max',
            ]);
        }

        return $reports;
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
