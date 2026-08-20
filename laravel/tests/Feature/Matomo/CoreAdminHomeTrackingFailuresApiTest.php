<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Api\OptOutEmbedRequest;
use App\Matomo\Archiving\ArchiveInvalidationManager;
use App\Matomo\Archiving\ArchiveReportRequest;
use App\Matomo\Archiving\ArchiveReportResult;
use App\Matomo\Archiving\ReportArchiver;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\CoreAdmin\BrandingManager;
use App\Matomo\CoreAdmin\CoreAdminSettings;
use App\Matomo\CoreAdmin\OptOutEmbedCodeGenerator;
use App\Matomo\Scheduling\ScheduledTaskRunner;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\TrackingFailures\Events\TrackingFailuresMakingHumanReadable;
use App\Matomo\TrackingFailures\TrackingFailureRepository;
use App\Matomo\UserChanges\UserChangeReadRepository;
use App\Support\MatomoProductUrl;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

class CoreAdminHomeTrackingFailuresApiTest extends TestCase
{
    public function test_superuser_lists_localized_tracking_failures_and_extensions_can_enrich_them(): void
    {
        $this->authenticate(true);
        $failures = $this->createMock(TrackingFailureRepository::class);
        $failures->expects($this->once())->method('all')->willReturn([
            $this->failure(7, 1, 'url=https%3A%2F%2Fshop.example%2Fthanks'),
            $this->failure(99, 2, 'url%5Bnested%5D=ignored'),
        ]);
        $this->app->instance(TrackingFailureRepository::class, $failures);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('details')->willReturnMap([
            [7, ['name' => 'Shop']],
            [99, []],
        ]);
        $this->app->instance(SiteRepository::class, $sites);
        Event::listen(
            TrackingFailuresMakingHumanReadable::class,
            static function (TrackingFailuresMakingHumanReadable $event): void {
                $event->failures[0]['extension'] = 'added';
            },
        );

        $response = $this->get($this->url('getTrackingFailures'))->assertOk();

        $response->assertJsonPath('0.site_name', 'Shop');
        $response->assertJsonPath('0.pretty_date_first_occurred', 'Aug 15, 2026 18:37:09');
        $response->assertJsonPath('0.url', 'https://shop.example/thanks');
        $response->assertJsonPath('0.problem', 'The site does not exist.');
        $response->assertJsonPath('0.solution', 'Update the configured idSite in the tracker.');
        $response->assertJsonPath('0.solution_url', MatomoProductUrl::https('faq/how-to/faq_30838/'));
        $response->assertJsonPath('0.extension', 'added');
        $response->assertJsonPath('1.site_name', 'Unknown');
        $response->assertJsonPath('1.url', '');
        $response->assertJsonPath(
            '1.problem',
            'Request was not authenticated but authentication was required.',
        );
        $response->assertJsonPath(
            '1.solution_url',
            MatomoProductUrl::https('faq/how-to/faq_30835/'),
        );
    }

    public function test_admin_lists_and_deletes_only_failures_for_administered_sites(): void
    {
        $this->authenticate(false, true, [7]);
        $failures = $this->createMock(TrackingFailureRepository::class);
        $failures->expects($this->once())->method('forSites')->with([7])->willReturn([]);
        $failures->expects($this->once())->method('deleteForSites')->with([7]);
        $failures->expects($this->never())->method('all');
        $failures->expects($this->never())->method('deleteAll');
        $this->app->instance(TrackingFailureRepository::class, $failures);

        $this->get($this->url('getTrackingFailures'))->assertOk()->assertExactJson([]);
        $this->post($this->url('deleteAllTrackingFailures'))
            ->assertOk()->assertExactJson(['result' => 'success', 'message' => 'ok']);
    }

    public function test_superuser_can_delete_all_failures(): void
    {
        $this->authenticate(true);
        $failures = $this->createMock(TrackingFailureRepository::class);
        $failures->expects($this->once())->method('deleteAll');
        $failures->expects($this->never())->method('deleteForSites');
        $this->app->instance(TrackingFailureRepository::class, $failures);

        $this->post($this->url('deleteAllTrackingFailures'))
            ->assertOk()->assertExactJson(['result' => 'success', 'message' => 'ok']);
    }

