<?php

declare(strict_types=1);

namespace App\Matomo\Insights;

use App\Matomo\Referrers\ReferrerDefinitionCatalog;
use App\Matomo\Reporting\ActionsReportBuilder;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Reporting\UserCountryReportBuilder;

final readonly class BuilderCoreInsightReportReader implements CoreInsightReportReader
{
    public function __construct(
        private ActionsReportBuilder $actionsBuilder,
        private UserCountryReportBuilder $countryBuilder,
        private BlobArchiveRepository $blobs,
        private ReferrerDefinitionCatalog $referrerDefinitions,
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

    public function referrers(
        string $recordName,
        int $siteId,
        ReportingPeriod $period,
        string $segmentHash,
    ): array {
        $archives = $this->blobs->rows([$siteId], [$period], $segmentHash, $recordName);
        $archiveRows = $archives[$siteId][$period->rangeKey()] ?? [];
        $rows = $this->archiveColumns($archiveRows);

        if ($recordName === 'Referrers_urlBySocialNetwork') {
            $rows = $this->group($rows, static fn (string $label): string => $label === 'instagram'
                ? 'Instagram'
                : $label);
        }

        if ($rows !== [] || ! in_array($recordName, [
            'Referrers_urlBySocialNetwork',
            'Referrers_entryUrlByAIAssistant',
        ], true)) {
            return $rows;
        }

        $websiteArchives = $this->blobs->rows(
            [$siteId],
            [$period],
            $segmentHash,
            'Referrers_urlByWebsite',
        );
        $websiteRows = $this->archiveColumns(
            $websiteArchives[$siteId][$period->rangeKey()] ?? [],
        );
        $name = $recordName === 'Referrers_urlBySocialNetwork'
            ? $this->referrerDefinitions->socialName(...)
            : $this->referrerDefinitions->aiAssistantName(...);

        return $this->group($websiteRows, $name);
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $archiveRows
     * @return list<array<string, mixed>>
     */
    private function archiveColumns(array $archiveRows): array
    {
        $rows = [];

        foreach ($archiveRows as $archiveRow) {
            $columns = $archiveRow['columns'];

            if (isset($columns['label'])) {
                $rows[] = $columns;
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(string): ?string  $name
     * @return list<array<string, mixed>>
     */
    private function group(array $rows, callable $name): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $label = $row['label'] ?? null;

            if (! is_string($label)) {
                continue;
            }

            $group = $name($label);

            if ($group === null || $group === '') {
                continue;
            }

            if (! isset($grouped[$group])) {
                $grouped[$group] = [...$row, 'label' => $group];

                continue;
            }

            foreach ($row as $column => $value) {
                if ($column === 'label' || (! is_int($value) && ! is_float($value))) {
                    continue;
                }

                $existing = $grouped[$group][$column] ?? 0;
                $grouped[$group][$column] = (is_int($existing) || is_float($existing))
                    ? $existing + $value
                    : $value;
            }
        }

        return array_values($grouped);
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
