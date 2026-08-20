<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;

final readonly class VisitorInterestReportBuilder
{
    /** @var array<string, array{string, string, string, bool}> */
    private const array REPORTS = [
        'VisitorInterest.getNumberOfVisitsPerVisitDuration' => [
            'VisitorInterest_timeGap', 'visitDuration', 'duration', true,
        ],
        'VisitorInterest.getNumberOfVisitsPerPage' => [
            'VisitorInterest_pageGap', 'actions', 'pages', true,
        ],
        'VisitorInterest.getNumberOfVisitsByDaysSinceLast' => [
            'VisitorInterest_daysSinceLastVisit', 'daysSinceLastVisit', 'days', false,
        ],
        'VisitorInterest.getNumberOfVisitsByVisitCount' => [
            'VisitorInterest_visitsByVisitCount', 'visitCount', 'visits', false,
        ],
    ];

    public function __construct(private BlobArchiveRepository $archives) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function build(
        string $method,
        array $siteIds,
        array $periods,
        string $segmentHash,
        bool $showMetadata,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiTableReport {
        [$recordName, $segmentName, $labelKind, $sort] = self::REPORTS[$method];
        $archiveRows = $this->archives->rows($siteIds, $periods, $segmentHash, $recordName);
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
                        $segmentName,
                        $labelKind,
                        $sort,
                        $showMetadata,
                    ),
                    [],
                );
            }

            return new ApiTableReport(
                $this->dateRows(
                    $archiveRows[$idSite] ?? [],
                    $periods,
                    $segmentName,
                    $labelKind,
                    $sort,
                    $showMetadata,
                ),
                $dimensions,
            );
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            if ($forceDateIndex) {
                $data[$idSite] = $this->dateRows(
                    $archiveRows[$idSite] ?? [],
                    $periods,
                    $segmentName,
                    $labelKind,
                    $sort,
                    $showMetadata,
                );
            } else {
                $period = $periods[0] ?? null;
                $data[$idSite] = $this->rows(
                    $period === null ? [] : ($archiveRows[$idSite][$period->rangeKey()] ?? []),
                    $segmentName,
                    $labelKind,
                    $sort,
                    $showMetadata,
                );
            }
        }

        return new ApiTableReport($data, $dimensions);
    }

    /**
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>>  $archiveRows
     * @param  list<ReportingPeriod>  $periods
     * @return array<string, list<array<string, float|int|string|null>>>
     */
    private function dateRows(
        array $archiveRows,
        array $periods,
        string $segmentName,
        string $labelKind,
        bool $sort,
        bool $showMetadata,
    ): array {
        $rows = [];

        foreach ($periods as $period) {
            $rows[$period->resultKey] = $this->rows(
                $archiveRows[$period->rangeKey()] ?? [],
                $segmentName,
                $labelKind,
                $sort,
                $showMetadata,
            );
        }

        return $rows;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $archiveRows
     * @return list<array<string, float|int|string|null>>
     */
    private function rows(
        array $archiveRows,
        string $segmentName,
        string $labelKind,
        bool $sort,
        bool $showMetadata,
    ): array {
        if ($sort) {
            usort($archiveRows, static fn (array $left, array $right): int => (int) ($left['columns']['label'] ?? 0) <=> (int) ($right['columns']['label'] ?? 0));
        }

        $total = array_sum(array_map(
            static fn (array $row): float|int => is_numeric($row['columns']['nb_visits'] ?? null)
                ? (float) $row['columns']['nb_visits']
                : 0,
            $archiveRows,
        ));
        $rows = [];

        foreach ($archiveRows as $archiveRow) {
            $row = $archiveRow['columns'];
            $label = is_string($row['label'] ?? null) || is_int($row['label'] ?? null)
                ? (string) $row['label']
                : '';
            $visits = $row['nb_visits'] ?? null;

            if ($showMetadata) {
                $row = [...$row, ...$archiveRow['metadata']];
            }

            if (is_float($visits) || is_int($visits)) {
                $row['nb_visits_percent_of_total'] = $this->percent($visits, $total);
            }

            if ($showMetadata) {
                $row['segment'] = $this->segment($label, $segmentName);
            }

            $row['label'] = $this->label($label, $labelKind);

            $rows[] = $row;
        }

        return $rows;
    }

    private function segment(string $label, string $segmentName): string
    {
        if ($label === 'General_NewVisits') {
            return 'visitorType==new';
        }

        [$minimum, $maximum] = $this->range($label);

        if ($maximum === null) {
            return $segmentName.'>='.$minimum;
        }

        return $minimum === $maximum
            ? $segmentName.'=='.$minimum
            : $segmentName.'>='.$minimum.';'.$segmentName.'<='.$maximum;
    }

    private function label(string $label, string $kind): string
    {
        if ($label === 'General_NewVisits') {
            return 'New visits';
        }

        [$minimum, $maximum] = $this->range($label);

        if ($kind === 'duration') {
            if ($maximum === null) {
                return floor($minimum / 60).'+ min';
            }

            return $minimum < 60
                ? $minimum.'–'.$maximum.'s'
                : (string) ceil($minimum / 60).'-'.ceil($maximum / 60).' min';
        }

        $singular = match ($kind) {
            'pages' => 'page',
            'days' => 'day',
            default => 'visit',
        };
        $plural = $singular.'s';

        if ($maximum === null) {
            return $minimum.'+ '.$plural;
        }

        if ($minimum === $maximum) {
            return $minimum.' '.($minimum === 1 ? $singular : $plural);
        }

        return $minimum.'-'.$maximum.' '.$plural;
    }

    /** @return array{int, int|null} */
    private function range(string $label): array
    {
        $label = urldecode($label);

        if (preg_match('/^(\d+)\s*-\s*(\d+)$/D', $label, $matches) === 1) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        preg_match('/^(\d+)/D', $label, $matches);

        return [(int) ($matches[1] ?? 0), null];
    }

    private function percent(float|int $value, float|int $total): string
    {
        $percent = (float) $total === 0.0 ? 0 : round((float) $value / (float) $total * 100, 1);

        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').'%';
    }
}
