<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;
use App\Matomo\Localization\MatomoTranslator;

final readonly class UserLanguageReportBuilder
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
        bool $groupLanguages,
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $language,
        bool $showMetadata,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiTableReport {
        $archiveRows = $this->archives->rows(
            $siteIds,
            $periods,
            $segmentHash,
            'UserLanguage_language',
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
                        $groupLanguages,
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
                    $groupLanguages,
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
                    $groupLanguages,
                    $language,
                    $showMetadata,
                );
            } else {
                $period = $periods[0] ?? null;
                $data[$idSite] = $this->rows(
                    $period === null ? [] : ($archiveRows[$idSite][$period->rangeKey()] ?? []),
                    $groupLanguages,
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
        bool $groupLanguages,
        string $language,
        bool $showMetadata,
    ): array {
        $rows = [];

        foreach ($periods as $period) {
            $rows[$period->resultKey] = $this->rows(
                $archiveRows[$period->rangeKey()] ?? [],
                $groupLanguages,
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
    private function rows(array $archiveRows, bool $groupLanguages, string $language, bool $showMetadata): array
    {
        $rows = $groupLanguages ? $this->group($archiveRows) : $archiveRows;
        $totals = [];

        foreach ($rows as $archiveRow) {
            foreach (['nb_visits', 'nb_actions', 'nb_visits_converted', 'bounce_count'] as $metric) {
                $value = $archiveRow['columns'][$metric] ?? null;

                if (is_float($value) || is_int($value)) {
                    $totals[$metric] = ($totals[$metric] ?? 0) + $value;
                }
            }
        }

        $result = [];

        foreach ($rows as $archiveRow) {
            $row = $archiveRow['columns'];
            $code = is_string($row['label'] ?? null) ? $row['label'] : '';

            if ($showMetadata) {
                $row = [...$row, ...$archiveRow['metadata']];
            }

            foreach (['nb_visits', 'nb_actions', 'nb_visits_converted', 'bounce_count'] as $metric) {
                $value = $row[$metric] ?? null;

                if ((is_float($value) || is_int($value)) && isset($totals[$metric])) {
                    $row[$metric.'_percent_of_total'] = $this->percent($value, $totals[$metric]);
                }
            }

            if ($showMetadata) {
                $row['segment'] = $groupLanguages
                    ? ($code === '' || $code === 'xx'
                        ? 'languageCode==xx'
                        : "languageCode=={$code},languageCode=@{$code}-")
                    : 'languageCode=='.$code;
            }

            $row['label'] = $groupLanguages
                ? $this->languageName($code, $language)
                : $this->localeName($code, $language);
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $rows
     * @return list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>
     */
    private function group(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $label = is_string($row['columns']['label'] ?? null) ? $row['columns']['label'] : '';
            $code = explode('-', $label)[0];

            if (! isset($grouped[$code])) {
                $grouped[$code] = ['columns' => ['label' => $code], 'metadata' => []];
            }

            foreach ($row['columns'] as $metric => $value) {
                if ($metric === 'label') {
                    continue;
                }

                if (is_float($value) || is_int($value)) {
                    if ($metric === 'max_actions') {
                        $grouped[$code]['columns'][$metric] = max(
                            (float) ($grouped[$code]['columns'][$metric] ?? 0),
                            $value,
                        );
                    } else {
                        $grouped[$code]['columns'][$metric] =
                            (float) ($grouped[$code]['columns'][$metric] ?? 0) + $value;
                    }
                }
            }
        }

        return array_values($grouped);
    }

    private function languageName(string $code, string $language): string
    {
        if ($code === '' || $code === 'xx') {
            return $this->translator->translate('General_Unknown', $language);
        }

        $key = 'Intl_Language_'.$code;
        $translation = $this->translator->translate($key, $language);

        return $translation === $key ? 'Language code '.$code : $translation;
    }

    private function localeName(string $code, string $language): string
    {
        $parts = explode('-', $code, 2);
        $base = $parts[0];
        $countryCode = $parts[1] ?? null;
        $name = $this->languageName($base, $language);

        if ($countryCode === null || $base === $countryCode) {
            return "{$name} ({$base})";
        }

        $key = 'Intl_Country_'.strtoupper($countryCode);
        $country = $this->translator->translate($key, $language);

        return $country === $key
            ? "{$name} ({$base})"
            : "{$name} - {$country} ({$code})";
    }

    private function percent(float|int $value, float|int $total): string
    {
        $percent = (float) $total === 0.0 ? 0 : round((float) $value / (float) $total * 100, 1);

        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').'%';
    }
}
