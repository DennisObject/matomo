<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;
use App\Matomo\BotTracking\AiAssistantMetadata;
use App\Matomo\BotTracking\BotTrackingRealtimeRepository;
use Carbon\CarbonImmutable;

final readonly class BotTrackingRealtimeReportBuilder
{
    public function __construct(
        private BotTrackingRealtimeRepository $requests,
        private AiAssistantMetadata $assistants,
    ) {}

    /**
     * @param  list<int>  $siteIds
     */
    public function build(
        string $method,
        array $siteIds,
        int $lastMinutes,
        bool $showMetadata,
    ): ApiTableReport {
        $end = CarbonImmutable::now('UTC');
        $start = $end->subMinutes($lastMinutes);
        $rows = $method === 'BotTracking.getAIChatbotsRealTime'
            ? $this->requests->chatbotActivity(
                $siteIds,
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
            )
            : $this->requests->topPageUrls(
                $siteIds,
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
            );

        foreach ($rows as &$row) {
            $label = $row['label'];

            if ($method === 'BotTracking.getAIChatbotsRealTime') {
                $label = $this->assistants->displayName($label);
                $row['label'] = $label;

                if ($showMetadata) {
                    $row['url'] = $this->assistants->domain($label);
                    $row['logo'] = $this->assistants->logo($label);
                }
            } elseif ($showMetadata) {
                $row['url'] = 'https://'.$label;
            }
        }

        unset($row);

        return new ApiTableReport($rows, []);
    }
}
