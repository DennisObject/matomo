<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Referrers\SearchEngineDefinitionCatalog;

/**
 * @phpstan-type ArchiveValue float|int|string|null
 * @phpstan-type ArchiveRow array{columns: array<string, ArchiveValue>, metadata: array<string, ArchiveValue>, subtableId: int|null}
 * @phpstan-type ArchiveRecords array<string, list<ArchiveRow>>
 */
final readonly class ReferrersSearchReportBuilder
{
    private const string KEYWORDS_RECORD = 'Referrers_searchEngineByKeyword';

    private const string ENGINES_RECORD = 'Referrers_keywordBySearchEngine';

    public function __construct(
        private HierarchicalBlobArchiveRepository $archives,
        private SearchEngineDefinitionCatalog $definitions,
        private MatomoTranslator $translator,
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
        string $language,
        bool $showMetadata,
        bool $formatMetrics,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiTableReport {
        $record = $this->record($method);
        $archives = $this->archives->records(
            $siteIds,
            $periods,
            $segmentHash,
            $record,
            $expanded || $flat || $idSubtable !== null,
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
                    $record,
                    $method,
                    $idSubtable,
                    $expanded,
                    $flat,
                    $showDimensions,
                    $language,
                    $showMetadata,
                    $formatMetrics,
                ), []);
            }

            return new ApiTableReport($this->dateRows(
                $archives[$idSite] ?? [],
                $periods,
                $record,
                $method,
                $idSubtable,
                $expanded,
                $flat,
                $showDimensions,
                $language,
                $showMetadata,
                $formatMetrics,
            ), $dimensions);
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            if ($forceDateIndex) {
                $data[$idSite] = $this->dateRows(
                    $archives[$idSite] ?? [],
                    $periods,
                    $record,
                    $method,
                    $idSubtable,
                    $expanded,
                    $flat,
                    $showDimensions,
                    $language,
                    $showMetadata,
                    $formatMetrics,
                );
            } else {
                $period = $periods[0] ?? null;
                $data[$idSite] = $period === null ? [] : $this->rows(
                    $archives[$idSite][$period->rangeKey()] ?? [],
                    $record,
                    $method,
                    $idSubtable,
                    $expanded,
                    $flat,
                    $showDimensions,
                    $language,
                    $showMetadata,
                    $formatMetrics,
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
        string $record,
        string $method,
        ?int $idSubtable,
        bool $expanded,
        bool $flat,
        bool $showDimensions,
        string $language,
        bool $showMetadata,
        bool $formatMetrics,
    ): array {
        $rows = [];

        foreach ($periods as $period) {
            $rows[$period->resultKey] = $this->rows(
                $archives[$period->rangeKey()] ?? [],
                $record,
                $method,
                $idSubtable,
                $expanded,
                $flat,
                $showDimensions,
                $language,
                $showMetadata,
                $formatMetrics,
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
        string $record,
        string $method,
        ?int $idSubtable,
        bool $expanded,
        bool $flat,
        bool $showDimensions,
        string $language,
        bool $showMetadata,
        bool $formatMetrics,
    ): array {
        $roots = $records[$record] ?? [];
        $rootIsKeyword = $record === self::KEYWORDS_RECORD;
        $direct = str_contains($method, 'From');

        if ($direct) {
            $parent = $this->parentLabel($roots, $idSubtable);
            $children = $records[$record.'_'.$idSubtable] ?? [];

            return $this->levelRows(
                $children,
                $records,
                $record,
                ! $rootIsKeyword,
                $parent,
                false,
                false,
                $showDimensions,
                $language,
                $showMetadata,
                $this->totals($children),
                $formatMetrics,
            );
        }

        $totals = $this->totals($roots);

        if ($flat) {
            $rows = [];

            foreach ($roots as $root) {
                $parent = (string) ($root['columns']['label'] ?? '');
                $subtableId = $root['subtableId'];

                if ($subtableId === null) {
                    $row = $this->row(
                        $root,
                        $rootIsKeyword,
                        '',
                        $language,
                        $showMetadata,
                        $totals,
                        $formatMetrics,
                    );
                    $row[$rootIsKeyword ? 'Referrers_Keyword' : 'Referrers_SearchEngine'] = $row['label'];
                    $rows[] = $row;

                    continue;
                }

                $rows = [...$rows, ...$this->levelRows(
                    $records[$record.'_'.$subtableId] ?? [],
                    $records,
                    $record,
                    ! $rootIsKeyword,
                    $parent,
                    false,
                    true,
                    $showDimensions,
                    $language,
                    $showMetadata,
                    $totals,
                    $formatMetrics,
                )];
            }

            return $rows;
        }

        return $this->levelRows(
            $roots,
            $records,
            $record,
            $rootIsKeyword,
            '',
            $expanded,
            false,
            $showDimensions,
            $language,
            $showMetadata,
            $totals,
            $formatMetrics,
        );
    }

    /**
     * @param  list<ArchiveRow>  $archiveRows
     * @param  ArchiveRecords  $records
     * @param  array<string, float>  $totals
     * @return list<array<string, mixed>>
     */
    private function levelRows(
        array $archiveRows,
        array $records,
        string $record,
        bool $keywordRows,
        string $parent,
        bool $expanded,
        bool $flat,
        bool $showDimensions,
        string $language,
        bool $showMetadata,
        array $totals,
        bool $formatMetrics,
    ): array {
        $rows = [];

        foreach ($archiveRows as $archiveRow) {
            $row = $this->row(
                $archiveRow,
                $keywordRows,
                $parent,
                $language,
                $showMetadata,
                $totals,
                $formatMetrics,
            );
            $label = (string) $row['label'];
            $subtableId = $archiveRow['subtableId'];

            if ($flat) {
                $first = $keywordRows ? $label : $this->keywordLabel($parent, $language);
                $second = $keywordRows ? $parent : $label;
                $row['label'] = $first.' - '.$second;
                $row['Referrers_Keyword'] = $first;
                $row['Referrers_SearchEngine'] = $second;
            } elseif ($showDimensions) {
                $row[$keywordRows ? 'Referrers_Keyword' : 'Referrers_SearchEngine'] = $label;
            }

            if ($showMetadata && $subtableId !== null) {
                $row['idsubdatatable'] = $subtableId;
            }

            if ($expanded && $subtableId !== null) {
                $row['subtable'] = $this->levelRows(
                    $records[$record.'_'.$subtableId] ?? [],
                    $records,
                    $record,
                    ! $keywordRows,
                    $label,
                    true,
                    false,
                    $showDimensions,
                    $language,
                    $showMetadata,
                    $totals,
                    $formatMetrics,
                );
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  ArchiveRow  $archiveRow
     * @param  array<string, float>  $totals
     * @return array<string, mixed>
     */
    private function row(
        array $archiveRow,
        bool $keyword,
        string $parent,
        string $language,
        bool $showMetadata,
        array $totals,
        bool $formatMetrics,
    ): array {
        $rawLabel = (string) ($archiveRow['columns']['label'] ?? '');
        $label = $keyword ? $this->keywordLabel($rawLabel, $language) : $rawLabel;
        $row = $archiveRow['columns'];
        $row['label'] = $label;

        if ($showMetadata) {
            $row = [...$row, ...$archiveRow['metadata']];

            if ($keyword) {
                $engine = $parent;
                $row['segment'] = ($engine !== '' ? 'referrerName=='.urlencode($engine).';' : '').
                    'referrerType==search;referrerKeyword=='.urlencode($rawLabel);

                if ($engine !== '') {
                    $url = $this->definitions->url($engine);
                    $backlink = $this->definitions->backlink($url, $rawLabel);

                    if ($backlink !== null) {
                        $row['url'] = $backlink;
                    }
                }
            } else {
                $row['segment'] = ($parent !== '' ? 'referrerKeyword=='.urlencode($parent).';' : '').
                    'referrerType==search;referrerName=='.urlencode($rawLabel);
                $url = $this->definitions->url($rawLabel);
                $row['url'] = $parent === '' ? $url : ($this->definitions->backlink($url, $parent) ?? $url);
                $row['logo'] = $this->definitions->logo($url);
            }
        }

        foreach (['nb_visits', 'nb_actions', 'nb_visits_converted', 'bounce_count'] as $metric) {
            $value = $row[$metric] ?? null;

            if (is_int($value) || is_float($value)) {
                $row[$metric.'_percent_of_total'] = $this->percent(
                    $value,
                    $totals[$metric] ?? 0.0,
                    $formatMetrics,
                );
            }
        }

        return $row;
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

    private function keywordLabel(string $keyword, string $language): string
    {
        return $keyword === ''
            ? $this->translator->translate('General_NotDefined', $language, [
                $this->translator->translate('General_ColumnKeyword', $language),
            ])
            : $keyword;
    }

    private function record(string $method): string
    {
        return in_array($method, [
            'Referrers.getKeywords',
            'Referrers.getSearchEnginesFromKeywordId',
        ], true) ? self::KEYWORDS_RECORD : self::ENGINES_RECORD;
    }

    private function percent(float|int $value, float $total, bool $format): int|float|string
    {
        $quotient = $total === 0.0 ? 0 : round((float) $value / $total, 4);

        if (! $format) {
            return $quotient;
        }

        $percent = $quotient * 100;

        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').'%';
    }
}
