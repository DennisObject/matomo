<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;
use App\Matomo\Localization\MatomoTranslator;

/**
 * @phpstan-type ArchiveValue float|int|string|null
 * @phpstan-type ArchiveRow array{columns: array<string, ArchiveValue>, metadata: array<string, ArchiveValue>, subtableId: int|null}
 * @phpstan-type ArchiveRecords array<string, list<ArchiveRow>>
 */
final readonly class ReferrersTypeReportBuilder
{
    private const string RECORD = 'Referrers_type';

    /** @var array<int, string> */
    private const array TYPE_NAMES = [
        1 => 'direct',
        2 => 'search',
        3 => 'website',
        6 => 'campaign',
        7 => 'social',
        8 => 'ai',
    ];

    /** @var array<int, string> */
    private const array TYPE_TRANSLATIONS = [
        1 => 'Referrers_DirectEntry',
        2 => 'Referrers_SearchEngines',
        3 => 'Referrers_Websites',
        6 => 'Referrers_Campaigns',
        7 => 'Referrers_Socials',
        8 => 'Referrers_AIAssistants',
    ];

    public function __construct(
        private HierarchicalBlobArchiveRepository $archives,
        private ReferrersSearchReportBuilder $search,
        private ReferrersSocialReportBuilder $social,
        private ReferrersAiReportBuilder $ai,
        private ReferrersWebsiteReportBuilder $websites,
        private ReferrersCampaignReportBuilder $campaigns,
        private MatomoTranslator $translator,
    ) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function build(
        string $method,
        ?string $typeReferrer,
        ?int $idSubtable,
        bool $expanded,
        bool $setReferrerTypeLabel,
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $language,
        bool $showMetadata,
        bool $forceDateIndex,
    ): ApiTableReport {
        $idSite = $siteIds[0] ?? 0;

        if ($method === 'Referrers.getReferrerType' && $idSubtable !== null && isset(self::TYPE_NAMES[$idSubtable])) {
            return $this->withoutSubtableIds($this->subreport(
                $idSubtable,
                $siteIds,
                $periods,
                $segmentHash,
                $language,
                $showMetadata,
                $forceDateIndex,
            ));
        }

        $records = $this->archives->records($siteIds, $periods, $segmentHash, self::RECORD, false);
        $subreports = ($expanded || $method === 'Referrers.getAll') && ! $forceDateIndex
            ? $this->subreports($siteIds, $periods, $segmentHash, $language, $showMetadata)
            : [];

        if (! $forceDateIndex) {
            $period = $periods[0] ?? null;
            $roots = $period === null ? [] : ($records[$idSite][$period->rangeKey()][self::RECORD] ?? []);

            return new ApiTableReport($method === 'Referrers.getAll'
                ? $this->allRows($roots, $subreports)
                : $this->typeRows(
                    $roots,
                    $subreports,
                    $typeReferrer,
                    $expanded,
                    $setReferrerTypeLabel,
                    $language,
                    $showMetadata,
                ), []);
        }

        $data = [];

        foreach ($periods as $period) {
            $roots = $records[$idSite][$period->rangeKey()][self::RECORD] ?? [];
            $data[$period->resultKey] = $this->typeRows(
                $roots,
                [],
                $typeReferrer,
                false,
                $setReferrerTypeLabel,
                $language,
                $showMetadata,
            );
        }

        return new ApiTableReport($data, ['date']);
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @return array<int, array<mixed>>
     */
    private function subreports(
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $language,
        bool $showMetadata,
    ): array {
        $reports = [];

        foreach (array_keys(self::TYPE_NAMES) as $type) {
            if ($type === 1) {
                continue;
            }

            $reports[$type] = $this->subreport(
                $type,
                $siteIds,
                $periods,
                $segmentHash,
                $language,
                $showMetadata,
                false,
            )->data;
        }

        return $reports;
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    private function subreport(
        int $type,
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $language,
        bool $showMetadata,
        bool $forceDateIndex,
    ): ApiTableReport {
        return match ($type) {
            2 => $this->search->build(
                'Referrers.getKeywords', null, false, false, false, $siteIds, $periods,
                $segmentHash, $language, $showMetadata, false, $forceDateIndex,
            ),
            3 => $this->websites->build(
                'Referrers.getWebsites', null, false, false, false, $siteIds, $periods,
                $segmentHash, $showMetadata, false, $forceDateIndex,
            ),
            6 => $this->campaigns->build(
                'Referrers.getCampaigns', null, false, $siteIds, $periods,
                $segmentHash, $showMetadata, false, $forceDateIndex,
            ),
            7 => $this->social->build(
                'Referrers.getSocials', null, false, false, false, $siteIds, $periods,
                $segmentHash, $showMetadata, false, $forceDateIndex,
            ),
            8 => $this->ai->build(
                'Referrers.getAIAssistants', null, null, false, false, false, $siteIds, $periods,
                $segmentHash, $language, $showMetadata, false, $forceDateIndex,
            ),
            default => new ApiTableReport([], []),
        };
    }

    /**
     * @param  list<ArchiveRow>  $roots
     * @param  array<int, array<mixed>>  $subreports
     * @return list<array<string, mixed>>
     */
    private function typeRows(
        array $roots,
        array $subreports,
        ?string $typeReferrer,
        bool $expanded,
        bool $setReferrerTypeLabel,
        string $language,
        bool $showMetadata,
    ): array {
        $rows = [];

        foreach ($roots as $root) {
            $type = (int) ($root['columns']['label'] ?? 0);

            if ($typeReferrer !== null && (string) $type !== $typeReferrer) {
                continue;
            }

            $row = $root['columns'];
            $shortName = self::TYPE_NAMES[$type] ?? null;

            if ($showMetadata) {
                $row = [...$row, ...$root['metadata']];
                $row['segment'] = $shortName === null ? '' : 'referrerType=='.$shortName;

                if ($setReferrerTypeLabel) {
                    $row['referrer_type'] = $row['label'] ?? null;
                }
            }

            $row['label'] = $setReferrerTypeLabel
                ? $this->translator->translate(self::TYPE_TRANSLATIONS[$type] ?? 'General_Others', $language)
                : $type;

            if ($type !== 1) {
                if ($expanded && isset($subreports[$type])) {
                    $row['subtable'] = $this->stripSubtableIds($subreports[$type]);
                } elseif ($showMetadata) {
                    $row['idsubdatatable'] = $type;
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<ArchiveRow>  $roots
     * @param  array<int, array<mixed>>  $subreports
     * @return list<array<string, mixed>>
     */
    private function allRows(array $roots, array $subreports): array
    {
        $rows = [];

        foreach ($roots as $root) {
            $type = (int) ($root['columns']['label'] ?? 0);

            foreach ($subreports[$type] ?? [] as $subrow) {
                if (! is_array($subrow)) {
                    continue;
                }

                unset($subrow['idsubdatatable'], $subrow['subtable']);
                $subrow['referer_type'] = $type;
                $rows[] = $subrow;
            }
        }

        return $rows;
    }

    private function withoutSubtableIds(ApiTableReport $report): ApiTableReport
    {
        return new ApiTableReport($this->stripSubtableIds($report->data), $report->dimensions);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<array-key, mixed>
     */
    private function stripSubtableIds(array $rows): array
    {
        foreach ($rows as &$row) {
            if (! is_array($row)) {
                continue;
            }

            unset($row['idsubdatatable']);

            if (isset($row['subtable']) && is_array($row['subtable'])) {
                $row['subtable'] = $this->stripSubtableIds($row['subtable']);
            }
        }

        return $rows;
    }
}
