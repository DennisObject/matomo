<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use stdClass;

final readonly class DatabaseBlobArchiveRepository implements BlobArchiveRepository
{
    private const int DONE_PARTIAL = 5;

    /** @var list<int> */
    private const array SELECTABLE_DONE_VALUES = [1, 3, 4, self::DONE_PARTIAL];

    public function __construct(private Connection $connection) {}

    public function rows(array $siteIds, array $periods, string $segmentHash, string $recordName): array
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
                    $result[(int) $idSite][$date1.','.$date2] = $decoded;
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

    /** @return array<string, float|int|string|null>|null */
    private function scalarMap(mixed $values): ?array
    {
        if (! is_array($values)) {
            return null;
        }

        $result = [];

        foreach ($values as $name => $value) {
            if (! is_string($name)
                || (! is_float($value) && ! is_int($value) && ! is_string($value) && $value !== null)) {
                return null;
            }

            $result[$name] = $value;
        }

        return $result;
    }

    private function pluginName(string $recordName): string
    {
        $separator = strpos($recordName, '_');

        return $separator === false ? $recordName : substr($recordName, 0, $separator);
    }
}
