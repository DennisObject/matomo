<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Archiving\Events\AutoArchiveSegmentsCollecting;
use App\Matomo\Archiving\Events\CronArchiveSitesSelecting;
use App\Matomo\Archiving\Events\CronArchivingCompleted;
use App\Matomo\Archiving\Events\CronArchivingStarting;
use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Scheduling\ScheduledTaskLock;
use App\Matomo\Scheduling\ScheduledTaskRunner;
use App\Matomo\Sites\SiteRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Throwable;

final readonly class DatabaseCronArchiveRunner implements CronArchiveRunner
{
    private const string STARTED_OPTION = 'LastFullArchivingStartTime';

    private const string COMPLETED_OPTION = 'LastCompletedFullArchiving';

    private const int LOCK_TTL = 86_400;

    private const int INVALIDATION_LIMIT = 10_000;

    /** @var array<int, string> */
    private const array PERIOD_LABELS = [
        1 => 'day',
        2 => 'week',
        3 => 'month',
        4 => 'year',
        5 => 'range',
    ];

    /**
     * @param  list<string>  $configuredAutoArchiveSegments
     * @param  list<string>  $enabledReportingPeriods
     */
    public function __construct(
        private Connection $connection,
        private SiteRepository $sites,
        private ReportArchiver $archiver,
        private ScheduledTaskRunner $scheduledTasks,
        private MutableOptionRepository $options,
        private ScheduledTaskLock $lock,
        private Dispatcher $events,
        private LoggerInterface $logger,
        private array $configuredAutoArchiveSegments,
        private array $enabledReportingPeriods,
    ) {}

    public function run(): CronArchiveRunResult
    {
        if (! $this->lock->acquire('CronArchive', self::LOCK_TTL)) {
            return new CronArchiveRunResult(
                ['Archiving skipped because another archiving run is active.'],
                0,
                0,
            );
        }

        try {
            return $this->runLocked();
        } finally {
            $this->lock->release();
        }
    }

    private function runLocked(): CronArchiveRunResult
    {
        $startedAt = CarbonImmutable::now('UTC');
        $previousCompletion = $this->timestampOption(self::COMPLETED_OPTION);
        $this->options->set(self::STARTED_OPTION, (string) $startedAt->getTimestamp());
        $allSiteIds = $this->sites->allIds();
        $selection = new CronArchiveSitesSelecting($allSiteIds);
        $this->events->dispatch($selection);
        $siteIds = $this->validSiteIds($selection->siteIds, $allSiteIds);
        $this->events->dispatch(new CronArchivingStarting($siteIds));
        $lines = ['Starting reports archiving.'];
        $archives = 0;
        $errors = 0;
        $attemptedInvalidations = [];

        $schema = $this->connection->getSchemaBuilder();
        $hasInvalidations = $schema->hasTable('archive_invalidations');
        $hasSegments = $schema->hasTable('segment');

        if ($hasInvalidations) {
            $this->resetStaleInvalidations($startedAt);
        }

        while ($hasInvalidations && count($attemptedInvalidations) < self::INVALIDATION_LIMIT) {
            $pending = $this->claimInvalidation($attemptedInvalidations, $startedAt, $hasSegments);

            if ($pending === null) {
                break;
            }

            $attemptedInvalidations[] = $pending['id'];

            if ($pending['request'] === null) {
                $this->deleteInvalidation($pending['id']);
                $lines[] = $pending['warning'] ?? 'Removed an invalid archive request.';

                continue;
            }

            try {
                $result = $this->archiver->archive($pending['request']);
                $this->deleteInvalidation($pending['id']);
                $archives++;
                $lines[] = $this->archiveLine($pending['request'], $result);
            } catch (Throwable $throwable) {
                $this->releaseInvalidation($pending['id']);
                $errors++;
                $lines[] = $this->failureLine($pending['request'], $throwable);
            }
        }

        $segments = $this->autoArchiveSegments($siteIds, $hasSegments);

        foreach ($siteIds as $siteId) {
            $timezone = $this->sites->timezone($siteId);

            if ($timezone === null) {
                continue;
            }

            $today = $startedAt->setTimezone($timezone)->startOfDay();
            $forceYesterday = $previousCompletion === null
                || CarbonImmutable::createFromTimestampUTC($previousCompletion)
                    ->setTimezone($timezone)
                    ->lessThan($today);

            foreach ([null, ...($segments[$siteId] ?? [])] as $segment) {
                foreach ($this->regularRequests($siteId, $today, $segment, $forceYesterday) as $request) {
                    try {
                        $result = $this->archiver->archive($request);
                        $archives++;
                        $lines[] = $this->archiveLine($request, $result);
                    } catch (Throwable $throwable) {
                        $errors++;
                        $lines[] = $this->failureLine($request, $throwable);
                    }
                }
            }
        }

        try {
            foreach ($this->scheduledTasks->run() as $task) {
                $lines[] = "Scheduled task {$task['task']}: {$task['output']}";
            }
        } catch (Throwable $throwable) {
            $errors++;
            $lines[] = 'Scheduled tasks failed: '.$throwable->getMessage();
        }

        if ($errors === 0) {
            $this->options->set(
                self::COMPLETED_OPTION,
                (string) CarbonImmutable::now('UTC')->getTimestamp(),
            );
        }

        $lines[] = "Processed {$archives} archives with {$errors} errors.";
        $result = new CronArchiveRunResult($lines, $archives, $errors);
        $this->events->dispatch(new CronArchivingCompleted($result));

        foreach ($lines as $line) {
            $errors === 0 ? $this->logger->info($line) : $this->logger->warning($line);
        }

        return $result;
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<int>  $existingSiteIds
     * @return list<int>
     */
    private function validSiteIds(array $siteIds, array $existingSiteIds): array
    {
        $existing = array_fill_keys($existingSiteIds, true);
        $valid = [];

        foreach ($siteIds as $siteId) {
            if ($siteId > 0 && isset($existing[$siteId])) {
                $valid[] = $siteId;
            }
        }

        return array_values(array_unique($valid));
    }

    /**
     * @return list<ArchiveReportRequest>
     */
    private function regularRequests(
        int $siteId,
        CarbonImmutable $today,
        ?string $segment,
        bool $forceYesterday,
    ): array {
        $requests = [];

        if (in_array('day', $this->enabledReportingPeriods, true)) {
            $requests[] = new ArchiveReportRequest(
                siteId: $siteId,
                period: 'day',
                date: $today->subDay()->toDateString(),
                segment: $segment,
                force: $forceYesterday,
            );
            $requests[] = new ArchiveReportRequest(
                siteId: $siteId,
                period: 'day',
                date: $today->toDateString(),
                segment: $segment,
            );
        }

        foreach (['week', 'month', 'year'] as $period) {
            if (in_array($period, $this->enabledReportingPeriods, true)) {
                $requests[] = new ArchiveReportRequest(
                    siteId: $siteId,
                    period: $period,
                    date: $today->toDateString(),
                    segment: $segment,
                );
            }
        }

        return $requests;
    }

    /**
     * @param  list<int>  $siteIds
     * @return array<int, list<string>>
     */
    private function autoArchiveSegments(array $siteIds, bool $hasSegmentTable): array
    {
        $global = new AutoArchiveSegmentsCollecting($this->configuredAutoArchiveSegments, null);
        $this->events->dispatch($global);
        $bySite = [];
        $storedGlobal = [];
        $storedBySite = [];

        if ($siteIds !== [] && $hasSegmentTable) {
            $rows = $this->connection
                ->table('segment')
                ->select(['definition', 'enable_only_idsite'])
                ->where('deleted', 0)
                ->where('auto_archive', 1)
                ->where(function ($query) use ($siteIds): void {
                    $query->where('enable_only_idsite', 0)
                        ->orWhereIn('enable_only_idsite', $siteIds);
                })
                ->get();

            foreach ($rows as $row) {
                $definition = $row->definition ?? null;
                $onlySite = $row->enable_only_idsite ?? null;

                if (! is_string($definition) || (! is_int($onlySite) && ! is_string($onlySite))) {
                    continue;
                }

                if ((int) $onlySite === 0) {
                    $storedGlobal[] = $definition;
                } else {
                    $storedBySite[(int) $onlySite][] = $definition;
                }
            }
        }

        foreach ($siteIds as $siteId) {
            $site = new AutoArchiveSegmentsCollecting([], $siteId);
            $this->events->dispatch($site);
            $bySite[$siteId] = $this->normalizedSegments([
                ...$global->definitions,
                ...$storedGlobal,
                ...($storedBySite[$siteId] ?? []),
                ...$site->definitions,
            ]);
        }

        return $bySite;
    }

    /**
     * @param  list<string>  $segments
     * @return list<string>
     */
    private function normalizedSegments(array $segments): array
    {
        $normalized = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);

            if ($segment !== '' && strlen($segment) <= 8192) {
                $normalized[] = $segment;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function resetStaleInvalidations(CarbonImmutable $now): void
    {
        $this->connection
            ->table('archive_invalidations')
            ->where('status', 1)
            ->where('ts_started', '<', $now->subDay()->toDateTimeString())
            ->update([
                'status' => 0,
                'ts_started' => null,
                'processing_host' => null,
                'process_id' => null,
            ]);
    }

    /**
     * @param  list<int>  $attempted
     * @return array{id: int, request: ArchiveReportRequest|null, warning: string|null}|null
     */
    private function claimInvalidation(
        array $attempted,
        CarbonImmutable $now,
        bool $hasSegmentTable,
    ): ?array {
        $row = $this->connection->transaction(function () use ($attempted, $now): ?stdClass {
            $query = $this->connection
                ->table('archive_invalidations')
                ->where('status', 0)
                ->orderBy('idinvalidation')
                ->lockForUpdate();

            if ($attempted !== []) {
                $query->whereNotIn('idinvalidation', $attempted);
            }

            $row = $query->first();

            if (! $row instanceof stdClass) {
                return null;
            }

            $id = (int) ($row->idinvalidation ?? 0);

            if ($id <= 0) {
                return null;
            }

            $this->connection->table('archive_invalidations')->where('idinvalidation', $id)->update([
                'status' => 1,
                'ts_started' => $now->toDateTimeString(),
                'processing_host' => substr(gethostname() ?: 'localhost', 0, 100),
                'process_id' => substr((string) (getmypid() ?: ''), 0, 15),
            ]);

            return $row;
        });

        if (! $row instanceof stdClass) {
            return null;
        }

        $id = (int) ($row->idinvalidation ?? 0);

        try {
            return [
                'id' => $id,
                'request' => $this->queuedRequest($row, $hasSegmentTable),
                'warning' => null,
            ];
        } catch (Throwable) {
            return [
                'id' => $id,
                'request' => null,
                'warning' => "Removed invalid archive request {$id}.",
            ];
        }
    }

    private function queuedRequest(stdClass $row, bool $hasSegmentTable): ArchiveReportRequest
    {
        $siteId = (int) ($row->idsite ?? 0);
        $periodId = (int) ($row->period ?? 0);
        $period = self::PERIOD_LABELS[$periodId] ?? null;
        $date1 = $row->date1 ?? null;
        $date2 = $row->date2 ?? null;
        $name = $row->name ?? null;

        if ($siteId <= 0 || $period === null || ! is_string($date1) || ! is_string($name)) {
            throw new RuntimeException('The archive invalidation is not valid.');
        }

        $date = $period === 'range'
            ? $date1.','.(is_string($date2) ? $date2 : '')
            : $date1;
        [$segment, $plugin] = $this->segmentAndPlugin($name, $hasSegmentTable);
        $report = $row->report ?? null;

        return new ArchiveReportRequest(
            siteId: $siteId,
            period: $period,
            date: $date,
            segment: $segment,
            plugin: $plugin,
            reports: is_string($report) && $report !== '' ? [$report] : [],
            force: true,
        );
    }

    /** @return array{string|null, string|null} */
    private function segmentAndPlugin(string $name, bool $hasSegmentTable): array
    {
        if (! str_starts_with($name, 'done')) {
            throw new RuntimeException('The archive invalidation name is not valid.');
        }

        $parts = explode('.', substr($name, 4), 2);
        $hash = $parts[0];
        $plugin = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;

        if ($hash === '') {
            return [null, $plugin];
        }

        if (preg_match('/^[a-f0-9]{32}$/D', $hash) !== 1) {
            throw new RuntimeException('The archive invalidation segment is not valid.');
        }

        foreach ($this->configuredAutoArchiveSegments as $definition) {
            if (md5(urldecode($definition)) === $hash) {
                return [$definition, $plugin];
            }
        }

        if ($hasSegmentTable) {
            $definition = $this->connection
                ->table('segment')
                ->where('hash', $hash)
                ->where('deleted', 0)
                ->value('definition');

            if (is_string($definition) && $definition !== '') {
                return [$definition, $plugin];
            }
        }

        throw new RuntimeException('The archive invalidation segment no longer exists.');
    }

    private function deleteInvalidation(int $id): void
    {
        $this->connection->table('archive_invalidations')->where('idinvalidation', $id)->delete();
    }

    private function releaseInvalidation(int $id): void
    {
        $this->connection->table('archive_invalidations')->where('idinvalidation', $id)->update([
            'status' => 0,
            'ts_started' => null,
            'processing_host' => null,
            'process_id' => null,
        ]);
    }

    private function timestampOption(string $name): ?int
    {
        $value = $this->options->value($name);

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    private function archiveLine(ArchiveReportRequest $request, ArchiveReportResult $result): string
    {
        $source = $result->cached ? 'cached' : 'fresh';

        return "Archived website {$request->siteId}, period {$request->period}, date {$request->date}, "
            ."{$result->visits} visits ({$source}).";
    }

    private function failureLine(ArchiveReportRequest $request, Throwable $throwable): string
    {
        return "Archiving website {$request->siteId}, period {$request->period}, date {$request->date} "
            .'failed: '.$throwable->getMessage();
    }
}
