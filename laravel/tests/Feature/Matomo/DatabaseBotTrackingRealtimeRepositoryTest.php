<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\BotTracking\DatabaseBotTrackingRealtimeRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseBotTrackingRealtimeRepositoryTest extends TestCase
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
        $this->connection->table('log_action')->insert([
            ['idaction' => 1, 'name' => 'example.test/a', 'type' => 1],
            ['idaction' => 2, 'name' => 'example.test/b', 'type' => 1],
            ['idaction' => 3, 'name' => 'example.test/file.pdf', 'type' => 3],
        ]);
        $this->connection->table('log_bot_request')->insert([
            $this->request(1, 7, '2026-08-15 11:45:00', 1, 'ChatGPT-User', 'ai_chatbot', 404),
            $this->request(2, 7, '2026-08-15 11:50:00', 2, 'ChatGPT-User', 'ai_chatbot', 200),
            $this->request(3, 7, '2026-08-15 11:55:00', 3, 'Claude-User', 'ai_chatbot', 500),
            $this->request(4, 8, '2026-08-15 11:58:00', 1, 'Perplexity-User', 'ai_chatbot', 200),
            $this->request(5, 7, '2026-08-15 11:59:00', 1, 'OtherBot', 'crawler', 200),
            $this->request(6, 7, '2026-08-15 10:00:00', 1, 'OldBot', 'ai_chatbot', 200),
        ]);
    }

    public function test_aggregates_bounded_chatbot_activity(): void
    {
        $rows = $this->repository(chatbotLimit: 1)->chatbotActivity(
            [0, 7, 7],
            '2026-08-15 11:30:00',
            '2026-08-15 12:00:00',
        );

        $this->assertSame([[
            'label' => 'ChatGPT-User',
            'requests' => 2,
            'BotTracking_AIChatbotsUniquePageUrls' => 2,
            'BotTracking_AIChatbotsNotFoundRequests' => 1,
            'BotTracking_AIChatbotsServerErrorRequests' => 0,
        ]], $rows);
    }

    public function test_aggregates_only_page_urls_and_applies_limit(): void
    {
        $this->connection->table('log_bot_request')->insert(
            $this->request(7, 7, '2026-08-15 11:57:00', 1, 'Claude-User', 'ai_chatbot', 200),
        );

        $rows = $this->repository(topPageLimit: 1)->topPageUrls(
            [7],
            '2026-08-15 11:30:00',
            '2026-08-15 12:00:00',
        );

        $this->assertSame([[
            'label' => 'example.test/a',
            'requests' => 2,
        ]], $rows);
    }

    private function repository(
        int $chatbotLimit = 100,
        int $topPageLimit = 100,
    ): DatabaseBotTrackingRealtimeRepository {
        return new DatabaseBotTrackingRealtimeRepository(
            $this->connection,
            $chatbotLimit,
            $topPageLimit,
            0,
        );
    }

    /**
     * @return array{
     *     idrequest: int,
     *     idsite: int,
     *     server_time: string,
     *     idaction_url: int,
     *     bot_name: string,
     *     bot_type: string,
     *     http_status_code: int
     * }
     */
    private function request(
        int $id,
        int $siteId,
        string $serverTime,
        int $actionId,
        string $botName,
        string $botType,
        int $status,
    ): array {
        return [
            'idrequest' => $id,
            'idsite' => $siteId,
            'server_time' => $serverTime,
            'idaction_url' => $actionId,
            'bot_name' => $botName,
            'bot_type' => $botType,
            'http_status_code' => $status,
        ];
    }
}
