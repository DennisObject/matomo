<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiReport;
use App\Matomo\Api\ApiTableReport;
use App\Matomo\Goals\GoalRepository;
use App\Matomo\Localization\MatomoTranslator;

final readonly class GoalsReportBuilder
{
    private const int ECOMMERCE_CART_GOAL = -1;

    private const int ECOMMERCE_ORDER_GOAL = 0;

    /** @var array<string, array{string, string}> */
    private const array ITEM_REPORTS = [
        'Goals.getItemsSku' => ['Goals_ItemsSku', 'productSku'],
        'Goals.getItemsName' => ['Goals_ItemsName', 'productName'],
        'Goals.getItemsCategory' => ['Goals_ItemsCategory', 'productCategory'],
    ];

    /** @var array<string, array{string, string, string}> */
    private const array RANGE_REPORTS = [
        'Goals.getDaysToConversion' => ['days_until_conv', 'Intl_OneDay', 'Intl_NDays'],
        'Goals.getVisitsUntilConversion' => ['visits_until_conv', 'General_OneVisit', 'General_NVisits'],
    ];

    /** @var list<string> */
    private const array BASE_METRICS = ['nb_conversions', 'nb_visits_converted', 'revenue'];

    public function __construct(
        private BlobArchiveRepository $blobs,
        private NumericArchiveRepository $numbers,
        private SegmentHashResolver $segments,
        private GoalRepository $goals,
        private MatomoTranslator $translator,
    ) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function items(
        string $method,
        bool $abandonedCarts,
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $language,
        bool $showMetadata,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiTableReport {
        [$baseRecord, $segmentName] = self::ITEM_REPORTS[$method];
        $recordName = $baseRecord.($abandonedCarts ? '_Cart' : '');
        $archiveRows = $this->blobs->rows($siteIds, $periods, $segmentHash, $recordName);

        return $this->tableReport(
            $archiveRows,
            $siteIds,
            $periods,
            $forceSiteIndex,
            $forceDateIndex,
            fn (array $rows): array => $this->itemRows(
                $rows,
                $baseRecord,
                $segmentName,
                $abandonedCarts,
                $language,
                $showMetadata,
            ),
        );
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function ranges(
        string $method,
        int|string|null $idGoal,
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $language,
        bool $showMetadata,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiTableReport {
        [$baseRecord, $singularKey, $pluralKey] = self::RANGE_REPORTS[$method];
        $recordName = $this->goalRecord($baseRecord, $this->goalId($idGoal));
        $archiveRows = $this->blobs->rows($siteIds, $periods, $segmentHash, $recordName);

        return $this->tableReport(
            $archiveRows,
            $siteIds,
            $periods,
            $forceSiteIndex,
            $forceDateIndex,
            fn (array $rows): array => $this->rangeRows(
                $rows,
                $singularKey,
                $pluralKey,
                $language,
                $showMetadata,
            ),
        );
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @param  list<string>|null  $requestedColumns
     */
    public function metrics(
        bool $withVisitorTypes,
        array $siteIds,
        array $periods,
        ?string $segment,
        int|string|null $idGoal,
        ?array $requestedColumns,
        bool $showAllGoalSpecificMetrics,
        bool $formatMetrics,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiReport {
        $goalId = $this->goalId($idGoal);
        $goalIds = $showAllGoalSpecificMetrics && $goalId === null
            ? $this->activeGoalIds($siteIds)
            : [];
        $definitions = $this->metricDefinitions($goalId, $goalIds);
        $columns = $this->columnsToShow(
            $goalId,
            $goalIds,
            $requestedColumns,
            $showAllGoalSpecificMetrics,
        );
        $records = $this->requiredRecords($definitions, $columns);
        $segments = $withVisitorTypes ? [
            '' => null,
            '_new_visit' => 'visitorType==new',
            '_returning_visit' => 'visitorType==returning,visitorType==returningCustomer',
        ] : ['' => null];
        $data = [];
        $dimensions = [
            ...($forceSiteIndex ? ['idSite'] : []),
            ...($forceDateIndex ? ['date'] : []),
        ];

        foreach ($segments as $suffix => $visitorSegment) {
            $combinedSegment = $this->combinedSegment($segment, $visitorSegment);
            $archiveRows = $this->numbers->pluginMetrics(
                $siteIds,
                $periods,
                $this->segments->resolve($combinedSegment),
                $records,
                'Goals',
            );
            $segmentData = $this->metricData(
                $archiveRows,
                $siteIds,
                $periods,
                $definitions,
                $columns,
                $formatMetrics,
                $forceSiteIndex,
                $forceDateIndex,
            );
            $data = $this->mergeMetrics($data, $segmentData, $dimensions, (string) $suffix, 0);
        }

        return new ApiReport($data, $dimensions);
    }

    /**
     * @param  array<int, array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>>>  $archiveRows
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @param  callable(list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>): list<array<string, float|int|string|null>>  $rows
     */
    private function tableReport(
        array $archiveRows,
        array $siteIds,
        array $periods,
        bool $forceSiteIndex,
        bool $forceDateIndex,
        callable $rows,
    ): ApiTableReport {
        $dimensions = [
            ...($forceSiteIndex ? ['idSite'] : []),
            ...($forceDateIndex ? ['date'] : []),
        ];

        if (! $forceSiteIndex) {
            $siteId = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $key = ($periods[0] ?? null)?->rangeKey();

                return new ApiTableReport($rows(
                    $key === null ? [] : ($archiveRows[$siteId][$key] ?? []),
                ), []);
            }

            return new ApiTableReport($this->tableDateRows(
                $archiveRows[$siteId] ?? [],
                $periods,
                $rows,
            ), $dimensions);
        }

        $data = [];

        foreach ($siteIds as $siteId) {
            if ($forceDateIndex) {
                $data[$siteId] = $this->tableDateRows(
                    $archiveRows[$siteId] ?? [],
                    $periods,
                    $rows,
                );
            } else {
                $key = ($periods[0] ?? null)?->rangeKey();
                $data[$siteId] = $rows(
                    $key === null ? [] : ($archiveRows[$siteId][$key] ?? []),
                );
            }
        }

        return new ApiTableReport($data, $dimensions);
    }

    /**
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>>  $archiveRows
     * @param  list<ReportingPeriod>  $periods
     * @param  callable(list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>): list<array<string, float|int|string|null>>  $rows
     * @return array<string, list<array<string, float|int|string|null>>>
     */
    private function tableDateRows(array $archiveRows, array $periods, callable $rows): array
    {
        $result = [];

        foreach ($periods as $period) {
            $result[$period->resultKey] = $rows($archiveRows[$period->rangeKey()] ?? []);
        }

        return $result;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $archiveRows
     * @return list<array<string, float|int|string|null>>
     */
    private function itemRows(
        array $archiveRows,
        string $recordName,
        string $segmentName,
        bool $abandonedCarts,
        string $language,
        bool $showMetadata,
    ): array {
        $totals = [];

        foreach (['nb_visits', 'nb_actions', 'revenue'] as $metric) {
            $totals[$metric] = array_sum(array_map(
                static fn (array $row): float => is_numeric($row['columns'][$metric] ?? null)
                    ? (float) $row['columns'][$metric]
                    : 0,
                $archiveRows,
            ));
        }

        $rows = [];

        foreach ($archiveRows as $archiveRow) {
            $row = $archiveRow['columns'];
            $rawLabel = $row['label'] ?? '';
            $orders = is_numeric($row['orders'] ?? null) ? (float) $row['orders'] : 0;
            $price = is_numeric($row['price'] ?? null) ? (float) $row['price'] : 0.0;
            $quantity = is_numeric($row['quantity'] ?? null) ? (float) $row['quantity'] : 0;
            $visits = is_numeric($row['nb_visits'] ?? null) ? (float) $row['nb_visits'] : 0;
            $viewPrice = is_numeric($row['avg_price_viewed'] ?? null)
                ? (float) $row['avg_price_viewed']
                : (is_numeric($row['price_viewed'] ?? null) ? (float) $row['price_viewed'] : 0);

            unset($row['price'], $row['avg_price_viewed'], $row['price_viewed']);

            if ($abandonedCarts && array_key_exists('orders', $row)) {
                unset($row['orders']);
                $row['abandoned_carts'] = $this->number($orders);
            }

            if ($showMetadata) {
                $row = [...$row, ...$archiveRow['metadata']];
            }

            foreach (['nb_visits', 'nb_actions', 'revenue'] as $metric) {
                $value = $row[$metric] ?? null;

                if (is_float($value) || is_int($value)) {
                    $row[$metric.'_percent_of_total'] = $this->percent(
                        $value,
                        $totals[$metric],
                        1,
                    );
                }
            }

            $row['avg_price'] = $price === 0.0
                ? $this->number($viewPrice)
                : $this->number($orders === 0.0 ? 0 : round($price / $orders, 2));
            $row['avg_quantity'] = $this->number(
                $orders === 0.0 ? 0 : round($quantity / $orders, 1),
            );
            $row['conversion_rate'] = $this->percent($orders, $visits, 2);

            if ($rawLabel === -1 || $rawLabel === '-1') {
                $row['label'] = $this->translator->translate('General_Others', $language);
            } elseif ($rawLabel === 'Value not defined') {
                $productKey = match ($recordName) {
                    'Goals_ItemsSku' => 'Goals_ProductSKU',
                    'Goals_ItemsName' => 'Goals_ProductName',
                    default => 'Goals_ProductCategory',
                };
                $row['label'] = $this->translator->translate(
                    'General_NotDefined',
                    $language,
                    [$this->translator->translate($productKey, $language)],
                );
            }

            if ($showMetadata && is_string($rawLabel) && $rawLabel !== 'Value not defined') {
                $row['segment'] = $segmentName.'=='.urlencode($rawLabel);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $archiveRows
     * @return list<array<string, float|int|string|null>>
     */
    private function rangeRows(
        array $archiveRows,
        string $singularKey,
        string $pluralKey,
        string $language,
        bool $showMetadata,
    ): array {
        usort($archiveRows, static fn (array $left, array $right): int => (int) urldecode(
            (string) ($left['columns']['label'] ?? 0),
        ) <=> (int) urldecode((string) ($right['columns']['label'] ?? 0)));
        $total = array_sum(array_map(
            static fn (array $row): float => is_numeric($row['columns']['nb_conversions'] ?? null)
                ? (float) $row['columns']['nb_conversions']
                : 0,
            $archiveRows,
        ));
        $rows = [];

        foreach ($archiveRows as $archiveRow) {
            $row = $archiveRow['columns'];
            $conversions = $row['nb_conversions'] ?? null;

            if ($showMetadata) {
                $row = [...$row, ...$archiveRow['metadata']];
            }

            if (is_float($conversions) || is_int($conversions)) {
                $row['nb_conversions_percent_of_total'] = $this->percent($conversions, $total, 1);
            }

            $row['label'] = $this->rangeLabel(
                (string) ($row['label'] ?? ''),
                $singularKey,
                $pluralKey,
                $language,
            );
            $rows[] = $row;
        }

        return $rows;
    }

    private function rangeLabel(
        string $label,
        string $singularKey,
        string $pluralKey,
        string $language,
    ): string {
        $label = urldecode($label);

        if (preg_match('/^(\d+)\s*-\s*(\d+)$/D', $label, $matches) === 1) {
            $lower = (int) $matches[1];
            $upper = (int) $matches[2];

            if ($lower === 1 && $upper === 1) {
                return $this->translator->translate($singularKey, $language);
            }

            $value = $lower === $upper ? (string) $lower : $lower.'-'.$upper;

            return $this->translator->translate($pluralKey, $language, [$value]);
        }

        if (preg_match('/^(\d+)/D', $label, $matches) === 1) {
            return $this->translator->translate($pluralKey, $language, [$matches[1].'+']);
        }

        return $this->translator->translate(trim($label), $language);
    }

    /** @param list<int> $siteIds
     * @return list<int>
     */
    private function activeGoalIds(array $siteIds): array
    {
        $ids = [];

        foreach ($this->goals->activeForSites($siteIds) as $goal) {
            if (is_numeric($goal['idgoal'] ?? null)) {
                $ids[] = (int) $goal['idgoal'];
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $goalIds
     * @return array<string, string>
     */
    private function metricDefinitions(int|string|null $idGoal, array $goalIds): array
    {
        $definitions = [];

        foreach ($this->metricsForGoal($idGoal) as $metric) {
            $definitions[$metric] = $this->goalRecord($metric, $idGoal);
        }

        if ($goalIds !== []) {
            $definitions = ['nb_visits' => 'nb_visits', ...$definitions];

            foreach ($goalIds as $goalId) {
                foreach ($this->metricsForGoal($goalId) as $metric) {
                    $definitions['goal_'.$goalId.'_'.$metric] = $this->goalRecord($metric, $goalId);
                }
            }
        }

        return $definitions;
    }

    /** @return list<string> */
    private function metricsForGoal(int|string|null $idGoal): array
    {
        $metrics = self::BASE_METRICS;

        if ($idGoal === self::ECOMMERCE_ORDER_GOAL) {
            $metrics = [
                ...$metrics,
                'revenue_subtotal',
                'revenue_tax',
                'revenue_shipping',
                'revenue_discount',
            ];
        }

        if ($idGoal === self::ECOMMERCE_ORDER_GOAL || $idGoal === self::ECOMMERCE_CART_GOAL) {
            $metrics[] = 'items';
        }

        return $metrics;
    }

    /**
     * @param  list<int>  $goalIds
     * @param  list<string>|null  $requestedColumns
     * @return list<string>
     */
    private function columnsToShow(
        int|string|null $idGoal,
        array $goalIds,
        ?array $requestedColumns,
        bool $showAllGoalSpecificMetrics,
    ): array {
        if ($requestedColumns !== null) {
            return $requestedColumns;
        }

        $columns = $showAllGoalSpecificMetrics && $idGoal === null ? ['nb_visits'] : [];
        $columns = [...$columns, ...$this->metricsForGoal($idGoal)];

        foreach ($goalIds as $goalId) {
            foreach ($this->metricsForGoal($goalId) as $metric) {
                $columns[] = 'goal_'.$goalId.'_'.$metric;
            }
        }

        foreach ($goalIds as $goalId) {
            $columns[] = 'goal_'.$goalId.'_conversion_rate';
        }

        $columns[] = 'conversion_rate';

        if ($idGoal === self::ECOMMERCE_ORDER_GOAL || $idGoal === self::ECOMMERCE_CART_GOAL) {
            $columns[] = 'avg_order_revenue';
        }

        return $columns;
    }

    /**
     * @param  array<string, string>  $definitions
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function requiredRecords(array $definitions, array $columns): array
    {
        $requiredColumns = $columns;

        foreach ($columns as $column) {
            if ($column === 'conversion_rate') {
                $requiredColumns = [...$requiredColumns, 'nb_visits', 'nb_visits_converted'];
            } elseif ($column === 'avg_order_revenue') {
                $requiredColumns = [...$requiredColumns, 'revenue', 'nb_conversions'];
            } elseif (preg_match('/^(goal_-?[0-9]+)_conversion_rate$/D', $column, $matches) === 1) {
                $requiredColumns = [
                    ...$requiredColumns,
                    'nb_visits',
                    $matches[1].'_nb_visits_converted',
                ];
            }
        }

        $records = [];

        foreach (array_values(array_unique($requiredColumns)) as $column) {
            if ($column === 'nb_visits') {
                $records[] = 'nb_visits';
            } elseif (isset($definitions[$column])) {
                $records[] = $definitions[$column];
            }
        }

        return array_values(array_unique($records));
    }

    /**
     * @param  array<int, array<string, array<string, int|float>>>  $archiveRows
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @param  array<string, string>  $definitions
     * @param  list<string>  $columns
     * @return array<array-key, mixed>
     */
    private function metricData(
        array $archiveRows,
        array $siteIds,
        array $periods,
        array $definitions,
        array $columns,
        bool $formatMetrics,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): array {
        if (! $forceSiteIndex) {
            $siteId = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $key = ($periods[0] ?? null)?->rangeKey();

                return $this->metricRow(
                    $key === null ? [] : ($archiveRows[$siteId][$key] ?? []),
                    $definitions,
                    $columns,
                    $formatMetrics,
                );
            }

            return $this->metricDateRows(
                $archiveRows[$siteId] ?? [],
                $periods,
                $definitions,
                $columns,
                $formatMetrics,
            );
        }

        $data = [];

        foreach ($siteIds as $siteId) {
            if ($forceDateIndex) {
                $data[$siteId] = $this->metricDateRows(
                    $archiveRows[$siteId] ?? [],
                    $periods,
                    $definitions,
                    $columns,
                    $formatMetrics,
                );
            } else {
                $key = ($periods[0] ?? null)?->rangeKey();
                $data[$siteId] = $this->metricRow(
                    $key === null ? [] : ($archiveRows[$siteId][$key] ?? []),
                    $definitions,
                    $columns,
                    $formatMetrics,
                );
            }
        }

        return $data;
    }

    /**
     * @param  array<string, array<string, int|float>>  $archiveRows
     * @param  list<ReportingPeriod>  $periods
     * @param  array<string, string>  $definitions
     * @param  list<string>  $columns
     * @return array<string, array<string, float|int|string>>
     */
    private function metricDateRows(
        array $archiveRows,
        array $periods,
        array $definitions,
        array $columns,
        bool $formatMetrics,
    ): array {
        $rows = [];

        foreach ($periods as $period) {
            $rows[$period->resultKey] = $this->metricRow(
                $archiveRows[$period->rangeKey()] ?? [],
                $definitions,
                $columns,
                $formatMetrics,
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, int|float>  $archiveRow
     * @param  array<string, string>  $definitions
     * @param  list<string>  $columns
     * @return array<string, float|int|string>
     */
    private function metricRow(
        array $archiveRow,
        array $definitions,
        array $columns,
        bool $formatMetrics,
    ): array {
        $metrics = [];

        foreach ($definitions as $column => $record) {
            $metrics[$column] = $archiveRow[$record] ?? 0;
        }

        $metrics['nb_visits'] = $archiveRow['nb_visits'] ?? ($metrics['nb_visits'] ?? 0);
        $row = [];

        foreach ($columns as $column) {
            if ($column === 'conversion_rate') {
                $value = $this->quotient(
                    $metrics['nb_visits_converted'] ?? 0,
                    $metrics['nb_visits'],
                    4,
                );
                $row[$column] = $formatMetrics ? $this->formattedQuotient($value) : $value;
            } elseif ($column === 'avg_order_revenue') {
                $row[$column] = $this->number($this->quotient(
                    $metrics['revenue'] ?? 0,
                    $metrics['nb_conversions'] ?? 0,
                    2,
                ));
            } elseif (preg_match('/^(goal_-?[0-9]+)_conversion_rate$/D', $column, $matches) === 1) {
                $value = $this->quotient(
                    $metrics[$matches[1].'_nb_visits_converted'] ?? 0,
                    $metrics['nb_visits'],
                    4,
                );
                $row[$column] = $formatMetrics ? $this->formattedQuotient($value) : $value;
            } elseif (isset($metrics[$column])) {
                $row[$column] = $metrics[$column];
            }
        }

        return $row;
    }

    private function goalId(int|string|null $idGoal): int|string|null
    {
        return match ($idGoal) {
            'ecommerceOrder' => self::ECOMMERCE_ORDER_GOAL,
            'ecommerceAbandonedCart' => self::ECOMMERCE_CART_GOAL,
            default => $idGoal,
        };
    }

    private function goalRecord(string $metric, int|string|null $idGoal): string
    {
        return 'Goal_'.($idGoal === null ? '' : $idGoal.'_').$metric;
    }

    private function combinedSegment(?string $segment, ?string $visitorSegment): ?string
    {
        if ($visitorSegment === null) {
            return $segment;
        }

        return $segment === null || $segment === ''
            ? $visitorSegment
            : $segment.';'.$visitorSegment;
    }

    /**
     * @param  array<array-key, mixed>  $target
     * @param  array<array-key, mixed>  $source
     * @param  list<'idSite'|'date'>  $dimensions
     * @return array<array-key, mixed>
     */
    private function mergeMetrics(
        array $target,
        array $source,
        array $dimensions,
        string $suffix,
        int $depth,
    ): array {
        if (! isset($dimensions[$depth])) {
            foreach ($source as $name => $value) {
                if (is_string($name)
                    && (is_float($value) || is_int($value) || is_string($value))) {
                    $target[$name.$suffix] = $value;
                }
            }

            return $target;
        }

        foreach ($source as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $existing = $target[$key] ?? [];
            $target[$key] = $this->mergeMetrics(
                is_array($existing) ? $existing : [],
                $value,
                $dimensions,
                $suffix,
                $depth + 1,
            );
        }

        return $target;
    }

    private function quotient(float|int $numerator, float|int $denominator, int $precision): float
    {
        return (float) $denominator === 0.0
            ? 0
            : round((float) $numerator / (float) $denominator, $precision);
    }

    private function formattedQuotient(float $value): string
    {
        return rtrim(rtrim(number_format($value * 100, 2, '.', ''), '0'), '.').'%';
    }

    private function percent(float|int $value, float|int $total, int $precision): string
    {
        $percent = (float) $total === 0.0 ? 0 : round((float) $value / (float) $total * 100, $precision);

        return rtrim(rtrim(number_format($percent, $precision, '.', ''), '0'), '.').'%';
    }

    private function number(float|int $value): float|int
    {
        return floor((float) $value) === (float) $value ? (int) $value : $value;
    }
}
