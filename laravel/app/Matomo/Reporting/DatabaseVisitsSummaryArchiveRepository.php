<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use stdClass;

final readonly class DatabaseVisitsSummaryArchiveRepository implements NumericArchiveRepository, VisitsSummaryArchiveRepository
{
    private const int DONE_PARTIAL = 5;

    /** @var list<int> */
    private const array SELECTABLE_DONE_VALUES = [1, 3, 4, self::DONE_PARTIAL];

    public function __construct(private Connection $connection) {}

    public function metrics(
        array $siteIds,
        array $periods,
        string $segmentHash,
        array $metrics,
    ): array {
        return $this->pluginMetrics($siteIds, $periods, $segmentHash, $metrics, 'VisitsSummary');
    }

    public function pluginMetrics(
        array $siteIds,
        array $periods,
        string $segmentHash,
        array $metrics,
        string $pluginName,
    ): array {
        if ($siteIds === [] || $periods === [] || $metrics === []) {
            return [];
        }

        $periodsByTable = [];

        foreach ($periods as $period) {
            $periodsByTable[$period->archiveTable()][$period->rangeKey()] = $period;
        }

        $result = [];

        foreach ($periodsByTable as $table => $tablePeriods) {
            if (! $this->connection->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $archiveIds = $this->archiveIds(
                $table,
                $siteIds,
                array_values($tablePeriods),
                $segmentHash,
                $pluginName,
            );

            if ($archiveIds === []) {
                continue;
            }

            $rows = [];

            foreach (array_chunk($archiveIds, 500) as $archiveIdChunk) {
                foreach (array_chunk($metrics, 500) as $metricChunk) {
                    $rows = [
                        ...$rows,
                        ...$this->connection
                            ->table($table)
                            ->select(['idarchive', 'idsite', 'date1', 'date2', 'name', 'value', 'ts_archived'])
                            ->whereIn('idarchive', $archiveIdChunk)
                            ->whereIn('name', $metricChunk)
                            ->get()
                            ->all(),
                    ];
                }
            }

            usort($rows, static function (object $left, object $right): int {
                $timestampComparison = ((string) ($left->ts_archived ?? ''))
                    <=> ((string) ($right->ts_archived ?? ''));

                return $timestampComparison !== 0
                    ? $timestampComparison
                    : ((int) ($left->idarchive ?? 0)) <=> ((int) ($right->idarchive ?? 0));
            });

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
                    || ! is_numeric($value)) {
                    continue;
                }

                $result[(int) $idSite][$date1.','.$date2][$name] = $this->numeric($value);
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

    /**
     * @param  list<stdClass>  $rows
     * @return list<int>
     */
    private function selectArchiveIds(array $rows): array
    {
        $archiveIds = [];

        foreach ($rows as $row) {
            $name = $row->name ?? null;
            $idArchive = $row->idarchive ?? null;

            if (! is_string($name)
                || (! is_int($idArchive) && ! is_string($idArchive))) {
                continue;
            }

            $archiveIds[] = (int) $idArchive;

            if (! str_contains($name, '.') && (int) ($row->value ?? 0) !== self::DONE_PARTIAL) {
                break;
            }
        }

        return array_values(array_unique($archiveIds));
    }

    private function numeric(mixed $value): int|float
    {
        $number = round((float) $value, 2);

        return floor($number) === $number ? (int) $number : $number;
    }
}
