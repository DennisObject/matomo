<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\TrackingFailures\Events\TrackingFailuresMakingHumanReadable;
use App\Matomo\TrackingFailures\TrackingFailureRepository;
use App\Matomo\UserChanges\UserChangeReadRepository;
use App\Support\MatomoProductUrl;
use Illuminate\Support\Facades\Event;
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

    /** @param list<int> $adminSiteIds */
    private function authenticate(
        bool $superuser,
        bool $hasSomeAdminAccess = false,
        array $adminSiteIds = [],
        bool $hasSomeViewAccess = false,
        string $login = 'alice',
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