    public function test_admin_can_delete_one_failure_only_for_an_administered_site(): void
    {
        $this->authenticate(false, true, [7]);
        $failures = $this->createMock(TrackingFailureRepository::class);
        $failures->expects($this->once())->method('delete')->with(7, 'opaque-id');
        $this->app->instance(TrackingFailureRepository::class, $failures);

        $this->post($this->url('deleteTrackingFailure', [
            'idSite' => '7',
            'idFailure' => 'opaque-id',
        ]))->assertOk()->assertExactJson(['result' => 'success', 'message' => 'ok']);

        $this->post($this->url('deleteTrackingFailure', [
            'idSite' => '8',
            'idFailure' => '1',
        ]))->assertStatus(401)->assertJsonPath(
            'message',
            "You can't access this resource as it requires 'admin' access for the website id = 8.",
        );
    }

    public function test_listing_and_delete_all_require_some_admin_access(): void
    {
        $this->authenticate(false, false);
        $failures = $this->createMock(TrackingFailureRepository::class);
        $this->app->instance(TrackingFailureRepository::class, $failures);

        foreach (['getTrackingFailures', 'deleteAllTrackingFailures'] as $method) {
            $this->post($this->url($method))->assertStatus(401)->assertJsonPath(
                'message',
                "You can't access this resource as it requires admin access for at least one website.",
            );
        }
    }

    public function test_delete_one_requires_site_and_failure_parameters_in_contract_order(): void
    {
        $this->authenticate(true);

        $this->post($this->url('deleteTrackingFailure'))
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'idSite'.");
        $this->post($this->url('deleteTrackingFailure', ['idSite' => '7']))
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'idFailure'.");
    }

    public function test_authenticated_viewer_can_mark_all_visible_changes_read(): void
    {
        $this->authenticate(false, false, [], true, 'alice');
        $changes = $this->createMock(UserChangeReadRepository::class);
        $changes->expects($this->once())->method('markAllRead')->with('alice')->willReturn(true);
        $this->app->instance(UserChangeReadRepository::class, $changes);

        $this->post($this->url('whatIsNewMarkAllChangesReadForCurrentUser'))
            ->assertOk()->assertExactJson(['value' => true]);
    }

    public function test_marking_changes_read_requires_view_access_first(): void
    {
        $this->authenticate(false, false, [], false, 'anonymous');

        $this->post($this->url('whatIsNewMarkAllChangesReadForCurrentUser'))
            ->assertStatus(401)
            ->assertJsonPath('message', 'You must have view access to at least one website.');
    }

    public function test_marking_changes_read_rejects_anonymous_viewer(): void
    {
        $this->authenticate(false, false, [], true, 'anonymous');

        $this->post($this->url('whatIsNewMarkAllChangesReadForCurrentUser'))
            ->assertStatus(401)
            ->assertJsonPath('message', 'You must be logged in to access this functionality.');
    }

    public function test_superuser_can_update_archive_settings_and_trusted_hosts(): void
    {
        $this->authenticate(true);
        $settings = $this->createMock(CoreAdminSettings::class);
        $settings->expects($this->exactly(2))->method('generalSettingsAdminEnabled')->willReturn(true);
        $settings->expects($this->once())->method('configureArchiving')->with(false, 7200);
        $settings->expects($this->once())->method('replaceTrustedHosts')->with([
            'analytics.example',
            'reports.example:8443',
        ]);
        $this->app->instance(CoreAdminSettings::class, $settings);

        $this->post($this->url('setArchiveSettings'), [
            'enableBrowserTriggerArchiving' => '0',
            'todayArchiveTimeToLive' => '7200',
        ])->assertOk()->assertExactJson(['value' => true]);
        $this->post($this->url('setTrustedHosts'), [
            'trustedHosts' => ['analytics.example', 'reports.example:8443'],
        ])->assertOk()->assertExactJson(['value' => true]);
    }

