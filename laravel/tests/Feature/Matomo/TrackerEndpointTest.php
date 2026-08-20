<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Tracker\TrackingRequest;
use App\Matomo\Tracker\VisitRecorder;
use Tests\TestCase;

final class TrackerEndpointTest extends TestCase
{
    private string $configurationPath;

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam('/dev/shm', 'matomo-tracker-config-');
        $this->assertIsString($path);
        $this->configurationPath = $path;
        $this->assertNotFalse(file_put_contents($path, <<<'INI'
            [database]
            host = "database"
            username = "matomo"
            password = "password"
            dbname = "matomo"
            tables_prefix = "matomo_"
            adapter = "PDO\\MYSQL"

            [General]
            salt = "test-salt"

            [Tracker]
            INI));
        $this->app->make('config')->set('matomo.config_path', $path);
    }

    protected function tearDown(): void
    {
        unlink($this->configurationPath);

        parent::tearDown();
    }

    public function test_records_valid_page_view_and_returns_uncached_pixel(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->siteId === 1
                && $request->visitorId === '0123456789abcdef'
                && $request->ipAddress === '127.0.0.0',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url())
            ->assertOk()
            ->assertHeader('Content-Type', 'image/gif')
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertHeaderContains('Cache-Control', 'no-cache')
            ->assertHeaderContains('Cache-Control', 'must-revalidate');
    }

    public function test_requires_the_record_flag(): void
    {
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get('/matomo.php')->assertOk();
    }

    public function test_accepts_post_requests_on_both_tracker_entrypoints(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->exactly(2))->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->post('/matomo.php', $this->parameters())->assertOk();
        $this->post('/piwik.php', $this->parameters())->assertOk();
    }

    public function test_rejects_invalid_tracking_input(): void
    {
        $this->bindSite();
        $this->get('/matomo.php?rec=1&idsite=0&url=javascript%3Aalert%281%29')->assertBadRequest();
    }

    public function test_records_event_parameters(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->actionType === 10
                && $request->eventCategory === 'Video'
                && $request->eventAction === 'Play'
                && $request->eventName === 'Trailer'
                && $request->eventValue === 2.5,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'e_c' => 'Video',
            'e_a' => 'Play',
            'e_n' => 'Trailer',
            'e_v' => '2.5',
        ]))->assertOk();
    }

    public function test_records_bulk_tracking_requests(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->exactly(2))->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->post('/matomo.php', ['requests' => [
            '?rec=1&idsite=1&url=https%3A%2F%2Fexample.test%2Fa',
            '?rec=1&idsite=1&url=https%3A%2F%2Fexample.test%2Fb',
        ]])->assertOk()->assertHeader('Content-Type', 'image/gif');
    }

    public function test_rejects_oversized_bulk_request(): void
    {
        $this->bindSite();
        $requests = array_fill(0, 51, '?idsite=1&url=https%3A%2F%2Fexample.test');
        $this->post('/matomo.php', ['requests' => $requests])->assertBadRequest();
    }

    public function test_records_json_bulk_envelopes_and_skips_requests_without_rec(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->url === 'https://example.test/recorded'
                && $request->ipAddress === '127.0.0.0',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->postJson('/matomo.php', ['requests' => [
            '?rec=1&idsite=1&url=https%3A%2F%2Fexample.test%2Frecorded',
            '?idsite=1&url=https%3A%2F%2Fexample.test%2Fskipped',
        ]])->assertOk();
    }

    public function test_rejects_an_invalid_batch_before_recording_any_items(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->post('/matomo.php', ['requests' => [
            '?rec=1&idsite=1&url=https%3A%2F%2Fexample.test%2Fvalid',
            '?rec=1&idsite=0&url=https%3A%2F%2Fexample.test%2Finvalid',
        ]])->assertBadRequest();
    }

    public function test_applies_site_do_not_track_to_nested_requests(): void
    {
        $this->bindSite();
        $this->mutableOptions()->set('PrivacyManager.idSite(1).doNotTrackEnabled', '1');
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->withHeader('DNT', '1')->post('/matomo.php', ['requests' => [
            '?rec=1&idsite=1&url=https%3A%2F%2Fexample.test%2Fa',
        ]])->assertOk()->assertHeader('Tk', 'N');
    }

    public function test_rejects_incomplete_or_non_numeric_events(): void
    {
        $this->bindSite();

        $this->get($this->url(['e_c' => 'Video']))->assertBadRequest();
        $this->get($this->url(['e_c' => 'Video', 'e_a' => 'Play', 'e_v' => '1e9999']))
            ->assertBadRequest();
    }

    public function test_records_downloads_before_other_action_types(): void
    {
        $this->bindSite(['exclude_unknown_urls' => 1]);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->actionType === 3
                && $request->url === 'https://cdn.example.test/file.zip?token=kept'
                && $request->eventCategory === null,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'download' => 'https://cdn.example.test/file.zip?token=kept',
            'link' => 'https://other.test/',
            'e_c' => 'Video',
            'e_a' => 'Play',
        ]))->assertOk();
    }

    public function test_records_outlinks(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->actionType === 2
                && $request->url === 'https://other.test/destination',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['link' => 'https://other.test/destination']))->assertOk();
    }

    public function test_rejects_unknown_website_ids(): void
    {
        $this->bindSite([]);

        $this->get($this->url())->assertBadRequest()
            ->assertSeeText('The requested website does not exist.');
    }

    public function test_honors_do_not_track_only_when_enabled(): void
    {
        $this->mutableOptions()->set('PrivacyManager.doNotTrackEnabled', '1');
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->withHeader('DNT', '1')->get($this->url())
            ->assertOk()
            ->assertHeader('Tk', 'N');
    }

    public function test_records_do_not_track_request_when_support_is_disabled(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->withHeader('DNT', '1')->get($this->url())->assertOk()->assertHeaderMissing('Tk');
    }

    public function test_honors_only_a_valid_ignore_cookie(): void
    {
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->withUnencryptedCookie('matomo_ignore', '*')->get($this->url())->assertOk();
    }

    public function test_does_not_treat_an_unset_ignore_cookie_as_opt_out(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->withUnencryptedCookie('matomo_ignore', 'invalid')->get($this->url())->assertOk();
    }

    public function test_silently_excludes_configured_ip_addresses(): void
    {
        $this->bindSite(['excluded_ips' => '127.0.0.*']);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url())->assertOk();
    }

    public function test_removes_tracking_and_campaign_parameters_from_stored_url(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->url === 'https://example.test/page?keep=yes',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'url' => 'https://example.test/page?keep=yes&gclid=secret&utm_campaign=private#fragment',
        ]))->assertOk();
    }

    public function test_rejects_unknown_hosts_when_the_site_requires_known_urls(): void
    {
        $this->bindSite(['exclude_unknown_urls' => 1]);

        $this->get($this->url(['url' => 'https://other.test/page']))
            ->assertBadRequest()
            ->assertSeeText('url does not belong to the requested website.');
    }

    /** @param array<string, int|string|null> $overrides */
    private function bindSite(array $overrides = ['idsite' => 1]): void
    {
        $details = $overrides === [] ? [] : [
            'idsite' => 1,
            'main_url' => 'https://example.test',
            'exclude_unknown_urls' => 0,
            'excluded_ips' => '',
            'excluded_user_agents' => '',
            'excluded_parameters' => '',
            'keep_url_fragment' => 0,
            ...$overrides,
        ];
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('details')->willReturn($details);
        $sites->method('urls')->willReturn(['https://example.test']);
        $this->app->instance(SiteRepository::class, $sites);
    }

    private function mutableOptions(): MutableOptionRepository
    {
        return $this->app->make(MutableOptionRepository::class);
    }

    /** @param array<string, string> $parameters */
    private function url(array $parameters = []): string
    {
        return '/matomo.php?'.http_build_query($this->parameters($parameters));
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, string>
     */
    private function parameters(array $parameters = []): array
    {
        return [
            'rec' => '1',
            'idsite' => '1',
            'url' => 'https://example.test/page',
            '_id' => '0123456789abcdef',
            ...$parameters,
        ];
    }
}
