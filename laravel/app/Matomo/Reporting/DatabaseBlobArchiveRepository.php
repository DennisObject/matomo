<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Archiving\ArchiveGoalMetrics;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use stdClass;

final readonly class DatabaseBlobArchiveRepository implements BatchBlobArchiveRepository, BlobArchiveMetadataRepository, BlobArchiveRepository, HierarchicalBlobArchiveRepository
{
    private const int DONE_PARTIAL = 5;

    /** @var list<int> */
    private const array SELECTABLE_DONE_VALUES = [1, 3, 4, self::DONE_PARTIAL];

    /** @var array<int, string> */
    private const array COLUMN_NAMES = [
        1 => 'nb_uniq_visitors',
        2 => 'nb_visits',
        3 => 'nb_actions',
        4 => 'max_actions',
        5 => 'sum_visit_length',
        6 => 'bounce_count',
        7 => 'nb_visits_converted',
        8 => 'nb_conversions',
        9 => 'revenue',
        10 => 'goals',
        11 => 'sum_daily_nb_uniq_visitors',
        12 => 'nb_hits',
        13 => 'sum_time_spent',
        14 => 'exit_nb_uniq_visitors',
        15 => 'exit_nb_visits',
        16 => 'sum_daily_exit_nb_uniq_visitors',
        17 => 'entry_nb_uniq_visitors',
        18 => 'sum_daily_entry_nb_uniq_visitors',
        19 => 'entry_nb_visits',
        20 => 'entry_nb_actions',
        21 => 'entry_sum_visit_length',
        22 => 'entry_bounce_count',
        23 => 'revenue',
        24 => 'quantity',
        25 => 'price',
        26 => 'orders',
        27 => 'price_viewed',
        29 => 'nb_hits_following_search',
        30 => 'sum_time_generation',
        31 => 'nb_hits_with_time_generation',
        32 => 'min_time_generation',
        33 => 'max_time_generation',
        34 => 'nb_events',
        35 => 'sum_event_value',
        36 => 'min_event_value',
        37 => 'max_event_value',
        38 => 'nb_events_with_value',
        39 => 'nb_users',
        40 => 'sum_daily_nb_users',
        41 => 'nb_impressions',
        42 => 'nb_interactions',
        43 => 'nb_uniq_fingerprints',
        44 => 'hits',
    ];

    public function __construct(private Connection $connection) {}

    public function rows(array $siteIds, array $periods, string $segmentHash, string $recordName): array
    {
        $rows = [];

        foreach ($this->archives($siteIds, $periods, $segmentHash, $recordName) as $idSite => $siteArchives) {
            foreach ($siteArchives as $range => $archive) {
                $rows[$idSite][$range] = $archive->rows;
            }
        }

        return $rows;
    }

    public function rowsForRecords(
        array $siteIds,
        array $periods,
        string $segmentHash,
        array $recordNames,
    ): array {
        $recordNames = array_values(array_unique(array_filter(
            $recordNames,
            static fn (string $recordName): bool => $recordName !== '',
        )));

        if ($siteIds === [] || $periods === [] || $recordNames === []) {
            return [];
        }

        $periodsByTable = [];

        foreach ($periods as $period) {
            $periodsByTable[$period->archiveTable()][$period->rangeKey()] = $period;
        }

        $recordsByPlugin = [];

        foreach ($recordNames as $recordName) {
            $recordsByPlugin[$this->pluginName($recordName)][] = $recordName;
        }

        $result = [];

        foreach ($periodsByTable as $numericTable => $tablePeriods) {
            $blobTable = str_replace('archive_numeric_', 'archive_blob_', $numericTable);

            if (! $this->connection->getSchemaBuilder()->hasTable($numericTable)
                || ! $this->connection->getSchemaBuilder()->hasTable($blobTable)) {
                continue;
            }

            foreach ($recordsByPlugin as $pluginName => $pluginRecords) {
                $archiveIds = $this->archiveIds(
                    $numericTable,
                    $siteIds,
                    array_values($tablePeriods),
                    $segmentHash,
                    $pluginName,
                );

                if ($archiveIds === []) {
                    continue;
                }

                foreach (array_chunk($pluginRecords, 500) as $recordChunk) {
                    $rows = $this->connection
                        ->table($blobTable)
                        ->select([
                            'idarchive',
                            'idsite',
                            'date1',
                            'date2',
                            'name',
                            'value',
                            'ts_archived',
                        ])
                        ->whereIn('idarchive', $archiveIds)
                        ->whereIn('name', $recordChunk)
                        ->orderBy('ts_archived')
                        ->orderBy('idarchive')
                        ->get();

                    foreach ($rows as $row) {
                        $idSite = $row->idsite ?? null;
                        $date1 = $row->date1 ?? null;
                        $date2 = $row->date2 ?? null;
                        $name = $row->name ?? null;
                        $value = $row->value ?? null;

                        if ((! is_int($idSite) && ! is_string($idSite))
                            || ! is_string($date1)
                            || ! is_string($date2)
                            || ! is_string($name)
                            || ! is_string($value)) {
                            continue;
                        }

                        $decoded = $this->decode($value);

                        if ($decoded !== null) {
                            $result[(int) $idSite][$date1.','.$date2][$name] = $decoded;
                        }
                    }
                }
            }
        }

        return $result;
    }

    public function archives(array $siteIds, array $periods, string $segmentHash, string $recordName): array
    {
        if ($siteIds === [] || $periods === [] || $recordName === '') {
            return [];
        }

        $periodsByTable = [];

        foreach ($periods as $period) {
            $periodsByTable[$period->archiveTable()][$period->rangeKey()] = $period;
        }

        $result = [];

        foreach ($periodsByTable as $numericTable => $tablePeriods) {
            $blobTable = str_replace('archive_numeric_', 'archive_blob_', $numericTable);

            if (! $this->connection->getSchemaBuilder()->hasTable($numericTable)
                || ! $this->connection->getSchemaBuilder()->hasTable($blobTable)) {
                continue;
            }

            $archiveIds = $this->archiveIds(
                $numericTable,
                $siteIds,
                array_values($tablePeriods),
                $segmentHash,
                $this->pluginName($recordName),
            );

            if ($archiveIds === []) {
                continue;
            }

            $rows = $this->connection
                ->table($blobTable)
                ->select(['idarchive', 'idsite', 'date1', 'date2', 'value', 'ts_archived'])
                ->whereIn('idarchive', $archiveIds)
                ->where('name', $recordName)
                ->orderBy('ts_archived')
                ->orderBy('idarchive')
                ->get();

            foreach ($rows as $row) {
                $idSite = $row->idsite ?? null;
                $date1 = $row->date1 ?? null;
                $date2 = $row->date2 ?? null;
                $value = $row->value ?? null;

                if ((! is_int($idSite) && ! is_string($idSite))
                    || ! is_string($date1)
                    || ! is_string($date2)
                    || ! is_string($value)) {
                    continue;
                }

                $decoded = $this->decode($value);

                if ($decoded !== null) {
                    $archivedAt = $row->ts_archived ?? null;
                    $result[(int) $idSite][$date1.','.$date2] = new BlobArchive(
                        $decoded,
                        is_string($archivedAt) ? $archivedAt : null,
                    );
                }
            }
        }

        return $result;
    }

    public function records(
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $recordName,
        bool $includeSubtables,
    ): array {
        if ($siteIds === [] || $periods === [] || $recordName === '') {
            return [];
        }

        $periodsByTable = [];

        foreach ($periods as $period) {
            $periodsByTable[$period->archiveTable()][$period->rangeKey()] = $period;
        }

        $result = [];

        foreach ($periodsByTable as $numericTable => $tablePeriods) {
            $blobTable = str_replace('archive_numeric_', 'archive_blob_', $numericTable);

            if (! $this->connection->getSchemaBuilder()->hasTable($numericTable)
                || ! $this->connection->getSchemaBuilder()->hasTable($blobTable)) {
                continue;
            }

            $archiveIds = $this->archiveIds(
                $numericTable,
                $siteIds,
                array_values($tablePeriods),
                $segmentHash,
                $this->pluginName($recordName),
            );

            if ($archiveIds === []) {
                continue;
            }

            $rows = $this->connection
                ->table($blobTable)
                ->select(['idarchive', 'idsite', 'date1', 'date2', 'name', 'value', 'ts_archived'])
                ->whereIn('idarchive', $archiveIds)
                ->when(
                    $includeSubtables,
                    static fn (Builder $query): Builder => $query->where(
                        static fn (Builder $names): Builder => $names
                            ->where('name', $recordName)
                            ->orWhere('name', 'like', $recordName.'_%'),
                    ),
                    static fn (Builder $query): Builder => $query->where('name', $recordName),
                )
                ->orderBy('ts_archived')
                ->orderBy('idarchive')
                ->get();

            foreach ($rows as $row) {
                $idSite = $row->idsite ?? null;
                $date1 = $row->date1 ?? null;
                $date2 = $row->date2 ?? null;
                $name = $row->name ?? null;
                $value = $row->value ?? null;

                if ((! is_int($idSite) && ! is_string($idSite))
                    || ! is_string($date1)
                    || ! is_string($date2)
                    || ! is_string($name)
                    || ! is_string($value)
                    || ! $this->isRequestedRecord($name, $recordName, $includeSubtables)) {
                    continue;
                }

                $decoded = $this->decodeHierarchy($value);

                if ($decoded !== null) {
                    $result[(int) $idSite][$date1.','.$date2][$name] = $decoded;
                }
            }
        }

        return $result;
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @return list<int>
     */
    private function archiveIds(
        string $table,
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $pluginName,
    ): array {
        $doneNames = ['done'.$segmentHash, 'done'.$segmentHash.'.'.$pluginName];
        $records = [];

        foreach (array_chunk($siteIds, 500) as $siteIdChunk) {
            $rows = $this->connection
                ->table($table)
                ->select(['idarchive', 'idsite', 'date1', 'date2', 'name', 'value', 'ts_archived'])
                ->whereIn('idsite', $siteIdChunk)
                ->whereIn('name', $doneNames)
                ->whereIn('value', self::SELECTABLE_DONE_VALUES)
                ->where(function (Builder $query) use ($periods): void {
                    foreach ($periods as $period) {
                        $query->orWhere(function (Builder $periodQuery) use ($period): void {
                            $periodQuery
                                ->where('period', $period->id)
                                ->where('date1', $period->startDate)
                                ->where('date2', $period->endDate);
                        });
                    }
                })
                ->orderByDesc('idarchive')
                ->get();

            foreach ($rows as $row) {
                $idSite = $row->idsite ?? null;
                $date1 = $row->date1 ?? null;
                $date2 = $row->date2 ?? null;

                if ((! is_int($idSite) && ! is_string($idSite))
                    || ! is_string($date1)
                    || ! is_string($date2)) {
                    continue;
                }

                $records[(int) $idSite][$date1.','.$date2][] = $row;
            }
        }

        $archiveIds = [];

        foreach ($records as $periodRecords) {
            foreach ($periodRecords as $rows) {
                $archiveIds = [...$archiveIds, ...$this->selectArchiveIds($rows)];
            }
        }

        return array_values(array_unique($archiveIds));
    }

    /** @param list<stdClass> $rows
     * @return list<int>
     */
    private function selectArchiveIds(array $rows): array
    {
        $archiveIds = [];

        foreach ($rows as $row) {
            $name = $row->name ?? null;
            $idArchive = $row->idarchive ?? null;

            if (! is_string($name) || (! is_int($idArchive) && ! is_string($idArchive))) {
                continue;
            }

            $archiveIds[] = (int) $idArchive;

            if (! str_contains($name, '.') && (int) ($row->value ?? 0) !== self::DONE_PARTIAL) {
                break;
            }
        }

        return array_values(array_unique($archiveIds));
    }

    /**
     * @return list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>|null
     */
    private function decode(string $value): ?array
    {
        $uncompressed = @gzuncompress($value);
        $serialized = is_string($uncompressed) ? $uncompressed : $value;
        $payload = @unserialize($serialized, ['allowed_classes' => false]);

        if (! is_array($payload)) {
            return null;
        }

        $rows = [];

        foreach ($payload as $row) {
            if (! is_array($row)) {
                return null;
            }

            $columns = $this->scalarMap($row[0] ?? []);
            $metadata = $this->scalarMap($row[1] ?? []);

            if ($columns === null || $metadata === null) {
                return null;
            }

            $rows[] = ['columns' => $columns, 'metadata' => $metadata];
        }

        return $rows;
    }

    /**
     * @return list<array{
     *     columns: array<string, float|int|string|null>,
     *     metadata: array<string, float|int|string|null>,
     *     subtableId: int|null
     * }>|null
     */
    private function decodeHierarchy(string $value): ?array
    {
        $uncompressed = @gzuncompress($value);
        $serialized = is_string($uncompressed) ? $uncompressed : $value;
        $payload = @unserialize($serialized, ['allowed_classes' => false]);

        if (! is_array($payload)) {
            return null;
        }

        $rows = [];

        foreach ($payload as $row) {
            if (! is_array($row)) {
                return null;
            }

            $columns = $this->scalarMap($row[0] ?? []);
            $metadata = $this->scalarMap($row[1] ?? []);
            $subtableId = $this->storedSubtableId($row[3] ?? null);

            if ($columns === null || $metadata === null || $subtableId === false) {
                return null;
            }

            $rows[] = [
                'columns' => $columns,
                'metadata' => $metadata,
                'subtableId' => $subtableId,
            ];
        }

        return $rows;
    }

    private function isRequestedRecord(string $name, string $recordName, bool $includeSubtables): bool
    {
        if ($name === $recordName) {
            return true;
        }

        return $includeSubtables
            && preg_match('/^'.preg_quote($recordName, '/').'_[1-9][0-9]*$/D', $name) === 1;
    }

    private function storedSubtableId(mixed $value): int|null|false
    {
        if ($value === null || $value === false) {
            return null;
        }

        if ((is_int($value) || is_string($value))
            && preg_match('/^[1-9][0-9]*$/D', (string) $value) === 1) {
            return (int) $value;
        }

        return false;
    }

    /** @return array<string, float|int|string|null>|null */
    private function scalarMap(mixed $values): ?array
    {
        if (! is_array($values)) {
            return null;
        }

        $result = [];

        foreach ($values as $name => $value) {
            if ((is_int($name) ? $name === ArchiveGoalMetrics::GOALS_COLUMN : $name === 'goals')
                && is_array($value)) {
                $goalColumns = ArchiveGoalMetrics::flatten($value);

                if ($goalColumns === null) {
                    return null;
                }

                foreach ($goalColumns as $goalName => $goalValue) {
                    $result[$goalName] = $goalValue;
                }

                continue;
            }

            if (! is_float($value) && ! is_int($value) && ! is_string($value) && $value !== null) {
                return null;
            }

            $columnName = is_int($name) ? (self::COLUMN_NAMES[$name] ?? (string) $name) : $name;

            if (isset($result[$columnName]) && is_numeric($result[$columnName]) && is_numeric($value)) {
                $value = (float) $result[$columnName] + (float) $value;
            }

            $result[$columnName] = $value;
        }

        return $result;
    }

    private function pluginName(string $recordName): string
    {
        $separator = strpos($recordName, '_');

        return $separator === false ? $recordName : substr($recordName, 0, $separator);
    }
}