    public function test_archive_settings_reject_invalid_time_to_live_before_writing(): void
    {
        $this->authenticate(true);
        $settings = $this->createMock(CoreAdminSettings::class);
        $settings->method('generalSettingsAdminEnabled')->willReturn(true);
        $settings->expects($this->never())->method('configureArchiving');
        $this->app->instance(CoreAdminSettings::class, $settings);

        $this->post($this->url('setArchiveSettings'), [
            'enableBrowserTriggerArchiving' => '1',
            'todayArchiveTimeToLive' => '0',
        ])->assertBadRequest()->assertJsonPath(
            'message',
            'Today archive time to live must be a number of seconds greater than zero',
        );
    }

    public function test_settings_updates_require_superuser_and_enabled_admin_setting(): void
    {
        $this->authenticate(false);
        $settings = $this->createMock(CoreAdminSettings::class);
        $settings->expects($this->never())->method('replaceTrustedHosts');
        $this->app->instance(CoreAdminSettings::class, $settings);

        $this->post($this->url('setTrustedHosts'), [
            'trustedHosts' => ['analytics.example'],
        ])->assertStatus(401)->assertJsonPath(
            'message',
            "You can't access this resource as it requires a 'superuser' access.",
        );
    }

    public function test_settings_updates_stop_when_general_admin_is_disabled(): void
    {
        $this->authenticate(true);
        $settings = $this->createMock(CoreAdminSettings::class);
        $settings->method('generalSettingsAdminEnabled')->willReturn(false);
        $settings->expects($this->never())->method('replaceTrustedHosts');
        $this->app->instance(CoreAdminSettings::class, $settings);

        $this->post($this->url('setTrustedHosts'), [
            'trustedHosts' => ['analytics.example'],
        ])->assertBadRequest()->assertJsonPath(
            'message',
            'General settings admin is not enabled',
        );
    }

    public function test_settings_updates_require_all_parameters(): void
    {
        $this->authenticate(true);

        $this->post($this->url('setArchiveSettings'))
            ->assertBadRequest()
            ->assertJsonPath(
                'message',
                "Please specify a value for 'enableBrowserTriggerArchiving'.",
            );
        $this->post($this->url('setArchiveSettings'), [
            'enableBrowserTriggerArchiving' => '1',
        ])->assertBadRequest()->assertJsonPath(
            'message',
            "Please specify a value for 'todayArchiveTimeToLive'.",
        );
        $this->post($this->url('setTrustedHosts'))
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'trustedHosts'.");
        $this->post($this->url('setArchiveSettings'), [
            'enableBrowserTriggerArchiving' => ['nested'],
            'todayArchiveTimeToLive' => '7200',
        ])->assertBadRequest();
        $this->post($this->url('setTrustedHosts'), [
            'trustedHosts' => [['nested']],
        ])->assertBadRequest();
    }

    public function test_superuser_can_publish_branding_settings(): void
    {
        $this->authenticate(true);
        $branding = $this->createMock(BrandingManager::class);
        $branding->expects($this->once())->method('update')->with(
            'alice',
            true,
            true,
            false,
        )->willReturn([
            'useCustomLogo' => true,
            'customLogoPath' => 'misc/user/logo.png',
        ]);
        $this->app->instance(BrandingManager::class, $branding);

        $this->post($this->url('setBrandingSettings'), [
            'useCustomLogo' => '1',
            'hasCustomLogo' => '1',
            'hasCustomFavicon' => '0',
        ])->assertOk()->assertExactJson([
            'useCustomLogo' => true,
            'customLogoPath' => 'misc/user/logo.png',
        ]);
    }

    public function test_branding_settings_require_superuser_and_all_boolean_parameters(): void
    {
        $this->authenticate(false);
        $branding = $this->createMock(BrandingManager::class);
        $branding->expects($this->never())->method('update');
        $this->app->instance(BrandingManager::class, $branding);

        $this->post($this->url('setBrandingSettings'), [
            'useCustomLogo' => '1',
            'hasCustomLogo' => '1',
            'hasCustomFavicon' => '0',
        ])->assertStatus(401);
    }

