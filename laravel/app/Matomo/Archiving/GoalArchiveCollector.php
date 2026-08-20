<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Goals\GoalRepository;
use App\Matomo\Reporting\BatchBlobArchiveRepository;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use stdClass;

final readonly class GoalArchiveCollector
{
    private const int CART_GOAL = -1;

    private const int ORDER_GOAL = 0;

    /** @var list<string> */
    private const array REPORTS = [
        'Goals.get',
        'Goals.getMetrics',
        'Goals.getItemsSku',
        'Goals.getItemsName',
        'Goals.getItemsCategory',
        'Goals.getDaysToConversion',
        'Goals.getVisitsUntilConversion',
    ];

    /** @var list<array{int, int}|array{int}> */
    private const array VISIT_RANGES = [
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
        [100],
    ];

    /** @var list<array{int, int}|array{int}> */
    private const array DAY_RANGES = [
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

    /** @var list<string> */
    private const array BASE_METRICS = [
        'nb_conversions',
        'nb_visits_converted',
        'revenue',
    ];

    /** @var list<string> */
    private const array ORDER_METRICS = [
        'revenue_subtotal',
        'revenue_tax',
        'revenue_shipping',
        'revenue_discount',
        'items',
    ];

    public function __construct(
        private Connection $connection,
        private ArchiveConversionQueryFactory $conversionQueries,
        private ReportingSubperiodFactory $subperiods,
        private SegmentHashResolver $segments,
        private BatchBlobArchiveRepository $blobs,
        private NumericArchiveRepository $numbers,
        private GoalRepository $goals,
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

        $goalIds = $this->goalIds($event->request->siteId);
        [$numeric, $ranges] = $event->period->label === 'day'
            ? $this->dayRecords(
                $event,
                $timezone,
                $goalIds,
                $this->hasGoalsOrEcommerce($event->request->siteId, $goalIds),
            )
            : $this->parentRecords($event, $goalIds);

        foreach ($numeric as $recordName => $value) {
            $event->records->addNumeric($recordName, $value);
        }

        foreach ($ranges as $recordName => $rows) {
            $event->records->addBlob($recordName, serialize($rows));
        }
    }

    /**
     * @param  list<int>  $configuredGoalIds
     * @return array{
     *     array<string, int|float>,
     *     array<string, list<array{0: array{label: string, nb_conversions: int|float}, 1: array{}}>>
     * }
     */
    private function dayRecords(
        ArchiveReportsCollecting $event,
        string $timezone,
        array $configuredGoalIds,
        bool $collectConversions,
    ): array {
        $results = $collectConversions ? $this->conversionRows($event, $timezone) : [];
        $goalIds = $configuredGoalIds;

        foreach ($results as $result) {
            $goalIds[] = (int) ($result->idgoal ?? 0);
        }

        $goalIds = $this->uniqueGoalIds($goalIds);
        $numeric = $this->emptyNumericRecords($goalIds);
        $ranges = $this->emptyRangeRecords($goalIds);
        $overviewVisits = [];
        $overviewDays = [];

        foreach ($results as $result) {
            $goalId = (int) ($result->idgoal ?? 0);

            foreach ($this->metricsForGoal($goalId) as $metric) {
                $numeric[$this->goalRecord($metric, $goalId)] = $this->numeric(
                    $result->{$metric} ?? null,
                );
            }

            if ($goalId !== self::CART_GOAL) {
                $numeric[$this->goalRecord('nb_conversions')] += $this->numeric(
                    $result->nb_conversions ?? null,
                );
                $numeric[$this->goalRecord('revenue')] += $this->numeric(
                    $result->revenue ?? null,
                );
            }

            $visits = $this->rangeRows($result, 'visits', self::VISIT_RANGES);
            $days = $this->rangeRows($result, 'days', self::DAY_RANGES);
            $ranges[$this->goalRecord('visits_until_conv', $goalId)] = $visits;
            $ranges[$this->goalRecord('days_until_conv', $goalId)] = $days;

            if ($goalId !== self::CART_GOAL && $goalId !== self::ORDER_GOAL) {
                $this->mergeRanges($overviewVisits, $visits);
                $this->mergeRanges($overviewDays, $days);
            }
        }

        $numeric[$this->goalRecord('nb_visits_converted')] = $event->coreMetrics[
            'nb_visits_converted'
        ] ?? 0;
        $ranges[$this->goalRecord('visits_until_conv')] = array_values($overviewVisits);
        $ranges[$this->goalRecord('days_until_conv')] = array_values($overviewDays);

        return [$numeric, $ranges];
    }

    /**
     * @param  list<int>  $goalIds
     * @return array{
     *     array<string, int|float>,
     *     array<string, list<array{0: array{label: string, nb_conversions: int|float}, 1: array{}}>>
     * }
     */
    private function parentRecords(ArchiveReportsCollecting $event, array $goalIds): array
    {
        $children = $this->subperiods->children($event->period);
        $segmentHash = $this->segments->resolve($event->request->segment);
        $numeric = $this->emptyNumericRecords($goalIds);
        $metricNames = array_keys($numeric);
        $archives = $this->numbers->pluginMetrics(
            [$event->request->siteId],
            $children,
            $segmentHash,
            $metricNames,
            'Goals',
        )[$event->request->siteId] ?? [];

        foreach ($children as $child) {
            foreach ($archives[$child->rangeKey()] ?? [] as $recordName => $value) {
                if (isset($numeric[$recordName])) {
                    $numeric[$recordName] += $value;
                }
            }
        }

        $numeric[$this->goalRecord('nb_visits_converted')] = $event->coreMetrics[
            'nb_visits_converted'
        ] ?? 0;
        $ranges = [];
        $rangeRecordNames = $this->rangeRecordNames($goalIds);
        $archives = $this->blobs->rowsForRecords(
            [$event->request->siteId],
            $children,
            $segmentHash,
            $rangeRecordNames,
        )[$event->request->siteId] ?? [];

        foreach ($rangeRecordNames as $recordName) {
            $rows = [];

            foreach ($children as $child) {
                $this->mergeRanges($rows, $this->storedRanges(
                    $archives[$child->rangeKey()][$recordName] ?? [],
                ));
            }

            $ranges[$recordName] = array_values($rows);
        }

        return [$numeric, $ranges];
    }

    /** @return list<stdClass> */
    private function conversionRows(ArchiveReportsCollecting $event, string $timezone): array
    {
        if (! $this->conversionColumnsExist()) {
            return [];
        }

        $query = $this->conversionQueries->make($event->request, $event->period, $timezone)
            ->addSelect('log_conversion.idgoal')
            ->selectRaw($this->metricSelect())
            ->addSelect($this->conversionVisitCountSelect());
        $this->addRangeSelects($query, 'visitor_count_visits', 'visits', self::VISIT_RANGES);
        $this->addRangeSelects(
            $query,
            'visitor_seconds_since_first',
            'days',
            self::DAY_RANGES,
            86_400,
        );

        return array_values($query
            ->groupBy('log_conversion.idgoal')
            ->orderBy('log_conversion.idgoal')
            ->get()
            ->all());
    }

    /** @return literal-string */
    private function metricSelect(): string
    {
        return implode(', ', [
            'COUNT(*) AS nb_conversions',
            $this->boundedRevenueSelect('revenue'),
            $this->boundedRevenueSelect('revenue_subtotal'),
            $this->boundedRevenueSelect('revenue_tax'),
            $this->boundedRevenueSelect('revenue_shipping'),
            $this->boundedRevenueSelect('revenue_discount'),
            'COALESCE(SUM(items), 0) AS items',
        ]);
    }

    private function conversionVisitCountSelect(): TrustedSegmentSqlExpression
    {
        $idVisit = $this->connection->getQueryGrammar()->wrap('log_conversion.idvisit');

        return new TrustedSegmentSqlExpression(
            "COUNT(DISTINCT {$idVisit}) AS nb_visits_converted",
        );
    }

    /** @return literal-string */
    private function boundedRevenueSelect(string $column): string
    {
        return match ($column) {
            'revenue' => 'ROUND(SUM(CASE WHEN ABS(revenue) > 1000000000000 THEN 0 ELSE COALESCE(revenue, 0) END), 2) AS revenue',
            'revenue_subtotal' => 'ROUND(SUM(CASE WHEN ABS(revenue_subtotal) > 1000000000000 THEN 0 ELSE COALESCE(revenue_subtotal, 0) END), 2) AS revenue_subtotal',
            'revenue_tax' => 'ROUND(SUM(CASE WHEN ABS(revenue_tax) > 1000000000000 THEN 0 ELSE COALESCE(revenue_tax, 0) END), 2) AS revenue_tax',
            'revenue_shipping' => 'ROUND(SUM(CASE WHEN ABS(revenue_shipping) > 1000000000000 THEN 0 ELSE COALESCE(revenue_shipping, 0) END), 2) AS revenue_shipping',
            'revenue_discount' => 'ROUND(SUM(CASE WHEN ABS(revenue_discount) > 1000000000000 THEN 0 ELSE COALESCE(revenue_discount, 0) END), 2) AS revenue_discount',
            default => throw new InvalidArgumentException(
                "The conversion revenue column '{$column}' is not supported.",
            ),
        };
    }

    /**
     * @param  list<array{int, int}|array{int}>  $ranges
     */
    private function addRangeSelects(
        Builder $query,
        string $column,
        string $prefix,
        array $ranges,
        int $divisor = 1,
    ): void {
        $column = match ($column) {
            'visitor_count_visits' => $this->connection->getQueryGrammar()->wrap(
                'log_conversion.visitor_count_visits',
            ),
            'visitor_seconds_since_first' => $this->connection->getQueryGrammar()->wrap(
                'log_conversion.visitor_seconds_since_first',
            ),
            default => throw new InvalidArgumentException(
                "The conversion range column '{$column}' is not supported.",
            ),
        };
        $expression = $divisor === 1
            ? $column
            : 'FLOOR('.$column.' / '.$divisor.')';

        foreach ($ranges as $index => $range) {
            $condition = count($range) === 2
                ? $expression.' BETWEEN '.$range[0].' AND '.$range[1]
                : $expression.' > '.$range[0];
            $query->addSelect(new TrustedSegmentSqlExpression(
                'COALESCE(SUM(CASE WHEN '.$condition.' THEN 1 ELSE 0 END), 0)'.
                ' AS '.$prefix.'_'.$index,
            ));
        }
    }

    /**
     * @param  list<array{int, int}|array{int}>  $ranges
     * @return list<array{0: array{label: string, nb_conversions: int|float}, 1: array{}}>
     */
    private function rangeRows(stdClass $result, string $prefix, array $ranges): array
    {
        $rows = [];

        foreach ($ranges as $index => $range) {
            $rows[] = [[
                'label' => $this->rangeLabel($range),
                'nb_conversions' => $this->numeric($result->{$prefix.'_'.$index} ?? null),
            ], []];
        }

        return $rows;
    }

    /**
     * @param  array<string, array{0: array{label: string, nb_conversions: int|float}, 1: array{}}>  $target
     * @param  list<array{0: array{label: string, nb_conversions: int|float}, 1: array{}}>  $source
     */
    private function mergeRanges(array &$target, array $source): void
    {
        foreach ($source as $row) {
            $label = $row[0]['label'];
            $target[$label] ??= [['label' => $label, 'nb_conversions' => 0], []];
            $target[$label][0]['nb_conversions'] += $row[0]['nb_conversions'];
        }
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $rows
     * @return list<array{0: array{label: string, nb_conversions: int|float}, 1: array{}}>
     */
    private function storedRanges(array $rows): array
    {
        $ranges = [];

        foreach ($rows as $row) {
            $label = $row['columns']['label'] ?? null;
            $conversions = $row['columns']['nb_conversions'] ?? null;

            if (! is_string($label) || (! is_float($conversions) && ! is_int($conversions))) {
                continue;
            }

            $ranges[] = [[
                'label' => $label,
                'nb_conversions' => $conversions,
            ], []];
        }

        return $ranges;
    }

    /** @param list<int> $goalIds
     * @return array<string, int|float>
     */
    private function emptyNumericRecords(array $goalIds): array
    {
        $records = array_fill_keys(array_map(
            fn (string $metric): string => $this->goalRecord($metric),
            self::BASE_METRICS,
        ), 0);

        foreach ($goalIds as $goalId) {
            foreach ($this->metricsForGoal($goalId) as $metric) {
                $records[$this->goalRecord($metric, $goalId)] = 0;
            }
        }

        return $records;
    }

    /**
     * @param  list<int>  $goalIds
     * @return array<string, list<array{0: array{label: string, nb_conversions: int|float}, 1: array{}}>>
     */
    private function emptyRangeRecords(array $goalIds): array
    {
        return array_fill_keys($this->rangeRecordNames($goalIds), []);
    }

    /** @param list<int> $goalIds
     * @return list<string>
     */
    private function rangeRecordNames(array $goalIds): array
    {
        $records = [
            $this->goalRecord('visits_until_conv'),
            $this->goalRecord('days_until_conv'),
        ];

        foreach ($goalIds as $goalId) {
            $records[] = $this->goalRecord('visits_until_conv', $goalId);
            $records[] = $this->goalRecord('days_until_conv', $goalId);
        }

        return $records;
    }

    /** @return list<int> */
    private function goalIds(int $siteId): array
    {
        $ids = [self::CART_GOAL, self::ORDER_GOAL];

        foreach ($this->goals->activeForSites([$siteId]) as $goal) {
            if (is_numeric($goal['idgoal'] ?? null)) {
                $ids[] = (int) $goal['idgoal'];
            }
        }

        return $this->uniqueGoalIds($ids);
    }

    /** @param list<int> $goalIds */
    private function hasGoalsOrEcommerce(int $siteId, array $goalIds): bool
    {
        foreach ($goalIds as $goalId) {
            if ($goalId !== self::CART_GOAL && $goalId !== self::ORDER_GOAL) {
                return true;
            }
        }

        return (int) ($this->sites->details($siteId)['ecommerce'] ?? 0) === 1;
    }

    /** @param list<int> $goalIds
     * @return list<int>
     */
    private function uniqueGoalIds(array $goalIds): array
    {
        $goalIds = array_values(array_unique($goalIds));
        sort($goalIds);

        return $goalIds;
    }

    /** @return list<string> */
    private function metricsForGoal(int $goalId): array
    {
        if ($goalId === self::ORDER_GOAL) {
            return [...self::BASE_METRICS, ...self::ORDER_METRICS];
        }

        if ($goalId === self::CART_GOAL) {
            return [...self::BASE_METRICS, 'items'];
        }

        return self::BASE_METRICS;
    }

    /** @param array{int, int}|array{int} $range */
    private function rangeLabel(array $range): string
    {
        return count($range) === 2
            ? $range[0].'-'.$range[1]
            : ($range[0] + 1).'%2B';
    }

    private function goalRecord(string $metric, ?int $goalId = null): string
    {
        return 'Goal_'.($goalId === null ? '' : $goalId.'_').$metric;
    }

    private function requested(ArchiveReportsCollecting $event): bool
    {
        if ($event->request->plugin !== null) {
            return $event->request->plugin === 'Goals';
        }

        return $event->request->reports === []
            || array_intersect($event->request->reports, self::REPORTS) !== [];
    }

    private function conversionColumnsExist(): bool
    {
        return $this->connection->getSchemaBuilder()->hasColumns('log_conversion', [
            'idgoal',
            'idvisit',
            'server_time',
            'revenue',
            'revenue_subtotal',
            'revenue_tax',
            'revenue_shipping',
            'revenue_discount',
            'items',
            'visitor_count_visits',
            'visitor_seconds_since_first',
        ]);
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
