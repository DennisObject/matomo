<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;

/**
 * @phpstan-type ArchiveValue float|int|string|null
 * @phpstan-type ArchiveRow array{columns: array<string, ArchiveValue>, metadata: array<string, ArchiveValue>, subtableId: int|null}
 * @phpstan-type ArchiveRecords array<string, list<ArchiveRow>>
 */
final readonly class ReferrersCampaignReportBuilder
{
    private const string RECORD = 'Referrers_keywordByCampaign';

    public function __construct(private HierarchicalBlobArchiveRepository $archives) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function build(
        string $method,
        ?int $idSubtable,
        bool $expanded,
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
            $expanded || $idSubtable !== null,
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
                    $showMetadata,
                ), []);
            }

            return new ApiTableReport($this->dateRows(
                $archives[$idSite] ?? [],
                $periods,
                $method,
                $idSubtable,
                $expanded,
                $showMetadata,
            ), $dimensions);
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            if ($forceDateIndex) {
                $data[$idSite] = $this->dateRows(
                    $archives[$idSite] ?? [],
                    $periods,
                    $method,
                    $idSubtable,
                    $expanded,
                    $showMetadata,
                );
            } else {
                $period = $periods[0] ?? null;
                $data[$idSite] = $period === null ? [] : $this->rows(
                    $archives[$idSite][$period->rangeKey()] ?? [],
                    $method,
                    $idSubtable,
                    $expanded,
                    $showMetadata,
                );
            }
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
        bool $showMetadata,
    ): array {
        $rows = [];

        foreach ($periods as $period) {
            $rows[$period->resultKey] = $this->rows(
                $archives[$period->rangeKey()] ?? [],
                $method,
                $idSubtable,
                $expanded,
                $showMetadata,
            );
        }

        return $rows;
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
        bool $showMetadata,
    ): array {
        if ($method === 'Referrers.getKeywordsFromCampaignId') {
            $campaign = $this->parentLabel($records, $idSubtable);

            return $this->tableRows(
                $records[self::RECORD.'_'.$idSubtable] ?? [],
                $records,
                $campaign,
                true,
                false,
                $showMetadata,
            );
        }

        return $this->tableRows(
            $records[self::RECORD] ?? [],
            $records,
            '',
            false,
            $expanded,
            $showMetadata,
        );
    }

    /**
     * @param  list<ArchiveRow>  $archiveRows
     * @param  ArchiveRecords  $records
     * @return list<array<string, mixed>>
     */
    private function tableRows(
        array $archiveRows,
        array $records,
        string $campaign,
        bool $keywords,
        bool $expanded,
        bool $showMetadata,
    ): array {
        $totals = $this->totals($archiveRows);
        $result = [];

        foreach ($archiveRows as $archiveRow) {
            $label = (string) ($archiveRow['columns']['label'] ?? '');
            $row = $archiveRow['columns'];

            if ($showMetadata) {
                $row = [...$row, ...$archiveRow['metadata']];
                $row['segment'] = $keywords
                    ? 'referrerName=='.urlencode($campaign).';referrerType==campaign;referrerKeyword=='.urlencode($label)
                    : 'referrerType==campaign;referrerName=='.urlencode($label);
            }

            $this->processedMetrics($row, $totals);
            $subtableId = $archiveRow['subtableId'];

            if ($showMetadata && $subtableId !== null) {
                $row['idsubdatatable'] = $subtableId;
            }

            if ($expanded && $subtableId !== null) {
                $row['subtable'] = $this->tableRows(
                    $records[self::RECORD.'_'.$subtableId] ?? [],
                    $records,
                    $label,
                    true,
                    true,
                    $showMetadata,
                );
            }

            $result[] = $row;
        }

        return $result;
    }

    /** @param ArchiveRecords $records */
    private function parentLabel(array $records, ?int $idSubtable): string
    {
        foreach ($records[self::RECORD] ?? [] as $row) {
            if ($row['subtableId'] === $idSubtable) {
                return (string) ($row['columns']['label'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param  list<ArchiveRow>  $rows
     * @return array<string, float>
     */
    private function totals(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            foreach ($row['columns'] as $metric => $value) {
                if ($metric !== 'label' && (is_int($value) || is_float($value))) {
                    $totals[$metric] = ($totals[$metric] ?? 0.0) + $value;
                }
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, float>  $totals
     */
    private function processedMetrics(array &$row, array $totals): void
    {
        foreach (['nb_visits', 'nb_actions'] as $metric) {
            $value = $row[$metric] ?? null;

            if (is_int($value) || is_float($value)) {
                $row[$metric.'_percent_of_total'] = $this->percent($value, $totals[$metric] ?? 0.0);
            }
        }

        $visits = (float) ($row['nb_visits'] ?? 0);
        $actions = (float) ($row['nb_actions'] ?? 0);
        $length = (float) ($row['sum_visit_length'] ?? 0);
        $bounces = (float) ($row['bounce_count'] ?? 0);
        $row['nb_actions_per_visit'] = $visits === 0.0 ? 0 : round($actions / $visits, 1);
        $row['avg_time_on_site'] = $visits === 0.0 ? 0 : (int) round($length / $visits);
        $row['bounce_rate'] = $this->percent($bounces, $visits);
    }

    private function percent(float|int $value, float $total): string
    {
        $percent = $total === 0.0 ? 0 : round((float) $value / $total * 100, 1);

        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').'%';
    }
}
