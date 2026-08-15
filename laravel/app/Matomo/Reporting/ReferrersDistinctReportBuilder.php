<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiMetricReport;
use InvalidArgumentException;

final readonly class ReferrersDistinctReportBuilder
{
    /** @var array<string, string> */
    private const array METRICS = [
        'Referrers.getNumberOfDistinctSearchEngines' => 'Referrers_distinctSearchEngines',
        'Referrers.getNumberOfDistinctSocialNetworks' => 'Referrers_distinctSocialNetworks',
        'Referrers.getNumberOfDistinctKeywords' => 'Referrers_distinctKeywords',
        'Referrers.getNumberOfDistinctCampaigns' => 'Referrers_distinctCampaigns',
        'Referrers.getNumberOfDistinctWebsites' => 'Referrers_distinctWebsites',
        'Referrers.getNumberOfDistinctAIAssistants' => 'Referrers_distinctAIAssistants',
        'Referrers.getNumberOfDistinctWebsitesUrls' => 'Referrers_distinctWebsitesUrls',
    ];

    public function __construct(private NumericArchiveRepository $archives) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function build(
        string $method,
        array $siteIds,
        array $periods,
        string $segmentHash,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiMetricReport {
        $metric = self::METRICS[$method]
            ?? throw new InvalidArgumentException("Unsupported Referrers method '{$method}'.");
        $archive = $this->archives->pluginMetrics($siteIds, $periods, $segmentHash, [$metric], 'Referrers');
        $dimensions = [
            ...($forceSiteIndex ? ['idSite'] : []),
            ...($forceDateIndex ? ['date'] : []),
        ];

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            return new ApiMetricReport(
                $forceDateIndex
                    ? $this->dateMetrics($archive[$idSite] ?? [], $periods, $metric)
                    : $this->value($archive[$idSite] ?? [], $periods[0] ?? null, $metric),
                $dimensions,
            );
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            $data[$idSite] = $forceDateIndex
                ? $this->dateMetrics($archive[$idSite] ?? [], $periods, $metric)
                : $this->value($archive[$idSite] ?? [], $periods[0] ?? null, $metric);
        }

        return new ApiMetricReport($data, $dimensions);
    }

    /**
     * @param  array<string, array<string, int|float>>  $archive
     * @param  list<ReportingPeriod>  $periods
     * @return array<string, int|float>
     */
    private function dateMetrics(array $archive, array $periods, string $metric): array
    {
        $values = [];

        foreach ($periods as $period) {
            $values[$period->resultKey] = $archive[$period->rangeKey()][$metric] ?? 0;
        }

        return $values;
    }

    /** @param array<string, array<string, int|float>> $archive */
    private function value(array $archive, ?ReportingPeriod $period, string $metric): int|float
    {
        return $period === null ? 0 : ($archive[$period->rangeKey()][$metric] ?? 0);
    }
}