    public function test_branding_settings_reject_missing_and_nested_boolean_parameters(): void
    {
        $this->authenticate(true);

        $this->post($this->url('setBrandingSettings'))
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'useCustomLogo'.");
        $this->post($this->url('setBrandingSettings'), [
            'useCustomLogo' => '1',
        ])->assertBadRequest()->assertJsonPath(
            'message',
            "Please specify a value for 'hasCustomLogo'.",
        );
        $this->post($this->url('setBrandingSettings'), [
            'useCustomLogo' => '1',
            'hasCustomLogo' => '1',
        ])->assertBadRequest()->assertJsonPath(
            'message',
            "Please specify a value for 'hasCustomFavicon'.",
        );
        $this->post($this->url('setBrandingSettings'), [
            'useCustomLogo' => ['nested'],
            'hasCustomLogo' => '1',
            'hasCustomFavicon' => '1',
        ])->assertBadRequest();
    }

    public function test_javascript_opt_out_embed_is_public_and_parses_all_parameters(): void
    {
        $this->authenticate(false);
        $generator = $this->createMock(OptOutEmbedCodeGenerator::class);
        $generator->expects($this->once())->method('javascript')->with(
            $this->callback(static fn (OptOutEmbedRequest $options): bool => $options == new OptOutEmbedRequest(
                backgroundColor: 'fff',
                fontColor: '123',
                fontSize: '15px',
                fontFamily: 'Arial',
                applyStyling: true,
                showIntro: false,
                matomoUrl: 'https://analytics.example/',
                language: 'fr',
            )),
        )->willReturn('<script>external</script>');
        $this->app->instance(OptOutEmbedCodeGenerator::class, $generator);

        $this->post($this->url('getOptOutJSEmbedCode'), [
            'backgroundColor' => 'fff',
            'fontColor' => '123',
            'fontSize' => '15px',
            'fontFamily' => 'Arial',
            'applyStyling' => '1',
            'showIntro' => '0',
            'matomoUrl' => 'https://analytics.example/',
            'language' => 'fr',
        ])->assertOk()->assertExactJson(['value' => '<script>external</script>']);
    }

    public function test_self_contained_opt_out_embed_uses_defaults_and_cookie_settings(): void
    {
        $this->authenticate(false);
        $generator = $this->createMock(OptOutEmbedCodeGenerator::class);
        $generator->expects($this->once())->method('selfContained')->with(
            $this->callback(static fn (OptOutEmbedRequest $options): bool => $options == new OptOutEmbedRequest(
                backgroundColor: '',
                fontColor: '',
                fontSize: '',
                fontFamily: '',
                applyStyling: false,
                showIntro: true,
                cookiePath: '/privacy',
                cookieDomain: '.example.test',
                cookieSameSite: 'Strict',
            )),
            'en',
        )->willReturn('<script>inline</script>');
        $this->app->instance(OptOutEmbedCodeGenerator::class, $generator);

        $this->post($this->url('getOptOutSelfContainedEmbedCode'), [
            'backgroundColor' => '',
            'fontColor' => '',
            'fontSize' => '',
            'fontFamily' => '',
            'cookiePath' => '/privacy',
            'cookieDomain' => '.example.test',
            'cookieSameSite' => 'Strict',
        ])->assertOk()->assertExactJson(['value' => '<script>inline</script>']);
    }

    public function test_opt_out_embed_parameters_are_required_in_signature_order(): void
    {
        $this->authenticate(false);
        $this->post($this->url('getOptOutJSEmbedCode'))
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'backgroundColor'.");
        $this->post($this->url('getOptOutJSEmbedCode'), [
            'backgroundColor' => '',
            'fontColor' => '',
            'fontSize' => '',
            'fontFamily' => '',
        ])->assertBadRequest()->assertJsonPath(
            'message',
            "Please specify a value for 'applyStyling'.",
        );
        $this->post($this->url('getOptOutSelfContainedEmbedCode'), [
            'backgroundColor' => '',
            'fontColor' => '',
            'fontSize' => '',
        ])->assertBadRequest()->assertJsonPath(
            'message',
            "Please specify a value for 'fontFamily'.",
        );
    }

