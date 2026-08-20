<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Reporting\HierarchicalBlobArchiveRepository;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * @phpstan-type OverviewReports array<string, HierarchicalArchiveTable>
 */
final readonly class BotTrackingOverviewArchiveCollector
{
    private const int AI_ASSISTANT_REFERRER = 8;

    private const int PAGE_URL_ACTION = 1;

    private const int DOWNLOAD_ACTION = 3;

    private const string PAGES_RECORD = 'BotTracking_AIChatbotsPages';

    private const string DOCUMENTS_RECORD = 'BotTracking_AIChatbotsDocuments';

    private const string REQUESTS = 'BotTracking_AIChatbotsRequests';

    private const string ACQUIRED_VISITS = 'BotTracking_AIChatbotsAcquiredVisits';

    private const string UNIQUE_PAGE_URLS = 'BotTracking_AIChatbotsUniquePageUrls';

    private const string UNIQUE_DOCUMENT_URLS = 'BotTracking_AIChatbotsUniqueDocumentUrls';

    private const string UNIQUE_CHATBOTS = 'BotTracking_AIChatbotsUniqueChatbots';

    private const string NOT_FOUND_REQUESTS = 'BotTracking_AIChatbotsNotFoundRequests';

    private const string SERVER_ERROR_REQUESTS = 'BotTracking_AIChatbotsServerErrorRequests';

    private const string RANKING_SUMMARY = '-1';

    /** @var array<string, string> */
    private const array CHATBOT_LABELS = [
        'ChatGPT-User' => 'ChatGPT',
        'MistralAI-User' => 'Le Chat',
        'Gemini-Deep-Research' => 'Gemini',
        'Claude-User' => 'Claude',
        'Perplexity-User' => 'Perplexity',
        'Google-NotebookLM' => 'NotebookLM',
    ];

    /** @var list<string> */
    private const array NUMERIC_RECORDS = [
        self::REQUESTS,
        self::ACQUIRED_VISITS,
        self::UNIQUE_CHATBOTS,
        self::NOT_FOUND_REQUESTS,
        self::SERVER_ERROR_REQUESTS,
    ];

    /** @var list<string> */
    private const array REPORTS = [
        'BotTracking.get',
        'BotTracking.getAIChatbotRequests',
        'BotTracking.getPageUrlsForAIChatbot',
        'BotTracking.getDocumentUrlsForAIChatbot',
    ];

    public function __construct(
        private Connection $connection,
        private ReportingSubperiodFactory $subperiods,
        private SegmentHashResolver $segments,
        private HierarchicalBlobArchiveRepository $blobs,
        private NumericArchiveRepository $numbers,
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

        [$metrics, $reports] = $event->period->label === 'day'
            ? $this->dayRecords($event, $timezone)
            : $this->parentRecords($event);

        foreach ($metrics as $name => $value) {
            $event->records->addNumeric($name, $value);
        }

        foreach ($reports as $recordName => $table) {
            foreach ($table->serialized(
                $this->effectiveLimit($this->configuration->rootLimit),
                $this->effectiveLimit($this->configuration->subtableLimit),
                'requests',
            ) as $suffix => $blob) {
                $event->records->addBlob($recordName.$suffix, $blob);
            }
        }
    }

    /** @return array{array<string, int|float>, OverviewReports} */
    private function dayRecords(
        ArchiveReportsCollecting $event,
        string $timezone,
    ): array {
        $reports = $this->emptyReports(true);
        $metrics = $this->emptyDayMetrics();

        if (! $this->columnsExist()) {
            return [$metrics, $reports];
        }

        [$start, $end] = $this->dateRange($event, $timezone);
        $visits = $this->acquiredVisitsByChatbot($event, $start, $end);
        $this->addActionRows($reports, $event, $start, $end, $visits);
        $metrics = $this->dayMetrics($event, $start, $end);

        return [$metrics, $reports];
    }

    /** @return array{array<string, int|float>, OverviewReports} */
    private function parentRecords(ArchiveReportsCollecting $event): array
    {
        $reports = $this->emptyReports(false);
        $children = $this->subperiods->children($event->period);
        $segmentHash = $this->segments->resolve($event->request->segment);
        $metrics = array_fill_keys(self::NUMERIC_RECORDS, 0);
        $archives = $this->numbers->pluginMetrics(
            [$event->request->siteId],
            $children,
            $segmentHash,
            self::NUMERIC_RECORDS,
            'BotTracking',
        )[$event->request->siteId] ?? [];

        foreach ($children as $child) {
            foreach ($metrics as $name => $value) {
                if ($name === self::UNIQUE_CHATBOTS) {
                    continue;
                }

                $metrics[$name] = $value + ($archives[$child->rangeKey()][$name] ?? 0);
            }
        }

        $chatbots = [];

        foreach ([self::PAGES_RECORD, self::DOCUMENTS_RECORD] as $recordName) {
            $records = $this->blobs->records(
                [$event->request->siteId],
                $children,
                $segmentHash,
                $recordName,
                true,
            )[$event->request->siteId] ?? [];

            foreach ($children as $child) {
                foreach ($records[$child->rangeKey()][$recordName] ?? [] as $root) {
                    $label = $this->archiveLabel($root['columns']['label'] ?? null);

                    if ($label === null) {
                        continue;
                    }

                    $reports[$recordName]->mergeRoot($label, $root['columns']);

                    if ($label !== self::RANKING_SUMMARY) {
                        $chatbots[$label] = true;
                    }

                    $subtableId = $root['subtableId'];

                    if ($subtableId === null) {
                        continue;
                    }

                    foreach ($records[$child->rangeKey()][$recordName.'_'.$subtableId] ?? [] as $row) {
                        $childLabel = $this->archiveLabel($row['columns']['label'] ?? null);

                        if ($childLabel !== null) {
                            $reports[$recordName]->mergeChild(
                                $label,
                                $childLabel,
                                $row['columns'],
                            );
                        }
                    }
                }
            }
        }

        $metrics[self::UNIQUE_CHATBOTS] = count($chatbots);

        return [$metrics, $reports];
    }

    /**
     * @param  OverviewReports  $reports
     * @param  array<string, int>  $visits
     */
    private function addActionRows(
        array $reports,
        ArchiveReportsCollecting $event,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $visits,
    ): void {
        $rootCounts = [];
        $roots = $this->botRequestQuery($event, $start, $end)
            ->join('log_action', 'log_action.idaction', '=', 'bot.idaction_url')
            ->whereIn('log_action.type', [self::PAGE_URL_ACTION, self::DOWNLOAD_ACTION])
            ->whereNotNull('log_action.name')
            ->where('log_action.name', '<>', '')
            ->select([
                'bot.bot_name',
                'log_action.type',
            ])
            ->selectRaw('COUNT(*) AS requests')
            ->groupBy(['bot.bot_name', 'log_action.type'])
            ->cursor();

        foreach ($roots as $row) {
            $botName = $this->stringValue($row->bot_name ?? null);
            $type = $this->integerValue($row->type ?? null);
            $requests = $this->integerValue($row->requests ?? null);

            if ($botName === null || $type === null || $requests === null) {
                continue;
            }

            $rootCounts[$type][$botName] = $requests;
            $metrics = [
                'requests' => $requests,
                'document_requests' => $type === self::DOWNLOAD_ACTION ? $requests : 0,
                'page_requests' => $type === self::PAGE_URL_ACTION ? $requests : 0,
                'visits_acquired' => $visits[$botName] ?? 0,
            ];

            foreach ($reports as $table) {
                $table->mergeRoot($botName, $metrics);
            }
        }

        foreach ([
            self::PAGE_URL_ACTION => self::PAGES_RECORD,
            self::DOWNLOAD_ACTION => self::DOCUMENTS_RECORD,
        ] as $type => $recordName) {
            $kept = [];
            $remainder = [];
            $position = 0;
            $rows = $this->botRequestQuery($event, $start, $end)
                ->join('log_action', 'log_action.idaction', '=', 'bot.idaction_url')
                ->where('log_action.type', $type)
                ->whereNotNull('log_action.name')
                ->where('log_action.name', '<>', '')
                ->select(['bot.bot_name', 'log_action.name'])
                ->selectRaw('COUNT(*) AS requests')
                ->groupBy(['bot.bot_name', 'log_action.name'])
                ->orderByDesc('requests')
                ->orderBy('bot.bot_name')
                ->orderBy('log_action.name')
                ->cursor();

            foreach ($rows as $row) {
                $botName = $this->stringValue($row->bot_name ?? null);
                $url = $this->stringValue($row->name ?? null);
                $requests = $this->integerValue($row->requests ?? null);

                if ($botName === null || $url === null || $requests === null) {
                    continue;
                }

                if ($this->configuration->rankingLimit === 0
                    || $position++ < $this->configuration->rankingLimit) {
                    $reports[$recordName]->mergeChild(
                        $botName,
                        $url,
                        ['requests' => $requests],
                    );
                    $kept[$botName] = true;

                    continue;
                }

                $remainder[$botName] = ($remainder[$botName] ?? 0) + $requests;
            }

            foreach ($remainder as $botName => $requests) {
                if (isset($kept[$botName]) && ($rootCounts[$type][$botName] ?? 0) > $requests) {
                    $reports[$recordName]->mergeChild(
                        $botName,
                        self::RANKING_SUMMARY,
                        ['requests' => $requests],
                    );
                }
            }
        }
    }

    /** @return array<string, int> */
    private function acquiredVisitsByChatbot(
        ArchiveReportsCollecting $event,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        $labels = array_flip(self::CHATBOT_LABELS);
        $visits = [];
        $rows = $this->connection
            ->table('log_visit')
            ->where('idsite', $event->request->siteId)
            ->where('visit_last_action_time', '>=', $start->toDateTimeString())
            ->where('visit_last_action_time', '<', $end->toDateTimeString())
            ->where('referer_type', self::AI_ASSISTANT_REFERRER)
            ->where('referer_name', '<>', '')
            ->select('referer_name')
            ->selectRaw('COUNT(*) AS visits')
            ->groupBy('referer_name')
            ->cursor();

        foreach ($rows as $row) {
            $label = $this->stringValue($row->referer_name ?? null);
            $count = $this->integerValue($row->visits ?? null);
            $botName = $label === null ? null : ($labels[$label] ?? null);

            if (is_string($botName) && $count !== null) {
                $visits[$botName] = $count;
            }
        }

        return $visits;
    }

    /** @return array<string, int|float> */
    private function dayMetrics(
        ArchiveReportsCollecting $event,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        $query = $this->botRequestQuery($event, $start, $end)
            ->leftJoin('log_action', 'log_action.idaction', '=', 'bot.idaction_url')
            ->selectRaw(implode(', ', [
                'COUNT(*) AS requests',
                'COALESCE(SUM(CASE WHEN bot.http_status_code IN (404, 410) THEN 1 ELSE 0 END), 0) AS not_found_requests',
                'COALESCE(SUM(CASE WHEN bot.http_status_code BETWEEN 500 AND 599 THEN 1 ELSE 0 END), 0) AS server_error_requests',
                'COUNT(DISTINCT bot.bot_name) AS unique_chatbots',
                'COUNT(DISTINCT CASE WHEN log_action.type = ? THEN log_action.name END) AS unique_pages',
                'COUNT(DISTINCT CASE WHEN log_action.type = ? THEN log_action.name END) AS unique_documents',
            ]), [self::PAGE_URL_ACTION, self::DOWNLOAD_ACTION]);
        $row = $query->first();
        $visits = $this->connection
            ->table('log_visit')
            ->where('idsite', $event->request->siteId)
            ->where('visit_last_action_time', '>=', $start->toDateTimeString())
            ->where('visit_last_action_time', '<', $end->toDateTimeString())
            ->where('referer_type', self::AI_ASSISTANT_REFERRER)
            ->count();

        return [
            self::REQUESTS => $this->numeric($row->requests ?? null),
            self::ACQUIRED_VISITS => $visits,
            self::UNIQUE_PAGE_URLS => $this->numeric($row->unique_pages ?? null),
            self::UNIQUE_DOCUMENT_URLS => $this->numeric($row->unique_documents ?? null),
            self::UNIQUE_CHATBOTS => $this->numeric($row->unique_chatbots ?? null),
            self::NOT_FOUND_REQUESTS => $this->numeric($row->not_found_requests ?? null),
            self::SERVER_ERROR_REQUESTS => $this->numeric(
                $row->server_error_requests ?? null,
            ),
        ];
    }

    private function botRequestQuery(
        ArchiveReportsCollecting $event,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): Builder {
        return $this->connection
            ->table('log_bot_request as bot')
            ->where('bot.idsite', $event->request->siteId)
            ->where('bot.server_time', '>=', $start->toDateTimeString())
            ->where('bot.server_time', '<', $end->toDateTimeString())
            ->where('bot.bot_type', 'ai_chatbot');
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

    /** @return OverviewReports */
    private function emptyReports(bool $day): array
    {
        $operations = $day ? ['visits_acquired' => 'max'] : [];

        return [
            self::PAGES_RECORD => new HierarchicalArchiveTable($operations),
            self::DOCUMENTS_RECORD => new HierarchicalArchiveTable($operations),
        ];
    }

    /** @return array<string, int> */
    private function emptyDayMetrics(): array
    {
        return [
            self::REQUESTS => 0,
            self::ACQUIRED_VISITS => 0,
            self::UNIQUE_PAGE_URLS => 0,
            self::UNIQUE_DOCUMENT_URLS => 0,
            self::UNIQUE_CHATBOTS => 0,
            self::NOT_FOUND_REQUESTS => 0,
            self::SERVER_ERROR_REQUESTS => 0,
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
            'bot_name',
            'bot_type',
            'http_status_code',
        ]) && $this->connection->getSchemaBuilder()->hasColumns('log_action', [
            'idaction',
            'name',
            'type',
        ]) && $this->connection->getSchemaBuilder()->hasColumns('log_visit', [
            'idsite',
            'visit_last_action_time',
            'referer_type',
            'referer_name',
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

    private function integerValue(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
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
