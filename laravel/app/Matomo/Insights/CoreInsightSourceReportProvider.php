<?php

declare(strict_types=1);

namespace App\Matomo\Insights;

use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Reporting\ReportingPeriod;

final readonly class CoreInsightSourceReportProvider implements InsightSourceReportProvider
{
    /** @var array<string, array{method: string, name: string, flat: bool}> */
    private const array ACTION_REPORTS = [
        'Actions_getPageUrls' => [
            'method' => 'Actions.getPageUrls',
            'name' => 'Actions_PageUrls',
            'flat' => false,
        ],
        'Actions_getPageTitles' => [
            'method' => 'Actions.getPageTitles',
            'name' => 'Actions_PageTitles',
            'flat' => false,
        ],
        'Actions_getDownloads' => [
            'method' => 'Actions.getDownloads',
            'name' => 'General_Downloads',
            'flat' => true,
        ],
    ];

    /** @var array<string, array{record: string, action: string, name: string}> */
    private const array REFERRER_REPORTS = [
        'Referrers_getWebsites' => [
            'record' => 'Referrers_urlByWebsite',
            'action' => 'getWebsites',
            'name' => 'Referrers_Websites',
        ],
        'Referrers_getCampaigns' => [
            'record' => 'Referrers_keywordByCampaign',
            'action' => 'getCampaigns',
            'name' => 'Referrers_Campaigns',
        ],
        'Referrers_getSocials' => [
            'record' => 'Referrers_urlBySocialNetwork',
            'action' => 'getSocials',
            'name' => 'Referrers_Socials',
        ],
        'Referrers_getSearchEngines' => [
            'record' => 'Referrers_keywordBySearchEngine',
            'action' => 'getSearchEngines',
            'name' => 'Referrers_SearchEngines',
        ],
        'Referrers_getAIAssistants' => [
            'record' => 'Referrers_entryUrlByAIAssistant',
            'action' => 'getAIAssistants',
            'name' => 'Referrers_AIAssistants',
        ],
    ];

    public function __construct(
        private CoreInsightReportReader $reports,
        private MatomoTranslator $translator,
    ) {}

    public function supports(string $reportUniqueId): bool
    {
        return $reportUniqueId === 'UserCountry_getCountry'
            || isset(self::ACTION_REPORTS[$reportUniqueId])
            || isset(self::REFERRER_REPORTS[$reportUniqueId]);
    }

    public function report(
        string $reportUniqueId,
        int $siteId,
        ReportingPeriod $period,
        string $segmentHash,
        string $language,
    ): ?InsightSourceReport {
        if ($reportUniqueId === 'UserCountry_getCountry') {
            $rows = $this->reports->countries($siteId, $period, $segmentHash, $language);

            return $this->source(
                $rows,
                'UserCountry',
                'getCountry',
                $this->translator->translate('UserCountry_Country', $language),
                $reportUniqueId,
                $language,
            );
        }

        $configuration = self::ACTION_REPORTS[$reportUniqueId] ?? null;

        if ($configuration === null) {
            $referrer = self::REFERRER_REPORTS[$reportUniqueId] ?? null;

            if ($referrer !== null) {
                return $this->source(
                    $this->reports->referrers(
                        $referrer['record'],
                        $siteId,
                        $period,
                        $segmentHash,
                    ),
                    'Referrers',
                    $referrer['action'],
                    $this->translator->translate($referrer['name'], $language),
                    $reportUniqueId,
                    $language,
                );
            }
        }

        if ($configuration === null) {
            return null;
        }

        $rows = $this->reports->actions(
            $configuration['method'],
            $configuration['flat'],
            $siteId,
            $period,
            $segmentHash,
            $language,
        );
        [$module, $action] = explode('.', $configuration['method'], 2);

        return $this->source(
            $rows,
            $module,
            $action,
            $this->translator->translate($configuration['name'], $language),
            $reportUniqueId,
            $language,
        );
    }

    /**
     * @param  array<array-key, mixed>  $rows
     */
    private function source(
        array $rows,
        string $module,
        string $action,
        string $name,
        string $uniqueId,
        string $language,
    ): InsightSourceReport {
        $normalized = [];
        $total = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized[] = $row;
            $value = $row['nb_visits'] ?? 0;

            if (is_int($value) || is_float($value) || is_numeric($value)) {
                $total += (int) $value;
            }
        }

        return new InsightSourceReport(array_slice($normalized, 0, 1000), [
            'module' => $module,
            'action' => $action,
            'name' => $name,
            'uniqueId' => $uniqueId,
            'parameters' => [],
            'metrics' => [
                'nb_visits' => $this->translator->translate('General_ColumnNbVisits', $language),
            ],
        ], $total);
    }
}
