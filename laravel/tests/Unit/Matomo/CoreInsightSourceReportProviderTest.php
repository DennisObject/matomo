<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Insights\CoreInsightReportReader;
use App\Matomo\Insights\CoreInsightSourceReportProvider;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Reporting\ReportingPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CoreInsightSourceReportProviderTest extends TestCase
{
    private CoreInsightReportReader&MockObject $reports;

    private CoreInsightSourceReportProvider $provider;

    protected function setUp(): void
    {
        $this->reports = $this->createMock(CoreInsightReportReader::class);
        $translator = $this->createStub(MatomoTranslator::class);
        $translator->method('translate')->willReturnCallback(
            static fn (string $key): string => $key,
        );
        $this->provider = new CoreInsightSourceReportProvider(
            $this->reports,
            $translator,
        );
    }

    #[DataProvider('actionReports')]
    public function test_loads_action_reports(
        string $uniqueId,
        string $method,
        bool $flat,
        string $name,
    ): void {
        $this->reports->expects($this->once())
            ->method('actions')
            ->with(
                $method,
                $flat,
                7,
                $this->period(),
                'segment-hash',
                'en',
            )
            ->willReturn([
                ['label' => 'one', 'nb_visits' => 2],
                ['label' => 'two', 'nb_visits' => '3'],
            ]);

        $report = $this->provider->report($uniqueId, 7, $this->period(), 'segment-hash', 'en');

        $this->assertNotNull($report);
        $this->assertSame(5, $report->metricTotal);
        $this->assertSame($name, $report->metadata['name']);
        $this->assertSame('General_ColumnNbVisits', $report->metadata['metrics']['nb_visits'] ?? null);
    }

    /** @return iterable<string, array{string, string, bool, string}> */
    public static function actionReports(): iterable
    {
        yield 'page URLs' => ['Actions_getPageUrls', 'Actions.getPageUrls', false, 'Actions_PageUrls'];
        yield 'page titles' => ['Actions_getPageTitles', 'Actions.getPageTitles', false, 'Actions_PageTitles'];
        yield 'downloads' => ['Actions_getDownloads', 'Actions.getDownloads', true, 'General_Downloads'];
    }

    public function test_loads_country_report(): void
    {
        $this->reports->expects($this->once())
            ->method('countries')
            ->willReturn([
                ['label' => 'nz', 'nb_visits' => 4],
            ]);

        $report = $this->provider->report(
            'UserCountry_getCountry',
            7,
            $this->period(),
            'segment-hash',
            'en',
        );

        $this->assertNotNull($report);
        $this->assertSame(4, $report->metricTotal);
        $this->assertSame('UserCountry_Country', $report->metadata['name']);
    }

    public function test_returns_null_for_an_unregistered_report(): void
    {
        $this->reports->expects($this->never())->method('actions');
        $this->reports->expects($this->never())->method('countries');

        $this->assertNull($this->provider->report(
            'Unknown_getReport',
            7,
            $this->period(),
            'segment-hash',
            'en',
        ));
    }

    public function test_reports_supported_source_ids(): void
    {
        $this->assertTrue($this->provider->supports('Actions_getPageUrls'));
        $this->assertTrue($this->provider->supports('UserCountry_getCountry'));
        $this->assertTrue($this->provider->supports('Referrers_getWebsites'));
        $this->assertFalse($this->provider->supports('Unknown_getReport'));
    }

    #[DataProvider('referrerReports')]
    public function test_loads_referrer_reports(
        string $uniqueId,
        string $record,
        string $action,
        string $name,
    ): void {
        $this->reports->expects($this->once())
            ->method('referrers')
            ->with($record, 7, $this->period(), 'segment-hash')
            ->willReturn([['label' => 'example', 'nb_visits' => 6]]);

        $report = $this->provider->report($uniqueId, 7, $this->period(), 'segment-hash', 'en');

        $this->assertNotNull($report);
        $this->assertSame(6, $report->metricTotal);
        $this->assertSame($action, $report->metadata['action']);
        $this->assertSame($name, $report->metadata['name']);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function referrerReports(): iterable
    {
        yield 'websites' => [
            'Referrers_getWebsites', 'Referrers_urlByWebsite', 'getWebsites', 'Referrers_Websites',
        ];
        yield 'campaigns' => [
            'Referrers_getCampaigns', 'Referrers_keywordByCampaign', 'getCampaigns', 'Referrers_Campaigns',
        ];
        yield 'socials' => [
            'Referrers_getSocials', 'Referrers_urlBySocialNetwork', 'getSocials', 'Referrers_Socials',
        ];
        yield 'search engines' => [
            'Referrers_getSearchEngines',
            'Referrers_keywordBySearchEngine',
            'getSearchEngines',
            'Referrers_SearchEngines',
        ];
        yield 'AI assistants' => [
            'Referrers_getAIAssistants',
            'Referrers_entryUrlByAIAssistant',
            'getAIAssistants',
            'Referrers_AIAssistants',
        ];
    }

    public function test_limits_comparison_rows_but_keeps_the_full_metric_total(): void
    {
        $rows = [];

        for ($index = 0; $index < 1001; $index++) {
            $rows[] = ['label' => 'row-'.$index, 'nb_visits' => 1];
        }

        $this->reports->method('actions')->willReturn($rows);
        $report = $this->provider->report(
            'Actions_getPageUrls',
            7,
            $this->period(),
            'segment-hash',
            'en',
        );

        $this->assertNotNull($report);
        $this->assertCount(1000, $report->rows);
        $this->assertSame(1001, $report->metricTotal);
    }

    private function period(): ReportingPeriod
    {
        return new ReportingPeriod('day', 1, '2026-08-14', '2026-08-14', '2026-08-14');
    }
}
