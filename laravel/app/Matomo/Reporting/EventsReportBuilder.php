<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;
use App\Matomo\Localization\MatomoTranslator;
use LogicException;

final readonly class EventsReportBuilder
{
    /** @var array<string, string> */
    private const array DEFAULT_SECONDARY_DIMENSIONS = [
        'Events.getCategory' => 'eventAction',
        'Events.getAction' => 'eventName',
        'Events.getName' => 'eventAction',
    ];

    /** @var array<string, array<string, string>|string> */
    private const array RECORDS = [
        'Events.getCategory' => [
            'eventAction' => 'Events_category_action',
            'eventName' => 'Events_category_name',
        ],
        'Events.getAction' => [
            'eventName' => 'Events_action_name',
            'eventCategory' => 'Events_action_category',
        ],
        'Events.getName' => [
            'eventAction' => 'Events_name_action',
            'eventCategory' => 'Events_name_category',
        ],
        'Events.getActionFromCategoryId' => 'Events_category_action',
        'Events.getNameFromCategoryId' => 'Events_category_name',
        'Events.getCategoryFromActionId' => 'Events_action_category',
        'Events.getNameFromActionId' => 'Events_action_name',
        'Events.getActionFromNameId' => 'Events_name_action',
        'Events.getCategoryFromNameId' => 'Events_name_category',
    ];

    /** @var array<string, array{array{string, string}, array{string, string}}> */
    private const array RECORD_DIMENSIONS = [
        'Events_category_action' => [
            ['Events_EventCategory', 'eventCategory'],
            ['Events_EventAction', 'eventAction'],
        ],
        'Events_category_name' => [
            ['Events_EventCategory', 'eventCategory'],
            ['Events_EventName', 'eventName'],
        ],
        'Events_action_category' => [
            ['Events_EventAction', 'eventAction'],
            ['Events_EventCategory', 'eventCategory'],
        ],
        'Events_action_name' => [
            ['Events_EventAction', 'eventAction'],
            ['Events_EventName', 'eventName'],
        ],
        'Events_name_action' => [
            ['Events_EventName', 'eventName'],
            ['Events_EventAction', 'eventAction'],
        ],
        'Events_name_category' => [
            ['Events_EventName', 'eventName'],
            ['Events_EventCategory', 'eventCategory'],
        ],
    ];

    public function __construct(
        private HierarchicalBlobArchiveRepository $archives,
        private MatomoTranslator $translator,
    ) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function build(
        string $method,
        ?string $secondaryDimension,
        bool $expanded,
        bool $flat,
        bool $showDimensions,
        ?int $idSubtable,
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $language,
        bool $showMetadata,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiTableReport {
        $recordName = $this->recordName($method, $secondaryDimension);
        $requestedRecord = $idSubtable === null || $idSubtable === 0
            ? $recordName
            : $recordName.'_'.$idSubtable;
        $includeSubtables = ($expanded || $flat) && $idSubtable === null;
        $archiveRecords = $this->archives->records(
            $siteIds,
            $periods,
            $segmentHash,
            $includeSubtables ? $recordName : $requestedRecord,
            $includeSubtables,
        );
        $rootRecords = $idSubtable !== null && $idSubtable !== 0
            ? $this->archives->records($siteIds, $periods, $segmentHash, $recordName, false)
            : $archiveRecords;
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
                    $archiveRecords[$idSite][$key] ?? [],
                    $rootRecords[$idSite][$key] ?? [],
                    $recordName,
                    $requestedRecord,
                    $method,
                    $expanded,
                    $flat,
                    $showDimensions,
                    $language,
                    $showMetadata,
                ), []);
            }

            return new ApiTableReport($this->dateRows(
                $archiveRecords[$idSite] ?? [],
                $rootRecords[$idSite] ?? [],
                $periods,
                $recordName,
                $requestedRecord,
                $method,
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
                    $archiveRecords[$idSite] ?? [],
                    $rootRecords[$idSite] ?? [],
                    $periods,
                    $recordName,
                    $requestedRecord,
                    $method,
                    $expanded,
                    $flat,
                    $showDimensions,
                    $language,
                    $showMetadata,
                );

                continue;
            }

            $key = ($periods[0] ?? null)?->rangeKey();
            $data[$idSite] = $key === null ? [] : $this->rows(
                $archiveRecords[$idSite][$key] ?? [],
                $rootRecords[$idSite][$key] ?? [],
                $recordName,
                $requestedRecord,
                $method,
                $expanded,
                $flat,
                $showDimensions,
                $language,
                $showMetadata,
            );
        }

        return new ApiTableReport($data, $dimensions);
    }

    /**
     * @param  array<string, array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>>>  $archiveRecords
     * @param  array<string, array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>>>  $rootRecords
     * @param  list<ReportingPeriod>  $periods
     * @return array<string, list<array<string, mixed>>>
     */
    private function dateRows(
        array $archiveRecords,
        array $rootRecords,
        array $periods,
        string $recordName,
        string $requestedRecord,
        string $method,
        bool $expanded,
        bool $flat,
        bool $showDimensions,
        string $language,
        bool $showMetadata,
    ): array {
        $result = [];

        foreach ($periods as $period) {
            $key = $period->rangeKey();
            $result[$period->resultKey] = $this->rows(
                $archiveRecords[$key] ?? [],
                $rootRecords[$key] ?? [],
                $recordName,
                $requestedRecord,
                $method,
                $expanded,
                $flat,
                $showDimensions,
                $language,
                $showMetadata,
            );
        }

        return $result;
    }

    /**
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>>  $records
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>>  $rootRecords
     * @return list<array<string, mixed>>
     */
    private function rows(
        array $records,
        array $rootRecords,
        string $recordName,
        string $requestedRecord,
        string $method,
        bool $expanded,
        bool $flat,
        bool $showDimensions,
        string $language,
        bool $showMetadata,
    ): array {
        $totalVisits = $this->totalVisits($rootRecords[$recordName] ?? $records[$requestedRecord] ?? []);
        $rows = $records[$requestedRecord] ?? [];

        if ($flat) {
            return $this->flatten(
                $rows,
                $records,
                $recordName,
                $totalVisits,
                $showDimensions,
                $language,
                $showMetadata,
            );
        }

        return $this->normalRows(
            $rows,
            $records,
            $recordName,
            $method,
            $expanded,
            true,
            $totalVisits,
            $language,
            $showMetadata,
        );
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>  $archiveRows
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>>  $records
     * @return list<array<string, mixed>>
     */
    private function normalRows(
        array $archiveRows,
        array $records,
        string $recordName,
        string $method,
        bool $expanded,
        bool $addSegment,
        float $totalVisits,
        string $language,
        bool $showMetadata,
    ): array {
        $result = [];
        $dimension = $this->methodDimension($method, $recordName);

        foreach ($archiveRows as $archiveRow) {
            $rawLabel = $archiveRow['columns']['label'] ?? '';
            $row = $this->decorate($archiveRow, $totalVisits, $language, $showMetadata);

            if ($addSegment
                && $showMetadata
                && ! $this->isSummary($rawLabel)
                && $rawLabel !== 'Piwik_EventNameNotSet') {
                $row['segment'] = $dimension[1].'=='.urlencode((string) $rawLabel);
            }

            $subtableId = $archiveRow['subtableId'];

            if ($showMetadata && $subtableId !== null) {
                $row['idsubdatatable'] = $subtableId;
            }

            if ($expanded && $subtableId !== null) {
                $subtable = $records[$recordName.'_'.$subtableId] ?? [];
                $row['subtable'] = $this->normalRows(
                    $subtable,
                    $records,
                    $recordName,
                    $this->subtableMethod($recordName),
                    true,
                    false,
                    $totalVisits,
                    $language,
                    $showMetadata,
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
    private function flatten(
        array $archiveRows,
        array $records,
        string $recordName,
        float $totalVisits,
        bool $showDimensions,
        string $language,
        bool $showMetadata,
    ): array {
        $result = [];
        [$primaryDimension, $secondaryDimension] = self::RECORD_DIMENSIONS[$recordName];

        foreach ($archiveRows as $archiveRow) {
            $rawParentLabel = $archiveRow['columns']['label'] ?? '';
            $parentLabel = $this->label($rawParentLabel, $language);
            $subtableId = $archiveRow['subtableId'];
            $subtable = $subtableId === null ? [] : ($records[$recordName.'_'.$subtableId] ?? []);

            if ($subtable === []) {
                $row = $this->decorate($archiveRow, $totalVisits, $language, $showMetadata);

                if ($showMetadata
                    && ! $this->isSummary($rawParentLabel)
                    && $rawParentLabel !== 'Piwik_EventNameNotSet') {
                    $row['segment'] = $primaryDimension[1].'=='.urlencode((string) $rawParentLabel);
                }

                if ($showDimensions) {
                    $row[$primaryDimension[0]] = $parentLabel;
                }

                $result[] = $row;

                continue;
            }

            foreach ($subtable as $child) {
                $rawChildLabel = $child['columns']['label'] ?? '';
                $childLabel = $this->label($rawChildLabel, $language);
                $child['metadata'] = [...$archiveRow['metadata'], ...$child['metadata']];
                $row = $this->decorate($child, $totalVisits, $language, $showMetadata);
                $row['label'] = trim($parentLabel).' - '.trim($childLabel);

                if ($showMetadata
                    && ! $this->isSummary($rawParentLabel)
                    && ! $this->isSummary($rawChildLabel)
                    && $rawParentLabel !== 'Piwik_EventNameNotSet'
                    && $rawChildLabel !== 'Piwik_EventNameNotSet') {
                    $row['segment'] = $primaryDimension[1].'=='.urlencode((string) $rawParentLabel)
                        .';'.$secondaryDimension[1].'=='.urlencode((string) $rawChildLabel);
                }

                if ($showDimensions) {
                    $row[$primaryDimension[0]] = $parentLabel;
                    $row[$secondaryDimension[0]] = $childLabel;
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
    private function decorate(
        array $archiveRow,
        float $totalVisits,
        string $language,
        bool $showMetadata,
    ): array {
        $row = $archiveRow['columns'];
        $row['label'] = $this->label($row['label'] ?? '', $language);

        if ($showMetadata) {
            $row = [...$row, ...$archiveRow['metadata']];
        }

        $visits = $row['nb_visits'] ?? null;

        if (is_float($visits) || is_int($visits)) {
            $row['nb_visits_percent_of_total'] = $this->percent($visits, $totalVisits);
        }

        $sumEventValue = $row['sum_event_value'] ?? null;
        $eventsWithValue = $row['nb_events_with_value'] ?? null;

        if ((is_float($sumEventValue) || is_int($sumEventValue))
            && (is_float($eventsWithValue) || is_int($eventsWithValue))) {
            $row['avg_event_value'] = $eventsWithValue == 0
                ? 0
                : round($sumEventValue / $eventsWithValue, 2);
        }

        return $row;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>  $rows
     */
    private function totalVisits(array $rows): float
    {
        return array_sum(array_map(
            static fn (array $row): float => (float) ($row['columns']['nb_visits'] ?? 0),
            $rows,
        ));
    }

    private function recordName(string $method, ?string $secondaryDimension): string
    {
        $record = self::RECORDS[$method];

        if (is_string($record)) {
            return $record;
        }

        $dimension = $secondaryDimension ?? self::DEFAULT_SECONDARY_DIMENSIONS[$method];

        return $record[$dimension];
    }

    /** @return array{string, string} */
    private function methodDimension(string $method, string $recordName): array
    {
        $dimensions = self::RECORD_DIMENSIONS[$recordName];

        return str_contains($method, 'From') ? $dimensions[1] : $dimensions[0];
    }

    private function subtableMethod(string $recordName): string
    {
        return match ($recordName) {
            'Events_category_action' => 'Events.getActionFromCategoryId',
            'Events_category_name' => 'Events.getNameFromCategoryId',
            'Events_action_category' => 'Events.getCategoryFromActionId',
            'Events_action_name' => 'Events.getNameFromActionId',
            'Events_name_action' => 'Events.getActionFromNameId',
            'Events_name_category' => 'Events.getCategoryFromNameId',
            default => throw new LogicException("The event archive record '{$recordName}' is not supported."),
        };
    }

    private function label(float|int|string|null $label, string $language): string
    {
        if ($this->isSummary($label)) {
            return $this->translator->translate('General_Others', $language);
        }

        if ($label === 'Piwik_EventNameNotSet') {
            $eventName = $this->translator->translate('Events_EventName', $language);

            return $this->translator->translate('General_NotDefined', $language, [$eventName]);
        }

        return (string) $label;
    }

    private function isSummary(float|int|string|null $label): bool
    {
        return $label === -1 || $label === '-1';
    }

    private function percent(float|int $value, float $total): string
    {
        $percent = $total === 0.0 ? 0 : round((float) $value / $total * 100, 1);

        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').'%';
    }
}
