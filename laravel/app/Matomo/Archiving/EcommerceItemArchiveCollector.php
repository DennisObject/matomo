<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Reporting\BatchBlobArchiveRepository;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\JoinClause;
use InvalidArgumentException;
use stdClass;

final readonly class EcommerceItemArchiveCollector
{
    private const int ROW_LIMIT = 10_000;

    private const int CART_GOAL = -1;

    /** @var array<string, list<string>> */
    private const array RECORDS = [
        'Goals_ItemsSku' => ['idaction_sku'],
        'Goals_ItemsName' => ['idaction_name'],
        'Goals_ItemsCategory' => [
            'idaction_category',
            'idaction_category2',
            'idaction_category3',
            'idaction_category4',
            'idaction_category5',
        ],
    ];

    /** @var array<string, string> */
    private const array ACTION_COLUMNS = [
        'idaction_category' => 'idaction_product_cat',
        'idaction_category2' => 'idaction_product_cat2',
        'idaction_category3' => 'idaction_product_cat3',
        'idaction_category4' => 'idaction_product_cat4',
        'idaction_category5' => 'idaction_product_cat5',
        'idaction_name' => 'idaction_product_name',
        'idaction_sku' => 'idaction_product_sku',
    ];

    /** @var list<string> */
    private const array REPORTS = [
        'Goals.getItemsSku',
        'Goals.getItemsName',
        'Goals.getItemsCategory',
    ];

    public function __construct(
        private Connection $connection,
        private ArchiveActionQueryFactory $actionQueries,
        private ReportingSubperiodFactory $subperiods,
        private SegmentHashResolver $segments,
        private BatchBlobArchiveRepository $blobs,
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

        $recordNames = $this->recordNames();
        $records = $event->period->label === 'day'
            ? $this->dayRecords($event, $timezone)
            : $this->parentRecords($event, $recordNames);

        foreach ($recordNames as $recordName) {
            $event->records->addBlob(
                $recordName,
                serialize($this->serializableRows($records[$recordName] ?? [])),
            );
        }
    }

    /**
     * @return array<string, array<string, array<string, float|int|string|null>>>
     */
    private function dayRecords(ArchiveReportsCollecting $event, string $timezone): array
    {
        $records = $this->emptyRecords();

        if (! $this->ecommerceEnabled($event->request->siteId)
            || ! $this->itemColumnsExist()
            || ! $this->actionColumnsExist()) {
            return $records;
        }

        foreach (self::RECORDS as $recordName => $dimensions) {
            foreach ($dimensions as $index => $dimension) {
                foreach ($this->itemRows($event, $timezone, $dimension) as $result) {
                    $label = $this->label($result->label ?? null, $index === 0);

                    if ($label === null) {
                        continue;
                    }

                    $cart = (int) ($result->ecommerce_type ?? 0) === self::CART_GOAL;
                    $target = $recordName.($cart ? '_Cart' : '');
                    $metrics = [
                        'revenue' => $this->numeric($result->revenue ?? null),
                        'quantity' => $this->numeric($result->quantity ?? null),
                        'price' => $this->numeric($result->price ?? null),
                        'orders' => $this->numeric(
                            $cart ? ($result->nb_visits ?? null) : ($result->orders ?? null),
                        ),
                    ];
                    $this->mergeRow($records[$target], $label, $metrics);
                }

                $actionColumn = self::ACTION_COLUMNS[$dimension];

                foreach ($this->viewRows($event, $timezone, $actionColumn) as $result) {
                    $label = $this->label($result->label ?? null, $index === 0);

                    if ($label === null) {
                        continue;
                    }

                    $metrics = [
                        'nb_uniq_visitors' => $this->numeric(
                            $result->nb_uniq_visitors ?? null,
                        ),
                        'nb_visits' => $this->numeric($result->nb_visits ?? null),
                        'nb_actions' => $this->numeric($result->nb_actions ?? null),
                        'avg_price_viewed' => $this->numeric(
                            $result->avg_price_viewed ?? null,
                        ),
                    ];
                    $this->mergeRow($records[$recordName], $label, $metrics);
                    $this->mergeRow($records[$recordName.'_Cart'], $label, $metrics);
                }
            }
        }

        return $records;
    }

    /**
     * @param  list<string>  $recordNames
     * @return array<string, array<string, array<string, float|int|string|null>>>
     */
    private function parentRecords(ArchiveReportsCollecting $event, array $recordNames): array
    {
        $children = $this->subperiods->children($event->period);
        $archives = $this->blobs->rowsForRecords(
            [$event->request->siteId],
            $children,
            $this->segments->resolve($event->request->segment),
            $recordNames,
        )[$event->request->siteId] ?? [];
        $records = $this->emptyRecords();

        foreach ($children as $child) {
            foreach ($recordNames as $recordName) {
                foreach ($archives[$child->rangeKey()][$recordName] ?? [] as $row) {
                    $label = $row['columns']['label'] ?? null;

                    if (! is_float($label) && ! is_int($label) && ! is_string($label)) {
                        continue;
                    }

                    $this->mergeRow($records[$recordName], $label, $row['columns']);
                }
            }
        }

        return $records;
    }

    /** @return iterable<stdClass> */
    private function itemRows(
        ArchiveReportsCollecting $event,
        string $timezone,
        string $dimension,
    ): iterable {
        $dimension = $this->itemDimension($dimension);
        $start = CarbonImmutable::parse($event->period->startDate, $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($event->period->endDate, $timezone)
            ->addDay()
            ->startOfDay()
            ->utc();
        $query = $this->connection
            ->table('log_conversion_item')
            ->join('log_action as product_action', static function (JoinClause $join) use ($dimension): void {
                $join->on('product_action.idaction', '=', 'log_conversion_item.'.$dimension);
            })
            ->where('log_conversion_item.idsite', $event->request->siteId)
            ->where('log_conversion_item.server_time', '>=', $start->toDateTimeString())
            ->where('log_conversion_item.server_time', '<', $end->toDateTimeString())
            ->where('log_conversion_item.deleted', 0)
            ->addSelect('product_action.name AS label')
            ->addSelect($this->itemMetricSelect())
            ->groupBy('product_action.name')
            ->groupBy($this->itemEcommerceTypeExpression())
            ->orderBy('product_action.name');

        return $query->cursor();
    }

    private function itemMetricSelect(): TrustedSegmentSqlExpression
    {
        $grammar = $this->connection->getQueryGrammar();
        $price = $grammar->wrap('log_conversion_item.price');
        $quantity = $grammar->wrap('log_conversion_item.quantity');
        $idOrder = $grammar->wrap('log_conversion_item.idorder');
        $idVisit = $grammar->wrap('log_conversion_item.idvisit');

        return new TrustedSegmentSqlExpression(implode(', ', [
            "ROUND(SUM(CASE WHEN ABS({$price}) > 1000000000000 THEN 0 ELSE {$quantity} * {$price} END), 2) AS revenue",
            "ROUND(SUM({$quantity}), 2) AS quantity",
            "ROUND(SUM(CASE WHEN ABS({$price}) > 1000000000000 THEN 0 ELSE {$price} END), 2) AS price",
            "COUNT(DISTINCT {$idOrder}) AS orders",
            "COUNT(DISTINCT {$idVisit}) AS nb_visits",
            "CASE {$idOrder} WHEN '0' THEN -1 ELSE 0 END AS ecommerce_type",
        ]));
    }

    private function itemEcommerceTypeExpression(): TrustedSegmentSqlExpression
    {
        $idOrder = $this->connection->getQueryGrammar()->wrap('log_conversion_item.idorder');

        return new TrustedSegmentSqlExpression(
            "CASE {$idOrder} WHEN '0' THEN -1 ELSE 0 END",
        );
    }

    /** @return iterable<stdClass> */
    private function viewRows(
        ArchiveReportsCollecting $event,
        string $timezone,
        string $dimension,
    ): iterable {
        $dimension = $this->actionDimension($dimension);
        $query = $this->actionQueries->make($event->request, $event->period, $timezone)
            ->join('log_action as product_action', static function (JoinClause $join) use ($dimension): void {
                $join->on('product_action.idaction', '=', 'log_link_visit_action.'.$dimension);
            })
            ->whereNotNull('log_link_visit_action.'.$dimension)
            ->addSelect('product_action.name AS label')
            ->addSelect($this->viewMetricSelect())
            ->groupBy('product_action.name')
            ->orderBy('product_action.name');

        return $query->cursor();
    }

    private function viewMetricSelect(): TrustedSegmentSqlExpression
    {
        $grammar = $this->connection->getQueryGrammar();
        $idVisitor = $grammar->wrap('log_visit.idvisitor');
        $idVisit = $grammar->wrap('log_link_visit_action.idvisit');
        $price = $grammar->wrap('log_link_visit_action.product_price');

        return new TrustedSegmentSqlExpression(implode(', ', [
            "COUNT(DISTINCT {$idVisitor}) AS nb_uniq_visitors",
            "COUNT(DISTINCT {$idVisit}) AS nb_visits",
            'COUNT(*) AS nb_actions',
            "ROUND(AVG({$price}), 2) AS avg_price_viewed",
        ]));
    }

    private function itemDimension(string $dimension): string
    {
        return match ($dimension) {
            'idaction_category' => 'idaction_category',
            'idaction_category2' => 'idaction_category2',
            'idaction_category3' => 'idaction_category3',
            'idaction_category4' => 'idaction_category4',
            'idaction_category5' => 'idaction_category5',
            'idaction_name' => 'idaction_name',
            'idaction_sku' => 'idaction_sku',
            default => throw new InvalidArgumentException(
                "The ecommerce item dimension '{$dimension}' is not supported.",
            ),
        };
    }

    private function actionDimension(string $dimension): string
    {
        return match ($dimension) {
            'idaction_product_cat' => 'idaction_product_cat',
            'idaction_product_cat2' => 'idaction_product_cat2',
            'idaction_product_cat3' => 'idaction_product_cat3',
            'idaction_product_cat4' => 'idaction_product_cat4',
            'idaction_product_cat5' => 'idaction_product_cat5',
            'idaction_product_name' => 'idaction_product_name',
            'idaction_product_sku' => 'idaction_product_sku',
            default => throw new InvalidArgumentException(
                "The ecommerce view dimension '{$dimension}' is not supported.",
            ),
        };
    }

    private function label(mixed $label, bool $primary): ?string
    {
        if (! is_float($label) && ! is_int($label) && ! is_string($label)) {
            return null;
        }

        $label = (string) $label;

        if ($label !== '') {
            return $label;
        }

        return $primary ? 'Value not defined' : null;
    }

    /**
     * @param  array<string, array<string, float|int|string|null>>  $rows
     * @param  array<string, float|int|string|null>  $metrics
     */
    private function mergeRow(array &$rows, float|int|string $label, array $metrics): void
    {
        $key = get_debug_type($label).':'.$label;
        $rows[$key] ??= ['label' => $label];

        foreach ($metrics as $metric => $value) {
            if ($metric === 'label' || (! is_float($value) && ! is_int($value))) {
                continue;
            }

            $rows[$key][$metric] = $this->numeric(
                (float) ($rows[$key][$metric] ?? 0) + $value,
            );
        }
    }

    /**
     * @param  array<string, array<string, float|int|string|null>>  $rows
     * @return list<array{0: array<string, float|int|string|null>, 1: array{}}>
     */
    private function serializableRows(array $rows): array
    {
        $summary = [];

        foreach ($rows as $key => $row) {
            if (in_array($row['label'] ?? null, [-1, '-1'], true)) {
                $this->mergeRow($summary, -1, $row);
                unset($rows[$key]);
            }
        }

        $rows = array_values($rows);
        usort($rows, static function (array $left, array $right): int {
            $revenue = (float) ($right['revenue'] ?? 0) <=> (float) ($left['revenue'] ?? 0);

            return $revenue !== 0
                ? $revenue
                : (string) ($left['label'] ?? '') <=> (string) ($right['label'] ?? '');
        });

        if (count($rows) + ($summary === [] ? 0 : 1) > self::ROW_LIMIT) {
            $remainder = array_splice($rows, self::ROW_LIMIT - 1);

            foreach ($remainder as $row) {
                $this->mergeRow($summary, -1, $row);
            }
        }

        if ($summary !== []) {
            $rows[] = array_values($summary)[0];
        }

        return array_map(
            static fn (array $row): array => [$row, []],
            $rows,
        );
    }

    /** @return list<string> */
    private function recordNames(): array
    {
        $names = [];

        foreach (array_keys(self::RECORDS) as $recordName) {
            $names[] = $recordName;
            $names[] = $recordName.'_Cart';
        }

        return $names;
    }

    /** @return array<string, array<string, array<string, float|int|string|null>>> */
    private function emptyRecords(): array
    {
        return array_fill_keys($this->recordNames(), []);
    }

    private function requested(ArchiveReportsCollecting $event): bool
    {
        if ($event->request->plugin !== null) {
            return $event->request->plugin === 'Goals';
        }

        return $event->request->reports === []
            || array_intersect($event->request->reports, self::REPORTS) !== [];
    }

    private function ecommerceEnabled(int $siteId): bool
    {
        return (int) ($this->sites->details($siteId)['ecommerce'] ?? 0) === 1;
    }

    private function itemColumnsExist(): bool
    {
        return $this->connection->getSchemaBuilder()->hasColumns('log_conversion_item', [
            'idsite',
            'idvisit',
            'idorder',
            'idaction_sku',
            'idaction_name',
            'idaction_category',
            'idaction_category2',
            'idaction_category3',
            'idaction_category4',
            'idaction_category5',
            'price',
            'quantity',
            'deleted',
            'server_time',
        ]);
    }

    private function actionColumnsExist(): bool
    {
        return $this->connection->getSchemaBuilder()->hasColumns('log_link_visit_action', [
            'idsite',
            'idvisit',
            'idaction_product_sku',
            'idaction_product_name',
            'idaction_product_cat',
            'idaction_product_cat2',
            'idaction_product_cat3',
            'idaction_product_cat4',
            'idaction_product_cat5',
            'product_price',
            'server_time',
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
