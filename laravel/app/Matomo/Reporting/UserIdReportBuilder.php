<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;
use App\Matomo\Localization\MatomoTranslator;

final readonly class UserIdReportBuilder
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
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $language,
        bool $showMetadata,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiTableReport {
        $archiveRows = $this->archives->rows($siteIds, $periods, $segmentHash, 'UserId_users');
        $dimensions = [...($forceSiteIndex ? ['idSite'] : []), ...($forceDateIndex ? ['date'] : [])];

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $period = $periods[0] ?? null;

                return new ApiTableReport($this->rows(
                    $period === null ? [] : ($archiveRows[$idSite][$period->rangeKey()] ?? []),
                    $language,
                    $showMetadata,
                ), []);
            }

            return new ApiTableReport($this->dateRows(
                $archiveRows[$idSite] ?? [],
                $periods,
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
                    $language,
                    $showMetadata,
                );
            } else {
                $period = $periods[0] ?? null;
                $data[$idSite] = $this->rows(
                    $period === null ? [] : ($archiveRows[$idSite][$period->rangeKey()] ?? []),
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
    private function dateRows(array $archiveRows, array $periods, string $language, bool $showMetadata): array
    {
        $rows = [];

        foreach ($periods as $period) {
            $rows[$period->resultKey] = $this->rows(
                $archiveRows[$period->rangeKey()] ?? [],
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
    private function rows(array $archiveRows, string $language, bool $showMetadata): array
    {
        $totals = [];

        foreach ($archiveRows as $archiveRow) {
            foreach (['nb_visits', 'nb_actions', 'nb_visits_converted', 'bounce_count'] as $metric) {
                $value = $archiveRow['columns'][$metric] ?? null;

                if (is_float($value) || is_int($value)) {
                    $totals[$metric] = ($totals[$metric] ?? 0) + $value;
                }
            }
        }

        $result = [];

        foreach ($archiveRows as $archiveRow) {
            $row = $archiveRow['columns'];
            $label = $row['label'] ?? '';
            $isSummary = $label === -1 || $label === '-1';

            if ($showMetadata) {
                $row = [...$row, ...$archiveRow['metadata']];
            }

            foreach ($totals as $metric => $total) {
                $value = $row[$metric] ?? null;

                if (is_float($value) || is_int($value)) {
                    $row[$metric.'_percent_of_total'] = $this->percent($value, $total);
                }
            }

            if ($isSummary) {
                $row['label'] = $this->translator->translate('General_Others', $language);
            } elseif ($showMetadata && is_string($label) && $label !== '') {
                $row['segment'] = 'userId=='.urlencode($label);
            }

            $result[] = $row;
        }

        return $result;
    }

    private function percent(float|int $value, float|int $total): string
    {
        $percent = (float) $total === 0.0 ? 0 : round((float) $value / (float) $total * 100, 1);

        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').'%';
    }
}
