<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;
use Carbon\CarbonImmutable;

final readonly class VisitTimeReportBuilder
{
    /** @var list<string> */
    private const array DAY_METRICS = [
        'nb_uniq_visitors',
        'nb_visits',
        'nb_actions',
        'nb_users',
        'sum_visit_length',
        'bounce_count',
        'nb_visits_converted',
    ];

    /** @var array<int, string> */
    private const array DAY_NAMES = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    public function __construct(
        private BlobArchiveRepository $archives,
        private VisitsSummaryArchiveRepository $numericArchives,
    ) {}

    public function byDayOfWeek(
        int $idSite,
        ReportingPeriod $period,
        string $segmentHash,
        bool $showMetadata,
    ): ApiTableReport {
        $days = [];
        $date = CarbonImmutable::parse($period->startDate, 'UTC');
        $end = CarbonImmutable::parse($period->endDate, 'UTC');

        while ($date->lessThanOrEqualTo($end)) {
            $day = $date->toDateString();
            $days[] = new ReportingPeriod('day', 1, $day, $day, $day);
            $date = $date->addDay();
        }

        $archiveRows = $this->numericArchives->metrics(
            [$idSite],
            $days,
            $segmentHash,
            self::DAY_METRICS,
        )[$idSite] ?? [];

        if ($archiveRows === []) {
            return new ApiTableReport([], []);
        }

        $grouped = [];

        foreach ($archiveRows as $range => $metrics) {
            $day = (int) CarbonImmutable::parse(substr($range, 0, 10), 'UTC')->format('N');

            foreach ($metrics as $metric => $value) {
                $grouped[$day][$metric] = ($grouped[$day][$metric] ?? 0) + $value;
            }
        }

        $rows = [];

        foreach (self::DAY_NAMES as $day => $name) {
            $row = ['label' => $name, 'nb_visits' => 0, ...($grouped[$day] ?? [])];

            if ($showMetadata) {
                $row['day_of_week'] = $day;
            }

            $rows[] = $row;
        }

        return new ApiTableReport($this->addPercentMetrics($rows), []);
    }

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
        $rows = array_map(static fn (array $row): array => $row['columns'], $archiveRows);
        $rows = $this->addPercentMetrics($rows);

        $result = [];

        foreach ($rows as $index => $row) {
            $label = $row['label'] ?? null;

            if (is_numeric($label)) {
                $hour = (int) $label;
                $row['label'] = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);

                if ($showMetadata) {
                    $row['segment'] = ($localTime ? 'visitLocalHour==' : 'visitStartServerHour==').$hour;
                }
            }

            if ($showMetadata) {
                $row = [...$row, ...$archiveRows[$index]['metadata']];
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param  list<array<string, float|int|string|null>>  $rows
     * @return list<array<string, float|int|string|null>>
     */
    private function addPercentMetrics(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            foreach (['nb_visits', 'nb_actions', 'nb_visits_converted', 'bounce_count'] as $metric) {
                $value = $row[$metric] ?? null;

                if (is_float($value) || is_int($value)) {
                    $totals[$metric] = ($totals[$metric] ?? 0) + $value;
                }
            }
        }

        foreach ($rows as &$row) {
            foreach (['nb_visits', 'nb_actions', 'nb_visits_converted', 'bounce_count'] as $metric) {
                $value = $row[$metric] ?? null;

                if ((is_float($value) || is_int($value)) && isset($totals[$metric])) {
                    $row[$metric.'_percent_of_total'] = $this->percent($value, $totals[$metric]);
                }
            }
        }

        unset($row);

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
