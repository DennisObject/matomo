<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;
use App\Matomo\Localization\MatomoTranslator;
use DeviceDetector\Parser\Client\Browser;
use DeviceDetector\Parser\OperatingSystem;

final readonly class ResolutionReportBuilder
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
        bool $configuration,
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
            $configuration ? 'Resolution_configuration' : 'Resolution_resolution',
        );
        $dimensions = [
            ...($forceSiteIndex ? ['idSite'] : []),
            ...($forceDateIndex ? ['date'] : []),
        ];

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $period = $periods[0] ?? null;

                return new ApiTableReport($this->rows(
                    $period === null ? [] : ($archiveRows[$idSite][$period->rangeKey()] ?? []),
                    $configuration,
                    $language,
                    $showMetadata,
                ), []);
            }

            return new ApiTableReport($this->dateRows(
                $archiveRows[$idSite] ?? [],
                $periods,
                $configuration,
                $language,
                $showMetadata,
            ), $dimensions);
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            $data[$idSite] = $forceDateIndex
                ? $this->dateRows(
                    $archiveRows[$idSite] ?? [],
                    $periods,
                    $configuration,
                    $language,
                    $showMetadata,
                )
                : $this->rows(
                    ($periods[0] ?? null) === null
                        ? []
                        : ($archiveRows[$idSite][$periods[0]->rangeKey()] ?? []),
                    $configuration,
                    $language,
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
    private function dateRows(
        array $archiveRows,
        array $periods,
        bool $configuration,
        string $language,
        bool $showMetadata,
    ): array {
        $rows = [];

        foreach ($periods as $period) {
            $rows[$period->resultKey] = $this->rows(
                $archiveRows[$period->rangeKey()] ?? [],
                $configuration,
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
    private function rows(array $archiveRows, bool $configuration, string $language, bool $showMetadata): array
    {
        $rows = $configuration ? $this->groupConfigurations($archiveRows, $language) : $archiveRows;
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

            if ($showMetadata && ! $configuration && ! $isSummary && is_string($label)) {
                $row['segment'] = 'resolution=='.$label;
            }

            if ($isSummary) {
                $row['label'] = $this->translator->translate('General_Others', $language);
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $rows
     * @return list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>
     */
    private function groupConfigurations(array $rows, string $language): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $rawLabel = $row['columns']['label'] ?? '';
            $label = is_string($rawLabel) ? $this->configurationLabel($rawLabel, $language) : $rawLabel;
            $key = (string) $label;

            if (! isset($grouped[$key])) {
                $grouped[$key] = ['columns' => ['label' => $label], 'metadata' => $row['metadata']];
            }

            foreach ($row['columns'] as $metric => $value) {
                if ($metric === 'label' || (! is_float($value) && ! is_int($value))) {
                    continue;
                }

                $grouped[$key]['columns'][$metric] = $metric === 'max_actions'
                    ? max((float) ($grouped[$key]['columns'][$metric] ?? 0), $value)
                    : (float) ($grouped[$key]['columns'][$metric] ?? 0) + $value;
            }
        }

        return array_values($grouped);
    }

    private function configurationLabel(string $label, string $language): string
    {
        if (! str_contains($label, ';')) {
            return $label;
        }

        [$os, $browser, $resolution] = array_pad(explode(';', $label), 3, '');
        $unknown = $this->translator->translate('General_Unknown', $language);
        $osName = $this->osName($os) ?? $unknown;
        $browsers = Browser::getAvailableBrowsers();
        $browserCode = substr($browser, 0, 2);
        $browserName = isset($browsers[$browserCode])
            ? ucfirst($browsers[$browserCode])
            : (strlen($browser) > 2 && ! str_contains($browser, 'UNK') ? $browser : $unknown);

        return "{$osName} / {$browserName} / {$resolution}";
    }

    private function osName(string $label): ?string
    {
        if ($label === '') {
            return null;
        }

        if (str_starts_with($label, 'BOT')) {
            return 'Bot';
        }

        $legacy = ['IPA' => 'IOS', 'IPH' => 'IOS', 'IPD' => 'IOS', 'W10' => 'WIN', 'W2K' => 'WIN'];
        $short = $legacy[substr($label, 0, 3)] ?? substr($label, 0, 3);
        $version = substr($label, 4, 15);
        $name = OperatingSystem::getNameFromId($short, $version);

        return $name !== '' ? $name : null;
    }

    private function percent(float|int $value, float|int $total): string
    {
        $percent = (float) $total === 0.0 ? 0 : round((float) $value / (float) $total * 100, 1);

        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').'%';
    }
}
