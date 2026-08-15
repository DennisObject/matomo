<?php

declare(strict_types=1);

namespace App\Matomo\BotTracking;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use PDO;
use stdClass;

final readonly class DatabaseBotTrackingRealtimeRepository implements BotTrackingRealtimeRepository
{
    private const string AI_CHATBOT_TYPE = 'ai_chatbot';

    private const int PAGE_URL_ACTION_TYPE = 1;

    public function __construct(
        private Connection $connection,
        private int $chatbotLimit,
        private int $topPageUrlLimit,
        private float $maximumExecutionTime,
    ) {}

    public function chatbotActivity(array $siteIds, string $startDate, string $endDate): array
    {
        $siteIds = $this->siteIds($siteIds);

        if ($siteIds === []) {
            return [];
        }

        $query = $this->connection
            ->table('log_bot_request as bot')
            ->leftJoin('log_action as action', 'action.idaction', '=', 'bot.idaction_url')
            ->selectRaw('bot.bot_name AS label')
            ->selectRaw('COUNT(*) AS requests')
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN action.type = ? THEN action.name END) '.
                    'AS BotTracking_AIChatbotsUniquePageUrls',
                [self::PAGE_URL_ACTION_TYPE],
            )
            ->selectRaw(
                'SUM(CASE WHEN bot.http_status_code IN (404, 410) THEN 1 ELSE 0 END) '.
                    'AS BotTracking_AIChatbotsNotFoundRequests',
            )
            ->selectRaw(
                'SUM(CASE WHEN bot.http_status_code BETWEEN 500 AND 599 THEN 1 ELSE 0 END) '.
                    'AS BotTracking_AIChatbotsServerErrorRequests',
            )
            ->whereIn('bot.idsite', $siteIds)
            ->where('bot.bot_type', self::AI_CHATBOT_TYPE)
            ->whereBetween('bot.server_time', [$startDate, $endDate])
            ->groupBy('bot.bot_name')
            ->orderByDesc('requests')
            ->orderBy('bot.bot_name')
            ->limit($this->chatbotLimit);

        return array_map(
            static fn (stdClass $row): array => [
                'label' => is_string($row->label ?? null) ? $row->label : '',
                'requests' => (int) ($row->requests ?? 0),
                'BotTracking_AIChatbotsUniquePageUrls' => (int) (
                    $row->BotTracking_AIChatbotsUniquePageUrls ?? 0
                ),
                'BotTracking_AIChatbotsNotFoundRequests' => (int) (
                    $row->BotTracking_AIChatbotsNotFoundRequests ?? 0
                ),
                'BotTracking_AIChatbotsServerErrorRequests' => (int) (
                    $row->BotTracking_AIChatbotsServerErrorRequests ?? 0
                ),
            ],
            $this->select($query),
        );
    }

    public function topPageUrls(array $siteIds, string $startDate, string $endDate): array
    {
        $siteIds = $this->siteIds($siteIds);

        if ($siteIds === []) {
            return [];
        }

        $query = $this->connection
            ->table('log_bot_request as bot')
            ->join('log_action as action', 'action.idaction', '=', 'bot.idaction_url')
            ->selectRaw('action.name AS label')
            ->selectRaw('COUNT(*) AS requests')
            ->whereIn('bot.idsite', $siteIds)
            ->where('bot.bot_type', self::AI_CHATBOT_TYPE)
            ->whereBetween('bot.server_time', [$startDate, $endDate])
            ->whereNotNull('action.name')
            ->where('action.name', '<>', '')
            ->where('action.type', self::PAGE_URL_ACTION_TYPE)
            ->groupBy('action.name')
            ->orderByDesc('requests')
            ->orderBy('action.name')
            ->limit($this->topPageUrlLimit);

        return array_map(
            static fn (stdClass $row): array => [
                'label' => is_string($row->label ?? null) ? $row->label : '',
                'requests' => (int) ($row->requests ?? 0),
            ],
            $this->select($query),
        );
    }

    /** @return list<stdClass> */
    private function select(Builder $query): array
    {
        $sql = $query->toSql();

        if ($this->maximumExecutionTime > 0 && in_array($this->connection->getDriverName(), [
            'mariadb',
            'mysql',
        ], true)) {
            $version = (string) $this->connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

            if (stripos($version, 'mariadb') !== false) {
                $sql = 'SET STATEMENT max_statement_time='.
                    (int) ceil($this->maximumExecutionTime).' FOR '.$sql;
            } else {
                $milliseconds = (int) ($this->maximumExecutionTime * 1000);
                $hintedSql = preg_replace(
                    '/^select\s+/iD',
                    'select /*+ MAX_EXECUTION_TIME('.$milliseconds.') */ ',
                    $sql,
                    1,
                );
                $sql = is_string($hintedSql) ? $hintedSql : $sql;
            }
        }

        return array_values($this->connection->select($sql, $query->getBindings()));
    }

    /** @param list<int> $siteIds
     * @return list<int>
     */
    private function siteIds(array $siteIds): array
    {
        return array_values(array_unique(array_filter(
            $siteIds,
            static fn (int $idSite): bool => $idSite > 0,
        )));
    }
}
