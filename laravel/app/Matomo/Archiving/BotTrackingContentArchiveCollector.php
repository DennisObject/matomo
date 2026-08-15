<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * @phpstan-type ContentReports array<string, HierarchicalArchiveTable>
 */
final readonly class BotTrackingContentArchiveCollector
{
    private const int PAGE_URL_ACTION = 1;

    private const int DOWNLOAD_ACTION = 3;

    private const string PAGES_RECORD = 'BotTracking_AIChatbotsRequestedPages';

    private const string DOCUMENTS_RECORD = 'BotTracking_AIChatbotsRequestedDocuments';

    private const string BROKEN_RECORD = 'BotTracking_AIChatbotsBrokenContent';

    private const string RANKING_SUMMARY = '-1';

    /** @var list<string> */
    private const array REPORTS = [
        'BotTracking.getAIChatbotContentPages',
        'BotTracking.getAIChatbotContentDocuments',
        'BotTracking.getAIChatbotBrokenContent',
    ];

    public function __construct(
        private Connection $connection,
        private ReportingSubperiodFactory $subperiods,
        private SegmentHashResolver $segments,
        private BlobArchiveRepository $blobs,
        private SiteRepository $sites,
        private BotTrackingArchiveConfiguration $configuration,
    ) {}

    public function __invoke(ArchiveReportsCollecting $event): void
    {
        if (! $this->requested($event) || $this->segmented($event)) {
            return;
        }

        $timezone = $this->sites->timezone($event->request->siteId);

        if ($timezone === null) {
            return;
        }

        $reports = $event->period->label === 'day'
            ? $this->dayReports($event, $timezone)
            : $this->parentReports($event);

        foreach ($reports as $recordName => $table) {
            $sortColumn = $recordName === self::BROKEN_RECORD
                ? 'total_broken_requests'
                : 'requests';
            $event->records->addBlob(
                $recordName,
                $table->serialized(
                    $this->effectiveLimit($this->configuration->contentLimit),
                    PHP_INT_MAX,
                    $sortColumn,
                )[''],
            );
        }
    }

    /** @return ContentReports */
    private function dayReports(
        ArchiveReportsCollecting $event,
        string $timezone,
    ): array {
        $reports = $this->emptyReports();

        if (! $this->columnsExist()) {
            return $reports;
        }

        [$start, $end] = $this->dateRange($event, $timezone);
        $this->addContentRows(
            $reports[self::PAGES_RECORD],
            $event,
            $start,
            $end,
            self::PAGE_URL_ACTION,
        );
        $this->addContentRows(
            $reports[self::DOCUMENTS_RECORD],
            $event,
            $start,
            $end,
            self::DOWNLOAD_ACTION,
        );
        $this->addBrokenRows($reports[self::BROKEN_RECORD], $event, $start, $end);

        return $reports;
    }

    /** @return ContentReports */
    private function parentReports(ArchiveReportsCollecting $event): array
    {
        $reports = $this->emptyReports();
        $children = $this->subperiods->children($event->period);
        $segmentHash = $this->segments->resolve($event->request->segment);

        foreach (array_keys($reports) as $recordName) {
            $archives = $this->blobs->rows(
                [$event->request->siteId],
                $children,
                $segmentHash,
                $recordName,
            )[$event->request->siteId] ?? [];

            foreach ($children as $child) {
                foreach ($archives[$child->rangeKey()] ?? [] as $row) {
                    $label = $this->archiveLabel($row['columns']['label'] ?? null);

                    if ($label !== null) {
                        $reports[$recordName]->mergeRoot($label, $row['columns']);
                    }
                }
            }
        }

        return $reports;
    }

    private function addContentRows(
        HierarchicalArchiveTable $table,
        ArchiveReportsCollecting $event,
        CarbonImmutable $start,
        CarbonImmutable $end,
        int $actionType,
    ): void {
        $rows = $this->baseQuery($event, $start, $end)
            ->where('log_action.type', $actionType)
            ->select('log_action.name')
            ->selectRaw(implode(', ', [
                'COUNT(*) AS requests',
                'COALESCE(SUM(bot.response_time_ms), 0) AS sum_server_time',
                'COALESCE(SUM(CASE WHEN bot.response_time_ms IS NOT NULL THEN 1 ELSE 0 END), 0) AS nb_server_time',
                'COALESCE(SUM(bot.response_size_bytes), 0) AS sum_response_size',
                'COALESCE(SUM(CASE WHEN bot.response_size_bytes IS NOT NULL THEN 1 ELSE 0 END), 0) AS nb_response_size',
                'COALESCE(SUM(CASE WHEN bot.http_status_code IN (404, 410) THEN 1 ELSE 0 END), 0) AS page_not_found_404_requests',
                'COALESCE(SUM(CASE WHEN bot.http_status_code BETWEEN 500 AND 599 THEN 1 ELSE 0 END), 0) AS server_error_5xx_requests',
            ]))
            ->groupBy('log_action.name')
            ->orderByDesc('requests')
            ->orderBy('log_action.name')
            ->cursor();
        $position = 0;

        foreach ($rows as $row) {
            $label = $this->stringValue($row->name ?? null);

            if ($label === null) {
                continue;
            }

            $this->mergeRankedRow(
                $table,
                $label,
                [
                    'requests' => $this->numeric($row->requests ?? null),
                    'sum_server_time' => $this->numeric($row->sum_server_time ?? null),
                    'nb_server_time' => $this->numeric($row->nb_server_time ?? null),
                    'sum_response_size' => $this->numeric($row->sum_response_size ?? null),
                    'nb_response_size' => $this->numeric($row->nb_response_size ?? null),
                    'page_not_found_404_requests' => $this->numeric(
                        $row->page_not_found_404_requests ?? null,
                    ),
                    'server_error_5xx_requests' => $this->numeric(
                        $row->server_error_5xx_requests ?? null,
                    ),
                ],
                $position++,
            );
        }
    }

    private function addBrokenRows(
        HierarchicalArchiveTable $table,
        ArchiveReportsCollecting $event,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): void {
        $rows = $this->baseQuery($event, $start, $end)
            ->whereIn('log_action.type', [self::PAGE_URL_ACTION, self::DOWNLOAD_ACTION])
            ->select('log_action.name')
            ->selectRaw(implode(', ', [
                'COALESCE(SUM(CASE WHEN bot.http_status_code IN (404, 410) THEN 1 ELSE 0 END), 0) AS page_not_found_404_requests',
                'COALESCE(SUM(CASE WHEN bot.http_status_code BETWEEN 500 AND 599 THEN 1 ELSE 0 END), 0) AS server_error_5xx_requests',
            ]))
            ->groupBy('log_action.name')
            ->havingRaw(
                'SUM(CASE WHEN bot.http_status_code IN (404, 410) OR bot.http_status_code BETWEEN 500 AND 599 THEN 1 ELSE 0 END) >= 1',
            )
            ->orderByRaw(
                'SUM(CASE WHEN bot.http_status_code IN (404, 410) OR bot.http_status_code BETWEEN 500 AND 599 THEN 1 ELSE 0 END) DESC',
            )
            ->orderBy('log_action.name')
            ->cursor();
        $position = 0;

        foreach ($rows as $row) {
            $label = $this->stringValue($row->name ?? null);

            if ($label === null) {
                continue;
            }

            $notFound = $this->numeric($row->page_not_found_404_requests ?? null);
            $serverErrors = $this->numeric($row->server_error_5xx_requests ?? null);
            $this->mergeRankedRow(
                $table,
                $label,
                [
                    'total_broken_requests' => $notFound + $serverErrors,
                    'page_not_found_404_requests' => $notFound,
                    'server_error_5xx_requests' => $serverErrors,
                ],
                $position++,
            );
        }
    }

    /** @param array<string, int|float> $metrics */
    private function mergeRankedRow(
        HierarchicalArchiveTable $table,
        string $label,
        array $metrics,
        int $position,
    ): void {
        $table->mergeRoot(
            $this->configuration->contentRankingLimit === 0
                || $position < $this->configuration->contentRankingLimit
                    ? $label
                    : self::RANKING_SUMMARY,
            $metrics,
        );
    }

    private function baseQuery(
        ArchiveReportsCollecting $event,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): Builder {
        return $this->connection
            ->table('log_bot_request as bot')
            ->join('log_action', 'log_action.idaction', '=', 'bot.idaction_url')
            ->where('bot.idsite', $event->request->siteId)
            ->where('bot.server_time', '>=', $start->toDateTimeString())
            ->where('bot.server_time', '<', $end->toDateTimeString())
            ->where('bot.bot_type', 'ai_chatbot')
            ->whereNotNull('log_action.name')
            ->where('log_action.name', '<>', '');
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function dateRange(
        ArchiveReportsCollecting $event,
        string $timezone,
    ): array {
        return [
            CarbonImmutable::parse($event->period->startDate, $timezone)->startOfDay()->utc(),
            CarbonImmutable::parse($event->period->endDate, $timezone)->addDay()->startOfDay()->utc(),
        ];
    }

    /** @return ContentReports */
    private function emptyReports(): array
    {
        return [
            self::PAGES_RECORD => new HierarchicalArchiveTable,
            self::DOCUMENTS_RECORD => new HierarchicalArchiveTable,
            self::BROKEN_RECORD => new HierarchicalArchiveTable,
        ];
    }

    private function requested(ArchiveReportsCollecting $event): bool
    {
        if ($event->request->plugin !== null) {
            return $event->request->plugin === 'BotTracking';
        }

        return $event->request->reports === []
            || array_intersect($event->request->reports, self::REPORTS) !== [];
    }

    private function segmented(ArchiveReportsCollecting $event): bool
    {
        return $event->request->segment !== null && $event->request->segment !== '';
    }

    private function columnsExist(): bool
    {
        return $this->connection->getSchemaBuilder()->hasColumns('log_bot_request', [
            'idsite',
            'server_time',
            'idaction_url',
            'bot_type',
            'http_status_code',
            'response_size_bytes',
            'response_time_ms',
        ]) && $this->connection->getSchemaBuilder()->hasColumns('log_action', [
            'idaction',
            'name',
            'type',
        ]);
    }

    private function archiveLabel(mixed $value): ?string
    {
        if (! is_float($value) && ! is_int($value) && ! is_string($value)) {
            return null;
        }

        return (string) $value;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function numeric(mixed $value): int|float
    {
        if (! is_numeric($value)) {
            return 0;
        }

        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }

    private function effectiveLimit(int $configured): int
    {
        return $configured === 0 ? PHP_INT_MAX : $configured;
    }
}
