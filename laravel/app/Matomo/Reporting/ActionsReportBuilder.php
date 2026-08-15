<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiReport;
use App\Matomo\Api\ApiTableReport;
use App\Matomo\Archiving\ActionArchiveConfiguration;
use App\Matomo\Archiving\ActionArchivePathResolver;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Sites\SiteRepository;
use LogicException;

/**
 * @phpstan-type ArchiveValue float|int|string|null
 * @phpstan-type ArchiveRow array{columns: array<string, ArchiveValue>, metadata: array<string, ArchiveValue>, subtableId: int|null}
 * @phpstan-type ArchiveRecords array<string, list<ArchiveRow>>
 */
final readonly class ActionsReportBuilder
{
    /** @var list<string> */
    private const array OVERVIEW_METRICS = [
        'nb_pageviews',
        'nb_uniq_pageviews',
        'nb_downloads',
        'nb_uniq_downloads',
        'nb_outlinks',
        'nb_uniq_outlinks',
        'nb_searches',
        'nb_keywords',
        'hits',
    ];

    /** @var array<string, string> */
    private const array RECORDS = [
        'Actions.getPageUrls' => 'Actions_actions_url',
        'Actions.getPageUrlsFollowingSiteSearch' => 'Actions_actions_url',
        'Actions.getEntryPageUrls' => 'Actions_actions_url',
        'Actions.getExitPageUrls' => 'Actions_actions_url',
        'Actions.getPageUrl' => 'Actions_actions_url',
        'Actions.getPageTitles' => 'Actions_actions',
        'Actions.getPageTitlesFollowingSiteSearch' => 'Actions_actions',
        'Actions.getEntryPageTitles' => 'Actions_actions',
        'Actions.getExitPageTitles' => 'Actions_actions',
        'Actions.getPageTitle' => 'Actions_actions',
        'Actions.getDownloads' => 'Actions_downloads',
        'Actions.getDownload' => 'Actions_downloads',
        'Actions.getOutlinks' => 'Actions_outlink',
        'Actions.getOutlink' => 'Actions_outlink',
        'Actions.getSiteSearchKeywords' => 'Actions_sitesearch',
        'Actions.getSiteSearchNoResultKeywords' => 'Actions_sitesearch',
        'Actions.getSiteSearchCategories' => 'Actions_SiteSearchCategories',
    ];

    /** @var array<string, int> */
    private const array TYPES = [
        'Actions_actions_url' => ActionArchivePathResolver::PAGE_URL,
        'Actions_actions' => ActionArchivePathResolver::PAGE_TITLE,
        'Actions_downloads' => ActionArchivePathResolver::DOWNLOAD,
        'Actions_outlink' => ActionArchivePathResolver::OUTLINK,
        'Actions_sitesearch' => ActionArchivePathResolver::SITE_SEARCH,
        'Actions_SiteSearchCategories' => ActionArchivePathResolver::SITE_SEARCH,
    ];

    /** @var array<string, string> */
    private const array SEGMENT_DIMENSIONS = [
        'Actions_actions_url' => 'pageUrl',
        'Actions_actions' => 'pageTitle',
        'Actions_downloads' => 'downloadUrl',
        'Actions_outlink' => 'outlinkUrl',
        'Actions_sitesearch' => 'siteSearchKeyword',
        'Actions_SiteSearchCategories' => 'siteSearchCategory',
    ];

    /** @var array<string, string> */
    private const array FLAT_DIMENSIONS = [
        'Actions_actions_url' => 'Actions_PageUrl',
        'Actions_actions' => 'Actions_PageTitle',
        'Actions_downloads' => 'Actions_DownloadUrl',
        'Actions_outlink' => 'Actions_ClickedUrl',
    ];

    /** @var list<string> */
    private const array PERCENT_METRICS = [
        'nb_visits',
        'nb_hits',
        'entry_bounce_count',
        'entry_nb_visits',
        'entry_nb_actions',
        'exit_nb_visits',
    ];

    /** @var array<string, array{string, string}> */
    private const array AVERAGE_METRICS = [
        'avg_time_on_page' => ['sum_time_spent', 'nb_hits'],
        'avg_time_generation' => ['sum_time_generation', 'nb_hits_with_time_generation'],
        'avg_time_network' => ['sum_time_network', 'nb_hits_with_time_network'],
        'avg_time_server' => ['sum_time_server', 'nb_hits_with_time_server'],
        'avg_time_transfer' => ['sum_time_transfer', 'nb_hits_with_time_transfer'],
        'avg_time_dom_processing' => ['sum_time_dom_processing', 'nb_hits_with_time_dom_processing'],
        'avg_time_dom_completion' => ['sum_time_dom_completion', 'nb_hits_with_time_dom_completion'],
        'avg_time_on_load' => ['sum_time_on_load', 'nb_hits_with_time_on_load'],
        'avg_bandwidth' => ['sum_bandwidth', 'nb_hits_with_bandwidth'],
    ];

    public function __construct(
        private HierarchicalBlobArchiveRepository $archives,
        private NumericArchiveRepository $numbers,
        private MatomoTranslator $translator,
        private SiteRepository $sites,
        private ActionArchiveConfiguration $configuration,
        private ActionArchivePathResolver $paths,
    ) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @param  list<string>|null  $requestedColumns
     * @param  list<string>  $showColumns
     * @param  list<string>  $hideColumns
     */
    public function overview(
        array $siteIds,
        array $periods,
        string $segmentHash,
        ?array $requestedColumns,
        array $showColumns,
        array $hideColumns,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiReport {
        $outputMetrics = $requestedColumns ?? self::OVERVIEW_METRICS;
        $archiveMetrics = [];

        foreach ($outputMetrics as $metric) {
            if ($metric === 'avg_time_generation') {
                $archiveMetrics[] = 'Actions_sum_time_generation';
                $archiveMetrics[] = 'Actions_nb_hits_with_time_generation';
            } elseif (in_array($metric, self::OVERVIEW_METRICS, true)) {
                $archiveMetrics[] = 'Actions_'.$metric;
            }
        }

        $archiveMetrics = array_values(array_unique($archiveMetrics));
        $records = $this->numbers->pluginMetrics(
            $siteIds,
            $periods,
            $segmentHash,
            $archiveMetrics,
            'Actions',
        );
        $dimensions = [
            ...($forceSiteIndex ? ['idSite'] : []),
            ...($forceDateIndex ? ['date'] : []),
        ];

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $period = $periods[0] ?? null;

                return new ApiReport($this->overviewRow(
                    $period === null ? null : ($records[$idSite][$period->rangeKey()] ?? null),
                    $outputMetrics,
                    $showColumns,
                    $hideColumns,
                ), []);
            }

            return new ApiReport($this->overviewDateRows(
                $records[$idSite] ?? [],
                $periods,
                $outputMetrics,
                $showColumns,
                $hideColumns,
            ), $dimensions);
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            if ($forceDateIndex) {
                $data[$idSite] = $this->overviewDateRows(
                    $records[$idSite] ?? [],
                    $periods,
                    $outputMetrics,
                    $showColumns,
                    $hideColumns,
                );

                continue;
            }

            $period = $periods[0] ?? null;
            $data[$idSite] = $this->overviewRow(
                $period === null ? null : ($records[$idSite][$period->rangeKey()] ?? null),
                $outputMetrics,
                $showColumns,
                $hideColumns,
            );
        }

        return new ApiReport($data, $dimensions);
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function table(
        string $method,
        bool $expanded,
        bool $flat,
        bool $showDimensions,
        ?int $idSubtable,
        ?int $depth,
        ?string $actionValue,
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $language,
        bool $showMetadata,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiTableReport {
        $recordName = self::RECORDS[$method] ?? throw new LogicException(
            "The Actions API method '{$method}' is not supported.",
        );
        $requestedRecord = $idSubtable === null ? $recordName : $recordName.'_'.$idSubtable;
        $includeSubtables = $idSubtable === null && ($expanded || $flat || $actionValue !== null);
        $records = $this->archives->records(
            $siteIds,
            $periods,
            $segmentHash,
            $includeSubtables ? $recordName : $requestedRecord,
            $includeSubtables,
        );
        $rootRecords = $idSubtable === null
            ? $records
            : $this->archives->records($siteIds, $periods, $segmentHash, $recordName, false);
        $flatRecords = $this->flatRecords($method, $flat, $idSubtable, $siteIds, $periods, $segmentHash);
        $goalTotals = $this->goalTotals($records, $siteIds, $periods, $segmentHash);
        $dimensions = [
            ...($forceSiteIndex ? ['idSite'] : []),
            ...($forceDateIndex ? ['date'] : []),
        ];

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $period = $periods[0] ?? null;
                $key = $period?->rangeKey();

                return new ApiTableReport($key === null ? [] : $this->rows(
                    $records[$idSite][$key] ?? [],
                    $rootRecords[$idSite][$key] ?? [],
                    $flatRecords[$idSite][$key] ?? [],
                    $goalTotals[$idSite][$key] ?? [],
                    $recordName,
                    $requestedRecord,
                    $method,
                    $idSite,
                    $expanded,
                    $flat,
                    $showDimensions,
                    $depth,
                    $actionValue,
                    $language,
                    $showMetadata,
                ), []);
            }

            return new ApiTableReport($this->dateRows(
                $records[$idSite] ?? [],
                $rootRecords[$idSite] ?? [],
                $flatRecords[$idSite] ?? [],
                $goalTotals[$idSite] ?? [],
                $periods,
                $recordName,
                $requestedRecord,
                $method,
                $idSite,
                $expanded,
                $flat,
                $showDimensions,
                $depth,
                $actionValue,
                $language,
                $showMetadata,
            ), $dimensions);
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            if ($forceDateIndex) {
                $data[$idSite] = $this->dateRows(
                    $records[$idSite] ?? [],
                    $rootRecords[$idSite] ?? [],
                    $flatRecords[$idSite] ?? [],
                    $goalTotals[$idSite] ?? [],
                    $periods,
                    $recordName,
                    $requestedRecord,
                    $method,
                    $idSite,
                    $expanded,
                    $flat,
                    $showDimensions,
                    $depth,
                    $actionValue,
                    $language,
                    $showMetadata,
                );

                continue;
            }

            $key = ($periods[0] ?? null)?->rangeKey();
            $data[$idSite] = $key === null ? [] : $this->rows(
                $records[$idSite][$key] ?? [],
                $rootRecords[$idSite][$key] ?? [],
                $flatRecords[$idSite][$key] ?? [],
                $goalTotals[$idSite][$key] ?? [],
                $recordName,
                $requestedRecord,
                $method,
                $idSite,
                $expanded,
                $flat,
                $showDimensions,
                $depth,
                $actionValue,
                $language,
                $showMetadata,
            );
        }

        return new ApiTableReport($data, $dimensions);
    }

    /**
     * @param  array<string, array<string, list<ArchiveRow>>>  $records
     * @param  array<string, array<string, list<ArchiveRow>>>  $rootRecords
     * @param  array<string, array<string, list<ArchiveRow>>>  $flatRecords
     * @param  array<string, array<string, int|float>>  $goalTotals
     * @param  list<ReportingPeriod>  $periods
     * @return array<string, list<array<string, mixed>>>
     */
    private function dateRows(
        array $records,
        array $rootRecords,
        array $flatRecords,
        array $goalTotals,
        array $periods,
        string $recordName,
        string $requestedRecord,
        string $method,
        int $idSite,
        bool $expanded,
        bool $flat,
        bool $showDimensions,
        ?int $depth,
        ?string $actionValue,
        string $language,
        bool $showMetadata,
    ): array {
        $result = [];

        foreach ($periods as $period) {
            $key = $period->rangeKey();
            $result[$period->resultKey] = $this->rows(
                $records[$key] ?? [],
                $rootRecords[$key] ?? [],
                $flatRecords[$key] ?? [],
                $goalTotals[$key] ?? [],
                $recordName,
                $requestedRecord,
                $method,
                $idSite,
                $expanded,
                $flat,
                $showDimensions,
                $depth,
                $actionValue,
                $language,
                $showMetadata,
            );
        }

        return $result;
    }

    /**
     * @param  ArchiveRecords  $records
     * @param  ArchiveRecords  $rootRecords
     * @param  ArchiveRecords  $flatRecords
     * @param  array<string, int|float>  $goalTotals
     * @return list<array<string, mixed>>
     */
    private function rows(
        array $records,
        array $rootRecords,
        array $flatRecords,
        array $goalTotals,
        string $recordName,
        string $requestedRecord,
        string $method,
        int $idSite,
        bool $expanded,
        bool $flat,
        bool $showDimensions,
        ?int $depth,
        ?string $actionValue,
        string $language,
        bool $showMetadata,
    ): array {
        $rootRows = $rootRecords[$recordName] ?? $records[$requestedRecord] ?? [];
        $totals = $this->totals($rootRows);

        if ($actionValue !== null) {
            $found = $this->find($records[$recordName] ?? [], $records, $recordName, $actionValue);

            return $found === null ? [] : [$this->decorate(
                $found,
                $recordName,
                $method,
                $idSite,
                [],
                false,
                $totals,
                $goalTotals,
                $language,
                $showMetadata,
            )];
        }

        if ($flat) {
            $flatName = $this->flatRecordName($recordName);
            $preFlattened = $flatName === null ? [] : ($flatRecords[$flatName] ?? []);

            return $preFlattened !== []
                ? $this->preFlattenedRows(
                    $preFlattened,
                    $recordName,
                    $method,
                    $idSite,
                    $showDimensions,
                    $totals,
                    $goalTotals,
                    $language,
                    $showMetadata,
                )
                : $this->flattenRows(
                    $records[$requestedRecord] ?? [],
                    $records,
                    $recordName,
                    $method,
                    $idSite,
                    [],
                    $showDimensions,
                    $totals,
                    $goalTotals,
                    $language,
                    $showMetadata,
                );
        }

        return $this->normalRows(
            $records[$requestedRecord] ?? [],
            $records,
            $recordName,
            $method,
            $idSite,
            [],
            $expanded,
            $depth,
            1,
            $totals,
            $goalTotals,
            $language,
            $showMetadata,
        );
    }

    /**
     * @param  list<ArchiveRow>  $archiveRows
     * @param  ArchiveRecords  $records
     * @param  list<string>  $path
     * @param  array<string, float>  $totals
     * @param  array<string, int|float>  $goalTotals
     * @return list<array<string, mixed>>
     */
    private function normalRows(
        array $archiveRows,
        array $records,
        string $recordName,
        string $method,
        int $idSite,
        array $path,
        bool $expanded,
        ?int $depth,
        int $level,
        array $totals,
        array $goalTotals,
        string $language,
        bool $showMetadata,
    ): array {
        $result = [];

        foreach ($archiveRows as $archiveRow) {
            if (! $this->keep($archiveRow, $method)) {
                continue;
            }

            $label = $archiveRow['columns']['label'] ?? '';
            $rowPath = [...$path, (string) $label];
            $subtableId = $archiveRow['subtableId'];
            $row = $this->decorate(
                $archiveRow,
                $recordName,
                $method,
                $idSite,
                $rowPath,
                $subtableId !== null,
                $totals,
                $goalTotals,
                $language,
                $showMetadata,
            );

            if ($showMetadata && $subtableId !== null) {
                $row['idsubdatatable'] = $subtableId;
            }

            if ($expanded && $subtableId !== null && ($depth === null || $level < $depth)) {
                $row['subtable'] = $this->normalRows(
                    $records[$recordName.'_'.$subtableId] ?? [],
                    $records,
                    $recordName,
                    $method,
                    $idSite,
                    $rowPath,
                    true,
                    $depth,
                    $level + 1,
                    $totals,
                    $goalTotals,
                    $language,
                    $showMetadata,
                );
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param  list<ArchiveRow>  $archiveRows
     * @param  ArchiveRecords  $records
     * @param  list<string>  $path
     * @param  array<string, float>  $totals
     * @param  array<string, int|float>  $goalTotals
     * @return list<array<string, mixed>>
     */
    private function flattenRows(
        array $archiveRows,
        array $records,
        string $recordName,
        string $method,
        int $idSite,
        array $path,
        bool $showDimensions,
        array $totals,
        array $goalTotals,
        string $language,
        bool $showMetadata,
    ): array {
        $result = [];

        foreach ($archiveRows as $archiveRow) {
            $rawLabel = $archiveRow['columns']['label'] ?? '';
            $rowPath = [...$path, (string) $rawLabel];
            $subtableId = $archiveRow['subtableId'];
            $children = $subtableId === null ? [] : ($records[$recordName.'_'.$subtableId] ?? []);

            if ($children !== []) {
                $result = [...$result, ...$this->flattenRows(
                    $children,
                    $records,
                    $recordName,
                    $method,
                    $idSite,
                    $rowPath,
                    $showDimensions,
                    $totals,
                    $goalTotals,
                    $language,
                    $showMetadata,
                )];

                continue;
            }

            if (! $this->keep($archiveRow, $method)) {
                continue;
            }

            $row = $this->decorate(
                $archiveRow,
                $recordName,
                $method,
                $idSite,
                $rowPath,
                false,
                $totals,
                $goalTotals,
                $language,
                $showMetadata,
            );
            $row['label'] = $this->flatLabel($rowPath, $recordName, $language);

            if ($showDimensions && isset(self::FLAT_DIMENSIONS[$recordName])) {
                $row[self::FLAT_DIMENSIONS[$recordName]] = $row['label'];
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param  list<ArchiveRow>  $archiveRows
     * @param  array<string, float>  $totals
     * @param  array<string, int|float>  $goalTotals
     * @return list<array<string, mixed>>
     */
    private function preFlattenedRows(
        array $archiveRows,
        string $recordName,
        string $method,
        int $idSite,
        bool $showDimensions,
        array $totals,
        array $goalTotals,
        string $language,
        bool $showMetadata,
    ): array {
        $result = [];

        foreach ($archiveRows as $archiveRow) {
            if (! $this->keep($archiveRow, $method)) {
                continue;
            }

            $label = (string) ($archiveRow['columns']['label'] ?? '');
            $row = $this->decorate(
                $archiveRow,
                $recordName,
                $method,
                $idSite,
                [$label],
                false,
                $totals,
                $goalTotals,
                $language,
                $showMetadata,
            );
            $row['label'] = $this->flatLabel([$label], $recordName, $language);

            if ($showDimensions && isset(self::FLAT_DIMENSIONS[$recordName])) {
                $row[self::FLAT_DIMENSIONS[$recordName]] = $row['label'];
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param  ArchiveRow  $archiveRow
     * @param  list<string>  $path
     * @param  array<string, float>  $totals
     * @param  array<string, int|float>  $goalTotals
     * @return array<string, mixed>
     */
    private function decorate(
        array $archiveRow,
        string $recordName,
        string $method,
        int $idSite,
        array $path,
        bool $folder,
        array $totals,
        array $goalTotals,
        string $language,
        bool $showMetadata,
    ): array {
        $rawLabel = $archiveRow['columns']['label'] ?? '';
        $row = $archiveRow['columns'];
        $row['label'] = $this->label($rawLabel, $recordName, $language);
        $row = $this->goals($row, $goalTotals);

        if ($showMetadata) {
            $row = [...$row, ...$archiveRow['metadata']];
            unset($row['page_title_path'], $row['folder_url_start']);

            if (! $this->isSummary($rawLabel)) {
                $segment = $this->segment($archiveRow, $recordName, $idSite, $path, $folder);

                if ($segment !== null) {
                    $row['segment'] = $segment;
                }
            }
        }

        if ($this->isSummary($rawLabel)) {
            $row['is_summary'] = 1;
        }

        foreach (self::PERCENT_METRICS as $metric) {
            $value = $row[$metric] ?? null;

            if (is_float($value) || is_int($value)) {
                $row[$metric.'_percent_of_total'] = $this->percent($value, $totals[$metric] ?? 0.0);
            }
        }

        $this->addProcessedMetrics($row, $method);

        if (in_array($method, [
            'Actions.getSiteSearchKeywords',
            'Actions.getSiteSearchNoResultKeywords',
            'Actions.getSiteSearchCategories',
        ], true)) {
            unset($row['site_search_has_no_result']);
            $numerator = $method === 'Actions.getSiteSearchCategories'
                ? ($row['nb_actions'] ?? 0)
                : ($row['nb_hits'] ?? 0);
            $visits = (float) ($row['nb_visits'] ?? 0);
            $row['nb_pages_per_search'] = $this->numeric(
                $visits === 0.0 ? 0 : round((float) $numerator / $visits, 1),
            );
        }

        if ($method === 'Actions.getSiteSearchCategories') {
            unset($row['nb_uniq_visitors']);
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function addProcessedMetrics(array &$row, string $method): void
    {
        if (! str_starts_with($method, 'Actions.getPage')) {
            return;
        }

        foreach (self::AVERAGE_METRICS as $name => [$sumName, $countName]) {
            $sum = $row[$sumName] ?? null;
            $count = $row[$countName] ?? null;

            if ((! is_float($sum) && ! is_int($sum)) || (! is_float($count) && ! is_int($count))) {
                continue;
            }

            $precision = $name === 'avg_time_generation' ? 3 : 0;
            $row[$name] = $this->numeric($count == 0 ? 0 : round($sum / $count, $precision));
        }

        $entryVisits = $row['entry_nb_visits'] ?? null;
        $entryBounces = $row['entry_bounce_count'] ?? null;

        if ((is_float($entryVisits) || is_int($entryVisits))
            && (is_float($entryBounces) || is_int($entryBounces))) {
            $row['bounce_rate'] = $this->rate($entryBounces, $entryVisits);
        }

        $visits = $row['nb_visits'] ?? null;
        $exitVisits = $row['exit_nb_visits'] ?? null;

        if ((is_float($visits) || is_int($visits))
            && (is_float($exitVisits) || is_int($exitVisits))) {
            $row['exit_rate'] = $this->rate($exitVisits, $visits);
        }
    }

    /**
     * @param  array<string, ArchiveValue>  $row
     * @param  array<string, int|float>  $goalTotals
     * @return array<string, mixed>
     */
    private function goals(array $row, array $goalTotals): array
    {
        $goals = [];

        foreach (array_keys($row) as $column) {
            if (preg_match('/^goal_(-?[0-9]+)_(.+)$/D', $column, $matches) !== 1) {
                continue;
            }

            $goals[(int) $matches[1]][$matches[2]] = $row[$column];
            unset($row[$column]);
        }

        foreach ($goals as $goalId => &$metrics) {
            $unique = $metrics['nb_conversions_page_uniq'] ?? null;
            $total = $goalTotals['Goal_'.$goalId.'_nb_conversions'] ?? null;

            if ((is_float($unique) || is_int($unique)) && (is_float($total) || is_int($total))) {
                $metrics['nb_conversions_page_rate'] = min(
                    1,
                    $total == 0 ? 0 : round($unique / $total, 3),
                );
            }
        }

        unset($metrics);

        if ($goals !== []) {
            ksort($goals);
            $row['goals'] = $goals;
        }

        return $row;
    }

    /**
     * @param  ArchiveRow  $archiveRow
     * @param  list<string>  $path
     */
    private function segment(
        array $archiveRow,
        string $recordName,
        int $idSite,
        array $path,
        bool $folder,
    ): ?string {
        $dimension = self::SEGMENT_DIMENSIONS[$recordName];

        if ($recordName === 'Actions_actions_url') {
            $url = $archiveRow['metadata']['url'] ?? null;

            if (is_string($url) && $url !== '') {
                return $dimension.'=='.$this->doubleEncode($url);
            }

            if (! $folder || $path === [] || $this->unknown($path[array_key_last($path)], $recordName)) {
                return null;
            }

            $folderPath = implode('/', array_map(
                static fn (string $label): string => trim($label, '/'),
                $path,
            ));
            $clauses = [];

            foreach ($this->sites->urls($idSite) as $siteUrl) {
                if (parse_url($siteUrl, PHP_URL_HOST) === null) {
                    continue;
                }

                $clauses[] = $dimension.'=^'.$this->doubleEncode(
                    rtrim($siteUrl, '/').'/'.$folderPath,
                );
            }

            return $clauses === [] ? null : implode(',', array_values(array_unique($clauses)));
        }

        if ($recordName === 'Actions_actions') {
            $title = $archiveRow['metadata']['page_title_path'] ?? null;
            $value = is_string($title) && $title !== ''
                ? trim($title)
                : trim(implode($this->configuration->titleDelimiter, $path));

            return $value === '' || $this->unknown($value, $recordName)
                ? null
                : $dimension.($folder ? '=^' : '==').$this->doubleEncode($value);
        }

        $value = $archiveRow['metadata']['url'] ?? ($archiveRow['columns']['label'] ?? null);

        if ($folder && in_array($recordName, ['Actions_downloads', 'Actions_outlink'], true)) {
            return null;
        }

        return is_string($value) && $value !== ''
            ? $dimension.'=='.$this->doubleEncode($value)
            : null;
    }

    /** @param ArchiveRow $row */
    private function keep(array $row, string $method): bool
    {
        if ($this->isSummary($row['columns']['label'] ?? null)
            && $method === 'Actions.getSiteSearchNoResultKeywords') {
            return false;
        }

        return match ($method) {
            'Actions.getPageUrlsFollowingSiteSearch',
            'Actions.getPageTitlesFollowingSiteSearch' => (float) ($row['columns']['nb_hits_following_search'] ?? 0) > 0,
            'Actions.getEntryPageUrls',
            'Actions.getEntryPageTitles' => array_key_exists('entry_nb_visits', $row['columns'])
                && (string) $row['columns']['entry_nb_visits'] !== '',
            'Actions.getExitPageUrls',
            'Actions.getExitPageTitles' => array_key_exists('exit_nb_visits', $row['columns'])
                && (string) $row['columns']['exit_nb_visits'] !== '',
            'Actions.getSiteSearchNoResultKeywords' => (float) ($row['columns']['site_search_has_no_result'] ?? 0) >= 1,
            default => true,
        };
    }

    /**
     * @param  list<ArchiveRow>  $rows
     * @param  ArchiveRecords  $records
     * @return ArchiveRow|null
     */
    private function find(
        array $rows,
        array $records,
        string $recordName,
        string $search,
    ): ?array {
        $type = self::TYPES[$recordName];
        $searchPath = $this->paths->path($search, $type, null);
        $current = $rows;
        $found = null;

        foreach ($searchPath as $label) {
            $found = null;

            foreach ($current as $row) {
                if ((string) ($row['columns']['label'] ?? '') === (string) $label) {
                    $found = $row;
                    break;
                }
            }

            if ($found === null) {
                return null;
            }

            $subtableId = $found['subtableId'];
            $current = $subtableId === null ? [] : ($records[$recordName.'_'.$subtableId] ?? []);
        }

        return $found;
    }

    /**
     * @param  list<ArchiveRow>  $rows
     * @return array<string, float>
     */
    private function totals(array $rows): array
    {
        $totals = array_fill_keys(self::PERCENT_METRICS, 0.0);

        foreach ($rows as $row) {
            foreach ($totals as $metric => $value) {
                $totals[$metric] = $value + (float) ($row['columns'][$metric] ?? 0);
            }
        }

        return $totals;
    }

    /**
     * @param  array<int, array<string, ArchiveRecords>>  $records
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @return array<int, array<string, array<string, int|float>>>
     */
    private function goalTotals(
        array $records,
        array $siteIds,
        array $periods,
        string $segmentHash,
    ): array {
        $goalIds = [];

        foreach ($records as $siteRecords) {
            foreach ($siteRecords as $periodRecords) {
                foreach ($periodRecords as $rows) {
                    foreach ($rows as $row) {
                        foreach (array_keys($row['columns']) as $column) {
                            if (preg_match('/^goal_(-?[0-9]+)_/D', $column, $matches) === 1) {
                                $goalIds[(int) $matches[1]] = true;
                            }
                        }
                    }
                }
            }
        }

        if ($goalIds === []) {
            return [];
        }

        $metrics = array_map(
            static fn (int $goalId): string => 'Goal_'.$goalId.'_nb_conversions',
            array_keys($goalIds),
        );

        return $this->numbers->pluginMetrics(
            $siteIds,
            $periods,
            $segmentHash,
            $metrics,
            'Goals',
        );
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @return array<int, array<string, ArchiveRecords>>
     */
    private function flatRecords(
        string $method,
        bool $flat,
        ?int $idSubtable,
        array $siteIds,
        array $periods,
        string $segmentHash,
    ): array {
        if (! $flat || $idSubtable !== null) {
            return [];
        }

        $recordName = self::RECORDS[$method];
        $flatRecordName = $this->flatRecordName($recordName);

        if ($flatRecordName === null || $this->configuration->flatLimit <= 0) {
            return [];
        }

        return $this->archives->records(
            $siteIds,
            $periods,
            $segmentHash,
            $flatRecordName,
            false,
        );
    }

    private function flatRecordName(string $recordName): ?string
    {
        return match ($recordName) {
            'Actions_actions_url' => 'Actions_actions_url_flat',
            'Actions_actions' => 'Actions_actions_flat',
            default => null,
        };
    }

    /** @param list<string> $path */
    private function flatLabel(array $path, string $recordName, string $language): string
    {
        if ($path === []) {
            return '';
        }

        $last = $path[array_key_last($path)];

        if ($this->isSummary($last)) {
            array_pop($path);
            $summary = $this->translator->translate('General_Others', $language);

            if ($path === []) {
                return $summary;
            }

            $parent = $this->paths->flatLabel($path, self::TYPES[$recordName]);

            if ($recordName === 'Actions_actions_url') {
                $parent = rtrim($parent, $this->configuration->urlDelimiter)
                    .$this->configuration->urlDelimiter;
            }

            return $parent.' - '.$summary;
        }

        $label = $this->paths->flatLabel($path, self::TYPES[$recordName]);

        if ($recordName === 'Actions_actions_url') {
            $suffix = $this->configuration->urlDelimiter.$this->configuration->defaultActionName;

            if ($suffix !== '' && str_ends_with($label, $suffix)) {
                $label = rtrim(substr($label, 0, -strlen($this->configuration->defaultActionName)),
                    $this->configuration->urlDelimiter).$this->configuration->urlDelimiter;
            }
        }

        return $label;
    }

    private function label(float|int|string|null $label, string $recordName, string $language): string
    {
        if ($this->isSummary($label)) {
            return $this->translator->translate('General_Others', $language);
        }

        $value = (string) $label;

        if (! $this->unknown($value, $recordName)) {
            return $value;
        }

        $dimension = $recordName === 'Actions_actions'
            ? $this->translator->translate('Actions_PageTitle', $language)
            : $this->translator->translate('Actions_PageUrl', $language);

        return $this->translator->translate('General_NotDefined', $language, [$dimension]);
    }

    private function unknown(string $value, string $recordName): bool
    {
        return $recordName === 'Actions_actions'
            ? trim($value) === 'Page Name not defined'
            : trim($value) === 'Page URL not defined';
    }

    private function isSummary(float|int|string|null $label): bool
    {
        return $label === -1 || $label === '-1';
    }

    private function doubleEncode(string $value): string
    {
        return urlencode(urlencode($value));
    }

    private function percent(float|int $value, float $total): string
    {
        $percent = $total === 0.0 ? 0 : round((float) $value / $total * 100, 1);

        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').'%';
    }

    private function rate(float|int $value, float|int $total): string
    {
        $percent = $total == 0 ? 0 : round((float) $value / $total, 2) * 100;

        return rtrim(rtrim(number_format($percent, 4, '.', ''), '0'), '.').'%';
    }

    private function numeric(float|int $value): float|int
    {
        $value = (float) $value;

        return floor($value) === $value ? (int) $value : $value;
    }

    /**
     * @param  array<string, array<string, int|float>>  $records
     * @param  list<ReportingPeriod>  $periods
     * @param  list<string>  $outputMetrics
     * @param  list<string>  $showColumns
     * @param  list<string>  $hideColumns
     * @return array<string, array<string, int|float>>
     */
    private function overviewDateRows(
        array $records,
        array $periods,
        array $outputMetrics,
        array $showColumns,
        array $hideColumns,
    ): array {
        $result = [];

        foreach ($periods as $period) {
            $result[$period->resultKey] = $this->overviewRow(
                $records[$period->rangeKey()] ?? null,
                $outputMetrics,
                $showColumns,
                $hideColumns,
            );
        }

        return $result;
    }

    /**
     * @param  array<string, int|float>|null  $record
     * @param  list<string>  $outputMetrics
     * @param  list<string>  $showColumns
     * @param  list<string>  $hideColumns
     * @return array<string, int|float>
     */
    private function overviewRow(
        ?array $record,
        array $outputMetrics,
        array $showColumns,
        array $hideColumns,
    ): array {
        $row = [];

        foreach ($outputMetrics as $metric) {
            if ($metric === 'avg_time_generation') {
                $sum = $record['Actions_sum_time_generation'] ?? 0;
                $count = $record['Actions_nb_hits_with_time_generation'] ?? 0;
                $row[$metric] = $this->numeric($count == 0 ? 0 : round($sum / $count, 3));
            } elseif (in_array($metric, self::OVERVIEW_METRICS, true)) {
                $row[$metric] = $record['Actions_'.$metric] ?? 0;
            }
        }

        if ($showColumns !== []) {
            $row = array_filter(
                $row,
                static fn (string $metric): bool => in_array($metric, $showColumns, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        if ($hideColumns !== []) {
            $row = array_filter(
                $row,
                static fn (string $metric): bool => ! in_array($metric, $hideColumns, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        return $row;
    }
}