    public function test_admin_can_invalidate_archive_ranges_with_all_options(): void
    {
        $this->authenticate(false, true, [7, 8]);
        $invalidations = $this->createMock(ArchiveInvalidationManager::class);
        $invalidations->expects($this->once())->method('invalidate')->with(
            [7, 8],
            ['2026-08-01,2026-08-31'],
            'range',
            'countryCode==fr',
            true,
            true,
        )->willReturn(['Success.']);
        $this->app->instance(ArchiveInvalidationManager::class, $invalidations);

        $this->post($this->url('invalidateArchivedReports'), [
            'idSites' => '7,8',
            'dates' => '2026-08-01,2026-08-31',
            'period' => 'range',
            'segment' => 'countryCode==fr',
            'cascadeDown' => '1',
            '_forceInvalidateNonexistent' => '1',
        ])->assertOk()->assertExactJson(['Success.']);
    }

    public function test_archive_invalidation_resolves_all_sites_and_requires_admin_access(): void
    {
        $this->authenticate(false, true, [7], viewSiteIds: [7, 8]);
        $invalidations = $this->createMock(ArchiveInvalidationManager::class);
        $invalidations->expects($this->never())->method('invalidate');
        $this->app->instance(ArchiveInvalidationManager::class, $invalidations);

        $this->post($this->url('invalidateArchivedReports'), [
            'idSites' => 'all',
            'dates' => '2026-08-15',
        ])->assertStatus(401)->assertJsonPath(
            'message',
            "You can't access this resource as it requires 'admin' access for the website id = 8.",
        );
    }

    public function test_archive_invalidation_rejects_invalid_sites_and_missing_dates(): void
    {
        $this->authenticate(false, true, [7]);

        $this->post($this->url('invalidateArchivedReports'), [
            'idSites' => '7,nope',
            'dates' => '2026-08-15',
        ])->assertBadRequest()->assertJsonPath(
            'message',
            "The parameter 'idSite=' contains an invalid value.",
        );
        $this->post($this->url('invalidateArchivedReports'), [
            'idSites' => '7',
        ])->assertBadRequest()->assertJsonPath(
            'message',
            "Please specify a value for 'dates'.",
        );
        $this->post($this->url('invalidateArchivedReports'), [
            'idSites' => ',,',
            'dates' => '2026-08-15',
        ])->assertBadRequest()->assertJsonPath(
            'message',
            "Specify a value for &idSites= as a comma separated list of website IDs, for which your token_auth has 'admin' permission",
        );
    }

    public function test_superuser_can_run_scheduled_tasks(): void
    {
        $this->authenticate(true);
        $runner = $this->createMock(ScheduledTaskRunner::class);
        $runner->expects($this->once())->method('run')->willReturn([
            ['task' => 'CoreAdmin.cleanup', 'output' => 'Time elapsed: 0.001s'],
        ]);
        $this->app->instance(ScheduledTaskRunner::class, $runner);

        $this->post($this->url('runScheduledTasks'))
            ->assertOk()
            ->assertExactJson([
                ['task' => 'CoreAdmin.cleanup', 'output' => 'Time elapsed: 0.001s'],
            ]);
    }

    public function test_non_superuser_cannot_run_scheduled_tasks(): void
    {
        $this->authenticate(false, true, [7]);
        $runner = $this->createMock(ScheduledTaskRunner::class);
        $runner->expects($this->never())->method('run');
        $this->app->instance(ScheduledTaskRunner::class, $runner);

        $this->post($this->url('runScheduledTasks'))
            ->assertStatus(401)
            ->assertJsonPath(
                'message',
                "You can't access this resource as it requires a 'superuser' access.",
            );
    }

