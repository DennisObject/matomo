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
 * @phpstan-type TrafficRows array<string, array{human: int, ai: int}>
 * @phpstan-type RankedTraffic array{rows: array<string, int>, summary: int}
 */
final readonly class BotTrackingFavouredPagesArchiveCollector
{
    private const int PAGE_URL_ACTION = 1;

    private const string HUMAN_RECORD = 'BotTracking_AIChatbotsHumanFavouredPages';

    private const string AI_RECORD = 'BotTracking_AIChatbotsAIFavouredPages';

    private const string RANKING_SUMMARY = '-1';

    /** @var list<string> */
    private const array REPORTS = [
        'BotTracking.getAIChatbotHumanFavouredPages',
        'BotTracking.getAIChatbotAIFavouredPages',
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
            $event->records->addBlob(
                $recordName,
                $table->serialized(
                    $this->effectiveLimit($this->configuration->favouredPagesLimit),
                    PHP_INT_MAX,
                    'discrepancy_score',
                )[''],
            );
        }
    }

    /** @return array<string, HierarchicalArchiveTable> */
    private function dayReports(
        ArchiveReportsCollecting $event,
        string $timezone,
    ): array {
        if (! $this->columnsExist()) {
            return $this->emptyReports();
        }

        [$start, $end] = $this->dateRange($event, $timezone);
        $ai = $this->aiTraffic($event, $start, $end);

        if ($ai['rows'] === []) {
            return $this->emptyReports();
        }

        $human = $this->humanTraffic($event, $start, $end);
        $rows = [];

        foreach ($ai['rows'] as $label => $requests) {
            $rows[$label] = ['human' => 0, 'ai' => $requests];
        }

        foreach ($human['rows'] as $label => $pageviews) {
            $rows[$label] ??= ['human' => 0, 'ai' => 0];
            $rows[$label]['human'] = $pageviews;
        }

        return $this->scoredReports(
            $rows,
            $human['summary'],
            $ai['summary'],
        );
    }

    /** @return array<string, HierarchicalArchiveTable> */
    private function parentReports(ArchiveReportsCollecting $event): array
    {
        $reports = [];
        $children = $this->subperiods->children($event->period);
        $segmentHash = $this->segments->resolve($event->request->segment);

        foreach ([self::HUMAN_RECORD, self::AI_RECORD] as $recordName) {
            $rows = [];
            $humanSummary = 0;
            $aiSummary = 0;
            $archives = $this->blobs->rows(
                [$event->request->siteId],
                $children,
                $segmentHash,
                $recordName,
            )[$event->request->siteId] ?? [];

            foreach ($children as $child) {
                foreach ($archives[$child->rangeKey()] ?? [] as $row) {
                    $label = $this->archiveLabel($row['columns']['label'] ?? null);

                    if ($label === null) {
                        continue;
                    }

                    $human = $this->integer($row['columns']['unique_human_pageviews'] ?? null);
                    $ai = $this->integer($row['columns']['ai_chatbot_requests'] ?? null);

                    if ($label === self::RANKING_SUMMARY) {
                        $humanSummary += $human;
                        $aiSummary += $ai;

                        continue;
                    }

                    $rows[$label] ??= ['human' => 0, 'ai' => 0];
                    $rows[$label]['human'] += $human;
                    $rows[$label]['ai'] += $ai;
                }
            }

            $reports[$recordName] = $this->scoredTable(
                $rows,
                $humanSummary,
                $aiSummary,
                $recordName === self::HUMAN_RECORD,
            );
        }

        return $reports;
    }

    /** @return RankedTraffic */
    private function aiTraffic(
        ArchiveReportsCollecting $event,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        $query = $this->connection
            ->table('log_bot_request as bot')
            ->join('log_action', 'log_action.idaction', '=', 'bot.idaction_url')
            ->where('bot.idsite', $event->request->siteId)
            ->where('bot.server_time', '>=', $start->toDateTimeString())
            ->where('bot.server_time', '<', $end->toDateTimeString())
            ->where('bot.bot_type', 'ai_chatbot')
            ->where('log_action.type', self::PAGE_URL_ACTION)
            ->whereNotNull('log_action.name')
            ->where('log_action.name', '<>', '')
            ->select('log_action.name')
            ->selectRaw('COUNT(*) AS traffic')
            ->groupBy('log_action.name')
            ->orderByDesc('traffic')
            ->orderBy('log_action.name');

        return $this->rankedTraffic($query);
    }

    /** @return RankedTraffic */
    private function humanTraffic(
        ArchiveReportsCollecting $event,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        $query = $this->connection
            ->table('log_link_visit_action')
            ->join(
                'log_action',
                'log_action.idaction',
                '=',
                'log_link_visit_action.idaction_url',
            )
            ->where('log_link_visit_action.idsite', $event->request->siteId)
            ->where('log_link_visit_action.server_time', '>=', $start->toDateTimeString())
            ->where('log_link_visit_action.server_time', '<', $end->toDateTimeString())
            ->whereNull('log_link_visit_action.idaction_event_category')
            ->where('log_action.type', self::PAGE_URL_ACTION)
            ->whereNotNull('log_action.name')
            ->where('log_action.name', '<>', '')
            ->select('log_action.name')
            ->selectRaw('COUNT(DISTINCT log_link_visit_action.idvisit) AS traffic')
            ->groupBy('log_action.name')
            ->orderByDesc('traffic')
            ->orderBy('log_action.name');

        return $this->rankedTraffic($query);
    }

    /** @return RankedTraffic */
    private function rankedTraffic(Builder $query): array
    {
        $rows = [];
        $summary = 0;
        $position = 0;

        foreach ($query->cursor() as $row) {
            $label = $this->stringValue($row->name ?? null);

            if ($label === null) {
                continue;
            }

            $traffic = $this->integer($row->traffic ?? null);

            if ($this->configuration->favouredPagesRankingLimit === 0
                || $position++ < $this->configuration->favouredPagesRankingLimit) {
                $rows[$label] = $traffic;
            } else {
                $summary += $traffic;
            }
        }

        return ['rows' => $rows, 'summary' => $summary];
    }

    /**
     * @param  TrafficRows  $rows
     * @return array<string, HierarchicalArchiveTable>
     */
    private function scoredReports(
        array $rows,
        int $humanSummary,
        int $aiSummary,
    ): array {
        return [
            self::HUMAN_RECORD => $this->scoredTable(
                $rows,
                $humanSummary,
                $aiSummary,
                true,
            ),
            self::AI_RECORD => $this->scoredTable(
                $rows,
                $humanSummary,
                $aiSummary,
                false,
            ),
        ];
    }

    /** @param TrafficRows $rows */
    private function scoredTable(
        array $rows,
        int $humanSummary,
        int $aiSummary,
        bool $humanFavoured,
    ): HierarchicalArchiveTable {
        $table = new HierarchicalArchiveTable(['discrepancy_score' => 'skip']);
        $maxStrong = 0;

        foreach ($rows as $traffic) {
            $maxStrong = max($maxStrong, $humanFavoured ? $traffic['human'] : $traffic['ai']);
        }

        foreach ($rows as $label => $traffic) {
            $strong = $humanFavoured ? $traffic['human'] : $traffic['ai'];
            $weak = $humanFavoured ? $traffic['ai'] : $traffic['human'];
            $table->mergeRoot($label, [
                'unique_human_pageviews' => $traffic['human'],
                'ai_chatbot_requests' => $traffic['ai'],
                'discrepancy_score' => $this->score($strong, $weak, $maxStrong),
            ]);
        }

        if ($humanSummary > 0 || $aiSummary > 0) {
            $table->mergeRoot(self::RANKING_SUMMARY, [
                'unique_human_pageviews' => $humanSummary,
                'ai_chatbot_requests' => $aiSummary,
            ]);
        }

        return $table;
    }

    private function score(int $strong, int $weak, int $maxStrong): float
    {
        $total = $strong + $weak;

        if ($total <= 0) {
            return 0.0;
        }

        $lean = max(0, ($strong - $weak) / $total);
        $anchor = log10($maxStrong + 1);
        $volume = $anchor > 0 ? log10($strong + 1) / $anchor : 0.0;

        return round(100 * $lean * $volume, 1);
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

    /** @return array<string, HierarchicalArchiveTable> */
    private function emptyReports(): array
    {
        return [
            self::HUMAN_RECORD => new HierarchicalArchiveTable,
            self::AI_RECORD => new HierarchicalArchiveTable,
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
        ]) && $this->connection->getSchemaBuilder()->hasColumns('log_action', [
            'idaction',
            'name',
            'type',
        ]) && $this->connection->getSchemaBuilder()->hasColumns('log_link_visit_action', [
            'idsite',
            'idvisit',
            'idaction_url',
            'idaction_event_category',
            'server_time',
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

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function effectiveLimit(int $configured): int
    {
        return $configured === 0 ? PHP_INT_MAX : $configured;
    }
}
