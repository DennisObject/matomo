<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;

/**
 * @phpstan-type ArchiveValue float|int|string|null
 * @phpstan-type ArchiveRow array{columns: array<string, ArchiveValue>, metadata: array<string, ArchiveValue>, subtableId: int|null}
 * @phpstan-type ArchiveRecords array<string, list<ArchiveRow>>
 */
final readonly class ReferrersWebsiteReportBuilder
{
    private const string RECORD = 'Referrers_urlByWebsite';

    public function __construct(private HierarchicalBlobArchiveRepository $archives) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function build(
        string $method,
        ?int $idSubtable,
        bool $expanded,
        bool $flat,
        bool $showDimensions,
        array $siteIds,
        array $periods,
        string $segmentHash,
        bool $showMetadata,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiTableReport {
        $archives = $this->archives->records(
            $siteIds,
            $periods,
            $segmentHash,
            self::RECORD,
            $expanded || $flat || $idSubtable !== null,
        );
        $dimensions = [
            ...($forceSiteIndex ? ['idSite'] : []),
            ...($forceDateIndex ? ['date'] : []),
        ];

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $period = $periods[0] ?? null;

                return new ApiTableReport($period === null ? [] : $this->rows(
                    $archives[$idSite][$period->rangeKey()] ?? [],
                    $method,
                    $idSubtable,
                    $expanded,
                    $flat,
                    $showDimensions,
                    $showMetadata,
                ), []);
            }

            return new ApiTableReport($this->dateRows(
                $archives[$idSite] ?? [],
                $periods,
                $method,
                $idSubtable,
                $expanded,
                $flat,
                $showDimensions,
                $showMetadata,
            ), $dimensions);
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            $data[$idSite] = $forceDateIndex
                ? $this->dateRows(
                    $archives[$idSite] ?? [],
                    $periods,
                    $method,
                    $idSubtable,
                    $expanded,
                    $flat,
                    $showDimensions,
                    $showMetadata,
                )
                : $this->singlePeriodRows(
                    $archives[$idSite] ?? [],
                    $periods[0] ?? null,
                    $method,
                    $idSubtable,
                    $expanded,
                    $flat,
                    $showDimensions,
                    $showMetadata,
                );
        }

        return new ApiTableReport($data, $dimensions);
    }

    /**
     * @param  array<string, ArchiveRecords>  $archives
     * @param  list<ReportingPeriod>  $periods
     * @return array<string, list<array<string, mixed>>>
     */
    private function dateRows(
        array $archives,
        array $periods,
        string $method,
        ?int $idSubtable,
        bool $expanded,
        bool $flat,
        bool $showDimensions,
        bool $showMetadata,
    ): array {
        $rows = [];

        foreach ($periods as $period) {
            $rows[$period->resultKey] = $this->rows(
                $archives[$period->rangeKey()] ?? [],
                $method,
                $idSubtable,
                $expanded,
                $flat,
                $showDimensions,
                $showMetadata,
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, ArchiveRecords>  $archives
     * @return list<array<string, mixed>>
     */
    private function singlePeriodRows(
        array $archives,
        ?ReportingPeriod $period,
        string $method,
        ?int $idSubtable,
        bool $expanded,
        bool $flat,
        bool $showDimensions,
        bool $showMetadata,
    ): array {
        return $period === null ? [] : $this->rows(
            $archives[$period->rangeKey()] ?? [],
            $method,
            $idSubtable,
            $expanded,
            $flat,
            $showDimensions,
            $showMetadata,
        );
    }

    /**
     * @param  ArchiveRecords  $records
     * @return list<array<string, mixed>>
     */
    private function rows(
        array $records,
        string $method,
        ?int $idSubtable,
        bool $expanded,
        bool $flat,
        bool $showDimensions,
        bool $showMetadata,
    ): array {
        if ($method === 'Referrers.getUrlsFromWebsiteId') {
            return $this->urlRows(
                $records[self::RECORD.'_'.$idSubtable] ?? [],
                $showMetadata,
                null,
                $showDimensions,
                false,
                true,
            );
        }

        $roots = $records[self::RECORD] ?? [];

        if ($flat) {
            return $this->flatRows($roots, $records, $showDimensions, $showMetadata);
        }

        $totals = $this->totals($roots);
        $rows = [];

        foreach ($roots as $archiveRow) {
            $website = (string) ($archiveRow['columns']['label'] ?? '');
            $row = $this->decorate($archiveRow['columns'], $totals);

            if ($showMetadata) {
                $row = [...$row, ...$archiveRow['metadata']];
                $row['segment'] = 'referrerName=='.urlencode($website);
            }

            $subtableId = $archiveRow['subtableId'];

            if ($showMetadata && $subtableId !== null) {
                $row['idsubdatatable'] = $subtableId;
            }

            if ($expanded && $subtableId !== null) {
                $row['subtable'] = $this->urlRows(
                    $records[self::RECORD.'_'.$subtableId] ?? [],
                    $showMetadata,
                    $website,
                    $showDimensions,
                    false,
                    false,
                );
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<ArchiveRow>  $roots
     * @param  ArchiveRecords  $records
     * @return list<array<string, mixed>>
     */
    private function flatRows(
        array $roots,
        array $records,
        bool $showDimensions,
        bool $showMetadata,
    ): array {
        $all = [];

        foreach ($roots as $root) {
            $website = (string) ($root['columns']['label'] ?? '');
            $subtableId = $root['subtableId'];

            if ($subtableId === null) {
                $row = $this->decorate($root['columns'], $this->totals($roots));
                $row['Referrers_Website'] = $website;
                $all[] = $row;

                continue;
            }

            $all = [
                ...$all,
                ...$this->urlRows(
                    $records[self::RECORD.'_'.$subtableId] ?? [],
                    $showMetadata,
                    $website,
                    $showDimensions,
                    true,
                    false,
                ),
            ];
        }

        $totals = $this->totalsFromRows($all);

        foreach ($all as &$row) {
            $row = $this->decorate($row, $totals);
        }

        unset($row);

        return $all;
    }

    /**
     * @param  list<ArchiveRow>  $archiveRows
     * @return list<array<string, mixed>>
     */
    private function urlRows(
        array $archiveRows,
        bool $showMetadata,
        ?string $website,
        bool $showDimensions,
        bool $flat = false,
        bool $directPath = false,
    ): array {
        if ($flat || $directPath) {
            $archiveRows = $this->groupByPath($archiveRows);
        }

        $totals = $this->totals($archiveRows);
        $rows = [];

        foreach ($archiveRows as $archiveRow) {
            $url = html_entity_decode((string) ($archiveRow['columns']['label'] ?? ''), ENT_QUOTES | ENT_HTML5);
            $row = $this->decorate($archiveRow['columns'], $totals);
            $path = $this->path($url);
            $row['label'] = $flat && $website !== null
                ? $website.'/'.$path
                : ($directPath ? $path : $url);

            if ($showMetadata) {
                $row = [...$row, ...$archiveRow['metadata'], 'url' => $url];
                $row['segment'] = 'referrerUrl=='.urlencode($url);
            }

            if ($flat || $showDimensions) {
                $row['Referrers_Website'] = $website ?? '';
                $row['Referrers_WebsitePage'] = $path;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<ArchiveRow>  $rows
     * @return list<ArchiveRow>
     */
    private function groupByPath(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $url = html_entity_decode((string) ($row['columns']['label'] ?? ''), ENT_QUOTES | ENT_HTML5);
            $path = $this->path($url);

            if (! isset($grouped[$path])) {
                $grouped[$path] = $row;

                continue;
            }

            foreach ($row['columns'] as $metric => $value) {
                if ($metric === 'label' || (! is_int($value) && ! is_float($value))) {
                    continue;
                }

                $current = $grouped[$path]['columns'][$metric] ?? 0;
                $grouped[$path]['columns'][$metric] = (is_int($current) || is_float($current))
                    ? $current + $value
                    : $value;
            }
        }

        return array_values($grouped);
    }

    private function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && trim($path, '/') !== '' ? trim($path, '/') : 'index';
    }

    /**
     * @param  array<string, float|int|string|null>  $columns
     * @param  array<string, float>  $totals
     * @return array<string, mixed>
     */
    private function decorate(array $columns, array $totals): array
    {
        $row = $columns;

        foreach (['nb_visits', 'nb_actions', 'nb_visits_converted', 'bounce_count'] as $metric) {
            $value = $row[$metric] ?? null;

            if (is_int($value) || is_float($value)) {
                $row[$metric.'_percent_of_total'] = $this->percent($value, $totals[$metric] ?? 0.0);
            }
        }

        return $row;
    }

    /**
     * @param  list<ArchiveRow>  $rows
     * @return array<string, float>
     */
    private function totals(array $rows): array
    {
        return $this->totalsFromRows(array_map(
            static fn (array $row): array => $row['columns'],
            $rows,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, float>
     */
    private function totalsFromRows(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            foreach ($row as $metric => $value) {
                if ($metric !== 'label' && (is_int($value) || is_float($value))) {
                    $totals[$metric] = ($totals[$metric] ?? 0.0) + $value;
                }
            }
        }

        return $totals;
    }

    private function percent(float|int $value, float $total): string
    {
        $percent = $total === 0.0 ? 0 : round((float) $value / $total * 100, 1);

        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').'%';
    }
}
