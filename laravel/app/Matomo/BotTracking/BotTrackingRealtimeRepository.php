<?php

declare(strict_types=1);

namespace App\Matomo\BotTracking;

interface BotTrackingRealtimeRepository
{
    /**
     * @param  list<int>  $siteIds
     * @return list<array{
     *     label: string,
     *     requests: int,
     *     BotTracking_AIChatbotsUniquePageUrls: int,
     *     BotTracking_AIChatbotsNotFoundRequests: int,
     *     BotTracking_AIChatbotsServerErrorRequests: int
     * }>
     */
    public function chatbotActivity(array $siteIds, string $startDate, string $endDate): array;

    /**
     * @param  list<int>  $siteIds
     * @return list<array{label: string, requests: int}>
     */
    public function topPageUrls(array $siteIds, string $startDate, string $endDate): array;
}
