<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Reporting\ReportingSettings;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Reporting\VisitsSummaryArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VisitsSummaryApiTest extends TestCase
{
    public function test_returns_the_default_metrics_and_processed_metrics_from_archives(): void
    {
        $this->bindViewAccess([7]);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())->method('timezone')->with(7)->willReturn('Pacific/Auckland');
        $archives = $this->createMock(VisitsSummaryArchiveRepository::class);
        $archives->expects($this->once())
            ->method('metrics')
            ->with(
                [7],
                $this->callback(static fn (array $periods): bool => count($periods) === 1
                    && $periods[0] instanceof ReportingPeriod
                    && $periods[0]->rangeKey() === '2026-08-14,2026-08-14'),
                '',
                [
                    'nb_uniq_visitors',
                    'nb_users',
                    'nb_visits',
                    'nb_actions',
                    'nb_visits_converted',
                    'bounce_count',
                    'sum_visit_length',
                    'max_actions',
                ],
            )
            ->willReturn([
                7 => ['2026-08-14,2026-08-14' => [
                    'nb_uniq_visitors' => 2,
                    'nb_users' => 1,
                    'nb_visits' => 4,
                    'nb_actions' => 10,
                    'nb_visits_converted' => 1,
                    'bounce_count' => 1,
                    'sum_visit_length' => 20,
                    'max_actions' => 5,
                ]],
            ]);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=VisitsSummary.get&idSite=7'.
            '&period=day&date=2026-08-14&format=json&token_auth=view-token',
        )->assertOk()
            ->assertHeader('Content-Type', 'application/json; charset=utf-8')
            ->assertContent(
                '{"nb_uniq_visitors":2,"nb_users":1,"nb_visits":4,"nb_actions":10,'.
                '"nb_visits_converted":1,"bounce_count":1,"sum_visit_length":20,'.
                '"max_actions":5,"bounce_rate":"25%","nb_actions_per_visit":2.5,'.
                '"avg_time_on_site":5}',
            );
    }

    public function test_columns_fetch_only_dependencies_and_keep_legacy_column_order(): void
    {
        $this->bindViewAccess([7]);
        $sites = $this->createMock(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $archives = $this->createMock(VisitsSummaryArchiveRepository::class);
        $archives->expects($this->once())
            ->method('metrics')
            ->with(
                [7],
                $this->isType('array'),
                '',
                ['bounce_count', 'nb_visits'],
            )
            ->willReturn([
                7 => ['2026-08-14,2026-08-14' => ['bounce_count' => 1, 'nb_visits' => 4]],
            ]);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=VisitsSummary.get&idSite=7&period=day'.
            '&date=2026-08-14&columns=bounce_rate,nb_visits&format=json&token_auth=view-token',
        )->assertOk()
            ->assertContent('{"nb_visits":4,"bounce_rate":"25%"}');
    }

    public function test_multiple_sites_and_dates_keep_the_nested_xml_contract(): void
    {
        $this->bindViewAccess([1, 2]);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('timezone');
        $archives = $this->createMock(VisitsSummaryArchiveRepository::class);
        $archives->expects($this->once())->method('metrics')->willReturn([
            1 => [
                '2026-08-13,2026-08-13' => ['nb_visits' => 2],
                '2026-08-14,2026-08-14' => ['nb_visits' => 3],
            ],
            2 => ['2026-08-14,2026-08-14' => ['nb_visits' => 1]],
        ]);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=VisitsSummary.get&idSite=1,2&period=day'.
            '&date=2026-08-13,2026-08-14&columns=nb_visits&format=xml&token_auth=view-token',
        )->assertOk()
            ->assertContent(<<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<results>
	<result idSite="1">
		<result date="2026-08-13">
			<nb_visits>2</nb_visits>
		</result>
		<result date="2026-08-14">
			<nb_visits>3</nb_visits>
		</result>
	</result>
	<result idSite="2">
		<result date="2026-08-13">
			<nb_visits>0</nb_visits>
		</result>
		<result date="2026-08-14">
			<nb_visits>1</nb_visits>
		</result>
	</result>
</results>
XML);
    }

    public function test_checks_every_site_before_reading_site_or_archive_data(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->exactly(2))
            ->method('hasViewAccessToSite')
            ->willReturnCallback(static fn (ApiAuthentication $authentication, int $idSite): bool => $idSite === 1);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('timezone');
        $archives = $this->createMock(VisitsSummaryArchiveRepository::class);
        $archives->expects($this->never())->method('metrics');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=VisitsSummary.get&idSite=1,2&period=day'.
            '&date=2026-08-14&format=json&token_auth=view-token',
        )->assertUnauthorized()
            ->assertExactJson([
                'result' => 'error',
                'message' => "You can't access this resource as it requires 'view' access for the website id = 2.",
            ]);
    }

    public function test_all_sites_uses_the_access_filtered_site_list_without_rechecking_each_site(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithAtLeastViewAccess')
            ->with(
                $this->isInstanceOf(ApiAuthentication::class),
                'viewer',
            )
            ->willReturn([2, 4]);
        $authorizer->expects($this->never())->method('hasViewAccessToSite');
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('timezone');
        $archives = $this->createMock(VisitsSummaryArchiveRepository::class);
        $archives->expects($this->once())
            ->method('metrics')
            ->with(
                [2, 4],
                $this->isType('array'),
                '',
                ['nb_visits'],
            )
            ->willReturn([
                2 => ['2026-08-14,2026-08-14' => ['nb_visits' => 3]],
                4 => ['2026-08-14,2026-08-14' => ['nb_visits' => 5]],
            ]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=VisitsSummary.get&idSite=all&period=day'.
            '&date=2026-08-14&columns=nb_visits&format=json&_restrictSitesToLogin=viewer',
        )->assertOk()
            ->assertExactJson([
                2 => ['nb_visits' => 3],
                4 => ['nb_visits' => 5],
            ]);
    }

    public function test_denies_anonymous_segments_before_resolving_or_reading_report_data(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasViewAccessToSite')->willReturn(true);
        $authorizer->expects($this->once())->method('authenticatedLogin')->willReturn('anonymous');
        $settings = $this->createMock(ReportingSettings::class);
        $settings->expects($this->once())->method('periodEnabled')->with('day')->willReturn(true);
        $settings->expects($this->once())->method('anonymousSegmentsEnabled')->willReturn(false);
        $segments = $this->createMock(SegmentHashResolver::class);
        $segments->expects($this->never())->method('resolve');
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('timezone');
        $archives = $this->createMock(VisitsSummaryArchiveRepository::class);
        $archives->expects($this->never())->method('metrics');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(ReportingSettings::class, $settings);
        $this->app->instance(SegmentHashResolver::class, $segments);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=VisitsSummary.get&idSite=7&period=day'.
            '&date=2026-08-14&segment=countryCode%3D%3DNZ&format=json',
        )->assertUnauthorized()
            ->assertExactJson([
                'result' => 'error',
                'message' => 'The Super User has disabled the Segmentation feature.',
            ]);
    }

    public function test_rejects_missing_report_parameters_before_authorization(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasViewAccessToSite');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get('/index.php?module=API&method=VisitsSummary.get&period=day&date=today&format=json')
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => "Please specify a value for 'idSite'.",
            ]);
    }

    #[DataProvider('reportFormats')]
    public function test_formats_the_report_like_the_legacy_api(
        string $format,
        string $contentType,
        string $content,
        ?string $contentDisposition,
    ): void {
        $this->bindViewAccess([7]);
        $sites = $this->createMock(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $archives = $this->createMock(VisitsSummaryArchiveRepository::class);
        $archives->expects($this->once())->method('metrics')->willReturn([
            7 => ['2026-08-14,2026-08-14' => ['nb_visits' => 4, 'bounce_count' => 1]],
        ]);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $archives);

        $response = $this->get(
            '/index.php?module=API&method=VisitsSummary.get&idSite=7&period=day'.
            '&date=2026-08-14&columns=nb_visits,bounce_rate&'.$format.'&token_auth=view-token',
        )->assertOk()
            ->assertHeader('Content-Type', $contentType)
            ->assertContent($content);

        if ($contentDisposition !== null) {
            $response->assertHeader('Content-Disposition', $contentDisposition);
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string|null}>
     */
    public static function reportFormats(): iterable
    {
        $values = ['nb_visits' => 4, 'bounce_rate' => '25%'];
        $html = "<table border=\"1\">\n".
            "<thead>\n".
            "\t<tr>\n".
            "\t\t<th>nb_visits</th>\n".
            "\t\t<th>bounce_rate</th>\n".
            "\t</tr>\n".
            "</thead>\n".
            "<tbody>\n".
            "\t<tr>\n".
            "\t\t<td>4</td>\n".
            "\t\t<td>25%</td>\n".
            "\t</tr>\n".
            "</tbody>\n".
            "</table>\n";
        $disposition = "attachment; filename*=UTF-8''Export";

        yield 'CSV' => [
            'format=csv&convertToUnicode=0',
            'application/vnd.ms-excel',
            "nb_visits,bounce_rate\n4,25%",
            $disposition,
        ];
        yield 'TSV' => [
            'format=tsv&convertToUnicode=0',
            'application/vnd.ms-excel',
            "nb_visits\tbounce_rate\n4\t25%",
            $disposition,
        ];
        yield 'HTML' => ['format=html', 'text/html; charset=utf-8', $html, null];
        yield 'original' => [
            'format=original',
            'text/plain; charset=utf-8',
            var_export($values, true),
            null,
        ];
        yield 'serialized original' => [
            'format=original&serialize=1',
            'text/plain; charset=utf-8',
            serialize($values),
            null,
        ];
        yield 'console' => [
            'format=console',
            'text/plain; charset=utf-8',
            "- 1 ['nb_visits' => 4, 'bounce_rate' => '25%'] [] [idsubtable = ]<br />\n",
            null,
        ];
    }

    public function test_rss_stays_on_the_unmigrated_path(): void
    {
        $this->app->instance(
            ApiAccessAuthorizer::class,
            $this->createStub(ApiAccessAuthorizer::class),
        );

        $this->get(
            '/index.php?module=API&method=VisitsSummary.get&idSite=1'.
            '&period=day&date=2026-08-14&format=rss',
        )->assertStatus(501)
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertContent('Error: This API method has not moved to Laravel yet.');
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function bindViewAccess(array $siteIds): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->exactly(count($siteIds)))
            ->method('hasViewAccessToSite')
            ->with(
                $this->isInstanceOf(ApiAuthentication::class),
                $this->isType('int'),
            )
            ->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