    public function test_superuser_can_archive_reports_with_the_legacy_contract(): void
    {
        $this->authenticate(true);
        $archiver = $this->createMock(ReportArchiver::class);
        $archiver->expects($this->once())->method('archive')->with(
            $this->callback(static fn (ArchiveReportRequest $request): bool => $request == new ArchiveReportRequest(
                siteId: 7,
                period: 'week',
                date: '2026-08-15',
                segment: 'countryCode==fr',
                plugin: 'VisitsSummary',
                reports: ['get', 'getVisits'],
                force: true,
            )),
        )->willReturn(new ArchiveReportResult([41, 42], 12.5, false));
        $this->app->instance(ReportArchiver::class, $archiver);

        $this->post($this->url('archiveReports'), [
            'idSite' => '7',
            'period' => 'WEEK',
            'date' => '2026-08-15',
            'segment' => 'countryCode==fr',
            'plugin' => 'VisitsSummary',
            'report' => ['get', 'getVisits'],
        ])->assertOk()->assertExactJson([
            'idarchives' => [41, 42],
            'nb_visits' => 12.5,
        ]);
    }

    public function test_archive_reports_requires_superuser_access(): void
    {
        $this->authenticate(false, true, [7]);
        $archiver = $this->createMock(ReportArchiver::class);
        $archiver->expects($this->never())->method('archive');
        $this->app->instance(ReportArchiver::class, $archiver);

        $this->post($this->url('archiveReports'), [
            'idSite' => '7',
            'period' => 'day',
            'date' => '2026-08-15',
        ])->assertStatus(401)->assertJsonPath(
            'message',
            "You can't access this resource as it requires a 'superuser' access.",
        );
    }

    public function test_archive_reports_validates_parameters_and_archiver_errors(): void
    {
        $this->authenticate(true);
        $archiver = $this->createMock(ReportArchiver::class);
        $archiver->expects($this->once())->method('archive')->willThrowException(
            new InvalidArgumentException('The website id = 7 does not exist.'),
        );
        $this->app->instance(ReportArchiver::class, $archiver);

        $this->post($this->url('archiveReports'))
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'idSite'.");
        $this->post($this->url('archiveReports'), ['idSite' => '7'])
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'period'.");
        $this->post($this->url('archiveReports'), [
            'idSite' => '7',
            'period' => 'quarter',
            'date' => '2026-08-15',
        ])->assertBadRequest()->assertJsonPath(
            'message',
            "The period 'quarter' is not supported.",
        );

        $this->post($this->url('archiveReports'), [
            'idSite' => '7',
            'period' => 'day',
            'date' => '2026-08-15',
        ])->assertBadRequest()->assertJsonPath(
            'message',
            'The website id = 7 does not exist.',
        );
    }

    /**
     * @param  list<int>  $adminSiteIds
     * @param  list<int>  $viewSiteIds
     */
    private function authenticate(
        bool $superuser,
        bool $hasSomeAdminAccess = false,
        array $adminSiteIds = [],
        bool $hasSomeViewAccess = false,
        string $login = 'alice',
        array $viewSiteIds = [],
    ): void {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn($login);
        $authorizer->method('hasSuperUserAccess')->willReturn($superuser);
        $authorizer->method('hasSomeAdminAccess')->willReturn($hasSomeAdminAccess);
        $authorizer->method('hasSomeViewAccess')->willReturn($hasSomeViewAccess);
        $authorizer->method('siteIdsWithRole')->with(
            $this->anything(),
            SiteAccessRole::Admin,
        )->willReturn($adminSiteIds);
        $authorizer->method('siteIdsWithAtLeastViewAccess')->willReturn($viewSiteIds);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    /** @return array{idsite: int, idfailure: int, date_first_occurred: string, request_url: string} */
    private function failure(int $siteId, int $failureId, string $requestUrl): array
    {
        return [
            'idsite' => $siteId,
            'idfailure' => $failureId,
            'date_first_occurred' => '2026-08-15 18:37:09',
            'request_url' => $requestUrl,
        ];
    }

    /** @param array<string, string> $parameters */
    private function url(string $method, array $parameters = []): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'CoreAdminHome.'.$method,
            'format' => 'json',
            'token_auth' => 'token',
            ...$parameters,
        ]);
    }
}
