<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiReport;

final readonly class ReferrersOverviewReportBuilder
{
    /** @var array<int, string> */
    private const array TYPE_METRICS = [
        1 => 'Referrers_visitorsFromDirectEntry',
        2 => 'Referrers_visitorsFromSearchEngines',
        3 => 'Referrers_visitorsFromWebsites',
        6 => 'Referrers_visitorsFromCampaigns',
        7 => 'Referrers_visitorsFromSocialNetworks',
        8 => 'Referrers_visitorsFromAIAssistants',
    ];

    /** @var list<string> */
    private const array DISTINCT_METRICS = [
        'Referrers_distinctSearchEngines',
        'Referrers_distinctSocialNetworks',
        'Referrers_distinctAIAssistants',
        'Referrers_distinctKeywords',
        'Referrers_distinctWebsites',
        'Referrers_distinctWebsitesUrls',
        'Referrers_distinctCampaigns',
    ];

    public function __construct(
        private BlobArchiveRepository $tables,
        private NumericArchiveRepository $numbers,
    ) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @param  list<string>|null  $columns
     */
    public function build(
        array $siteIds,
        array $periods,
        string $segmentHash,
        ?array $columns,
        bool $formatMetrics,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiReport {
        $types = $this->tables->rows($siteIds, $periods, $segmentHash, 'Referrers_type');
        $numbers = $this->numbers->pluginMetrics(
            $siteIds,
            $periods,
            $segmentHash,
            self::DISTINCT_METRICS,
            'Referrers',
        );
        $dimensions = [
            ...($forceSiteIndex ? ['idSite'] : []),
            ...($forceDateIndex ? ['date'] : []),
        ];

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $period = $periods[0] ?? null;

                return new ApiReport($period === null ? [] : $this->row(
                    $types[$idSite][$period->rangeKey()] ?? [],
                    $numbers[$idSite][$period->rangeKey()] ?? [],
                    $columns,
                    $formatMetrics,
                ), []);
            }

            return new ApiReport($this->dateRows(
                $types[$idSite] ?? [],
                $numbers[$idSite] ?? [],
                $periods,
                $columns,
                $formatMetrics,
            ), $dimensions);
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            if ($forceDateIndex) {
                $data[$idSite] = $this->dateRows(
                    $types[$idSite] ?? [],
                    $numbers[$idSite] ?? [],
                    $periods,
                    $columns,
                    $formatMetrics,
                );
            } else {
                $period = $periods[0] ?? null;
                $data[$idSite] = $period === null ? [] : $this->row(
                    $types[$idSite][$period->rangeKey()] ?? [],
                    $numbers[$idSite][$period->rangeKey()] ?? [],
                    $columns,
                    $formatMetrics,
                );
            }
        }

        return new ApiReport($data, $dimensions);
    }

    /**
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>>  $types
     * @param  array<string, array<string, int|float>>  $numbers
     * @param  list<ReportingPeriod>  $periods
     * @param  list<string>|null  $columns
     * @return array<string, array<string, int|float|string>>
     */
    private function dateRows(
        array $types,
        array $numbers,
        array $periods,
        ?array $columns,
        bool $formatMetrics,
    ): array {
        $rows = [];

        foreach ($periods as $period) {
            $rows[$period->resultKey] = $this->row(
                $types[$period->rangeKey()] ?? [],
                $numbers[$period->rangeKey()] ?? [],
                $columns,
                $formatMetrics,
            );
        }

        return $rows;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $typeRows
     * @param  array<string, int|float>  $numbers
     * @param  list<string>|null  $columns
     * @return array<string, int|float|string>
     */
    private function row(
        array $typeRows,
        array $numbers,
        ?array $columns,
        bool $formatMetrics,
    ): array {
        $row = array_fill_keys(array_values(self::TYPE_METRICS), 0);
        $totalVisits = 0.0;

        foreach ($typeRows as $typeRow) {
            $type = (int) ($typeRow['columns']['label'] ?? 0);
            $visits = $typeRow['columns']['nb_visits'] ?? 0;

            if (! is_int($visits) && ! is_float($visits)) {
                continue;
            }

            $totalVisits += $visits;
            $metric = self::TYPE_METRICS[$type] ?? null;

            if ($metric !== null) {
                $row[$metric] = $visits;
            }
        }

        foreach (array_values(self::TYPE_METRICS) as $metric) {
            $row[$metric.'_percent'] = $this->percent($row[$metric], $totalVisits, $formatMetrics);
        }

        foreach (self::DISTINCT_METRICS as $metric) {
            $row[$metric] = $numbers[$metric] ?? 0;
        }

        if ($columns === null) {
            return $row;
        }

        return array_filter(
            $row,
            static fn (string $metric): bool => in_array($metric, $columns, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function percent(int|float $value, float $total, bool $format): int|float|string
    {
        $quotient = $total === 0.0 ? 0 : round($value / $total, 4);

        if (! $format) {
            return $quotient;
        }

        $percent = $quotient * 100;

        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'%';
    }
}
