<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Archiving\ArchiveRecordSet;
use App\Matomo\Archiving\ArchiveReportRequest;
use App\Matomo\Archiving\BotTrackingArchiveConfiguration;
use App\Matomo\Archiving\BotTrackingOverviewArchiveCollector;
use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Archiving\ReportingSubperiodFactory;
use App\Matomo\Reporting\HierarchicalBlobArchiveRepository;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class BotTrackingOverviewArchiveCollectorTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->app->make('db')->connection();
        $this->connection->getSchemaBuilder()->create('log_action', function (Blueprint $table): void {
            $table->unsignedBigInteger('idaction')->primary();
            $table->string('name')->nullable();
            $table->integer('type');
        });
        $this->connection->getSchemaBuilder()->create('log_bot_request', function (Blueprint $table): void {
            $table->unsignedBigInteger('idrequest')->primary();
            $table->unsignedInteger('idsite');
            $table->dateTime('server_time');
            $table->unsignedBigInteger('idaction_url')->nullable();
            $table->string('bot_name');
            $table->string('bot_type');
            $table->unsignedSmallInteger('http_status_code')->nullable();
        });
        $this->connection->getSchemaBuilder()->create('log_visit', function (Blueprint $table): void {
            $table->unsignedBigInteger('idvisit')->primary();
            $table->unsignedInteger('idsite');
            $table->dateTime('visit_last_action_time');
            $table->unsignedTinyInteger('referer_type');
            $table->string('referer_name');
        });
    }

    public function test_archives_day_overview_metrics_and_chatbot_url_trees(): void
    {
        $this->connection->table('log_action')->insert([
            ['idaction' => 1, 'name' => 'example.test/article', 'type' => 1],
            ['idaction' => 2, 'name' => 'example.test/other', 'type' => 1],
            ['idaction' => 3, 'name' => 'example.test/file.pdf', 'type' => 3],
        ]);
        $this->connection->table('log_bot_request')->insert([
            $this->botRequest(1, 7, '2026-08-15 01:00:00', 1, 'ChatGPT-User', 200),
            $this->botRequest(2, 7, '2026-08-15 02:00:00', 1, 'ChatGPT-User', 200),
            $this->botRequest(3, 7, '2026-08-15 03:00:00', 2, 'ChatGPT-User', 200),
            $this->botRequest(4, 7, '2026-08-15 04:00:00', 3, 'ChatGPT-User', 500),
            $this->botRequest(5, 7, '2026-08-15 05:00:00', 1, 'Claude-User', 404),
            $this->botRequest(6, 7, '2026-08-15 06:00:00', 1, 'Crawler', 200, 'crawler'),
            $this->botRequest(7, 8, '2026-08-15 07:00:00', 1, 'Perplexity-User', 200),
            $this->botRequest(8, 7, '2026-08-14 23:59:59', 1, 'Old', 200),
        ]);
        $this->connection->table('log_visit')->insert([
            $this->visit(1, 7, '2026-08-15 01:30:00', 8, 'ChatGPT'),
            $this->visit(2, 7, '2026-08-15 02:30:00', 8, 'ChatGPT'),
            $this->visit(3, 7, '2026-08-15 03:30:00', 8, 'Claude'),
            $this->visit(4, 7, '2026-08-15 04:30:00', 1, 'ChatGPT'),
            $this->visit(5, 8, '2026-08-15 05:30:00', 8, 'Perplexity'),
        ]);

        $records = $this->collect(
            period: new ReportingPeriod('day', 1, '2026-08-15', '2026-08-15', '2026-08-15'),
            configuration: new BotTrackingArchiveConfiguration(10, 10, 2),
        );

        $this->assertSame([
            'BotTracking_AIChatbotsRequests' => 5,
            'BotTracking_AIChatbotsAcquiredVisits' => 3,
            'BotTracking_AIChatbotsUniquePageUrls' => 2,
            'BotTracking_AIChatbotsUniqueDocumentUrls' => 1,
            'BotTracking_AIChatbotsUniqueChatbots' => 2,
            'BotTracking_AIChatbotsNotFoundRequests' => 1,
            'BotTracking_AIChatbotsServerErrorRequests' => 1,
        ], $records->numeric());

        $pages = $this->decodedRows($records->blobs()['BotTracking_AIChatbotsPages']);
        $pageRoots = $this->rowsByLabel($pages);
        $this->assertSame([
            'label' => 'ChatGPT-User',
            'requests' => 4,
            'document_requests' => 1,
            'page_requests' => 3,
            'visits_acquired' => 2,
        ], $pageRoots['ChatGPT-User'][0]);
        $this->assertSame(1, $pageRoots['ChatGPT-User'][3]);
        $this->assertSame([
            'example.test/article' => 2,
            'example.test/other' => 1,
        ], $this->requestCounts(
            $this->decodedRows($records->blobs()['BotTracking_AIChatbotsPages_1']),
        ));
        $this->assertSame(1, $pageRoots['Claude-User'][0]['requests']);
        $this->assertNull($pageRoots['Claude-User'][3]);

        $documents = $this->rowsByLabel(
            $this->decodedRows($records->blobs()['BotTracking_AIChatbotsDocuments']),
        );
        $this->assertSame(1, $documents['ChatGPT-User'][3]);
        $this->assertSame(
            ['example.test/file.pdf' => 1],
            $this->requestCounts(
                $this->decodedRows($records->blobs()['BotTracking_AIChatbotsDocuments_1']),
            ),
        );
    }

    public function test_aggregates_parent_metrics_and_hierarchical_rows(): void
    {
        $dayOne = new ReportingPeriod('day', 1, '2026-08-14', '2026-08-14', '2026-08-14');
        $dayTwo = new ReportingPeriod('day', 1, '2026-08-15', '2026-08-15', '2026-08-15');
        $subperiods = $this->createMock(ReportingSubperiodFactory::class);
        $subperiods->expects($this->once())->method('children')->willReturn([$dayOne, $dayTwo]);
        $numbers = $this->createMock(NumericArchiveRepository::class);
        $numbers->expects($this->once())->method('pluginMetrics')->willReturn([7 => [
            $dayOne->rangeKey() => [
                'BotTracking_AIChatbotsRequests' => 3,
                'BotTracking_AIChatbotsAcquiredVisits' => 1,
                'BotTracking_AIChatbotsUniqueChatbots' => 1,
                'BotTracking_AIChatbotsNotFoundRequests' => 1,
                'BotTracking_AIChatbotsServerErrorRequests' => 0,
            ],
            $dayTwo->rangeKey() => [
                'BotTracking_AIChatbotsRequests' => 4,
                'BotTracking_AIChatbotsAcquiredVisits' => 2,
                'BotTracking_AIChatbotsUniqueChatbots' => 2,
                'BotTracking_AIChatbotsNotFoundRequests' => 0,
                'BotTracking_AIChatbotsServerErrorRequests' => 2,
            ],
        ]]);
        $blobs = $this->createMock(HierarchicalBlobArchiveRepository::class);
        $blobs->expects($this->exactly(2))->method('records')->willReturnCallback(
            static function (
                array $siteIds,
                array $periods,
                string $segmentHash,
                string $recordName,
                bool $includeSubtables,
            ) use ($dayOne, $dayTwo): array {
                self::assertSame([7], $siteIds);
                self::assertSame('', $segmentHash);
                self::assertTrue($includeSubtables);
                $suffix = $recordName === 'BotTracking_AIChatbotsPages' ? 'page' : 'doc';

                return [7 => [
                    $dayOne->rangeKey() => [
                        $recordName => [[
                            'columns' => [
                                'label' => 'ChatGPT-User',
                                'requests' => 3,
                                'visits_acquired' => 1,
                            ],
                            'metadata' => [],
                            'subtableId' => 1,
                        ]],
                        $recordName.'_1' => [[
                            'columns' => ['label' => $suffix.'-one', 'requests' => 3],
                            'metadata' => [],
                            'subtableId' => null,
                        ]],
                    ],
                    $dayTwo->rangeKey() => [
                        $recordName => [
                            [
                                'columns' => [
                                    'label' => 'ChatGPT-User',
                                    'requests' => 2,
                                    'visits_acquired' => 1,
                                ],
                                'metadata' => [],
                                'subtableId' => 1,
                            ],
                            [
                                'columns' => [
                                    'label' => 'Claude-User',
                                    'requests' => 2,
                                    'visits_acquired' => 1,
                                ],
                                'metadata' => [],
                                'subtableId' => null,
                            ],
                        ],
                        $recordName.'_1' => [[
                            'columns' => ['label' => $suffix.'-two', 'requests' => 2],
                            'metadata' => [],
                            'subtableId' => null,
                        ]],
                    ],
                ]];
            },
        );

        $records = $this->collect(
            period: new ReportingPeriod('week', 2, '2026-08-10', '2026-08-16', '2026-08-10'),
            subperiods: $subperiods,
            numbers: $numbers,
            blobs: $blobs,
        );

        $this->assertSame([
            'BotTracking_AIChatbotsRequests' => 7,
            'BotTracking_AIChatbotsAcquiredVisits' => 3,
            'BotTracking_AIChatbotsUniqueChatbots' => 2,
            'BotTracking_AIChatbotsNotFoundRequests' => 1,
            'BotTracking_AIChatbotsServerErrorRequests' => 2,
        ], $records->numeric());
        $roots = $this->rowsByLabel(
            $this->decodedRows($records->blobs()['BotTracking_AIChatbotsPages']),
        );
        $this->assertSame(5, $roots['ChatGPT-User'][0]['requests']);
        $this->assertSame(2, $roots['ChatGPT-User'][0]['visits_acquired']);
        $this->assertSame([
            'page-one' => 3,
            'page-two' => 2,
        ], $this->requestCounts(
            $this->decodedRows($records->blobs()['BotTracking_AIChatbotsPages_1']),
        ));
    }

    public function test_does_not_archive_segmented_requests(): void
    {
        $records = $this->collect(
            period: new ReportingPeriod('day', 1, '2026-08-15', '2026-08-15', '2026-08-15'),
            segment: 'browserCode==FF',
        );

        $this->assertTrue($records->isEmpty());
    }

    public function test_reads_legacy_archive_limits(): void
    {
        $configuration = BotTrackingArchiveConfiguration::fromFiles(
            base_path('../config/global.ini.php'),
            base_path('missing-installation-config.ini.php'),
        );

        $this->assertSame(250, $configuration->rootLimit);
        $this->assertSame(250, $configuration->subtableLimit);
        $this->assertSame(50_000, $configuration->rankingLimit);
        $this->assertSame(50_000, $configuration->contentLimit);
        $this->assertSame(50_000, $configuration->contentRankingLimit);
    }

    private function collect(
        ReportingPeriod $period,
        ?BotTrackingArchiveConfiguration $configuration = null,
        ?ReportingSubperiodFactory $subperiods = null,
        ?NumericArchiveRepository $numbers = null,
        ?HierarchicalBlobArchiveRepository $blobs = null,
        ?string $segment = null,
    ): ArchiveRecordSet {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $segments = $this->createStub(SegmentHashResolver::class);
        $segments->method('resolve')->willReturn('');
        $collector = new BotTrackingOverviewArchiveCollector(
            connection: $this->connection,
            subperiods: $subperiods ?? $this->createStub(ReportingSubperiodFactory::class),
            segments: $segments,
            blobs: $blobs ?? $this->createStub(HierarchicalBlobArchiveRepository::class),
            numbers: $numbers ?? $this->createStub(NumericArchiveRepository::class),
            sites: $sites,
            configuration: $configuration ?? new BotTrackingArchiveConfiguration(10, 10, 100),
        );
        $records = new ArchiveRecordSet;
        $collector(new ArchiveReportsCollecting(
            request: new ArchiveReportRequest(
                siteId: 7,
                period: $period->label,
                date: $period->resultKey,
                segment: $segment,
                plugin: 'BotTracking',
            ),
            period: $period,
            coreMetrics: [],
            records: $records,
        ));

        return $records;
    }

    /**
     * @return array{idrequest: int, idsite: int, server_time: string, idaction_url: int,
     *     bot_name: string, bot_type: string, http_status_code: int}
     */
    private function botRequest(
        int $id,
        int $siteId,
        string $time,
        int $actionId,
        string $botName,
        int $status,
        string $botType = 'ai_chatbot',
    ): array {
        return [
            'idrequest' => $id,
            'idsite' => $siteId,
            'server_time' => $time,
            'idaction_url' => $actionId,
            'bot_name' => $botName,
            'bot_type' => $botType,
            'http_status_code' => $status,
        ];
    }

    /** @return array{idvisit: int, idsite: int, visit_last_action_time: string, referer_type: int, referer_name: string} */
    private function visit(
        int $id,
        int $siteId,
        string $time,
        int $referrerType,
        string $referrerName,
    ): array {
        return [
            'idvisit' => $id,
            'idsite' => $siteId,
            'visit_last_action_time' => $time,
            'referer_type' => $referrerType,
            'referer_name' => $referrerName,
        ];
    }

    /** @return list<array{0: array<string, mixed>, 1: array<mixed>, 3: int|null}> */
    private function decodedRows(string $blob): array
    {
        $rows = unserialize($blob, ['allowed_classes' => false]);

        if (! is_array($rows) || ! array_is_list($rows)) {
            self::fail('The archive blob must contain a row list.');
        }

        $decoded = [];

        foreach ($rows as $row) {
            if (! is_array($row)
                || ! is_array($row[0] ?? null)
                || ! is_array($row[1] ?? null)) {
                self::fail('The archive blob contains an invalid row.');
            }

            $subtableId = $row[3] ?? null;

            if ($subtableId !== null && ! is_int($subtableId)) {
                self::fail('The archive row contains an invalid subtable ID.');
            }

            $columns = [];

            foreach ($row[0] as $name => $value) {
                if (! is_string($name)) {
                    self::fail('The archive row contains an invalid column name.');
                }

                $columns[$name] = $value;
            }

            $decoded[] = [0 => $columns, 1 => $row[1], 3 => $subtableId];
        }

        return $decoded;
    }

    /**
     * @param  list<array{0: array<string, mixed>, 1: array<mixed>, 3: int|null}>  $rows
     * @return array<int|string, array{0: array<string, mixed>, 1: array<mixed>, 3: int|null}>
     */
    private function rowsByLabel(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $indexed[(string) $row[0]['label']] = $row;
        }

        return $indexed;
    }

    /**
     * @param  list<array{0: array<string, mixed>, 1: array<mixed>, 3: int|null}>  $rows
     * @return array<string, int>
     */
    private function requestCounts(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row[0]['label']] = (int) $row[0]['requests'];
        }

        return $counts;
    }
}
