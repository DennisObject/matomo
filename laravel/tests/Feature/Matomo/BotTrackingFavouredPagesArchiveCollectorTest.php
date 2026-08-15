<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Archiving\ArchiveRecordSet;
use App\Matomo\Archiving\ArchiveReportRequest;
use App\Matomo\Archiving\BotTrackingArchiveConfiguration;
use App\Matomo\Archiving\BotTrackingFavouredPagesArchiveCollector;
use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Archiving\ReportingSubperiodFactory;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class BotTrackingFavouredPagesArchiveCollectorTest extends TestCase
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
            $table->string('bot_type');
        });
        $this->connection->getSchemaBuilder()->create('log_link_visit_action', function (Blueprint $table): void {
            $table->unsignedBigInteger('idlink_va')->primary();
            $table->unsignedInteger('idsite');
            $table->unsignedBigInteger('idvisit');
            $table->unsignedBigInteger('idaction_url');
            $table->unsignedBigInteger('idaction_event_category')->nullable();
            $table->dateTime('server_time');
        });
    }

    public function test_archives_variant_scores_and_unscored_summary_rows(): void
    {
        $this->connection->table('log_action')->insert([
            ['idaction' => 1, 'name' => 'example.test/a', 'type' => 1],
            ['idaction' => 2, 'name' => 'example.test/b', 'type' => 1],
            ['idaction' => 3, 'name' => 'example.test/c', 'type' => 1],
            ['idaction' => 4, 'name' => 'example.test/out', 'type' => 2],
        ]);
        $this->connection->table('log_bot_request')->insert([
            $this->botRequest(1, 7, '2026-08-15 01:00:00', 1),
            $this->botRequest(2, 7, '2026-08-15 02:00:00', 2),
            $this->botRequest(3, 7, '2026-08-15 03:00:00', 2),
            $this->botRequest(4, 7, '2026-08-15 04:00:00', 2),
            $this->botRequest(5, 7, '2026-08-15 05:00:00', 2),
            $this->botRequest(6, 7, '2026-08-15 06:00:00', 4),
            $this->botRequest(7, 7, '2026-08-15 07:00:00', 1, 'crawler'),
            $this->botRequest(8, 8, '2026-08-15 08:00:00', 1),
        ]);
        $this->connection->table('log_link_visit_action')->insert([
            $this->humanAction(1, 7, 1, 1),
            $this->humanAction(2, 7, 2, 1),
            $this->humanAction(3, 7, 3, 1),
            $this->humanAction(4, 7, 3, 1),
            $this->humanAction(5, 7, 4, 2),
            $this->humanAction(6, 7, 5, 3),
            $this->humanAction(7, 7, 6, 3),
            $this->humanAction(8, 7, 7, 3),
            $this->humanAction(9, 7, 8, 3),
            $this->humanAction(10, 7, 9, 3),
            $this->humanAction(11, 7, 10, 3, 99),
            $this->humanAction(12, 8, 11, 3),
        ]);

        $records = $this->collect(
            period: new ReportingPeriod('day', 1, '2026-08-15', '2026-08-15', '2026-08-15'),
            configuration: new BotTrackingArchiveConfiguration(
                favouredPagesLimit: 3,
                favouredPagesRankingLimit: 2,
            ),
        );

        $human = $this->rowsByLabel($this->decodedRows(
            $records->blobs()['BotTracking_AIChatbotsHumanFavouredPages'],
        ));
        $this->assertSame(100.0, $human['example.test/c'][0]['discrepancy_score']);
        $this->assertSame(38.7, $human['example.test/a'][0]['discrepancy_score']);
        $this->assertArrayNotHasKey('example.test/b', $human);
        $this->assertSame(1, $human[-1][0]['unique_human_pageviews']);
        $this->assertSame(4, $human[-1][0]['ai_chatbot_requests']);
        $this->assertArrayNotHasKey('discrepancy_score', $human[-1][0]);

        $ai = $this->rowsByLabel($this->decodedRows(
            $records->blobs()['BotTracking_AIChatbotsAIFavouredPages'],
        ));
        $this->assertSame(100.0, $ai['example.test/b'][0]['discrepancy_score']);
        $this->assertSame(0.0, $ai['example.test/a'][0]['discrepancy_score']);
        $this->assertArrayNotHasKey('example.test/c', $ai);
        $this->assertSame(6, $ai[-1][0]['unique_human_pageviews']);
        $this->assertSame(0, $ai[-1][0]['ai_chatbot_requests']);
        $this->assertArrayNotHasKey('discrepancy_score', $ai[-1][0]);
    }

    public function test_recomputes_parent_scores_from_additive_traffic(): void
    {
        $dayOne = new ReportingPeriod('day', 1, '2026-08-14', '2026-08-14', '2026-08-14');
        $dayTwo = new ReportingPeriod('day', 1, '2026-08-15', '2026-08-15', '2026-08-15');
        $subperiods = $this->createMock(ReportingSubperiodFactory::class);
        $subperiods->expects($this->once())->method('children')->willReturn([$dayOne, $dayTwo]);
        $blobs = $this->createMock(BlobArchiveRepository::class);
        $blobs->expects($this->exactly(2))->method('rows')->willReturnCallback(
            static function (
                array $siteIds,
                array $periods,
                string $segmentHash,
                string $recordName,
            ) use ($dayOne, $dayTwo): array {
                self::assertSame([7], $siteIds);
                self::assertSame('', $segmentHash);

                return [7 => [
                    $dayOne->rangeKey() => [
                        self::archiveRow('example.test/a', 3, 1, 99.9),
                        self::archiveRow('example.test/b', 1, 2, 88.8),
                        self::archiveRow(-1, 2, 0, null),
                    ],
                    $dayTwo->rangeKey() => [
                        self::archiveRow('example.test/a', 3, 1, 77.7),
                        self::archiveRow('example.test/b', 0, 3, 66.6),
                        self::archiveRow(-1, 0, 1, null),
                    ],
                ]];
            },
        );

        $records = $this->collect(
            period: new ReportingPeriod('week', 2, '2026-08-10', '2026-08-16', '2026-08-10'),
            subperiods: $subperiods,
            blobs: $blobs,
        );
        $human = $this->rowsByLabel($this->decodedRows(
            $records->blobs()['BotTracking_AIChatbotsHumanFavouredPages'],
        ));

        $this->assertSame(50.0, $human['example.test/a'][0]['discrepancy_score']);
        $this->assertSame(0.0, $human['example.test/b'][0]['discrepancy_score']);
        $this->assertSame(2, $human[-1][0]['unique_human_pageviews']);
        $this->assertSame(1, $human[-1][0]['ai_chatbot_requests']);
        $this->assertArrayNotHasKey('discrepancy_score', $human[-1][0]);

        $ai = $this->rowsByLabel($this->decodedRows(
            $records->blobs()['BotTracking_AIChatbotsAIFavouredPages'],
        ));
        $this->assertSame(0.0, $ai['example.test/a'][0]['discrepancy_score']);
        $this->assertSame(66.7, $ai['example.test/b'][0]['discrepancy_score']);
    }

    public function test_skips_human_scan_when_there_are_no_ai_page_requests(): void
    {
        $this->connection->table('log_action')->insert([
            ['idaction' => 1, 'name' => 'example.test/a', 'type' => 1],
        ]);
        $this->connection->table('log_link_visit_action')->insert([
            $this->humanAction(1, 7, 1, 1),
        ]);

        $records = $this->collect(
            period: new ReportingPeriod('day', 1, '2026-08-15', '2026-08-15', '2026-08-15'),
        );

        $this->assertSame([], $this->decodedRows(
            $records->blobs()['BotTracking_AIChatbotsHumanFavouredPages'],
        ));
        $this->assertSame([], $this->decodedRows(
            $records->blobs()['BotTracking_AIChatbotsAIFavouredPages'],
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

    private function collect(
        ReportingPeriod $period,
        ?BotTrackingArchiveConfiguration $configuration = null,
        ?ReportingSubperiodFactory $subperiods = null,
        ?BlobArchiveRepository $blobs = null,
        ?string $segment = null,
    ): ArchiveRecordSet {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $segments = $this->createStub(SegmentHashResolver::class);
        $segments->method('resolve')->willReturn('');
        $collector = new BotTrackingFavouredPagesArchiveCollector(
            connection: $this->connection,
            subperiods: $subperiods ?? $this->createStub(ReportingSubperiodFactory::class),
            segments: $segments,
            blobs: $blobs ?? $this->createStub(BlobArchiveRepository::class),
            sites: $sites,
            configuration: $configuration ?? new BotTrackingArchiveConfiguration,
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

    /** @return array{idrequest: int, idsite: int, server_time: string, idaction_url: int, bot_type: string} */
    private function botRequest(
        int $id,
        int $siteId,
        string $time,
        int $actionId,
        string $botType = 'ai_chatbot',
    ): array {
        return [
            'idrequest' => $id,
            'idsite' => $siteId,
            'server_time' => $time,
            'idaction_url' => $actionId,
            'bot_type' => $botType,
        ];
    }

    /**
     * @return array{idlink_va: int, idsite: int, idvisit: int, idaction_url: int,
     *     idaction_event_category: int|null, server_time: string}
     */
    private function humanAction(
        int $id,
        int $siteId,
        int $visitId,
        int $actionId,
        ?int $eventCategory = null,
    ): array {
        return [
            'idlink_va' => $id,
            'idsite' => $siteId,
            'idvisit' => $visitId,
            'idaction_url' => $actionId,
            'idaction_event_category' => $eventCategory,
            'server_time' => '2026-08-15 12:00:00',
        ];
    }

    /**
     * @return array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}
     */
    private static function archiveRow(
        int|string $label,
        int $human,
        int $ai,
        ?float $score,
    ): array {
        $columns = [
            'label' => $label,
            'unique_human_pageviews' => $human,
            'ai_chatbot_requests' => $ai,
        ];

        if ($score !== null) {
            $columns['discrepancy_score'] = $score;
        }

        return ['columns' => $columns, 'metadata' => []];
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
}
