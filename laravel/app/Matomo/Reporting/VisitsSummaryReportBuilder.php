<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiReport;
use LogicException;

final readonly class VisitsSummaryReportBuilder
{
    /** @var array<string, list<string>> */
    private const array PROCESSED_METRICS = [
        'bounce_rate' => ['bounce_count', 'nb_visits'],
        'nb_actions_per_visit' => ['nb_actions', 'nb_visits'],
        'avg_time_on_site' => ['sum_visit_length', 'nb_visits'],
    ];

    public function __construct(
        private VisitsSummaryArchiveRepository $archives,
        private ReportingSettings $settings,
    ) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @param  list<string>|null  $requestedColumns
     * @param  list<string>  $showColumns
     * @param  list<string>  $hideColumns
     */
    public function build(
        array $siteIds,
        array $periods,
        string $requestedPeriod,
        string $segmentHash,
        ?array $requestedColumns,
        array $showColumns,
        array $hideColumns,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiReport {
        $coreMetrics = $this->coreMetrics($requestedPeriod);
        $archiveMetrics = $this->archiveMetrics($coreMetrics, $requestedColumns);
        $archiveRows = $this->archives->metrics(
            $siteIds,
            $periods,
            $segmentHash,
            $archiveMetrics,
        );
        $dimensions = [
            ...($forceSiteIndex ? ['idSite'] : []),
            ...($forceDateIndex ? ['date'] : []),
        ];

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $period = $periods[0] ?? null;
                $metrics = $period === null ? null : ($archiveRows[$idSite][$period->rangeKey()] ?? null);

                return new ApiReport(
                    $this->row(
                        $metrics,
                        $archiveMetrics,
                        $requestedColumns,
                        $showColumns,
                        $hideColumns,
                        true,
                    ),
                    [],
                );
            }

            return new ApiReport(
                $this->dateRows(
                    $idSite,
                    $periods,
                    $archiveRows,
                    $archiveMetrics,
                    $requestedColumns,
                    $showColumns,
                    $hideColumns,
                ),
                $dimensions,
            );
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            if ($forceDateIndex) {
                $data[$idSite] = $this->dateRows(
                    $idSite,
                    $periods,
                    $archiveRows,
                    $archiveMetrics,
                    $requestedColumns,
                    $showColumns,
                    $hideColumns,
                );

                continue;
            }

            $period = $periods[0] ?? null;
            $metrics = $period === null ? null : ($archiveRows[$idSite][$period->rangeKey()] ?? null);
            $data[$idSite] = $this->row(
                $metrics,
                $archiveMetrics,
                $requestedColumns,
                $showColumns,
                $hideColumns,
                false,
            );
        }

        return new ApiReport($data, $dimensions);
    }

    /**
     * @param  list<ReportingPeriod>  $periods
     * @param  array<int, array<string, array<string, int|float>>>  $archiveRows
     * @param  list<string>  $archiveMetrics
     * @param  list<string>|null  $requestedColumns
     * @param  list<string>  $showColumns
     * @param  list<string>  $hideColumns
     * @return array<string, array<string, int|float|string>>
     */
    private function dateRows(
        int $idSite,
        array $periods,
        array $archiveRows,
        array $archiveMetrics,
        ?array $requestedColumns,
        array $showColumns,
        array $hideColumns,
    ): array {
        $rows = [];

        foreach ($periods as $period) {
            $rows[$period->resultKey] = $this->row(
                $archiveRows[$idSite][$period->rangeKey()] ?? null,
                $archiveMetrics,
                $requestedColumns,
                $showColumns,
                $hideColumns,
                false,
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, int|float>|null  $archiveRow
     * @param  list<string>  $archiveMetrics
     * @param  list<string>|null  $requestedColumns
     * @param  list<string>  $showColumns
     * @param  list<string>  $hideColumns
     * @return array<string, int|float|string>
     */
    private function row(
        ?array $archiveRow,
        array $archiveMetrics,
        ?array $requestedColumns,
        array $showColumns,
        array $hideColumns,
        bool $standalone,
    ): array {
        if ($archiveMetrics === []) {
            return [];
        }

        if ($archiveRow === null && ! $standalone && count($archiveMetrics) !== 1) {
            return [];
        }

        $row = array_fill_keys($archiveMetrics, 0);

        foreach ($archiveRow ?? [] as $metric => $value) {
            if (array_key_exists($metric, $row)) {
                $row[$metric] = $value;
            }
        }

        $rawRow = $row;

        foreach (self::PROCESSED_METRICS as $metric => $dependencies) {
            if (array_diff($dependencies, array_keys($row)) === []) {
                $row[$metric] = $this->processedMetric($metric, $rawRow);
            }
        }

        if ($requestedColumns !== null) {
            $row = array_filter(
                $row,
                static fn (string $metric): bool => in_array($metric, $requestedColumns, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        if ($showColumns !== []) {
            $row = array_filter(
                $row,
                static fn (string $metric): bool => in_array($metric, $showColumns, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        if ($hideColumns !== []) {
            $row = array_filter(
                $row,
                static fn (string $metric): bool => ! in_array($metric, $hideColumns, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        return $row;
    }

    /**
     * @param  list<string>  $coreMetrics
     * @param  list<string>|null  $requestedColumns
     * @return list<string>
     */
    private function archiveMetrics(array $coreMetrics, ?array $requestedColumns): array
    {
        if ($requestedColumns === null) {
            return $coreMetrics;
        }

        $coreMetricSet = array_flip($coreMetrics);
        $metrics = [];

        foreach ($requestedColumns as $metric) {
            if (isset(self::PROCESSED_METRICS[$metric])) {
                $metrics = [...$metrics, ...self::PROCESSED_METRICS[$metric]];
            } elseif (isset($coreMetricSet[$metric])) {
                $metrics[] = $metric;
            }
        }

        return array_values(array_unique($metrics));
    }

    /**
     * @return list<string>
     */
    private function coreMetrics(string $period): array
    {
        $metrics = [
            'nb_visits',
            'nb_actions',
            'nb_visits_converted',
            'bounce_count',
            'sum_visit_length',
            'max_actions',
        ];

        return $this->settings->uniqueVisitorsEnabled($period)
            ? ['nb_uniq_visitors', 'nb_users', ...$metrics]
            : $metrics;
    }

    /**
     * @param  array<string, int|float>  $row
     */
    private function processedMetric(string $metric, array $row): int|float|string
    {
        $visits = (float) ($row['nb_visits'] ?? 0);

        return match ($metric) {
            'bounce_rate' => $this->numberText(
                $visits === 0.0 ? 0 : round((float) $row['bounce_count'] / $visits, 2) * 100,
            ).'%',
            'nb_actions_per_visit' => $this->numeric(
                $visits === 0.0 ? 0 : round((float) $row['nb_actions'] / $visits, 1),
            ),
            'avg_time_on_site' => $this->numeric(
                $visits === 0.0 ? 0 : round((float) $row['sum_visit_length'] / $visits),
            ),
            default => throw new LogicException("The processed metric '{$metric}' is not supported."),
        };
    }

    private function numeric(float|int $value): int|float
    {
        $value = (float) $value;

        return floor($value) === $value ? (int) $value : $value;
    }

    private function numberText(float|int $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }
}
