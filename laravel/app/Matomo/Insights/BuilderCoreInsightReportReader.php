<?php

declare(strict_types=1);

namespace App\Matomo\Insights;

use App\Matomo\Reporting\ActionsReportBuilder;
use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Reporting\UserCountryReportBuilder;

final readonly class BuilderCoreInsightReportReader implements CoreInsightReportReader
{
    public function __construct(
        private ActionsReportBuilder $actionsBuilder,
        private UserCountryReportBuilder $countryBuilder,
    ) {}

    public function actions(
        string $method,
        bool $flat,
        int $siteId,
        ReportingPeriod $period,
        string $segmentHash,
        string $language,
    ): array {
        $data = $this->actionsBuilder->table(
            method: $method,
            expanded: false,
            flat: $flat,
            showDimensions: false,
            idSubtable: null,
            depth: null,
            actionValue: null,
            siteIds: [$siteId],
            periods: [$period],
            segmentHash: $segmentHash,
            language: $language,
            showMetadata: false,
            forceSiteIndex: false,
            forceDateIndex: false,
        )->data;

        return $this->rows($data);
    }

    public function countries(
        int $siteId,
        ReportingPeriod $period,
        string $segmentHash,
        string $language,
    ): array {
        $data = $this->countryBuilder->table(
            continents: false,
            siteIds: [$siteId],
            periods: [$period],
            segmentHash: $segmentHash,
            language: $language,
            showMetadata: false,
            forceSiteIndex: false,
            forceDateIndex: false,
        )->data;

        return $this->rows($data);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function rows(array $data): array
    {
        $rows = [];

        foreach ($data as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
