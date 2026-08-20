<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiReport;

final readonly class PagePerformanceReportBuilder
{
    /** @var array<string, array{string, string}> */
    private const array AVERAGES = [
        'avg_time_network' => ['PagePerformance_network_time', 'PagePerformance_network_hits'],
        'avg_time_server' => ['PagePerformance_servery_time', 'PagePerformance_server_hits'],
        'avg_time_transfer' => ['PagePerformance_transfer_time', 'PagePerformance_transfer_hits'],
        'avg_time_dom_processing' => ['PagePerformance_domprocessing_time', 'PagePerformance_domprocessing_hits'],
        'avg_time_dom_completion' => ['PagePerformance_domcompletion_time', 'PagePerformance_domcompletion_hits'],
        'avg_time_on_load' => ['PagePerformance_onload_time', 'PagePerformance_onload_hits'],
        'avg_page_load_time' => ['PagePerformance_pageload_time', 'PagePerformance_pageload_hits'],
    ];

    public function __construct(private VisitsSummaryArchiveRepository $archives) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function build(
        array $siteIds,
        array $periods,
        string $segmentHash,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiReport {
        $metrics = array_values(array_unique(array_merge(...array_values(self::AVERAGES))));
        $archiveRows = $this->archives->metrics($siteIds, $periods, $segmentHash, $metrics);
        $dimensions = [...($forceSiteIndex ? ['idSite'] : []), ...($forceDateIndex ? ['date'] : [])];

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $period = $periods[0] ?? null;

                return new ApiReport($this->row(
                    $period === null ? [] : ($archiveRows[$idSite][$period->rangeKey()] ?? []),
                ), []);
            }

            return new ApiReport($this->dateRows($archiveRows[$idSite] ?? [], $periods), $dimensions);
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            if ($forceDateIndex) {
                $data[$idSite] = $this->dateRows($archiveRows[$idSite] ?? [], $periods);
            } else {
                $period = $periods[0] ?? null;
                $data[$idSite] = $this->row(
                    $period === null ? [] : ($archiveRows[$idSite][$period->rangeKey()] ?? []),
                );
            }
        }

        return new ApiReport($data, $dimensions);
    }

    /**
     * @param  array<string, array<string, float|int>>  $archiveRows
     * @param  list<ReportingPeriod>  $periods
     * @return array<string, array<string, float|int>>
     */
    private function dateRows(array $archiveRows, array $periods): array
    {
        $result = [];

        foreach ($periods as $period) {
            $result[$period->resultKey] = $this->row($archiveRows[$period->rangeKey()] ?? []);
        }

        return $result;
    }

    /**
     * @param  array<string, float|int>  $metrics
     * @return array<string, float|int>
     */
    private function row(array $metrics): array
    {
        $row = [];

        foreach (self::AVERAGES as $name => [$timeMetric, $hitsMetric]) {
            $time = ($metrics[$timeMetric] ?? 0) / 1000;
            $hits = $metrics[$hitsMetric] ?? 0;
            $row[$name] = (float) $hits === 0.0 ? 0 : round($time / $hits, 2);
        }

        return $row;
    }
}
