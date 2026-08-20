<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiReport;
use App\Matomo\Api\ApiTableReport;
use App\Matomo\BotTracking\AiAssistantMetadata;
use App\Matomo\Localization\MatomoTranslator;
use LogicException;

final readonly class BotTrackingReportBuilder
{
    /** @var list<string> */
    private const array OVERVIEW_METRICS = [
        'BotTracking_AIChatbotsRequests',
        'BotTracking_AIChatbotsAcquiredVisits',
        'BotTracking_AIChatbotsUniquePageUrls',
        'BotTracking_AIChatbotsNotFoundRequests',
        'BotTracking_AIChatbotsUniqueChatbots',
        'BotTracking_AIChatbotsUniqueDocumentUrls',
        'BotTracking_AIChatbotsServerErrorRequests',
    ];

    private const string CLICK_THROUGH_RATE = 'BotTracking_AIChatbotsClickThroughRate';

    /** @var array<string, string> */
    private const array RECORDS = [
        'BotTracking.getAIChatbotContentPages' => 'BotTracking_AIChatbotsRequestedPages',
        'BotTracking.getAIChatbotContentDocuments' => 'BotTracking_AIChatbotsRequestedDocuments',
        'BotTracking.getAIChatbotBrokenContent' => 'BotTracking_AIChatbotsBrokenContent',
        'BotTracking.getAIChatbotHumanFavouredPages' => 'BotTracking_AIChatbotsHumanFavouredPages',
        'BotTracking.getAIChatbotAIFavouredPages' => 'BotTracking_AIChatbotsAIFavouredPages',
    ];

    public function __construct(
        private NumericArchiveRepository $numerics,
        private HierarchicalBlobArchiveRepository $blobs,
        private AiAssistantMetadata $assistants,
        private MatomoTranslator $translator,
    ) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @param  list<string>|null  $requestedColumns
     * @param  list<string>  $showColumns
     * @param  list<string>  $hideColumns
     */
    public function buildOverview(
        array $siteIds,
        array $periods,
        string $requestedPeriod,
        ?array $requestedColumns,
        array $showColumns,
        array $hideColumns,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiReport {
        $available = $this->overviewMetrics($requestedPeriod);
        $archiveMetrics = $this->overviewArchiveMetrics($available, $requestedColumns);
        $archiveRows = $this->numerics->pluginMetrics(
            $siteIds,
            $periods,
            '',
            $archiveMetrics,
            'BotTracking',
        );
        $dimensions = $this->dimensions($forceSiteIndex, $forceDateIndex);

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $period = $periods[0] ?? null;
                $row = $period === null ? null : ($archiveRows[$idSite][$period->rangeKey()] ?? null);

                return new ApiReport($this->overviewRow(
                    $row,
                    $available,
                    $archiveMetrics,
                    $requestedColumns,
                    $showColumns,
                    $hideColumns,
                    true,
                ), []);
            }

            return new ApiReport($this->overviewDateRows(
                $idSite,
                $periods,
                $archiveRows,
                $available,
                $archiveMetrics,
                $requestedColumns,
                $showColumns,
                $hideColumns,
            ), $dimensions);
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            if ($forceDateIndex) {
                $data[$idSite] = $this->overviewDateRows(
                    $idSite,
                    $periods,
                    $archiveRows,
                    $available,
                    $archiveMetrics,
                    $requestedColumns,
                    $showColumns,
                    $hideColumns,
                );

                continue;
            }

            $period = $periods[0] ?? null;
            $row = $period === null ? null : ($archiveRows[$idSite][$period->rangeKey()] ?? null);
            $data[$idSite] = $this->overviewRow(
                $row,
                $available,
                $archiveMetrics,
                $requestedColumns,
                $showColumns,
                $hideColumns,
                false,
            );
        }

        return new ApiReport($data, $dimensions);
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function buildTable(
        string $method,
        ?string $secondaryDimension,
        bool $expanded,
        bool $flat,
        ?int $idSubtable,
        array $siteIds,
        array $periods,
        string $language,
        bool $showMetadata,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiTableReport {
        [$recordName, $requestedRecord, $includeSubtables] = $this->recordRequest(
            $method,
            $secondaryDimension,
            $expanded,
            $flat,
            $idSubtable,
        );
        $archiveRecords = $this->blobs->records(
            $siteIds,
            $periods,
            '',
            $includeSubtables ? $recordName : $requestedRecord,
            $includeSubtables,
        );
        $dimensions = $this->dimensions($forceSiteIndex, $forceDateIndex);

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $key = ($periods[0] ?? null)?->rangeKey();

                return new ApiTableReport($key === null ? [] : $this->tableRows(
                    $archiveRecords[$idSite][$key] ?? [],
                    $recordName,
                    $requestedRecord,
                    $method,
                    $expanded,
                    $flat,
                    $language,
                    $showMetadata,
                ), []);
            }

            return new ApiTableReport($this->tableDateRows(
                $archiveRecords[$idSite] ?? [],
                $periods,
                $recordName,
                $requestedRecord,
                $method,
                $expanded,
                $flat,
                $language,
                $showMetadata,
            ), $dimensions);
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            if ($forceDateIndex) {
                $data[$idSite] = $this->tableDateRows(
                    $archiveRecords[$idSite] ?? [],
                    $periods,
                    $recordName,
                    $requestedRecord,
                    $method,
                    $expanded,
                    $flat,
                    $language,
                    $showMetadata,
                );

                continue;
            }

            $key = ($periods[0] ?? null)?->rangeKey();
            $data[$idSite] = $key === null ? [] : $this->tableRows(
                $archiveRecords[$idSite][$key] ?? [],
                $recordName,
                $requestedRecord,
                $method,
                $expanded,
                $flat,
                $language,
                $showMetadata,
            );
        }

        return new ApiTableReport($data, $dimensions);
    }

    /**
     * @param  list<ReportingPeriod>  $periods
     * @param  array<int, array<string, array<string, int|float>>>  $archiveRows
     * @param  list<string>  $available
     * @param  list<string>  $archiveMetrics
     * @param  list<string>|null  $requestedColumns
     * @param  list<string>  $showColumns
     * @param  list<string>  $hideColumns
     * @return array<string, array<string, float|int>>
     */
    private function overviewDateRows(
        int $idSite,
        array $periods,
        array $archiveRows,
        array $available,
        array $archiveMetrics,
        ?array $requestedColumns,
        array $showColumns,
        array $hideColumns,
    ): array {
        $result = [];

        foreach ($periods as $period) {
            $result[$period->resultKey] = $this->overviewRow(
                $archiveRows[$idSite][$period->rangeKey()] ?? null,
                $available,
                $archiveMetrics,
                $requestedColumns,
                $showColumns,
                $hideColumns,
                false,
            );
        }

        return $result;
    }

    /**
     * @param  array<string, int|float>|null  $archiveRow
     * @param  list<string>  $available
     * @param  list<string>  $archiveMetrics
     * @param  list<string>|null  $requestedColumns
     * @param  list<string>  $showColumns
     * @param  list<string>  $hideColumns
     * @return array<string, float|int>
     */
    private function overviewRow(
        ?array $archiveRow,
        array $available,
        array $archiveMetrics,
        ?array $requestedColumns,
        array $showColumns,
        array $hideColumns,
        bool $standalone,
    ): array {
        if ($archiveMetrics === []) {
            return [];
        }

        if ($archiveRow === null && ! $standalone && count($archiveMetrics) !== 1) {
            return [];
        }

        $row = array_fill_keys($archiveMetrics, 0);

        foreach ($archiveRow ?? [] as $metric => $value) {
            if (array_key_exists($metric, $row)) {
                $row[$metric] = $value;
            }
        }

        $requests = (float) ($row['BotTracking_AIChatbotsRequests'] ?? 0);
        $visits = (float) ($row['BotTracking_AIChatbotsAcquiredVisits'] ?? 0);
        $row[self::CLICK_THROUGH_RATE] = $requests === 0.0 ? 0 : round($visits / $requests, 4);
        $outputColumns = $requestedColumns ?? [...$available, self::CLICK_THROUGH_RATE];
        $row = array_filter(
            $row,
            static fn (string $metric): bool => in_array($metric, $outputColumns, true),
            ARRAY_FILTER_USE_KEY,
        );

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

    /** @return list<string> */
    private function overviewMetrics(string $period): array
    {
        if ($period === 'day') {
            return self::OVERVIEW_METRICS;
        }

        return array_values(array_filter(
            self::OVERVIEW_METRICS,
            static fn (string $metric): bool => ! in_array($metric, [
                'BotTracking_AIChatbotsUniquePageUrls',
                'BotTracking_AIChatbotsUniqueDocumentUrls',
            ], true),
        ));
    }

    /**
     * @param  list<string>  $available
     * @param  list<string>|null  $requestedColumns
     * @return list<string>
     */
    private function overviewArchiveMetrics(array $available, ?array $requestedColumns): array
    {
        if ($requestedColumns === null) {
            return $available;
        }

        $metrics = array_values(array_intersect($available, $requestedColumns));

        if (in_array(self::CLICK_THROUGH_RATE, $requestedColumns, true)) {
            $metrics[] = 'BotTracking_AIChatbotsRequests';
            $metrics[] = 'BotTracking_AIChatbotsAcquiredVisits';
        }

        return array_values(array_unique($metrics));
    }

    /** @return array{string, string, bool} */
    private function recordRequest(
        string $method,
        ?string $secondaryDimension,
        bool $expanded,
        bool $flat,
        ?int $idSubtable,
    ): array {
        $recordName = match ($method) {
            'BotTracking.getAIChatbotRequests',
            'BotTracking.getPageUrlsForAIChatbot' => 'BotTracking_AIChatbotsPages',
            'BotTracking.getDocumentUrlsForAIChatbot' => 'BotTracking_AIChatbotsDocuments',
            default => self::RECORDS[$method]
                ?? throw new LogicException("The BotTracking method '{$method}' is not supported."),
        };

        if ($method === 'BotTracking.getAIChatbotRequests' && $secondaryDimension === 'documents') {
            $recordName = 'BotTracking_AIChatbotsDocuments';
        }

        $requestedRecord = $idSubtable === null ? $recordName : $recordName.'_'.$idSubtable;
        $includeSubtables = $method === 'BotTracking.getAIChatbotRequests'
            && ($expanded || $flat);

        return [$recordName, $requestedRecord, $includeSubtables];
    }

    /**
     * @param  array<string, array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>>>  $archiveRecords
     * @param  list<ReportingPeriod>  $periods
     * @return array<string, list<array<string, mixed>>>
     */
    private function tableDateRows(
        array $archiveRecords,
        array $periods,
        string $recordName,
        string $requestedRecord,
        string $method,
        bool $expanded,
        bool $flat,
        string $language,
        bool $showMetadata,
    ): array {
        $result = [];

        foreach ($periods as $period) {
            $result[$period->resultKey] = $this->tableRows(
                $archiveRecords[$period->rangeKey()] ?? [],
                $recordName,
                $requestedRecord,
                $method,
                $expanded,
                $flat,
                $language,
                $showMetadata,
            );
        }

        return $result;
    }

    /**
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>>  $records
     * @return list<array<string, mixed>>
     */
    private function tableRows(
        array $records,
        string $recordName,
        string $requestedRecord,
        string $method,
        bool $expanded,
        bool $flat,
        string $language,
        bool $showMetadata,
    ): array {
        $rows = $records[$requestedRecord] ?? [];

        if ($method !== 'BotTracking.getAIChatbotRequests') {
            return array_map(
                fn (array $row): array => $this->decorateFlatRecord($row, $method, $language, $showMetadata),
                $rows,
            );
        }

        if ($flat) {
            return $this->flattenChatbotRows($rows, $records, $recordName, $language, $showMetadata);
        }

        return $this->chatbotRows(
            $rows,
            $records,
            $recordName,
            $expanded,
            $language,
            $showMetadata,
        );
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>  $archiveRows
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>>  $records
     * @return list<array<string, mixed>>
     */
    private function chatbotRows(
        array $archiveRows,
        array $records,
        string $recordName,
        bool $expanded,
        string $language,
        bool $showMetadata,
    ): array {
        $result = [];

        foreach ($archiveRows as $archiveRow) {
            $row = $archiveRow['columns'];
            $rawLabel = $row['label'] ?? '';
            $label = $this->label($rawLabel, $language);
            $label = is_string($rawLabel) ? $this->assistants->displayName($label) : $label;
            $row['label'] = $label;

            if ($showMetadata) {
                $row = [...$row, ...$archiveRow['metadata']];
                $domain = $this->assistants->domain($label);
                $row['url'] = $domain;
                $row['logo'] = $this->assistants->logo($label);
            }

            $subtableId = $archiveRow['subtableId'];

            if ($subtableId !== null) {
                $row['idsubdatatable'] = $subtableId;
            }

            if ($expanded && $subtableId !== null) {
                $row['subtable'] = array_map(
                    fn (array $child): array => $this->plainRow($child, $language, $showMetadata),
                    $records[$recordName.'_'.$subtableId] ?? [],
                );
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>  $archiveRows
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>>  $records
     * @return list<array<string, mixed>>
     */
    private function flattenChatbotRows(
        array $archiveRows,
        array $records,
        string $recordName,
        string $language,
        bool $showMetadata,
    ): array {
        $result = [];
        $dimension = $recordName === 'BotTracking_AIChatbotsDocuments'
            ? 'BotTracking_DocumentUrl'
            : 'BotTracking_PageUrl';

        foreach ($archiveRows as $archiveRow) {
            $rawParent = $archiveRow['columns']['label'] ?? '';
            $parent = $this->label($rawParent, $language);
            $parent = is_string($rawParent) ? $this->assistants->displayName($parent) : $parent;
            $subtableId = $archiveRow['subtableId'];

            if ($subtableId === null) {
                continue;
            }

            foreach ($records[$recordName.'_'.$subtableId] ?? [] as $child) {
                $row = $this->plainRow($child, $language, false);
                $childLabel = $row['label'] ?? '';
                $row['label'] = trim($parent).' - '.trim((string) $childLabel);
                $row['BotTracking_AIChatbotName'] = $parent;
                $row[$dimension] = $childLabel;

                if ($showMetadata) {
                    $row = [...$row, ...$archiveRow['metadata'], ...$child['metadata']];
                    $row['url'] = null;
                    $row['logo'] = $this->assistants->logo('');
                }

                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * @param  array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}  $archiveRow
     * @return array<string, float|int|string|null>
     */
    private function decorateFlatRecord(
        array $archiveRow,
        string $method,
        string $language,
        bool $showMetadata,
    ): array {
        $row = $this->plainRow($archiveRow, $language, $showMetadata);

        if (in_array($method, [
            'BotTracking.getAIChatbotContentPages',
            'BotTracking.getAIChatbotContentDocuments',
        ], true)) {
            $serverCount = (float) ($row['nb_server_time'] ?? 0);
            $responseCount = (float) ($row['nb_response_size'] ?? 0);

            if ($serverCount !== 0.0) {
                $row['avg_server_time'] = (float) ($row['sum_server_time'] ?? 0) / $serverCount / 1000;
            }

            if ($responseCount !== 0.0) {
                $row['avg_response_size'] = (float) ($row['sum_response_size'] ?? 0) / $responseCount;
            }
        }

        return $row;
    }

    /**
     * @param  array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}  $archiveRow
     * @return array<string, float|int|string|null>
     */
    private function plainRow(array $archiveRow, string $language, bool $showMetadata): array
    {
        $row = $archiveRow['columns'];
        $row['label'] = $this->label($row['label'] ?? '', $language);

        return $showMetadata ? [...$row, ...$archiveRow['metadata']] : $row;
    }

    private function label(float|int|string|null $label, string $language): string
    {
        return $label === -1 || $label === '-1'
            ? $this->translator->translate('General_Others', $language)
            : (string) $label;
    }

    /** @return list<'idSite'|'date'> */
    private function dimensions(bool $forceSiteIndex, bool $forceDateIndex): array
    {
        return [...($forceSiteIndex ? ['idSite'] : []), ...($forceDateIndex ? ['date'] : [])];
    }
}
