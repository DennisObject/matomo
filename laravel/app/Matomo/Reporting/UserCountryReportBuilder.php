<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiMetricReport;
use App\Matomo\Api\ApiTableReport;
use App\Matomo\Geolocation\CountryMetadataProvider;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Options\OptionRepository;
use Carbon\CarbonImmutable;
use Throwable;

final readonly class UserCountryReportBuilder
{
    private const string COUNTRY_RECORD = 'UserCountry_country';

    private const string DISTINCT_COUNTRIES_METRIC = 'UserCountry_distinctCountries';

    public function __construct(
        private BlobArchiveRepository $blobs,
        private BlobArchiveMetadataRepository $blobMetadata,
        private NumericArchiveRepository $numbers,
        private CountryMetadataProvider $countries,
        private OptionRepository $options,
        private MatomoTranslator $translator,
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
    public function locations(
        bool $cities,
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $language,
        bool $showMetadata,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiTableReport {
        $archives = $this->blobMetadata->archives(
            $siteIds,
            $periods,
            $segmentHash,
            $cities ? 'UserCountry_city' : 'UserCountry_region',
        );
        $dimensions = [
            ...($forceSiteIndex ? ['idSite'] : []),
            ...($forceDateIndex ? ['date'] : []),
        ];

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $period = $periods[0] ?? null;
                $archive = $period === null ? null : ($archives[$idSite][$period->rangeKey()] ?? null);

                return new ApiTableReport(
                    $period === null || $archive === null
                        ? []
                        : $this->locationRows($archive, $period, $cities, $language, $showMetadata),
                    [],
                );
            }

            return new ApiTableReport(
                $this->locationDateRows(
                    $archives[$idSite] ?? [],
                    $periods,
                    $cities,
                    $language,
                    $showMetadata,
                ),
                $dimensions,
            );
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            if ($forceDateIndex) {
                $data[$idSite] = $this->locationDateRows(
                    $archives[$idSite] ?? [],
                    $periods,
                    $cities,
                    $language,
                    $showMetadata,
                );
            } else {
                $period = $periods[0] ?? null;
                $archive = $period === null ? null : ($archives[$idSite][$period->rangeKey()] ?? null);
                $data[$idSite] = $period === null || $archive === null
                    ? []
                    : $this->locationRows($archive, $period, $cities, $language, $showMetadata);
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
     * @param  array<string, BlobArchive>  $archives
     * @param  list<ReportingPeriod>  $periods
     * @return array<string, list<array<string, float|int|string|null>>>
     */
    private function locationDateRows(
        array $archives,
        array $periods,
        bool $cities,
        string $language,
        bool $showMetadata,
    ): array {
        $rows = [];

        foreach ($periods as $period) {
            $archive = $archives[$period->rangeKey()] ?? null;
            $rows[$period->resultKey] = $archive === null
                ? []
                : $this->locationRows($archive, $period, $cities, $language, $showMetadata);
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

    /** @return list<array<string, float|int|string|null>> */
    private function locationRows(
        BlobArchive $archive,
        ReportingPeriod $period,
        bool $cities,
        string $language,
        bool $showMetadata,
    ): array {
        $rows = $this->groupLocations(
            $archive->rows,
            $cities,
            $this->shouldConvertRegions($period, $archive->archivedAt),
        );
        $totals = $this->totals($rows);
        $result = [];
        $unknown = $this->translator->translate('General_Unknown', $language);

        foreach ($rows as $archiveRow) {
            $row = $archiveRow['columns'];
            $label = is_string($row['label'] ?? null) ? $row['label'] : '';

            if ($label === '-1') {
                $row['label'] = $this->translator->translate('General_Others', $language);
                $result[] = $row;

                continue;
            }

            if ($showMetadata) {
                $row = [...$row, ...$archiveRow['metadata']];
            }

            foreach ($totals as $metric => $total) {
                $value = $row[$metric] ?? null;

                if (is_float($value) || is_int($value)) {
                    $row[$metric.'_percent_of_total'] = $this->percent($value, $total);
                }
            }

            $parts = explode('|', $label);
            $city = $cities ? $parts[0] : null;
            $region = $parts[$cities ? 1 : 0] ?? '';
            $country = strtolower($parts[$cities ? 2 : 1] ?? '');
            $country = $country === '' ? 'xx' : $country;
            $region = $region === '' ? 'xx' : $region;
            $countryName = $this->countries->countryName($country, $language);
            $regionName = $this->countries->regionName($country, $region, $language);

            if ($showMetadata) {
                $row['region'] = $region;
                $row['country'] = $country;
                $row['country_name'] = $countryName;
                $row['region_name'] = $regionName;
                $row['logo'] = $this->countries->flag($country);
            }

            if (! $cities) {
                $row['label'] = $country === 'xx' ? $unknown : $regionName.', '.$countryName;

                if ($showMetadata && $region !== 'xx' && $country !== 'xx') {
                    $row['segment'] = 'regionCode=='.urlencode($region).
                        ';countryCode=='.urlencode($country);
                }

                $result[] = $row;

                continue;
            }

            $cityName = in_array($city, [null, '', 'xx'], true) ? $unknown : $city;

            if ($showMetadata) {
                $row['city_name'] = $cityName;

                if ($cityName === $unknown) {
                    $row['city'] = 'xx';
                }

                foreach (['lat' => 3, 'long' => 4] as $metadata => $index) {
                    if (isset($parts[$index]) && $parts[$index] !== '') {
                        $row[$metadata] = $parts[$index];
                    }
                }

                if ($cityName !== $unknown && $region !== 'xx' && $country !== 'xx') {
                    $row['segment'] = 'city=='.urlencode($cityName).
                        ';regionCode=='.urlencode($region).
                        ';countryCode=='.urlencode($country);
                }
            }

            $row['label'] = $cityName;

            if ($country !== 'xx') {
                if ($region !== 'xx') {
                    $row['label'] .= ', '.$regionName;
                }

                $row['label'] .= ', '.$countryName;
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $rows
     * @return list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>
     */
    private function groupLocations(array $rows, bool $cities, bool $convertRegions): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $rawLabel = $row['columns']['label'] ?? '';
            $label = is_string($rawLabel) ? $rawLabel : (string) $rawLabel;

            if ($label !== '-1') {
                $parts = explode('|', $label);
                $regionIndex = $cities ? 1 : 0;
                $countryIndex = $cities ? 2 : 1;
                $region = $parts[$regionIndex] ?? '';
                $country = $parts[$countryIndex] ?? '';

                if ($convertRegions) {
                    $converted = $this->countries->convertLegacyRegion($country, $region);
                    $parts[$regionIndex] = $converted['region'];
                    $parts[$countryIndex] = $converted['country'];
                    $label = implode('|', $parts);
                } elseif ($region === '1' && strtolower($country) === 'ti') {
                    $parts[$regionIndex] = '14';
                    $parts[$countryIndex] = 'cn';
                    $label = implode('|', $parts);
                }
            }

            if (! isset($grouped[$label])) {
                $grouped[$label] = ['columns' => ['label' => $label], 'metadata' => $row['metadata']];
            }

            foreach ($row['columns'] as $metric => $value) {
                if ($metric === 'label' || (! is_float($value) && ! is_int($value))) {
                    continue;
                }

                if ($metric === 'max_actions') {
                    $grouped[$label]['columns'][$metric] = max(
                        (float) ($grouped[$label]['columns'][$metric] ?? 0),
                        $value,
                    );
                } else {
                    $grouped[$label]['columns'][$metric] =
                        (float) ($grouped[$label]['columns'][$metric] ?? 0) + $value;
                }
            }
        }

        return array_values($grouped);
    }

    private function shouldConvertRegions(ReportingPeriod $period, ?string $archivedAt): bool
    {
        $switch = $this->options->value('usercountry.switchtoisoregions');

        if (! is_numeric($switch) || (int) $switch < 1) {
            return false;
        }

        try {
            $switchDate = CarbonImmutable::createFromTimestampUTC((int) $switch);
            $periodStart = CarbonImmutable::parse($period->startDate, 'UTC');

            $converted = $this->options->value('regioncodes_converted');

            if (! in_array($converted, [null, '', '0'], true) && $archivedAt !== null) {
                $archiveDate = CarbonImmutable::parse($archivedAt, 'UTC');

                if ($archiveDate->isAfter($switchDate)) {
                    return false;
                }
            }

            return ! $switchDate->isBefore($periodStart);
        } catch (Throwable) {
            return false;
        }
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
