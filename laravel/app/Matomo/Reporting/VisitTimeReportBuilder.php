<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;

final readonly class VisitTimeReportBuilder
{
    public function __construct(private BlobArchiveRepository $archives) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function build(
        array $siteIds,
        array $periods,
        string $segmentHash,
        bool $localTime,
        bool $showMetadata,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiTableReport {
        $archiveRows = $this->archives->rows(
            $siteIds,
            $periods,
            $segmentHash,
            $localTime ? 'VisitTime_localTime' : 'VisitTime_serverTime',
        );
        $dimensions = [
            ...($forceSiteIndex ? ['idSite'] : []),
            ...($forceDateIndex ? ['date'] : []),
        ];

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $period = $periods[0] ?? null;

                return new ApiTableReport(
                    $this->rows(
                        $period === null ? [] : ($archiveRows[$idSite][$period->rangeKey()] ?? []),
                        $localTime,
                        $showMetadata,
                    ),
                    [],
                );
            }

            return new ApiTableReport(
                $this->dateRows($archiveRows[$idSite] ?? [], $periods, $localTime, $showMetadata),
                $dimensions,
            );
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            if ($forceDateIndex) {
                $data[$idSite] = $this->dateRows(
                    $archiveRows[$idSite] ?? [],
                    $periods,
                    $localTime,
                    $showMetadata,
                );

                continue;
            }

            $period = $periods[0] ?? null;
            $data[$idSite] = $this->rows(
                $period === null ? [] : ($archiveRows[$idSite][$period->rangeKey()] ?? []),
                $localTime,
                $showMetadata,
            );
        }

        return new ApiTableReport($data, $dimensions);
    }

    /**
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>>  $archiveRows
     * @param  list<ReportingPeriod>  $periods
     * @return array<string, list<array<string, float|int|string|null>>>
     */
    private function dateRows(array $archiveRows, array $periods, bool $localTime, bool $showMetadata): array
    {
        $rows = [];

        foreach ($periods as $period) {
            $rows[$period->resultKey] = $this->rows(
                $archiveRows[$period->rangeKey()] ?? [],
                $localTime,
                $showMetadata,
            );
        }

        return $rows;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $archiveRows
     * @return list<array<string, float|int|string|null>>
     */
    private function rows(array $archiveRows, bool $localTime, bool $showMetadata): array
    {
        usort($archiveRows, static fn (array $left, array $right): int => (int) ($left['columns']['label'] ?? 0) <=> (int) ($right['columns']['label'] ?? 0));
        $totals = [];

        foreach ($archiveRows as $archiveRow) {
            foreach (['nb_visits', 'nb_actions', 'nb_visits_converted', 'bounce_count'] as $metric) {
                $value = $archiveRow['columns'][$metric] ?? null;

                if (is_float($value) || is_int($value)) {
                    $totals[$metric] = ($totals[$metric] ?? 0) + $value;
                }
            }
        }

        $rows = [];

        foreach ($archiveRows as $archiveRow) {
            $row = $archiveRow['columns'];
            $label = $row['label'] ?? null;

            if (is_numeric($label)) {
                $hour = (int) $label;
                $row['label'] = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);

                if ($showMetadata) {
                    $row['segment'] = ($localTime ? 'visitLocalHour==' : 'visitStartServerHour==').$hour;
                }
            }

            foreach (['nb_visits', 'nb_actions', 'nb_visits_converted', 'bounce_count'] as $metric) {
                $value = $row[$metric] ?? null;

                if ((is_float($value) || is_int($value)) && isset($totals[$metric])) {
                    $row[$metric.'_percent_of_total'] = $this->percent($value, $totals[$metric]);
                }
            }

            if ($showMetadata) {
                $row = [...$row, ...$archiveRow['metadata']];
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function percent(float|int $value, float|int $total): string
    {
        if ((float) $total === 0.0) {
            return '0%';
        }

        $percent = round((float) $value / (float) $total * 100, 1);

        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').'%';
    }
}
