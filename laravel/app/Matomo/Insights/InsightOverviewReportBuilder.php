<?php

declare(strict_types=1);

namespace App\Matomo\Insights;

use App\Matomo\Api\InsightsRequest;

final readonly class InsightOverviewReportBuilder
{
    /** @var list<string> */
    private const array REPORTS = [
        'Actions_getPageUrls',
        'Actions_getPageTitles',
        'Actions_getDownloads',
        'Referrers_getWebsites',
        'Referrers_getCampaigns',
        'Referrers_getSocials',
        'Referrers_getSearchEngines',
        'Referrers_getAIAssistants',
        'UserCountry_getCountry',
    ];

    public function __construct(
        private InsightReportBuilder $reports,
        private InsightSourceReportProvider $sources,
    ) {}

    /** @return array<string, list<array<string, mixed>>> */
    public function build(
        InsightsRequest $request,
        string $timezone,
        string $language,
        bool $moversAndShakers,
    ): array {
        $overview = [];

        foreach (self::REPORTS as $uniqueId) {
            if (! $this->sources->supports($uniqueId)) {
                continue;
            }

            $reportRequest = new InsightsRequest(
                period: $request->period,
                date: $request->date,
                siteId: $request->siteId,
                reportUniqueId: $uniqueId,
                segment: $request->segment,
                comparedToXPeriods: $request->comparedToXPeriods,
                limitIncreaser: $moversAndShakers ? 4 : 3,
                limitDecreaser: $moversAndShakers ? 4 : 3,
                minImpactPercent: $moversAndShakers ? 2 : 1,
                minGrowthPercent: $moversAndShakers ? 20 : 25,
            );

            $report = $this->reports->build(
                $reportRequest,
                $timezone,
                $language,
                $moversAndShakers,
            );

            $name = $report->metadata['reportName'] ?? $uniqueId;
            $overview[is_string($name) ? $name : $uniqueId] = $report->rows;
        }

        return $overview;
    }
}
