<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiMetricReport;
use App\Matomo\Api\ApiTableReport;
use App\Matomo\Geolocation\CountryMetadataProvider;

final readonly class UserCountryReportBuilder
{
    private const string COUNTRY_RECORD = 'UserCountry_country';

    private const string DISTINCT_COUNTRIES_METRIC = 'UserCountry_distinctCountries';

    public function __construct(
        private BlobArchiveRepository $blobs,
        private NumericArchiveRepository $numbers,
        private CountryMetadataProvider $countries,
    ) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function table(
        bool $continents,
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $language,
        bool $showMetadata,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiTableReport {
        $archiveRows = $this->blobs->rows($siteIds, $periods, $segmentHash, self::COUNTRY_RECORD);
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
                        $continents,
                        $language,
                        $showMetadata,
                    ),
                    [],
                );
            }

            return new ApiTableReport(
                $this->dateRows(
                    $archiveRows[$idSite] ?? [],
                    $periods,
                    $continents,
                    $language,
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
                    $continents,
                    $language,
                    $showMetadata,
                );
            } else {
                $period = $periods[0] ?? null;
                $data[$idSite] = $this->rows(
                    $period === null ? [] : ($archiveRows[$idSite][$period->rangeKey()] ?? []),
                    $continents,
                    $language,
                    $showMetadata,
                );
            }
        }

        return new ApiTableReport($data, $dimensions);
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function distinctCountries(
        array $siteIds,
        array $periods,
        string $segmentHash,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiMetricReport {
        $archive = $this->numbers->pluginMetrics(
            $siteIds,
            $periods,
            $segmentHash,
            [self::DISTINCT_COUNTRIES_METRIC],
            'UserCountry',
        );
        $dimensions = [
            ...($forceSiteIndex ? ['idSite'] : []),
            ...($forceDateIndex ? ['date'] : []),
        ];

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $period = $periods[0] ?? null;

                return new ApiMetricReport(
                    $period === null
                        ? 0
                        : ($archive[$idSite][$period->rangeKey()][self::DISTINCT_COUNTRIES_METRIC] ?? 0),
                    [],
                );
            }

            return new ApiMetricReport(
                $this->dateMetrics($archive[$idSite] ?? [], $periods),
                $dimensions,
            );
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            if ($forceDateIndex) {
                $data[$idSite] = $this->dateMetrics($archive[$idSite] ?? [], $periods);
            } else {
                $period = $periods[0] ?? null;
                $data[$idSite] = $period === null
                    ? 0
                    : ($archive[$idSite][$period->rangeKey()][self::DISTINCT_COUNTRIES_METRIC] ?? 0);
            }
        }

        return new ApiMetricReport($data, $dimensions);
    }

    /** @return array<string, string> */
    public function countryCodeMapping(string $language): array
    {
        $mapping = [];

        foreach ($this->countries->codes() as $code) {
            $mapping[$code] = $this->countries->countryName($code, $language);
        }

        return $mapping;
    }

    /**
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>>  $archiveRows
     * @param  list<ReportingPeriod>  $periods
     * @return array<string, list<array<string, float|int|string|null>>>
     */
    private function dateRows(
        array $archiveRows,
        array $periods,
        bool $continents,
        string $language,
        bool $showMetadata,
    ): array {
        $rows = [];

        foreach ($periods as $period) {
            $rows[$period->resultKey] = $this->rows(
                $archiveRows[$period->rangeKey()] ?? [],
                $continents,
                $language,
                $showMetadata,
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, int|float>>  $archive
     * @param  list<ReportingPeriod>  $periods
     * @return array<string, int|float>
     */
    private function dateMetrics(array $archive, array $periods): array
    {
        $metrics = [];

        foreach ($periods as $period) {
            $metrics[$period->resultKey] =
                $archive[$period->rangeKey()][self::DISTINCT_COUNTRIES_METRIC] ?? 0;
        }

        return $metrics;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $archiveRows
     * @return list<array<string, float|int|string|null>>
     */
    private function rows(array $archiveRows, bool $continents, string $language, bool $showMetadata): array
    {
        $grouped = $this->group($archiveRows, $continents);
        $totals = $this->totals($grouped);
        $result = [];

        foreach ($grouped as $archiveRow) {
            $row = $archiveRow['columns'];
            $code = is_string($row['label'] ?? null) ? $row['label'] : '';

            if ($showMetadata) {
                $row = [...$row, ...$archiveRow['metadata']];
            }

            foreach ($totals as $metric => $total) {
                $value = $row[$metric] ?? null;

                if (is_float($value) || is_int($value)) {
                    $row[$metric.'_percent_of_total'] = $this->percent($value, $total);
                }
            }

            if ($continents) {
                $label = $this->countries->continentName($code, $language);
                $row['label'] = $label;

                if ($showMetadata) {
                    $row['code'] = $label;
                }
            } else {
                $row['label'] = $this->countries->countryName($code, $language);

                if ($showMetadata) {
                    $row['code'] = $code;
                    $row['logo'] = $this->countries->flag($code);
                    $row['segment'] = 'countryCode=='.$code;
                    $row['logoHeight'] = 16;
                }
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $rows
     * @return list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>
     */
    private function group(array $rows, bool $continents): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $label = is_string($row['columns']['label'] ?? null) ? strtolower($row['columns']['label']) : '';
            $countryCode = $label === 'ti' ? 'cn' : $label;
            $key = $continents ? $this->countries->continentCode($countryCode) : $countryCode;

            if (! isset($grouped[$key])) {
                $grouped[$key] = ['columns' => ['label' => $key], 'metadata' => $row['metadata']];
            }

            foreach ($row['columns'] as $metric => $value) {
                if ($metric === 'label' || (! is_float($value) && ! is_int($value))) {
                    continue;
                }

                if ($metric === 'max_actions') {
                    $grouped[$key]['columns'][$metric] = max(
                        (float) ($grouped[$key]['columns'][$metric] ?? 0),
                        $value,
                    );
                } else {
                    $grouped[$key]['columns'][$metric] =
                        (float) ($grouped[$key]['columns'][$metric] ?? 0) + $value;
                }
            }
        }

        return array_values($grouped);
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $rows
     * @return array<string, int|float>
     */
    private function totals(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            foreach (['nb_visits', 'nb_actions', 'nb_visits_converted', 'nb_conversions', 'bounce_count', 'revenue'] as $metric) {
                $value = $row['columns'][$metric] ?? null;

                if (is_float($value) || is_int($value)) {
                    $totals[$metric] = ($totals[$metric] ?? 0) + $value;
                }
            }
        }

        return $totals;
    }

    private function percent(float|int $value, float|int $total): string
    {
        $percent = (float) $total === 0.0 ? 0 : round((float) $value / (float) $total * 100, 1);

        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').'%';
    }
}
