<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Archiving\Events\ArchiveReportsCompleted;
use App\Matomo\Archiving\Events\ArchiveReportsStarting;
use App\Matomo\Archiving\Events\ArchiveVisitsQueryBuilding;
use App\Matomo\Options\OptionRepository;
use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final readonly class DatabaseReportArchiver implements ReportArchiver
{
    private const int DONE_OK = 1;

    private const int DONE_ERROR = 2;

    private const int DONE_OK_TEMPORARY = 3;

    private const int DONE_INVALIDATED = 4;

    private const int DONE_PARTIAL = 5;

    private const int DONE_ERROR_INVALIDATED = 6;

    private const int DEFAULT_TODAY_TIME_TO_LIVE = 900;

    private const string TODAY_TIME_TO_LIVE_OPTION = 'todayArchiveTimeToLive';

    public function __construct(
        private Connection $connection,
        private ReportingPeriodFactory $periods,
        private SegmentHashResolver $segments,
        private SiteRepository $sites,
        private OptionRepository $options,
        private SegmentDefinitionValidator $segmentValidator,
        private Dispatcher $events,
    ) {}

    public function archive(ArchiveReportRequest $request): ArchiveReportResult
    {
        $timezone = $this->validate($request);
        [$periods] = $this->periods->make($request->period, $request->date, $timezone);
        $this->events->dispatch(new ArchiveReportsStarting($request));
        $archiveIds = [];
        $visits = 0;
        $cached = true;

        foreach ($periods as $period) {
            [$archiveId, $periodVisits, $periodCached] = $this->archivePeriod(
                $request,
                $period,
                $timezone,
            );
            $archiveIds[] = $archiveId;
            $visits += $periodVisits;
            $cached = $cached && $periodCached;
        }

        $result = new ArchiveReportResult(
            archiveIds: array_values(array_unique($archiveIds)),
            visits: $visits,
            cached: $cached,
        );
        $this->events->dispatch(new ArchiveReportsCompleted($request, $result));

        return $result;
    }

    private function validate(ArchiveReportRequest $request): string
    {
        if ($request->siteId <= 0) {
            throw new InvalidArgumentException('The website ID must be a positive integer.');
        }

        $timezone = $this->sites->timezone($request->siteId);

        if ($timezone === null) {
            throw new InvalidArgumentException("The website id = {$request->siteId} does not exist.");
        }

        if ($request->segment !== null && strlen($request->segment) > 8192) {
            throw new InvalidArgumentException('The segment definition is too long.');
        }

        $this->segmentValidator->validate($request->segment);

        if ($request->plugin !== null
            && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,119}$/D', $request->plugin) !== 1) {
            throw new InvalidArgumentException("The plugin name '{$request->plugin}' is not valid.");
        }

        foreach ($request->reports as $report) {
            if ($report === '' || strlen($report) > 255) {
                throw new InvalidArgumentException("The report name '{$report}' is not valid.");
            }
        }

        return $timezone;
    }

    /** @return array{int, int|float, bool} */
    private function archivePeriod(
        ArchiveReportRequest $request,
        ReportingPeriod $period,
        string $timezone,
    ): array {
        if ($period->label !== 'day') {
            throw new RuntimeException(
                'Parent report periods require subarchive aggregation and are not available yet.',
            );
        }

        $numericTable = $period->archiveTable();
        $blobTable = str_replace('archive_numeric_', 'archive_blob_', $numericTable);
        $this->ensureArchiveTables($numericTable, $blobTable);
        $segmentHash = $this->segments->resolve($request->segment);
        $doneName = $this->doneName($segmentHash, $request->plugin);
        $partial = $request->plugin !== null || $request->reports !== [];

        if (! $request->force) {
            $existing = $this->reusableArchive(
                $numericTable,
                $request,
                $period,
                $timezone,
                $segmentHash,
                $doneName,
                $partial,
            );

            if ($existing !== null) {
                return [$existing['id'], $existing['visits'], true];
            }
        }

        $archiveId = $this->nextArchiveId($numericTable);
        $archivedAt = CarbonImmutable::now('UTC')->toDateTimeString();
        $base = $this->baseRow($archiveId, $request->siteId, $period, $archivedAt);
        $this->connection->table($numericTable)->insert([
            ...$base,
            'name' => $doneName,
            'value' => self::DONE_ERROR,
        ]);

        $metrics = $this->coreMetrics($request, $period, $timezone);
        $records = new ArchiveRecordSet;

        foreach ($metrics as $name => $value) {
            $records->addNumeric($name, $value);
        }

        $this->events->dispatch(new ArchiveReportsCollecting(
            request: $request,
            period: $period,
            coreMetrics: $metrics,
            records: $records,
        ));
        $blobs = $this->compressedBlobs($records->blobs());

        $this->connection->transaction(function () use (
            $numericTable,
            $blobTable,
            $base,
            $doneName,
            $partial,
            $records,
            $blobs,
        ): void {
            $this->insertNumericRecords($numericTable, $base, $records->numeric());
            $this->insertBlobRecords($blobTable, $base, $blobs);
            $currentStatus = $this->connection
                ->table($numericTable)
                ->where('idarchive', $base['idarchive'])
                ->where('name', $doneName)
                ->value('value');
            $doneValue = (int) $currentStatus === self::DONE_ERROR_INVALIDATED
                ? self::DONE_INVALIDATED
                : ($partial ? self::DONE_PARTIAL : self::DONE_OK);
            $this->connection
                ->table($numericTable)
                ->where('idarchive', $base['idarchive'])
                ->where('name', $doneName)
                ->update(['value' => $doneValue]);
        });

        return [$archiveId, $metrics['nb_visits'], false];
    }

    /**
     * @return array{id: int, visits: int|float}|null
     */
    private function reusableArchive(
        string $numericTable,
        ArchiveReportRequest $request,
        ReportingPeriod $period,
        string $timezone,
        string $segmentHash,
        string $doneName,
        bool $partial,
    ): ?array {
        $fullDoneName = $this->doneName($segmentHash, null);
        $row = $this->connection
            ->table($numericTable)
            ->select(['idarchive', 'name', 'ts_archived'])
            ->where('idsite', $request->siteId)
            ->where('period', $period->id)
            ->where('date1', $period->startDate)
            ->where('date2', $period->endDate)
            ->where(function (Builder $query) use ($fullDoneName, $doneName, $partial): void {
                $query->where(function (Builder $full) use ($fullDoneName): void {
                    $full->where('name', $fullDoneName)
                        ->whereIn('value', [self::DONE_OK, self::DONE_OK_TEMPORARY]);
                });

                if ($partial) {
                    $query->orWhere(function (Builder $requested) use ($doneName): void {
                        $requested->where('name', $doneName)
                            ->whereIn('value', [
                                self::DONE_OK,
                                self::DONE_OK_TEMPORARY,
                                self::DONE_PARTIAL,
                            ]);
                    });
                }
            })
            ->orderByDesc('ts_archived')
            ->orderByDesc('idarchive')
            ->first();

        if (! $row instanceof stdClass || ! $this->isFresh($row, $period, $timezone)) {
            return null;
        }

        $idArchive = $row->idarchive ?? null;

        if (! is_int($idArchive) && ! is_string($idArchive)) {
            return null;
        }

        $visits = $this->connection
            ->table($numericTable)
            ->where('idarchive', (int) $idArchive)
            ->where('name', 'nb_visits')
            ->value('value');

        return [
            'id' => (int) $idArchive,
            'visits' => $this->numeric($visits),
        ];
    }

    private function isFresh(stdClass $row, ReportingPeriod $period, string $timezone): bool
    {
        if ($period->endDate < CarbonImmutable::today($timezone)->toDateString()) {
            return true;
        }

        $archivedAt = $row->ts_archived ?? null;

        if (! is_string($archivedAt) || $archivedAt === '') {
            return false;
        }

        $configured = $this->options->value(self::TODAY_TIME_TO_LIVE_OPTION);
        $timeToLive = is_string($configured) && ctype_digit($configured) && (int) $configured > 0
            ? (int) $configured
            : self::DEFAULT_TODAY_TIME_TO_LIVE;

        return CarbonImmutable::parse($archivedAt, 'UTC')
            ->greaterThanOrEqualTo(CarbonImmutable::now('UTC')->subSeconds($timeToLive));
    }

    /** @return array<string, int|float> */
    private function coreMetrics(
        ArchiveReportRequest $request,
        ReportingPeriod $period,
        string $timezone,
    ): array {
        $start = CarbonImmutable::parse($period->startDate, $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($period->endDate, $timezone)->addDay()->startOfDay()->utc();
        $query = $this->connection
            ->table('log_visit')
            ->where('idsite', $request->siteId)
            ->where('visit_last_action_time', '>=', $start->toDateTimeString())
            ->where('visit_last_action_time', '<', $end->toDateTimeString());
        $building = new ArchiveVisitsQueryBuilding($request, $period, $query);
        $this->events->dispatch($building);

        if (! $building->segmentApplied) {
            throw new InvalidArgumentException(
                "The segment '{$request->segment}' cannot be archived because no segment handler supports it.",
            );
        }

        $row = $query->selectRaw(implode(', ', [
            'COUNT(DISTINCT idvisitor) AS nb_uniq_visitors',
            'COUNT(DISTINCT config_id) AS nb_uniq_fingerprints',
            'COUNT(*) AS nb_visits',
            'COALESCE(SUM(visit_total_actions), 0) AS nb_actions',
            'COALESCE(MAX(visit_total_actions), 0) AS max_actions',
            'COALESCE(SUM(visit_total_time), 0) AS sum_visit_length',
            'COALESCE(SUM(CASE WHEN visit_total_actions IN (0, 1) THEN 1 ELSE 0 END), 0) AS bounce_count',
            'COALESCE(SUM(CASE WHEN visit_goal_converted = 1 THEN 1 ELSE 0 END), 0) AS nb_visits_converted',
            'COUNT(DISTINCT user_id) AS nb_users',
        ]))->first();

        if (! $row instanceof stdClass) {
            throw new RuntimeException('The visit metrics query did not return a result.');
        }

        return [
            'nb_uniq_visitors' => $this->numeric($row->nb_uniq_visitors ?? null),
            'nb_uniq_fingerprints' => $this->numeric($row->nb_uniq_fingerprints ?? null),
            'nb_visits' => $this->numeric($row->nb_visits ?? null),
            'nb_actions' => $this->numeric($row->nb_actions ?? null),
            'max_actions' => $this->numeric($row->max_actions ?? null),
            'sum_visit_length' => $this->numeric($row->sum_visit_length ?? null),
            'bounce_count' => $this->numeric($row->bounce_count ?? null),
            'nb_visits_converted' => $this->numeric($row->nb_visits_converted ?? null),
            'nb_users' => $this->numeric($row->nb_users ?? null),
        ];
    }

    private function numeric(mixed $value): int|float
    {
        if (! is_numeric($value)) {
            return 0;
        }

        $number = round((float) $value, 2);

        return floor($number) === $number ? (int) $number : $number;
    }

    private function doneName(string $segmentHash, ?string $plugin): string
    {
        return 'done'.$segmentHash.($plugin === null ? '' : '.'.$plugin);
    }

    /**
     * @return array{idarchive: int, idsite: int, date1: string, date2: string, period: int, ts_archived: string}
     */
    private function baseRow(
        int $archiveId,
        int $siteId,
        ReportingPeriod $period,
        string $archivedAt,
    ): array {
        return [
            'idarchive' => $archiveId,
            'idsite' => $siteId,
            'date1' => $period->startDate,
            'date2' => $period->endDate,
            'period' => $period->id,
            'ts_archived' => $archivedAt,
        ];
    }

    /**
     * @param  array{idarchive: int, idsite: int, date1: string, date2: string, period: int, ts_archived: string}  $base
     * @param  array<string, int|float>  $records
     */
    private function insertNumericRecords(string $table, array $base, array $records): void
    {
        $rows = [];

        foreach ($records as $name => $value) {
            $rows[] = [...$base, 'name' => $name, 'value' => $value];
        }

        foreach (array_chunk($rows, 1_000) as $chunk) {
            $this->connection->table($table)->insert($chunk);
        }
    }

    /**
     * @param  array{idarchive: int, idsite: int, date1: string, date2: string, period: int, ts_archived: string}  $base
     * @param  array<string, string>  $records
     */
    private function insertBlobRecords(string $table, array $base, array $records): void
    {
        $rows = [];

        foreach ($records as $name => $value) {
            $rows[] = [...$base, 'name' => $name, 'value' => $value];
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            $this->connection->table($table)->insert($chunk);
        }
    }

    /**
     * @param  array<string, string>  $records
     * @return array<string, string>
     */
    private function compressedBlobs(array $records): array
    {
        $compressed = [];

        foreach ($records as $name => $value) {
            $blob = gzcompress($value);

            if (! is_string($blob)) {
                throw new RuntimeException("Archive record '{$name}' could not be compressed.");
            }

            $compressed[$name] = $blob;
        }

        return $compressed;
    }

    private function nextArchiveId(string $numericTable): int
    {
        return $this->connection->transaction(function () use ($numericTable): int {
            $sequenceName = $this->connection->getTablePrefix().$numericTable;

            if (strlen($sequenceName) > 120
                || preg_match('/^[A-Za-z0-9_]+$/D', $sequenceName) !== 1) {
                throw new RuntimeException('The archive sequence name is not valid.');
            }

            $maximum = $this->connection->table($numericTable)->max('idarchive');
            $maximum = is_numeric($maximum) ? (int) $maximum : 0;
            $this->connection->table('sequence')->insertOrIgnore([
                'name' => $sequenceName,
                'value' => $maximum,
            ]);
            $value = $this->connection
                ->table('sequence')
                ->where('name', $sequenceName)
                ->lockForUpdate()
                ->value('value');

            if (! is_int($value) && ! is_string($value)) {
                throw new RuntimeException("Archive sequence '{$sequenceName}' could not be read.");
            }

            $next = max((int) $value, $maximum) + 1;
            $this->connection
                ->table('sequence')
                ->where('name', $sequenceName)
                ->update(['value' => $next]);

            return $next;
        });
    }

    private function ensureArchiveTables(string $numericTable, string $blobTable): void
    {
        if (preg_match('/^archive_numeric_[0-9]{4}_[0-9]{2}$/D', $numericTable) !== 1
            || preg_match('/^archive_blob_[0-9]{4}_[0-9]{2}$/D', $blobTable) !== 1) {
            throw new RuntimeException('An archive table name was not valid.');
        }

        if ($this->connection->getDriverName() === 'mysql') {
            $this->createMysqlArchiveTable($numericTable, false);
            $this->createMysqlArchiveTable($blobTable, true);

            return;
        }

        $schema = $this->connection->getSchemaBuilder();

        if (! $schema->hasTable($numericTable)) {
            $schema->create($numericTable, function (Blueprint $table): void {
                $this->archiveColumns($table);
                $table->double('value')->nullable();
                $table->index(['idsite', 'date1', 'date2', 'period', 'name']);
            });
        }

        if (! $schema->hasTable($blobTable)) {
            $schema->create($blobTable, function (Blueprint $table): void {
                $this->archiveColumns($table);
                $table->binary('value')->nullable();
            });
        }
    }

    private function createMysqlArchiveTable(string $table, bool $blob): void
    {
        $physicalTable = $this->connection->getTablePrefix().$table;

        if (preg_match('/^[A-Za-z0-9_]+$/D', $physicalTable) !== 1) {
            throw new RuntimeException('The physical archive table name is not valid.');
        }

        $valueType = $blob ? 'LONGBLOB' : 'DOUBLE';
        $siteIndex = $blob
            ? ''
            : ', INDEX index_idsite_dates_period(idsite, date1, date2, period, name(6))';
        $this->connection->statement(
            "CREATE TABLE IF NOT EXISTS `{$physicalTable}` ("
            .'idarchive INTEGER UNSIGNED NOT NULL, '
            .'name VARCHAR(190) NOT NULL, '
            .'idsite INTEGER UNSIGNED NULL, '
            .'date1 DATE NULL, '
            .'date2 DATE NULL, '
            .'period TINYINT UNSIGNED NULL, '
            .'ts_archived DATETIME NULL, '
            ."value {$valueType} NULL, "
            .'PRIMARY KEY(idarchive, name)'
            .$siteIndex
            .', INDEX index_period_archived(period, ts_archived))',
        );
    }

    private function archiveColumns(Blueprint $table): void
    {
        $table->unsignedInteger('idarchive');
        $table->string('name', 190);
        $table->unsignedInteger('idsite')->nullable();
        $table->date('date1')->nullable();
        $table->date('date2')->nullable();
        $table->unsignedTinyInteger('period')->nullable();
        $table->dateTime('ts_archived')->nullable();
        $table->primary(['idarchive', 'name']);
        $table->index(['period', 'ts_archived']);
    }
}
