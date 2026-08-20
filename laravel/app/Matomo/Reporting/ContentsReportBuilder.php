<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;
use App\Matomo\Localization\MatomoTranslator;

final readonly class ContentsReportBuilder
{
    public function __construct(
        private BlobArchiveRepository $archives,
        private MatomoTranslator $translator,
    ) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function build(
        bool $byName,
        ?int $idSubtable,
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $language,
        bool $showMetadata,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiTableReport {
        $recordName = $byName ? 'Contents_name_piece' : 'Contents_piece_name';

        if ($idSubtable !== null) {
            $recordName .= '_'.$idSubtable;
        }

        $archiveRows = $this->archives->rows($siteIds, $periods, $segmentHash, $recordName);
        $dimensions = [...($forceSiteIndex ? ['idSite'] : []), ...($forceDateIndex ? ['date'] : [])];

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $period = $periods[0] ?? null;

                return new ApiTableReport($this->rows(
                    $period === null ? [] : ($archiveRows[$idSite][$period->rangeKey()] ?? []),
                    $byName,
                    $idSubtable,
                    $language,
                    $showMetadata,
                ), []);
            }

            return new ApiTableReport($this->dateRows(
                $archiveRows[$idSite] ?? [],
                $periods,
                $byName,
                $idSubtable,
                $language,
                $showMetadata,
            ), $dimensions);
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            if ($forceDateIndex) {
                $data[$idSite] = $this->dateRows(
                    $archiveRows[$idSite] ?? [],
                    $periods,
                    $byName,
                    $idSubtable,
                    $language,
                    $showMetadata,
                );
            } else {
                $period = $periods[0] ?? null;
                $data[$idSite] = $this->rows(
                    $period === null ? [] : ($archiveRows[$idSite][$period->rangeKey()] ?? []),
                    $byName,
                    $idSubtable,
                    $language,
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
        bool $byName,
        ?int $idSubtable,
        string $language,
        bool $showMetadata,
    ): array {
        $rows = [];

        foreach ($periods as $period) {
            $rows[$period->resultKey] = $this->rows(
                $archiveRows[$period->rangeKey()] ?? [],
                $byName,
                $idSubtable,
                $language,
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
        bool $byName,
        ?int $idSubtable,
        string $language,
        bool $showMetadata,
    ): array {
        $totalVisits = array_sum(array_map(
            static fn (array $row): float => (float) ($row['columns']['nb_visits'] ?? 0),
            $archiveRows,
        ));
        $result = [];

        foreach ($archiveRows as $archiveRow) {
            $row = $archiveRow['columns'];
            $label = $row['label'] ?? '';
            $isSummary = $label === -1 || $label === '-1';
            $visits = $row['nb_visits'] ?? null;
            $impressions = $row['nb_impressions'] ?? null;
            $interactions = $row['nb_interactions'] ?? null;

            if ($showMetadata) {
                $row = [...$row, ...$archiveRow['metadata']];
            }

            if (is_float($visits) || is_int($visits)) {
                $row['nb_visits_percent_of_total'] = $this->percent($visits, $totalVisits, 1);
            }

            if ((is_float($impressions) || is_int($impressions))
                && (is_float($interactions) || is_int($interactions))) {
                $row['interaction_rate'] = $this->percent($interactions, $impressions, 2);
            }

            if ($isSummary) {
                $row['label'] = $this->translator->translate('General_Others', $language);
            } elseif ($label === 'Piwik_ContentPieceNotSet') {
                $contentPiece = $this->translator->translate('Contents_ContentPiece', $language);
                $row['label'] = $this->translator->translate('General_NotDefined', $language, [$contentPiece]);
            } elseif ($idSubtable === null && $showMetadata && is_string($label)) {
                $row['segment'] = ($byName ? 'contentName==' : 'contentPiece==').urlencode($label);
            }

            $result[] = $row;
        }

        return $result;
    }

    private function percent(float|int $value, float|int $total, int $precision): string
    {
        $percent = (float) $total === 0.0 ? 0 : round((float) $value / (float) $total * 100, $precision);

        return rtrim(rtrim(number_format($percent, $precision, '.', ''), '0'), '.').'%';
    }
}
