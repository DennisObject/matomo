<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;
use App\Matomo\Referrers\ReferrerDefinitionCatalog;

/**
 * @phpstan-type ArchiveValue float|int|string|null
 * @phpstan-type ArchiveRow array{columns: array<string, ArchiveValue>, metadata: array<string, ArchiveValue>, subtableId: int|null}
 * @phpstan-type ArchiveRecords array<string, list<ArchiveRow>>
 * @phpstan-type SocialRow array{name: string, columns: array<string, ArchiveValue>, urls: list<ArchiveRow>}
 */
final readonly class ReferrersSocialReportBuilder
{
    private const string SOCIAL_RECORD = 'Referrers_urlBySocialNetwork';

    private const string WEBSITE_RECORD = 'Referrers_urlByWebsite';

    public function __construct(
        private HierarchicalBlobArchiveRepository $archives,
        private ReferrerDefinitionCatalog $definitions,
    ) {}

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
        $social = $this->archives->records($siteIds, $periods, $segmentHash, self::SOCIAL_RECORD, true);
        $websites = $this->needsFallback($social, $siteIds, $periods)
            ? $this->archives->records($siteIds, $periods, $segmentHash, self::WEBSITE_RECORD, true)
            : [];
        $dimensions = [
            ...($forceSiteIndex ? ['idSite'] : []),
            ...($forceDateIndex ? ['date'] : []),
        ];

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $period = $periods[0] ?? null;

                return new ApiTableReport($period === null ? [] : $this->rows(
                    $social[$idSite][$period->rangeKey()] ?? [],
                    $websites[$idSite][$period->rangeKey()] ?? [],
                    $method,
                    $idSubtable,
                    $expanded,
                    $flat,
                    $showDimensions,
                    $showMetadata,
                ), []);
            }

            return new ApiTableReport($this->dateRows(
                $social[$idSite] ?? [],
                $websites[$idSite] ?? [],
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
            if ($forceDateIndex) {
                $data[$idSite] = $this->dateRows(
                    $social[$idSite] ?? [],
                    $websites[$idSite] ?? [],
                    $periods,
                    $method,
                    $idSubtable,
                    $expanded,
                    $flat,
                    $showDimensions,
                    $showMetadata,
                );
            } else {
                $period = $periods[0] ?? null;
                $data[$idSite] = $period === null ? [] : $this->rows(
                    $social[$idSite][$period->rangeKey()] ?? [],
                    $websites[$idSite][$period->rangeKey()] ?? [],
                    $method,
                    $idSubtable,
                    $expanded,
                    $flat,
                    $showDimensions,
                    $showMetadata,
                );
            }
        }

        return new ApiTableReport($data, $dimensions);
    }

    /**
     * @param  array<string, ArchiveRecords>  $social
     * @param  array<string, ArchiveRecords>  $websites
     * @param  list<ReportingPeriod>  $periods
     * @return array<string, list<array<string, mixed>>>
     */
    private function dateRows(
        array $social,
        array $websites,
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
                $social[$period->rangeKey()] ?? [],
                $websites[$period->rangeKey()] ?? [],
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
     * @param  ArchiveRecords  $socialRecords
     * @param  ArchiveRecords  $websiteRecords
     * @return list<array<string, mixed>>
     */
    private function rows(
        array $socialRecords,
        array $websiteRecords,
        string $method,
        ?int $idSubtable,
        bool $expanded,
        bool $flat,
        bool $showDimensions,
        bool $showMetadata,
    ): array {
        $socials = $this->socials($socialRecords, $websiteRecords);
        $totals = $this->totals(array_column($socials, 'columns'));

        if ($method === 'Referrers.getUrlsForSocial') {
            $selected = $idSubtable === null ? null : $this->definitions->socialNameAtPosition($idSubtable);
            $urls = [];

            foreach ($socials as $social) {
                if ($selected === null || $social['name'] === $selected) {
                    $urls = [...$urls, ...$social['urls']];
                }
            }

            return $this->urlRows($urls, $totals, $showMetadata);
        }

        $rows = [];

        foreach ($socials as $social) {
            if ($flat) {
                foreach ($social['urls'] as $url) {
                    $row = $this->decorate($url['columns'], $totals);
                    $fullUrl = html_entity_decode((string) ($url['columns']['label'] ?? ''), ENT_QUOTES | ENT_HTML5);
                    $row['label'] = $social['name'].' - '.$fullUrl;
                    $this->socialMetadata($row, $social['name'], $showMetadata);
                    $row['Referrers_SocialNetwork'] = $social['name'];
                    $row['Referrers_WebsitePage'] = $fullUrl;
                    $rows[] = $row;
                }

                continue;
            }

            $row = $this->decorate($social['columns'], $totals);
            $row['label'] = $social['name'];
            $this->socialMetadata($row, $social['name'], $showMetadata);

            if ($showDimensions) {
                $row['Referrers_SocialNetwork'] = $social['name'];
            }

            if ($expanded) {
                $row['subtable'] = $this->urlRows($social['urls'], $totals, false);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  ArchiveRecords  $socialRecords
     * @param  ArchiveRecords  $websiteRecords
     * @return list<SocialRow>
     */
    private function socials(array $socialRecords, array $websiteRecords): array
    {
        $roots = $socialRecords[self::SOCIAL_RECORD] ?? [];

        if ($roots !== []) {
            $socials = [];

            foreach ($roots as $root) {
                $name = (string) ($root['columns']['label'] ?? '');
                $name = $name === 'instagram' ? 'Instagram' : $name;
                $subtableId = $root['subtableId'];
                $socials[] = [
                    'name' => $name,
                    'columns' => $root['columns'],
                    'urls' => $subtableId === null ? [] : ($socialRecords[self::SOCIAL_RECORD.'_'.$subtableId] ?? []),
                ];
            }

            return $this->mergeSocials($socials);
        }

        $fallback = [];

        foreach ($websiteRecords[self::WEBSITE_RECORD] ?? [] as $root) {
            $url = (string) ($root['columns']['label'] ?? '');
            $name = $this->definitions->socialName($url);

            if ($name === null) {
                continue;
            }

            $subtableId = $root['subtableId'];
            $fallback[] = [
                'name' => $name,
                'columns' => $root['columns'],
                'urls' => $subtableId === null ? [] : ($websiteRecords[self::WEBSITE_RECORD.'_'.$subtableId] ?? []),
            ];
        }

        return $this->mergeSocials($fallback);
    }

    /**
     * @param  list<SocialRow>  $socials
     * @return list<SocialRow>
     */
    private function mergeSocials(array $socials): array
    {
        $merged = [];

        foreach ($socials as $social) {
            $name = $social['name'];

            if (! isset($merged[$name])) {
                $merged[$name] = $social;

                continue;
            }

            $merged[$name]['columns'] = $this->sum($merged[$name]['columns'], $social['columns']);
            $merged[$name]['urls'] = [...$merged[$name]['urls'], ...$social['urls']];
        }

        return array_values($merged);
    }

    /**
     * @param  list<ArchiveRow>  $urls
     * @param  array<string, float>  $totals
     * @return list<array<string, mixed>>
     */
    private function urlRows(array $urls, array $totals, bool $showMetadata): array
    {
        $rows = [];

        foreach ($urls as $urlRow) {
            $url = html_entity_decode((string) ($urlRow['columns']['label'] ?? ''), ENT_QUOTES | ENT_HTML5);
            $row = $this->decorate($urlRow['columns'], $totals);
            $row['label'] = preg_replace('#^https?://#i', '', $url) ?? $url;

            if ($showMetadata) {
                $row = [...$row, ...$urlRow['metadata'], 'url' => $url];
                $row['segment'] = 'referrerUrl=='.urlencode($url);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function socialMetadata(array &$row, string $name, bool $showMetadata): void
    {
        if (! $showMetadata) {
            return;
        }

        $row['url'] = $this->definitions->socialUrl($name) ?? '';
        $row['logo'] = $this->definitions->socialLogo($name);
        $row['segment'] = 'referrerType==social;referrerName=='.urlencode($name);
    }

    /**
     * @param  array<string, ArchiveValue>  $columns
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
     * @param  list<array<string, ArchiveValue>>  $rows
     * @return array<string, float>
     */
    private function totals(array $rows): array
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

    /**
     * @param  array<string, ArchiveValue>  $left
     * @param  array<string, ArchiveValue>  $right
     * @return array<string, ArchiveValue>
     */
    private function sum(array $left, array $right): array
    {
        foreach ($right as $metric => $value) {
            if ($metric !== 'label' && (is_int($value) || is_float($value))) {
                $current = $left[$metric] ?? 0;
                $left[$metric] = (is_int($current) || is_float($current)) ? $current + $value : $value;
            }
        }

        return $left;
    }

    /**
     * @param  array<int, array<string, ArchiveRecords>>  $social
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    private function needsFallback(array $social, array $siteIds, array $periods): bool
    {
        foreach ($siteIds as $idSite) {
            foreach ($periods as $period) {
                if (($social[$idSite][$period->rangeKey()][self::SOCIAL_RECORD] ?? []) === []) {
                    return true;
                }
            }
        }

        return false;
    }

    private function percent(float|int $value, float $total): string
    {
        $percent = $total === 0.0 ? 0 : round((float) $value / $total * 100, 1);

        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').'%';
    }
}
