<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiReport;

final readonly class ExamplePluginReportBuilder
{
    /** @var list<string> */
    private const array METRICS = [
        'ExamplePlugin_example_metric',
        'ExamplePlugin_example_metric2',
    ];

    public function __construct(private NumericArchiveRepository $archives) {}

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
        $archiveRows = $this->archives->pluginMetrics(
            $siteIds,
            $periods,
            $segmentHash,
            self::METRICS,
            'ExamplePlugin',
        );
        $dimensions = [
            ...($forceSiteIndex ? ['idSite'] : []),
            ...($forceDateIndex ? ['date'] : []),
        ];

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $key = ($periods[0] ?? null)?->rangeKey();

                return new ApiReport($this->row(
                    $key === null ? null : ($archiveRows[$idSite][$key] ?? null),
                    true,
                ), []);
            }

            return new ApiReport($this->dateRows(
                $archiveRows[$idSite] ?? [],
                $periods,
            ), $dimensions);
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            if ($forceDateIndex) {
                $data[$idSite] = $this->dateRows($archiveRows[$idSite] ?? [], $periods);

                continue;
            }

            $key = ($periods[0] ?? null)?->rangeKey();
            $data[$idSite] = $this->row(
                $key === null ? null : ($archiveRows[$idSite][$key] ?? null),
                false,
            );
        }

        return new ApiReport($data, $dimensions);
    }

    /**
     * @param  array<string, array<string, int|float>>  $archiveRows
     * @param  list<ReportingPeriod>  $periods
     * @return array<string, array<string, int|float>>
     */
    private function dateRows(array $archiveRows, array $periods): array
    {
        $result = [];

        foreach ($periods as $period) {
            $result[$period->resultKey] = $this->row(
                $archiveRows[$period->rangeKey()] ?? null,
                false,
            );
        }

        return $result;
    }

    /**
     * @param  array<string, int|float>|null  $archiveRow
     * @return array<string, int|float>
     */
    private function row(?array $archiveRow, bool $standalone): array
    {
        if ($archiveRow === null && ! $standalone) {
            return [];
        }

        $row = array_fill_keys(self::METRICS, 0);

        foreach ($archiveRow ?? [] as $metric => $value) {
            if (array_key_exists($metric, $row)) {
                $row[$metric] = $value;
            }
        }

        return $row;
    }
}
