<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Archiving\Events\ArchiveSitesSelecting;
use App\Matomo\Archiving\Events\AutoArchiveSegmentsCollecting;
use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Reporting\SegmentHashResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use stdClass;
use Throwable;

final readonly class DatabaseArchiveInvalidationManager implements ArchiveInvalidationManager
{
    private const int DONE_ERROR = 2;

    private const int DONE_INVALIDATED = 4;

    private const int DONE_PARTIAL = 5;

    private const int DONE_ERROR_INVALIDATED = 6;

    private const string ARCHIVES_TO_PURGE_OPTION = 'InvalidatedOldReports_DatesWebsiteIds';

    public function __construct(
        private Connection $connection,
        private ReportingPeriodFactory $periods,
        private SegmentHashResolver $segments,
        private MutableOptionRepository $options,
        private Dispatcher $events,
        /** @var list<string> */
        private array $enabledReportingPeriods,
        /** @var list<string> */
        private array $configuredAutoArchiveSegments,
    ) {}

    public function invalidate(
        array $siteIds,
        array $dates,
        ?string $period,
        ?string $segment,
        bool $cascadeDown,
        bool $forceInvalidateNonexistent,
    ): array {
        $this->validateSegmentSyntax($segment);
        $sites = new ArchiveSitesSelecting($siteIds, $dates, $period, $segment);
        $this->events->dispatch($sites);
        $siteIds = $this->normalizedSiteIds($sites->siteIds);
        [$basePeriods, $processedDates, $invalidDates, $warningDates] = $this->validatedPeriods(
            $dates,
            $period,
        );
        $periods = $this->expandedPeriods($basePeriods, $cascadeDown);
        $segmentHash = $this->segments->resolve($segment);
        $autoArchiveSegmentHashes = $segmentHash === ''
            ? $this->autoArchiveSegmentHashes($siteIds)
            : [];

        $this->connection->transaction(function () use (
            $siteIds,
            $periods,
            $period,
            $segmentHash,
            $forceInvalidateNonexistent,
            $processedDates,
            $cascadeDown,
            $autoArchiveSegmentHashes,
        ): void {
            $yearMonths = [];

            foreach ($this->periodsByTable($periods) as $table => $tablePeriods) {
                $yearMonths[] = substr($table, strlen('archive_numeric_'));

                if (! $this->connection->getSchemaBuilder()->hasTable($table)) {
                    $this->queueInvalidations(
                        $siteIds,
                        $tablePeriods,
                        $segmentHash,
                        $forceInvalidateNonexistent,
                        [],
                        $autoArchiveSegmentHashes,
                    );

                    continue;
                }

                $archives = $this->archivesToInvalidate($table, $siteIds, $tablePeriods, $segmentHash);
                $archiveIds = array_values(array_unique(array_map(
                    static fn (stdClass $archive): int => (int) $archive->idarchive,
                    $archives,
                )));

                if ($archiveIds !== []) {
                    $this->updateArchiveStatus($table, $archiveIds, $segmentHash);
                }

                if ($period !== 'range') {
                    $this->invalidateContainingRanges($table, $siteIds, $tablePeriods, $segmentHash);
                }

                $this->queueInvalidations(
                    $siteIds,
                    $tablePeriods,
                    $segmentHash,
                    $forceInvalidateNonexistent,
                    $archives,
                    $autoArchiveSegmentHashes,
                );
            }

            $this->addArchivesToPurge($yearMonths);

            if (($period === null || $period === 'day' || $cascadeDown) && $segmentHash === '') {
                $this->forgetRememberedInvalidations($siteIds, $processedDates);
            }
        });

        $logs = [];

        if ($warningDates !== []) {
            $minimumDate = CarbonImmutable::today('UTC')
                ->subDays($this->deleteLogsOlderThanDays())
                ->toDateString();
            $logs[] = 'Warning: the following Dates have not been invalidated, because they are earlier than your Log Deletion limit: '
                .implode(', ', $warningDates)
                ."\n The last day with logs is {$minimumDate}. "
                ."\n Please disable 'Delete old Logs' or set it to a higher deletion threshold (eg. 180 days or 365 years).'.";
        }

        $logs[] = 'Success. The following dates were invalidated successfully: '.implode(', ', $processedDates);

        if ($invalidDates !== []) {
            $logs[] = "Warning: some of the Dates to invalidate were invalid: '"
                .implode("', '", $invalidDates)
                ."'. Matomo simply ignored those and proceeded with the others.";
        }

        return $logs;
    }

    /**
     * @param  list<int>  $siteIds
     * @return list<string>
     */
    private function autoArchiveSegmentHashes(array $siteIds): array
    {
        $global = new AutoArchiveSegmentsCollecting($this->configuredAutoArchiveSegments, null);
        $this->events->dispatch($global);
        $definitions = $global->definitions;

        foreach ($siteIds as $siteId) {
            $site = new AutoArchiveSegmentsCollecting([], $siteId);
            $this->events->dispatch($site);
            $definitions = [...$definitions, ...$site->definitions];
        }

        $hashes = [];

        foreach ($definitions as $definition) {
            if (trim($definition) !== '') {
                $hashes[] = md5(urldecode($definition));
            }
        }

        if ($siteIds !== [] && $this->connection->getSchemaBuilder()->hasTable('segment')) {
            $rows = $this->connection
                ->table('segment')
                ->select(['definition', 'hash'])
                ->where('deleted', 0)
                ->where('auto_archive', 1)
                ->where(function (Builder $query) use ($siteIds): void {
                    $query->where('enable_only_idsite', 0)
                        ->orWhereIn('enable_only_idsite', $siteIds);
                })
                ->get();

            foreach ($rows as $row) {
                $storedHash = $row->hash ?? null;

                if (is_string($storedHash) && preg_match('/^[a-f0-9]{32}$/Di', $storedHash) === 1) {
                    $hashes[] = strtolower($storedHash);

                    continue;
                }

                $definition = $row->definition ?? null;

                if (is_string($definition) && $definition !== '') {
                    $hashes[] = md5(urldecode($definition));
                }
            }
        }

        return array_values(array_unique($hashes));
    }

    /**
     * @param  array<mixed>  $siteIds
     * @return list<int>
     */
    private function normalizedSiteIds(array $siteIds): array
    {
        $normalized = [];

        foreach ($siteIds as $siteId) {
            if ((is_int($siteId) || is_string($siteId))
                && preg_match('/^[1-9][0-9]*$/D', (string) $siteId) === 1) {
                $normalized[] = (int) $siteId;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  list<string>  $dates
     * @return array{list<ReportingPeriod>, list<string>, list<string>, list<string>}
     */
    private function validatedPeriods(array $dates, ?string $period): array
    {
        $periods = [];
        $processedDates = [];
        $invalidDates = [];
        $warningDates = [];
        $minimumDate = $this->minimumDateWithLogs();

        foreach (array_values(array_unique($dates)) as $date) {
            try {
                $reportingPeriod = $this->basePeriod($period, trim($date));
            } catch (Throwable) {
                $invalidDates[] = trim($date);

                continue;
            }

            if ($minimumDate !== null
                && ($reportingPeriod->startDate < $minimumDate
                    || $reportingPeriod->endDate < $minimumDate)) {
                $warningDates[] = $this->displayDate($reportingPeriod, $period, trim($date));

                continue;
            }

            $periods[] = $reportingPeriod;
            $processedDates[] = $this->displayDate($reportingPeriod, $period, trim($date));
        }

        return [$periods, $processedDates, $invalidDates, $warningDates];
    }

    private function basePeriod(?string $period, string $date): ReportingPeriod
    {
        if ($period === 'range') {
            if (! $this->validRangeSpecifier($date)) {
                throw new InvalidArgumentException("The date '{$date}' is not a valid range.");
            }

            [$ranges] = $this->periods->make('range', $date, 'UTC');
            $range = $ranges[0] ?? throw new InvalidArgumentException("The date '{$date}' is not a valid range.");

            return new ReportingPeriod(
                label: 'range',
                id: 5,
                startDate: $range->startDate,
                endDate: $range->endDate,
                resultKey: $range->rangeKey(),
            );
        }

        $normalized = strtolower($date);

        if (! in_array($normalized, ['today', 'yesterday'], true)
            && ! $this->validIsoDate($date, false)) {
            throw new InvalidArgumentException("The date '{$date}' is not valid.");
        }

        [$periods] = $this->periods->make($period ?? 'day', $date, 'UTC');

        return $periods[0] ?? throw new InvalidArgumentException("The date '{$date}' is not valid.");
    }

    private function validRangeSpecifier(string $date): bool
    {
        if (preg_match('/^(last|previous)[0-9]*$/D', $date) === 1) {
            return true;
        }

        if (preg_match(
            '/^((?:[0-9]{4}-[0-9]{1,2}-[0-9]{1,2})|last[ -]?(?:week|month|year)),'.
            '((?:[0-9]{4}-[0-9]{1,2}-[0-9]{1,2})|today|now|yesterday|last[ -]?(?:week|month|year))$/Di',
            $date,
            $parts,
        ) !== 1) {
            return false;
        }

        return $this->validRelativeOrIsoDate($parts[1])
            && $this->validRelativeOrIsoDate($parts[2]);
    }

    private function validRelativeOrIsoDate(string $date): bool
    {
        return preg_match('/^(today|now|yesterday|last[ -]?(week|month|year))$/Di', $date) === 1
            || $this->validIsoDate($date, true);
    }

    private function validIsoDate(string $date, bool $allowSingleDigits): bool
    {
        $dayPattern = $allowSingleDigits ? '[0-9]{1,2}' : '[0-9]{2}';

        if (preg_match('/^([0-9]{4})-('.$dayPattern.')-('.$dayPattern.')$/D', $date, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private function validateSegmentSyntax(?string $segment): void
    {
        (new SegmentDefinitionValidator)->validate($segment);
    }

    /**
     * @param  list<ReportingPeriod>  $basePeriods
     * @return list<ReportingPeriod>
     */
    private function expandedPeriods(array $basePeriods, bool $cascadeDown): array
    {
        $expanded = [];

        foreach ($basePeriods as $period) {
            $this->addPeriod($expanded, $period);

            if ($cascadeDown && $period->label !== 'range') {
                $this->addChildren($expanded, $period);
            }

            if ($period->label !== 'range' && $this->shouldPropagateUp($period)) {
                $this->addParents($expanded, $period);
            }
        }

        return array_values($expanded);
    }

    /** @param array<string, ReportingPeriod> $expanded */
    private function addChildren(array &$expanded, ReportingPeriod $period): void
    {
        if ($period->label === 'range') {
            return;
        }

        if ($period->label === 'day') {
            if ($this->shouldPropagateUp($period)) {
                $this->addParents($expanded, $period);
            }

            return;
        }

        if ($period->label === 'week') {
            $cursor = CarbonImmutable::parse($period->startDate, 'UTC');
            $end = CarbonImmutable::parse($period->endDate, 'UTC');

            while ($cursor->lessThanOrEqualTo($end)) {
                $day = $this->makePeriod('day', $cursor->toDateString());
                $this->addPeriod($expanded, $day);
                $this->addChildren($expanded, $day);
                $cursor = $cursor->addDay();
            }

            return;
        }

        if ($period->label === 'year') {
            $cursor = CarbonImmutable::parse($period->startDate, 'UTC');

            for ($month = 0; $month < 12; $month++) {
                $child = $this->makePeriod('month', $cursor->addMonths($month)->toDateString());
                $this->addPeriod($expanded, $child);
                $this->addChildren($expanded, $child);
            }

            return;
        }

        $cursor = CarbonImmutable::parse($period->startDate, 'UTC');
        $end = CarbonImmutable::parse($period->endDate, 'UTC');

        while ($cursor->lessThanOrEqualTo($end)) {
            $week = $this->makePeriod('week', $cursor->toDateString());

            if ($week->startDate === $cursor->toDateString() && $week->endDate <= $period->endDate) {
                $this->addPeriod($expanded, $week);
                $this->addChildren($expanded, $week);
                $cursor = CarbonImmutable::parse($week->endDate, 'UTC')->addDay();

                continue;
            }

            $day = $this->makePeriod('day', $cursor->toDateString());
            $this->addPeriod($expanded, $day);
            $this->addChildren($expanded, $day);
            $cursor = $cursor->addDay();
        }
    }

    /** @param array<string, ReportingPeriod> $expanded */
    private function addParents(
        array &$expanded,
        ReportingPeriod $period,
        ?string $originalDate = null,
    ): void {
        $parent = match ($period->label) {
            'day' => 'week',
            'week' => 'month',
            'month' => 'year',
            default => null,
        };

        if ($parent === null || ! in_array($parent, $this->enabledReportingPeriods, true)) {
            return;
        }

        $originalDate ??= $period->startDate;
        $parentPeriod = $this->makePeriod($parent, $originalDate);
        $this->addPeriod($expanded, $parentPeriod);
        $this->addParents($expanded, $parentPeriod, $originalDate);
    }

    private function shouldPropagateUp(ReportingPeriod $period): bool
    {
        return substr($period->startDate, 0, 7) === substr($period->endDate, 0, 7)
            && substr($period->startDate, 0, 4) === substr($period->endDate, 0, 4);
    }

    private function makePeriod(string $label, string $date): ReportingPeriod
    {
        [$periods] = $this->periods->make($label, $date, 'UTC');

        return $periods[0] ?? throw new InvalidArgumentException("The date '{$date}' is not valid.");
    }

    /** @param array<string, ReportingPeriod> $periods */
    private function addPeriod(array &$periods, ReportingPeriod $period): void
    {
        $periods[$period->id.'.'.$period->rangeKey()] = $period;
    }

    /**
     * @param  list<ReportingPeriod>  $periods
     * @return array<string, list<ReportingPeriod>>
     */
    private function periodsByTable(array $periods): array
    {
        $tables = [];

        foreach ($periods as $period) {
            $tables[$period->archiveTable()][] = $period;
        }

        return $tables;
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @return list<stdClass>
     */
    private function archivesToInvalidate(
        string $table,
        array $siteIds,
        array $periods,
        string $segmentHash,
    ): array {
        return array_values($this->connection
            ->table($table)
            ->select(['idarchive', 'idsite', 'period', 'date1', 'date2', 'name'])
            ->whereIn('idsite', $siteIds)
            ->where('value', '<>', self::DONE_PARTIAL)
            ->where(fn (Builder $query): Builder => $this->periodCondition($query, $periods))
            ->where(fn (Builder $query): Builder => $this->doneNameCondition($query, $segmentHash))
            ->get()
            ->all());
    }

    /** @param list<int> $archiveIds */
    private function updateArchiveStatus(string $table, array $archiveIds, string $segmentHash): void
    {
        $this->connection
            ->table($table)
            ->whereIn('idarchive', $archiveIds)
            ->whereNotIn('value', [self::DONE_ERROR, self::DONE_ERROR_INVALIDATED])
            ->where(fn (Builder $query): Builder => $this->doneNameCondition($query, $segmentHash))
            ->update(['value' => self::DONE_INVALIDATED]);
        $this->connection
            ->table($table)
            ->whereIn('idarchive', $archiveIds)
            ->where('value', self::DONE_ERROR)
            ->where(fn (Builder $query): Builder => $this->doneNameCondition($query, $segmentHash))
            ->update(['value' => self::DONE_ERROR_INVALIDATED]);
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    private function invalidateContainingRanges(
        string $table,
        array $siteIds,
        array $periods,
        string $segmentHash,
    ): void {
        $ids = $this->connection
            ->table($table)
            ->whereIn('idsite', $siteIds)
            ->where('period', 5)
            ->where(function (Builder $query) use ($periods): void {
                foreach ($periods as $index => $period) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $query->{$method}(function (Builder $query) use ($period): void {
                        $query->where('date1', '<=', $period->startDate)
                            ->where('date2', '>=', $period->endDate);
                    });
                }
            })
            ->where(fn (Builder $query): Builder => $this->doneNameCondition($query, $segmentHash))
            ->pluck('idarchive')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($ids !== []) {
            $this->updateArchiveStatus($table, array_values(array_unique($ids)), $segmentHash);
        }
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @param  list<stdClass>  $archives
     * @param  list<string>  $autoArchiveSegmentHashes
     */
    private function queueInvalidations(
        array $siteIds,
        array $periods,
        string $segmentHash,
        bool $forceInvalidateNonexistent,
        array $archives,
        array $autoArchiveSegmentHashes,
    ): void {
        $archiveIds = [];
        $archiveNames = [];

        foreach ($archives as $archive) {
            $periodKey = $this->archiveKey(
                (int) $archive->idsite,
                (int) $archive->period,
                (string) $archive->date1,
                (string) $archive->date2,
                '',
            );
            $name = (string) $archive->name;
            $key = $this->archiveKey(
                (int) $archive->idsite,
                (int) $archive->period,
                (string) $archive->date1,
                (string) $archive->date2,
                $name,
            );
            $archiveIds[$key] = (int) $archive->idarchive;
            $archiveNames[$periodKey][] = $name;
        }

        $creationDates = $this->siteCreationDates($siteIds);
        $hasSiteTable = $this->connection->getSchemaBuilder()->hasTable('site');
        $primaryName = 'done'.$segmentHash;

        foreach ($siteIds as $siteId) {
            foreach ($periods as $period) {
                if ($hasSiteTable && ! isset($creationDates[$siteId])) {
                    continue;
                }

                if (isset($creationDates[$siteId]) && $period->endDate < $creationDates[$siteId]) {
                    continue;
                }

                $periodKey = $this->archiveKey(
                    $siteId,
                    $period->id,
                    $period->startDate,
                    $period->endDate,
                    '',
                );

                if ($period->label === 'range' && ! $forceInvalidateNonexistent) {
                    continue;
                }

                $names = [$primaryName];

                foreach ($archiveNames[$periodKey] ?? [] as $archiveName) {
                    if ($archiveName === $primaryName || str_contains($archiveName, '.')) {
                        continue;
                    }

                    if (preg_match('/^done([a-zA-Z0-9]+)$/D', $archiveName, $matches) === 1
                        && in_array($matches[1], $autoArchiveSegmentHashes, true)) {
                        $names[] = $archiveName;
                    }
                }

                foreach (array_values(array_unique($names)) as $name) {
                    $key = $this->archiveKey(
                        $siteId,
                        $period->id,
                        $period->startDate,
                        $period->endDate,
                        $name,
                    );
                    $idArchive = $archiveIds[$key] ?? null;
                    $exists = $this->connection
                        ->table('archive_invalidations')
                        ->where('idsite', $siteId)
                        ->where('period', $period->id)
                        ->where('date1', $period->startDate)
                        ->where('date2', $period->endDate)
                        ->where('name', $name)
                        ->where('status', 0)
                        ->whereNull('report')
                        ->exists();

                    if (! $exists) {
                        $this->connection->table('archive_invalidations')->insert([
                            'idarchive' => $idArchive,
                            'name' => $name,
                            'report' => null,
                            'idsite' => $siteId,
                            'date1' => $period->startDate,
                            'date2' => $period->endDate,
                            'period' => $period->id,
                            'ts_invalidated' => CarbonImmutable::now('UTC')->toDateTimeString(),
                            'status' => 0,
                        ]);
                    }
                }
            }
        }
    }

    /** @param list<ReportingPeriod> $periods */
    private function periodCondition(Builder $query, array $periods): Builder
    {
        foreach ($periods as $index => $period) {
            $method = $index === 0 ? 'where' : 'orWhere';
            $query->{$method}(function (Builder $query) use ($period): void {
                $query->where('period', $period->id);

                if ($period->label === 'range') {
                    $query->where('date2', '>=', $period->startDate)
                        ->where('date1', '<=', $period->endDate);
                } else {
                    $query->where('date1', $period->startDate)
                        ->where('date2', $period->endDate);
                }
            });
        }

        return $query;
    }

    private function doneNameCondition(Builder $query, string $segmentHash): Builder
    {
        if ($segmentHash === '') {
            return $query->where('name', 'like', 'done%');
        }

        return $query->where('name', 'done'.$segmentHash)
            ->orWhere('name', 'like', 'done'.$segmentHash.'.%');
    }

    /**
     * @param  list<int>  $siteIds
     * @return array<int, string>
     */
    private function siteCreationDates(array $siteIds): array
    {
        if (! $this->connection->getSchemaBuilder()->hasTable('site')) {
            return [];
        }

        $dates = [];

        foreach ($this->connection->table('site')->whereIn('idsite', $siteIds)->get(['idsite', 'ts_created']) as $site) {
            if (isset($site->idsite, $site->ts_created)) {
                $dates[(int) $site->idsite] = substr((string) $site->ts_created, 0, 10);
            }
        }

        return $dates;
    }

    private function archiveKey(int $siteId, int $period, string $date1, string $date2, string $name): string
    {
        return implode('|', [$siteId, $period, substr($date1, 0, 10), substr($date2, 0, 10), $name]);
    }

    /** @param list<string> $yearMonths */
    private function addArchivesToPurge(array $yearMonths): void
    {
        $items = [];
        $serialized = $this->options->value(self::ARCHIVES_TO_PURGE_OPTION);

        if ($serialized !== null && $serialized !== '') {
            $decoded = @unserialize($serialized, ['allowed_classes' => false]);

            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_string($key) && preg_match('/^[0-9]{4}_[0-9]{2}$/D', $key) === 1) {
                        $items[] = $key;
                    } elseif (is_string($value)) {
                        $items[] = $value;
                    }
                }
            }
        }

        $items = array_values(array_unique([...$items, ...$yearMonths]));
        $this->options->set(self::ARCHIVES_TO_PURGE_OPTION, serialize($items));
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<string>  $dates
     */
    private function forgetRememberedInvalidations(array $siteIds, array $dates): void
    {
        if (! $this->connection->getSchemaBuilder()->hasTable('option')) {
            return;
        }

        foreach ($siteIds as $siteId) {
            foreach ($dates as $date) {
                if (str_contains($date, ',')) {
                    continue;
                }

                $needle = 'report_to_invalidate_'.$siteId.'_'.$date;
                $names = $this->connection
                    ->table('option')
                    ->where('option_name', 'like', '%report%to%invalidate%'.$siteId.'%'.$date.'%')
                    ->pluck('option_name')
                    ->filter(static fn (mixed $name): bool => is_string($name) && str_contains($name, $needle))
                    ->all();

                if ($names !== []) {
                    $this->connection->table('option')->whereIn('option_name', $names)->delete();
                }
            }
        }
    }

    private function minimumDateWithLogs(): ?string
    {
        if (! $this->deleteLogsEnabled()) {
            return null;
        }

        return CarbonImmutable::today('UTC')
            ->subDays($this->deleteLogsOlderThanDays())
            ->toDateString();
    }

    private function deleteLogsEnabled(): bool
    {
        return in_array(strtolower($this->options->value('delete_logs_enable') ?? ''), ['1', 'true', 'yes', 'on'], true);
    }

    private function deleteLogsOlderThanDays(): int
    {
        return max(1, (int) ($this->options->value('delete_logs_older_than') ?? 180));
    }

    private function displayDate(
        ReportingPeriod $period,
        ?string $requestedPeriod,
        ?string $requestedDate = null,
    ): string {
        if ($requestedPeriod === 'range') {
            return $period->rangeKey();
        }

        return match (strtolower($requestedDate ?? '')) {
            'today' => CarbonImmutable::today('UTC')->toDateString(),
            'yesterday' => CarbonImmutable::today('UTC')->subDay()->toDateString(),
            default => $requestedDate ?? $period->startDate,
        };
    }
}
