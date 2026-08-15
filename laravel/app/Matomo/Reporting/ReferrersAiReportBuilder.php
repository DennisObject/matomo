<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Referrers\ReferrerDefinitionCatalog;

/**
 * @phpstan-type ArchiveValue float|int|string|null
 * @phpstan-type ArchiveRow array{columns: array<string, ArchiveValue>, metadata: array<string, ArchiveValue>, subtableId: int|null}
 * @phpstan-type ArchiveRecords array<string, list<ArchiveRow>>
 */
final readonly class ReferrersAiReportBuilder
{
    private const string URL_RECORD = 'Referrers_entryUrlByAIAssistant';

    private const string TITLE_RECORD = 'Referrers_entryTitleByAIAssistant';

    private const string WEBSITE_RECORD = 'Referrers_urlByWebsite';

    public function __construct(
        private HierarchicalBlobArchiveRepository $archives,
        private ReferrerDefinitionCatalog $definitions,
        private MatomoTranslator $translator,
    ) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function build(
        string $method,
        ?string $secondaryDimension,
        ?int $idSubtable,
        bool $expanded,
        bool $flat,
        bool $showDimensions,
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $language,
        bool $showMetadata,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiTableReport {
        $record = $this->record($method, $secondaryDimension);
        $archives = $this->archives->records($siteIds, $periods, $segmentHash, $record, true);
        $websites = $method === 'Referrers.getAIAssistants'
            && $this->needsFallback($archives, $siteIds, $periods, $record)
            ? $this->archives->records($siteIds, $periods, $segmentHash, self::WEBSITE_RECORD, $expanded)
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
                    $archives[$idSite][$period->rangeKey()] ?? [],
                    $websites[$idSite][$period->rangeKey()] ?? [],
                    $record,
                    $method,
                    $idSubtable,
                    $expanded,
                    $flat,
                    $showDimensions,
                    $language,
                    $showMetadata,
                ), []);
            }

            return new ApiTableReport($this->dateRows(
                $archives[$idSite] ?? [],
                $websites[$idSite] ?? [],
                $periods,
                $record,
                $method,
                $idSubtable,
                $expanded,
                $flat,
                $showDimensions,
                $language,
                $showMetadata,
            ), $dimensions);
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            if ($forceDateIndex) {
                $data[$idSite] = $this->dateRows(
                    $archives[$idSite] ?? [],
                    $websites[$idSite] ?? [],
                    $periods,
                    $record,
                    $method,
                    $idSubtable,
                    $expanded,
                    $flat,
                    $showDimensions,
                    $language,
                    $showMetadata,
                );
            } else {
                $period = $periods[0] ?? null;
                $data[$idSite] = $period === null ? [] : $this->rows(
                    $archives[$idSite][$period->rangeKey()] ?? [],
                    $websites[$idSite][$period->rangeKey()] ?? [],
                    $record,
                    $method,
                    $idSubtable,
                    $expanded,
                    $flat,
                    $showDimensions,
                    $language,
                    $showMetadata,
                );
            }
        }

        return new ApiTableReport($data, $dimensions);
    }

    /**
     * @param  array<string, ArchiveRecords>  $archives
     * @param  array<string, ArchiveRecords>  $websites
     * @param  list<ReportingPeriod>  $periods
     * @return array<string, list<array<string, mixed>>>
     */
    private function dateRows(
        array $archives,
        array $websites,
        array $periods,
        string $record,
        string $method,
        ?int $idSubtable,
        bool $expanded,
        bool $flat,
        bool $showDimensions,
        string $language,
        bool $showMetadata,
    ): array {
        $rows = [];

        foreach ($periods as $period) {
            $rows[$period->resultKey] = $this->rows(
                $archives[$period->rangeKey()] ?? [],
                $websites[$period->rangeKey()] ?? [],
                $record,
                $method,
                $idSubtable,
                $expanded,
                $flat,
                $showDimensions,
                $language,
                $showMetadata,
            );
        }

        return $rows;
    }

    /**
     * @param  ArchiveRecords  $records
     * @param  ArchiveRecords  $websites
     * @return list<array<string, mixed>>
     */
    private function rows(
        array $records,
        array $websites,
        string $record,
        string $method,
        ?int $idSubtable,
        bool $expanded,
        bool $flat,
        bool $showDimensions,
        string $language,
        bool $showMetadata,
    ): array {
        $roots = $records[$record] ?? [];
        $totals = $this->totals($roots);

        if ($method !== 'Referrers.getAIAssistants') {
            $assistant = $this->parentLabel($roots, $idSubtable);
            $children = $idSubtable === null
                ? $this->allChildren($roots, $records, $record)
                : ($records[$record.'_'.$idSubtable] ?? []);

            return $this->entryRows(
                $children,
                $totals,
                $assistant,
                $record === self::TITLE_RECORD,
                $language,
                $showMetadata,
            );
        }

        if ($roots === []) {
            $roots = $this->fallbackRoots($websites);
            $totals = $this->totals($roots);
        }

        $rows = [];

        foreach ($roots as $root) {
            $assistant = (string) ($root['columns']['label'] ?? '');
            $row = $this->decorate($root['columns'], $totals);
            $this->assistantMetadata($row, $assistant, $showMetadata);
            $subtableId = $root['subtableId'];

            if ($flat && $subtableId !== null) {
                foreach ($records[$record.'_'.$subtableId] ?? [] as $child) {
                    $childRow = $this->decorate($child['columns'], $totals);
                    $entry = (string) ($child['columns']['label'] ?? '');
                    $childRow['label'] = $assistant.' - '.$entry;
                    $this->assistantMetadata($childRow, $assistant, $showMetadata);
                    unset($childRow['url']);
                    $childRow['Referrers_AIAssistant'] = $assistant;
                    $childRow[$record === self::TITLE_RECORD
                        ? 'Actions_EntryPageTitle'
                        : 'Actions_EntryPageUrl'] = $entry;
                    $rows[] = $childRow;
                }

                continue;
            }

            if ($showDimensions) {
                $row['Referrers_AIAssistant'] = $assistant;
            }

            if ($showMetadata && $subtableId !== null) {
                $row['idsubdatatable'] = $subtableId;
            }

            if ($expanded && $subtableId !== null) {
                $row['subtable'] = $this->entryRows(
                    $records[$record.'_'.$subtableId] ?? [],
                    $totals,
                    $assistant,
                    $record === self::TITLE_RECORD,
                    $language,
                    false,
                );
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<ArchiveRow>  $rows
     * @param  array<string, float>  $totals
     * @return list<array<string, mixed>>
     */
    private function entryRows(
        array $rows,
        array $totals,
        string $assistant,
        bool $titles,
        string $language,
        bool $showMetadata,
    ): array {
        $result = [];

        foreach ($rows as $archiveRow) {
            $rawLabel = (string) ($archiveRow['columns']['label'] ?? '');
            $row = $this->decorate($archiveRow['columns'], $totals);
            $row['label'] = $rawLabel === ''
                ? $this->translator->translate('General_NotDefined', $language, [
                    $this->translator->translate(
                        $titles ? 'Actions_ColumnPageName' : 'Actions_ColumnPageURL',
                        $language,
                    ),
                ])
                : ($titles ? $rawLabel : (preg_replace('#^https?://#i', '', $rawLabel) ?? $rawLabel));

            if ($showMetadata) {
                $row = [...$row, ...$archiveRow['metadata']];

                if (! $titles) {
                    $row['url'] = $rawLabel;
                }

                $dimension = $titles ? 'entryPageTitle' : 'entryPageUrl';
                $row['segment'] = ($assistant !== ''
                    ? 'referrerName=='.urlencode($assistant).';referrerType==ai;'
                    : '').$dimension.'=='.urlencode($rawLabel);
            }

            $result[] = $row;
        }

        return $result;
    }

    /** @param array<string, mixed> $row */
    private function assistantMetadata(array &$row, string $assistant, bool $showMetadata): void
    {
        if (! $showMetadata) {
            return;
        }

        $row['url'] = $this->definitions->aiAssistantUrl($assistant) ?? '';
        $row['logo'] = $this->definitions->aiAssistantLogo($assistant);
        $row['segment'] = 'referrerType==ai;referrerName=='.urlencode($assistant);
    }

    /**
     * @param  ArchiveRecords  $websites
     * @return list<ArchiveRow>
     */
    private function fallbackRoots(array $websites): array
    {
        $grouped = [];

        foreach ($websites[self::WEBSITE_RECORD] ?? [] as $row) {
            $name = $this->definitions->aiAssistantName((string) ($row['columns']['label'] ?? ''));

            if ($name === null) {
                continue;
            }

            if (! isset($grouped[$name])) {
                $row['columns']['label'] = $name;
                $row['subtableId'] = null;
                $grouped[$name] = $row;

                continue;
            }

            $grouped[$name]['columns'] = $this->sum($grouped[$name]['columns'], $row['columns']);
        }

        return array_values($grouped);
    }

    /**
     * @param  list<ArchiveRow>  $roots
     * @param  ArchiveRecords  $records
     * @return list<ArchiveRow>
     */
    private function allChildren(array $roots, array $records, string $record): array
    {
        $children = [];

        foreach ($roots as $root) {
            $id = $root['subtableId'];

            if ($id !== null) {
                $children = [...$children, ...($records[$record.'_'.$id] ?? [])];
            }
        }

        return $children;
    }

    /** @param list<ArchiveRow> $roots */
    private function parentLabel(array $roots, ?int $idSubtable): string
    {
        foreach ($roots as $root) {
            if ($root['subtableId'] === $idSubtable) {
                return (string) ($root['columns']['label'] ?? '');
            }
        }

        return '';
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
     * @param  array<int, array<string, ArchiveRecords>>  $archives
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    private function needsFallback(array $archives, array $siteIds, array $periods, string $record): bool
    {
        foreach ($siteIds as $idSite) {
            foreach ($periods as $period) {
                if (($archives[$idSite][$period->rangeKey()][$record] ?? []) === []) {
                    return true;
                }
            }
        }

        return false;
    }

    private function record(string $method, ?string $secondaryDimension): string
    {
        if ($method === 'Referrers.getEntryPageTitlesForAIAssistant'
            || ($method === 'Referrers.getAIAssistants' && $secondaryDimension === 'entryPageTitle')) {
            return self::TITLE_RECORD;
        }

        return self::URL_RECORD;
    }

    private function percent(float|int $value, float $total): string
    {
        $percent = $total === 0.0 ? 0 : round((float) $value / $total * 100, 1);

        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').'%';
    }
}
