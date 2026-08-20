<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Archiving\ArchiveRecordSet;
use App\Matomo\Archiving\ArchiveReportRequest;
use App\Matomo\Archiving\BotTrackingArchiveConfiguration;
use App\Matomo\Archiving\BotTrackingContentArchiveCollector;
use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Archiving\ReportingSubperiodFactory;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class BotTrackingContentArchiveCollectorTest extends TestCase
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
            $table->unsignedSmallInteger('http_status_code')->nullable();
            $table->unsignedInteger('response_size_bytes')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
        });
    }

    public function test_archives_ranked_page_document_and_broken_content_rows(): void
    {
        $this->connection->table('log_action')->insert([
            ['idaction' => 1, 'name' => 'example.test/a', 'type' => 1],
            ['idaction' => 2, 'name' => 'example.test/b', 'type' => 1],
            ['idaction' => 3, 'name' => 'example.test/file.pdf', 'type' => 3],
            ['idaction' => 4, 'name' => 'example.test/out', 'type' => 2],
        ]);
        $this->connection->table('log_bot_request')->insert([
            $this->request(1, 7, '2026-08-15 01:00:00', 1, 404, 1000, 100),
            $this->request(2, 7, '2026-08-15 02:00:00', 1, 200, null, null),
            $this->request(3, 7, '2026-08-15 03:00:00', 2, 500, 2000, 300),
            $this->request(4, 7, '2026-08-15 04:00:00', 3, 410, 3000, 400),
            $this->request(5, 7, '2026-08-15 05:00:00', 4, 500, 4000, 500),
            $this->request(6, 7, '2026-08-15 06:00:00', 1, 500, 5000, 600, 'crawler'),
            $this->request(7, 8, '2026-08-15 07:00:00', 1, 500, 6000, 700),
            $this->request(8, 7, '2026-08-14 23:59:59', 1, 500, 7000, 800),
        ]);

        $records = $this->collect(
            period: new ReportingPeriod('day', 1, '2026-08-15', '2026-08-15', '2026-08-15'),
            configuration: new BotTrackingArchiveConfiguration(
                contentLimit: 10,
                contentRankingLimit: 1,
            ),
        );

        $pages = $this->rowsByLabel(
            $this->decodedRows($records->blobs()['BotTracking_AIChatbotsRequestedPages']),
        );
        $this->assertSame([
            'label' => 'example.test/a',
            'requests' => 2,
            'sum_server_time' => 100,
            'nb_server_time' => 1,
            'sum_response_size' => 1000,
            'nb_response_size' => 1,
            'page_not_found_404_requests' => 1,
            'server_error_5xx_requests' => 0,
        ], $pages['example.test/a'][0]);
        $this->assertSame(1, $pages[-1][0]['requests']);
        $this->assertSame(300, $pages[-1][0]['sum_server_time']);
        $this->assertSame(1, $pages[-1][0]['server_error_5xx_requests']);

        $documents = $this->rowsByLabel(
            $this->decodedRows($records->blobs()['BotTracking_AIChatbotsRequestedDocuments']),
        );
        $this->assertSame(1, $documents['example.test/file.pdf'][0]['requests']);
        $this->assertSame(400, $documents['example.test/file.pdf'][0]['sum_server_time']);

        $broken = $this->rowsByLabel(
            $this->decodedRows($records->blobs()['BotTracking_AIChatbotsBrokenContent']),
        );
        $this->assertSame(1, $broken['example.test/a'][0]['total_broken_requests']);
        $this->assertSame(2, $broken[-1][0]['total_broken_requests']);
        $this->assertSame(1, $broken[-1][0]['page_not_found_404_requests']);
        $this->assertSame(1, $broken[-1][0]['server_error_5xx_requests']);
    }

    public function test_aggregates_parent_accumulators_by_url(): void
    {
        $dayOne = new ReportingPeriod('day', 1, '2026-08-14', '2026-08-14', '2026-08-14');
        $dayTwo = new ReportingPeriod('day', 1, '2026-08-15', '2026-08-15', '2026-08-15');
        $subperiods = $this->createMock(ReportingSubperiodFactory::class);
        $subperiods->expects($this->once())->method('children')->willReturn([$dayOne, $dayTwo]);
        $blobs = $this->createMock(BlobArchiveRepository::class);
        $blobs->expects($this->exactly(3))->method('rows')->willReturnCallback(
            static function (
                array $siteIds,
                array $periods,
                string $segmentHash,
                string $recordName,
            ) use ($dayOne, $dayTwo): array {
                self::assertSame([7], $siteIds);
                self::assertSame('', $segmentHash);
                $sortMetric = $recordName === 'BotTracking_AIChatbotsBrokenContent'
                    ? 'total_broken_requests'
                    : 'requests';

                return [7 => [
                    $dayOne->rangeKey() => [[
                        'columns' => [
                            'label' => 'example.test/a',
                            $sortMetric => 2,
                            'sum_server_time' => 100,
                            'nb_server_time' => 1,
                        ],
                        'metadata' => [],
                    ]],
                    $dayTwo->rangeKey() => [[
                        'columns' => [
                            'label' => 'example.test/a',
                            $sortMetric => 3,
                            'sum_server_time' => 500,
                            'nb_server_time' => 2,
                        ],
                        'metadata' => [],
                    ]],
                ]];
            },
        );

        $records = $this->collect(
            period: new ReportingPeriod('week', 2, '2026-08-10', '2026-08-16', '2026-08-10'),
            subperiods: $subperiods,
            blobs: $blobs,
        );
        $pages = $this->rowsByLabel(
            $this->decodedRows($records->blobs()['BotTracking_AIChatbotsRequestedPages']),
        );

        $this->assertSame(5, $pages['example.test/a'][0]['requests']);
        $this->assertSame(600, $pages['example.test/a'][0]['sum_server_time']);
        $this->assertSame(3, $pages['example.test/a'][0]['nb_server_time']);
        $broken = $this->rowsByLabel(
            $this->decodedRows($records->blobs()['BotTracking_AIChatbotsBrokenContent']),
        );
        $this->assertSame(5, $broken['example.test/a'][0]['total_broken_requests']);
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
        $collector = new BotTrackingContentArchiveCollector(
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

    /**
     * @return array{idrequest: int, idsite: int, server_time: string, idaction_url: int,
     *     bot_type: string, http_status_code: int, response_size_bytes: int|null,
     *     response_time_ms: int|null}
     */
    private function request(
        int $id,
        int $siteId,
        string $time,
        int $actionId,
        int $status,
        ?int $size,
        ?int $responseTime,
        string $botType = 'ai_chatbot',
    ): array {
        return [
            'idrequest' => $id,
            'idsite' => $siteId,
            'server_time' => $time,
            'idaction_url' => $actionId,
            'bot_type' => $botType,
            'http_status_code' => $status,
            'response_size_bytes' => $size,
            'response_time_ms' => $responseTime,
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
}
