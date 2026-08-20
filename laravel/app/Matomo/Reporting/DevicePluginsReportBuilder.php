<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;

final readonly class DevicePluginsReportBuilder
{
    public function __construct(
        private BlobArchiveRepository $blobs,
        private VisitsSummaryArchiveRepository $metrics,
    ) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function build(
        array $siteIds,
        array $periods,
        string $segmentHash,
        bool $showMetadata,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiTableReport {
        $plugins = $this->blobs->rows($siteIds, $periods, $segmentHash, 'DevicePlugins_plugin');
        $browsers = $this->blobs->rows($siteIds, $periods, $segmentHash, 'DevicesDetection_browserVersions');
        $visits = $this->metrics->metrics($siteIds, $periods, $segmentHash, ['nb_visits']);
        $dimensions = [...($forceSiteIndex ? ['idSite'] : []), ...($forceDateIndex ? ['date'] : [])];

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $period = $periods[0] ?? null;
                $key = $period?->rangeKey();

                return new ApiTableReport($key === null ? [] : $this->rows(
                    $plugins[$idSite][$key] ?? [],
                    $browsers[$idSite][$key] ?? [],
                    $visits[$idSite][$key]['nb_visits'] ?? 0,
                    $showMetadata,
                ), []);
            }

            return new ApiTableReport($this->dateRows(
                $plugins[$idSite] ?? [],
                $browsers[$idSite] ?? [],
                $visits[$idSite] ?? [],
                $periods,
                $showMetadata,
            ), $dimensions);
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            if ($forceDateIndex) {
                $data[$idSite] = $this->dateRows(
                    $plugins[$idSite] ?? [],
                    $browsers[$idSite] ?? [],
                    $visits[$idSite] ?? [],
                    $periods,
                    $showMetadata,
                );

                continue;
            }

            $key = ($periods[0] ?? null)?->rangeKey();
            $data[$idSite] = $key === null ? [] : $this->rows(
                $plugins[$idSite][$key] ?? [],
                $browsers[$idSite][$key] ?? [],
                $visits[$idSite][$key]['nb_visits'] ?? 0,
                $showMetadata,
            );
        }

        return new ApiTableReport($data, $dimensions);
    }

    /**
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>>  $plugins
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>>  $browsers
     * @param  array<string, array<string, float|int>>  $visits
     * @param  list<ReportingPeriod>  $periods
     * @return array<string, list<array<string, float|int|string|null>>>
     */
    private function dateRows(array $plugins, array $browsers, array $visits, array $periods, bool $showMetadata): array
    {
        $result = [];

        foreach ($periods as $period) {
            $key = $period->rangeKey();
            $result[$period->resultKey] = $this->rows(
                $plugins[$key] ?? [],
                $browsers[$key] ?? [],
                $visits[$key]['nb_visits'] ?? 0,
                $showMetadata,
            );
        }

        return $result;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $plugins
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $browsers
     * @return list<array<string, float|int|string|null>>
     */
    private function rows(array $plugins, array $browsers, float|int $totalVisits, bool $showMetadata): array
    {
        $ieVisits = 0.0;

        foreach ($browsers as $browser) {
            $label = $browser['columns']['label'] ?? null;

            if (in_array($label, ['IE;10.0', 'IE;9.0', 'IE;8.0', 'IE;7.0', 'IE;6.0'], true)) {
                $value = $browser['columns']['nb_visits'] ?? 0;
                $ieVisits += is_float($value) || is_int($value) ? $value : 0;
            }
        }

        $eligibleVisits = max(0, (float) $totalVisits - $ieVisits);
        $pluginVisits = array_sum(array_map(
            static fn (array $row): float => (float) ($row['columns']['nb_visits'] ?? 0),
            $plugins,
        ));
        $result = [];

        foreach ($plugins as $plugin) {
            $row = $plugin['columns'];
            $label = $row['label'] ?? '';
            $visits = $row['nb_visits'] ?? 0;
            $isSummary = $label === -1 || $label === '-1';

            if ($showMetadata) {
                $row = [...$row, ...$plugin['metadata']];
            }

            if ($isSummary) {
                $row['label'] = 'Others';
            } elseif (is_string($label)) {
                $row['label'] = ucfirst($label);

                if ($showMetadata) {
                    $row['logo'] = 'plugins/Morpheus/icons/dist/plugins/'.$label.'.png';
                }
            }

            if (is_float($visits) || is_int($visits)) {
                $row['nb_visits_percentage'] = $this->visitsPercent($visits, $eligibleVisits);
                $row['nb_visits_percent_of_total'] = $this->percent($visits, $pluginVisits);
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

    private function visitsPercent(float|int $value, float|int $total): string
    {
        $quotient = (float) $total === 0.0 ? 0 : round((float) $value / (float) $total, 2);

        return number_format($quotient * 100, 0, '.', '').'%';
    }
}
