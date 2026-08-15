<?php

declare(strict_types=1);

namespace App\Matomo\MultiSites;

use App\Matomo\MultiSites\Events\MultiSitesTotalsFiltering;
use App\Matomo\Plugins\PluginState;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Sites\CurrencyProvider;
use App\Matomo\Sites\SiteRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class MultiSitesReportBuilder
{
    /** @var array<string, array{record: string, evolution: string, ecommerce: bool}> */
    private const array BASE_METRICS = [
        'nb_visits' => [
            'record' => 'nb_visits',
            'evolution' => 'visits_evolution',
            'ecommerce' => false,
        ],
        'nb_actions' => [
            'record' => 'nb_actions',
            'evolution' => 'actions_evolution',
            'ecommerce' => false,
        ],
    ];

    /** @var array<string, array{record: string, evolution: string, ecommerce: bool}> */
    private const array ACTION_METRICS = [
        'nb_pageviews' => [
            'record' => 'Actions_nb_pageviews',
            'evolution' => 'pageviews_evolution',
            'ecommerce' => false,
        ],
        'hits' => [
            'record' => 'Actions_hits',
            'evolution' => 'hits_evolution',
            'ecommerce' => false,
        ],
    ];

    /** @var array<string, array{record: string, evolution: string, ecommerce: bool}> */
    private const array GOAL_METRICS = [
        'revenue' => [
            'record' => 'Goal_revenue',
            'evolution' => 'revenue_evolution',
            'ecommerce' => false,
        ],
    ];

    /** @var array<string, array{record: string, evolution: string, ecommerce: bool}> */
    private const array ENHANCED_GOAL_METRICS = [
        'nb_conversions' => [
            'record' => 'Goal_nb_conversions',
            'evolution' => 'nb_conversions_evolution',
            'ecommerce' => false,
        ],
        'orders' => [
            'record' => 'Goal_0_nb_conversions',
            'evolution' => 'orders_evolution',
            'ecommerce' => true,
        ],
        'ecommerce_revenue' => [
            'record' => 'Goal_0_revenue',
            'evolution' => 'ecommerce_revenue_evolution',
            'ecommerce' => true,
        ],
    ];

    /** @var array<string, array{record: string, evolution: string, ecommerce: bool}> */
    private const array BOT_METRICS = [
        'ai_chatbots_requests' => [
            'record' => 'BotTracking_AIChatbotsRequests',
            'evolution' => 'ai_chatbots_requests_evolution',
            'ecommerce' => false,
        ],
    ];

    public function __construct(
        private NumericArchiveRepository $archives,
        private SiteRepository $sites,
        private PluginState $plugins,
        private CurrencyProvider $currencies,
        private Dispatcher $events,
    ) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @param  list<string>  $showColumns
     */
    public function build(
        array $siteIds,
        array $periods,
        string $requestedPeriod,
        string $requestedDate,
        string $timezone,
        string $segmentHash,
        bool $enhanced,
        array $showColumns,
        bool $includeZeroVisitRows,
        bool $includeSiteLabel,
    ): MultiSitesReport {
        $metrics = $this->metrics($enhanced, $segmentHash, $showColumns);
        $previousPeriods = $this->previousPeriods($periods, $requestedPeriod, $requestedDate);
        $archivePeriods = $periods;

        foreach ($previousPeriods as $previousPeriod) {
            $archivePeriods[$previousPeriod->rangeKey()] = $previousPeriod;
        }

        $records = array_values(array_unique(array_column($metrics, 'record')));
        $archiveRows = $this->archives->pluginMetrics(
            $siteIds,
            array_values($archivePeriods),
            $segmentHash,
            $records,
            'MultiSites',
        );
        $detailsById = $this->detailsById($siteIds);
        $currencySymbols = $this->currencies->symbols();
        $rowsByPeriod = [];
        $totalsByPeriod = [];

        foreach ($periods as $period) {
            $previous = $previousPeriods[$period->rangeKey()] ?? null;
            $rows = [];

            foreach ($siteIds as $siteId) {
                $site = $detailsById[$siteId] ?? [];
                $current = $archiveRows[$siteId][$period->rangeKey()] ?? [];
                $past = $previous === null
                    ? []
                    : ($archiveRows[$siteId][$previous->rangeKey()] ?? []);
                $row = $this->row(
                    $siteId,
                    $site,
                    $current,
                    $past,
                    $metrics,
                    $period,
                    $previous,
                    $includeSiteLabel,
                    $currencySymbols,
                );

                if (! $includeZeroVisitRows
                    && isset($metrics['nb_visits'])
                    && (float) ($row['nb_visits'] ?? 0) === 0.0) {
                    continue;
                }

                $rows[] = $row;
            }

            $rowsByPeriod[$period->resultKey] = $rows;
            $totalsByPeriod[$period->resultKey] = $this->totals($rows, $metrics);
        }

        return new MultiSitesReport(
            rowsByPeriod: $rowsByPeriod,
            totalsByPeriod: $totalsByPeriod,
            lastDate: $this->lastDate(
                $periods,
                $requestedPeriod,
                $requestedDate,
                $timezone,
            ),
        );
    }

    /**
     * @param  list<array<string, float|int|string|null>>  $rows
     * @param  array<string, float|int>  $totals
     * @return array{numSites: int, totals: array<string, float|int|string>, lastDate: string, sites: list<array<string, float|int|string|null>>}
     */
    public function grouped(
        array $rows,
        array $totals,
        string $lastDate,
        ?string $pattern,
        int $offset,
        int $limit,
        string $sortColumn,
        string $sortOrder,
        bool $formatMetrics,
    ): array {
        $groups = [];
        $ungrouped = [];

        foreach ($rows as $row) {
            $group = is_string($row['group'] ?? null) ? trim($row['group']) : '';

            if ($group === '') {
                $ungrouped[] = $row;
            } else {
                $groups[$group][] = $row;
            }
        }

        $units = [];

        foreach ($groups as $label => $groupRows) {
            $matchedRows = $this->matchingRows($groupRows, $pattern);

            if ($matchedRows === []) {
                continue;
            }

            $groupRow = $this->groupRow($label, $groupRows);
            $this->sortRows($matchedRows, $sortColumn, $sortOrder);
            $units[] = ['row' => $groupRow, 'children' => $matchedRows];
        }

        foreach ($this->matchingRows($ungrouped, $pattern) as $row) {
            $units[] = ['row' => $row, 'children' => []];
        }

        usort($units, fn (array $left, array $right): int => $this->compareRows(
            $left['row'],
            $right['row'],
            $sortColumn,
            $sortOrder,
        ));

        $flat = [];

        foreach ($units as $unit) {
            $flat[] = $unit['row'];
            $flat = [...$flat, ...$unit['children']];
        }

        $numSites = count($flat);
        $page = $limit < 0
            ? array_slice($flat, max(0, $offset))
            : array_slice($flat, max(0, $offset), max(0, $limit));

        if ($page !== [] && empty($page[0]['isGroup']) && ! empty($page[0]['group'])) {
            $groupLabel = (string) $page[0]['group'];

            foreach (array_reverse(array_slice($flat, 0, max(0, $offset))) as $preceding) {
                if (($preceding['label'] ?? null) === $groupLabel && ! empty($preceding['isGroup'])) {
                    array_unshift($page, $preceding);
                    break;
                }
            }
        }

        if ($formatMetrics) {
            $totals = $this->formatTotals($totals);

            foreach ($page as &$row) {
                $row = $this->formatRow($row);
            }

            unset($row);
        }

        return [
            'numSites' => $numSites,
            'totals' => $totals,
            'lastDate' => $lastDate,
            'sites' => $page,
        ];
    }

    /**
     * @param  list<string>  $showColumns
     * @return array<string, array{record: string, evolution: string, ecommerce: bool}>
     */
    private function metrics(bool $enhanced, string $segmentHash, array $showColumns): array
    {
        $metrics = self::BASE_METRICS;

        if ($this->plugins->isActivated('Actions')) {
            $metrics = [...$metrics, ...self::ACTION_METRICS];
        }

        if ($this->plugins->isActivated('BotTracking') && $segmentHash === '') {
            $metrics = [...$metrics, ...self::BOT_METRICS];
        }

        if ($this->plugins->isActivated('Goals')) {
            $metrics = [...$metrics, ...self::GOAL_METRICS];

            if ($enhanced) {
                $metrics = [...$metrics, ...self::ENHANCED_GOAL_METRICS];
            }
        }

        if ($showColumns === []) {
            return $metrics;
        }

        return array_filter(
            $metrics,
            static fn (string $metric): bool => in_array($metric, $showColumns, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param  list<ReportingPeriod>  $periods
     * @return array<string, ReportingPeriod>
     */
    private function previousPeriods(array $periods, string $requestedPeriod, string $requestedDate): array
    {
        if (preg_match('/(last|previous)([0-9]*)/', $requestedDate) === 1) {
            return [];
        }

        $previous = [];

        foreach ($periods as $period) {
            $start = CarbonImmutable::parse($period->startDate, 'UTC');
            $end = CarbonImmutable::parse($period->endDate, 'UTC');

            if ($requestedPeriod === 'range') {
                $days = $start->diffInDays($end) + 1;
                $pastEnd = $start->subDay();
                $pastStart = $pastEnd->subDays($days - 1);
            } else {
                [$pastStart, $pastEnd] = match ($requestedPeriod) {
                    'day' => [$start->subDay(), $end->subDay()],
                    'week' => [$start->subWeek(), $end->subWeek()],
                    'month' => [
                        $start->subMonthNoOverflow()->startOfMonth(),
                        $start->subMonthNoOverflow()->endOfMonth(),
                    ],
                    'year' => [
                        $start->subYearNoOverflow()->startOfYear(),
                        $start->subYearNoOverflow()->endOfYear(),
                    ],
                    default => throw new \InvalidArgumentException(
                        "The period '{$requestedPeriod}' is not supported.",
                    ),
                };
            }

            $previous[$period->rangeKey()] = new ReportingPeriod(
                label: $requestedPeriod,
                id: $period->id,
                startDate: $pastStart->toDateString(),
                endDate: $pastEnd->toDateString(),
                resultKey: $pastStart->toDateString(),
            );
        }

        return $previous;
    }

    /**
     * @param  list<int>  $siteIds
     * @return array<int, array<string, int|string|null>>
     */
    private function detailsById(array $siteIds): array
    {
        $details = [];

        foreach ($this->sites->detailsForIds($siteIds) as $site) {
            $siteId = $site['idsite'] ?? null;

            if (is_int($siteId)) {
                $details[$siteId] = $site;
            }
        }

        return $details;
    }

    /**
     * @param  array<string, int|string|null>  $site
     * @param  array<string, int|float>  $current
     * @param  array<string, int|float>  $past
     * @param  array<string, array{record: string, evolution: string, ecommerce: bool}>  $metrics
     * @param  array<string, string>  $currencySymbols
     * @return array<string, float|int|string|null>
     */
    private function row(
        int $siteId,
        array $site,
        array $current,
        array $past,
        array $metrics,
        ReportingPeriod $period,
        ?ReportingPeriod $previous,
        bool $includeSiteLabel,
        array $currencySymbols,
    ): array {
        $row = [];

        if ($includeSiteLabel) {
            $name = $site['name'] ?? '';
            $row['label'] = is_string($name) ? $name : '';
        }

        $isEcommerce = (int) ($site['ecommerce'] ?? 0) === 1;

        foreach ($metrics as $name => $definition) {
            if ($definition['ecommerce'] && ! $isEcommerce) {
                continue;
            }

            $currentValue = $this->numeric($current[$definition['record']] ?? 0);
            $pastValue = $this->numeric($past[$definition['record']] ?? 0);

            if ($name === 'hits' && isset($metrics['ai_chatbots_requests'])) {
                $currentValue = $this->numeric(
                    $currentValue + (float) ($current[self::BOT_METRICS['ai_chatbots_requests']['record']] ?? 0),
                );
                $pastValue = $this->numeric(
                    $pastValue + (float) ($past[self::BOT_METRICS['ai_chatbots_requests']['record']] ?? 0),
                );
            }

            $row[$name] = $currentValue;
            $row[$definition['evolution']] = $this->evolution($currentValue, $pastValue);
            $row[$definition['evolution'].'_trend'] = $this->trend($currentValue, $pastValue);
            $row['previous_'.$name] = $pastValue;
        }

        $currencyCode = $site['currency'] ?? null;
        $row['idsite'] = $siteId;
        $row['ratio'] = 1;
        $row['currencySymbol'] = is_string($currencyCode)
            ? ($currencySymbols[$currencyCode] ?? $currencyCode)
            : '';
        $row['periodName'] = $period->label;
        $row['previousRange'] = $previous === null ? '' : $this->periodLabel($previous);
        $row['group'] = is_string($site['group'] ?? null) ? $site['group'] : '';
        $row['main_url'] = is_string($site['main_url'] ?? null) ? $site['main_url'] : '';

        return $row;
    }

    /**
     * @param  list<array<string, float|int|string|null>>  $rows
     * @param  array<string, array{record: string, evolution: string, ecommerce: bool}>  $metrics
     * @return array<string, float|int>
     */
    private function totals(array $rows, array $metrics): array
    {
        $eventRows = array_map(static function (array $row): array {
            $row['label'] = $row['idsite'] ?? null;

            return $row;
        }, $rows);
        $event = new MultiSitesTotalsFiltering($eventRows);
        $this->events->dispatch($event);
        $totals = [];

        foreach ($metrics as $name => $definition) {
            $totals[$name] = 0;
            $totals['previous_'.$name] = 0;
        }

        foreach ($event->rows as $row) {
            foreach ($metrics as $name => $definition) {
                $totals[$name] = $this->numeric($totals[$name] + (float) ($row[$name] ?? 0));
                $totals['previous_'.$name] = $this->numeric(
                    $totals['previous_'.$name] + (float) ($row['previous_'.$name] ?? 0),
                );
            }
        }

        return $totals;
    }

    /**
     * @param  list<array<string, float|int|string|null>>  $rows
     * @return list<array<string, float|int|string|null>>
     */
    private function matchingRows(array $rows, ?string $pattern): array
    {
        $pattern = strtolower(trim($pattern ?? ''));

        if ($pattern === '') {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($pattern): bool {
            $label = strtolower((string) ($row['label'] ?? ''));
            $group = strtolower((string) ($row['group'] ?? ''));

            return str_contains($label, $pattern) || str_contains($group, $pattern);
        }));
    }

    /**
     * @param  list<array<string, float|int|string|null>>  $rows
     * @return array<string, float|int|string|null>
     */
    private function groupRow(string $label, array $rows): array
    {
        $row = ['label' => $label, 'isGroup' => 1];

        foreach ($rows as $siteRow) {
            foreach ($siteRow as $name => $value) {
                if ((! is_int($value) && ! is_float($value))
                    || str_ends_with($name, '_trend')
                    || in_array($name, ['idsite', 'ratio'], true)) {
                    continue;
                }

                if (str_contains($name, '_evolution')) {
                    continue;
                }

                $row[$name] = $this->numeric((float) ($row[$name] ?? 0) + $value);
            }
        }

        foreach (array_keys($row) as $name) {
            if (str_starts_with($name, 'previous_')) {
                continue;
            }

            $previousName = 'previous_'.$name;

            if (! array_key_exists($previousName, $row)
                || (! is_int($row[$name]) && ! is_float($row[$name]))
                || (! is_int($row[$previousName]) && ! is_float($row[$previousName]))) {
                continue;
            }

            $evolutionName = match ($name) {
                'nb_visits' => 'visits_evolution',
                'nb_actions' => 'actions_evolution',
                'nb_pageviews' => 'pageviews_evolution',
                default => $name.'_evolution',
            };
            $currentValue = $row[$name];
            $previousValue = $row[$previousName];
            $row[$evolutionName] = $this->evolution($currentValue, $previousValue);
            $row[$evolutionName.'_trend'] = $this->trend($currentValue, $previousValue);
        }

        return $row;
    }

    /** @param list<array<string, float|int|string|null>> $rows */
    private function sortRows(array &$rows, string $column, string $order): void
    {
        usort($rows, fn (array $left, array $right): int => $this->compareRows(
            $left,
            $right,
            $column,
            $order,
        ));
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function compareRows(array $left, array $right, string $column, string $order): int
    {
        $leftValue = $left[$column] ?? null;
        $rightValue = $right[$column] ?? null;

        if (is_numeric($leftValue) && is_numeric($rightValue)) {
            $comparison = (float) $leftValue <=> (float) $rightValue;
        } else {
            $comparison = strcasecmp((string) $leftValue, (string) $rightValue);
        }

        if ($comparison === 0) {
            $comparison = strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        }

        return strtolower($order) === 'asc' ? $comparison : -$comparison;
    }

    /**
     * @param  array<string, float|int>  $totals
     * @return array<string, string>
     */
    private function formatTotals(array $totals): array
    {
        foreach ($totals as $name => &$value) {
            $value = str_contains($name, 'revenue')
                ? $this->formatNumber($value, 2)
                : $this->formatNumber($value, 0);
        }

        unset($value);

        return $totals;
    }

    /**
     * @param  array<string, float|int|string|null>  $row
     * @return array<string, float|int|string|null>
     */
    private function formatRow(array $row): array
    {
        $currency = (string) ($row['currencySymbol'] ?? '');

        foreach ($row as $name => &$value) {
            if (! is_int($value) && ! is_float($value)) {
                continue;
            }

            if (str_contains($name, 'revenue') && ! str_contains($name, 'evolution')) {
                $value = $currency.$this->formatNumber($value, 2);
            } elseif (in_array($name, [
                'nb_visits',
                'nb_actions',
                'nb_pageviews',
                'hits',
                'nb_conversions',
                'orders',
                'ai_chatbots_requests',
                'previous_nb_visits',
                'previous_nb_actions',
                'previous_nb_pageviews',
                'previous_hits',
                'previous_nb_conversions',
                'previous_orders',
                'previous_ai_chatbots_requests',
            ], true)) {
                $value = $this->formatNumber($value, 0);
            }
        }

        unset($value);

        return $row;
    }

    private function formatNumber(float|int $value, int $decimals): string
    {
        $decimals = $decimals === 2 && floor((float) $value) === (float) $value ? 0 : $decimals;

        return number_format((float) $value, $decimals, '.', ',');
    }

    private function evolution(float|int $current, float|int $past): string
    {
        if ((float) $current === (float) $past) {
            return '0%';
        }

        if ((float) $past === 0.0) {
            return '100%';
        }

        $percentage = round((((float) $current - (float) $past) / (float) $past) * 100, 1);
        $text = rtrim(rtrim(number_format($percentage, 1, '.', ''), '0'), '.');

        return $text.'%';
    }

    private function trend(float|int $current, float|int $past): int
    {
        return (float) $current <=> (float) $past;
    }

    private function numeric(float|int $value): float|int
    {
        $value = round((float) $value, 2);

        return floor($value) === $value ? (int) $value : $value;
    }

    private function periodLabel(ReportingPeriod $period): string
    {
        $start = CarbonImmutable::parse($period->startDate, 'UTC');
        $end = CarbonImmutable::parse($period->endDate, 'UTC');

        return match ($period->label) {
            'day' => $start->format('D, M j'),
            'week', 'range' => $start->format('M j').' - '.$end->format('M j'),
            'month' => $start->format('M Y'),
            'year' => $start->format('Y'),
            default => $period->startDate,
        };
    }

    /**
     * @param  list<ReportingPeriod>  $periods
     */
    private function lastDate(
        array $periods,
        string $requestedPeriod,
        string $requestedDate,
        string $timezone,
    ): string {
        if ($requestedPeriod === 'range'
            || str_contains($requestedDate, ',')
            || preg_match('/(last|previous)([0-9]*)/', $requestedDate) === 1) {
            return '';
        }

        $period = $periods[0] ?? null;

        if ($period === null) {
            return '';
        }

        $anchor = match (strtolower($requestedDate)) {
            'now', 'today' => CarbonImmutable::today($timezone),
            'yesterday', 'yesterdaysametime' => CarbonImmutable::today($timezone)->subDay(),
            default => CarbonImmutable::parse($requestedDate, $timezone),
        };

        return match ($requestedPeriod) {
            'day' => $anchor->subDay()->toDateString(),
            'week' => $anchor->subWeek()->toDateString(),
            'month' => $anchor->subMonthNoOverflow()->toDateString(),
            'year' => $anchor->subYearNoOverflow()->toDateString(),
            default => '',
        };
    }
}
