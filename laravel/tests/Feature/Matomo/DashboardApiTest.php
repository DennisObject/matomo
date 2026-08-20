<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Api\Methods\ApiMethodDispatcher;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Dashboard\DashboardLayoutProvider;
use App\Matomo\Dashboard\DashboardRecipientPolicy;
use App\Matomo\Dashboard\DashboardRepository;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    public function test_anonymous_user_receives_the_default_dashboard_only_when_requested(): void
    {
        $this->authenticate(null);
        $layouts = new RecordingDashboardLayoutProvider;
        $this->app->instance(DashboardLayoutProvider::class, $layouts);

        $this->get($this->url('getDashboards'))
            ->assertOk()
            ->assertExactJson([[
                'name' => 'Dashboard',
                'id' => 1,
                'widgets' => [['module' => 'CoreHome', 'action' => 'defaultWidget']],
            ]]);

        $this->get($this->url('getDashboards', ['returnDefaultIfEmpty' => '0']))
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_user_can_read_own_dashboards_with_legacy_names_and_visible_widgets(): void
    {
        $this->authenticate('alice');
        $repository = new MemoryDashboardRepository([
            'alice' => [
                ['iddashboard' => 1, 'name' => 'Main &amp; More &copy;', 'layout' => 'first'],
                ['iddashboard' => 2, 'name' => null, 'layout' => 'second'],
                ['iddashboard' => 3, 'name' => '', 'layout' => 'third'],
            ],
        ]);
        $this->app->instance(DashboardRepository::class, $repository);
        $this->app->instance(DashboardLayoutProvider::class, new RecordingDashboardLayoutProvider([
            'first' => [['module' => 'VisitsSummary', 'action' => 'get']],
            'second' => [],
            'third' => [['module' => 'Live', 'action' => 'widget']],
        ]));

        $this->get($this->url('getDashboards'))
            ->assertOk()
            ->assertExactJson([
                [
                    'name' => 'Main & More &copy;',
                    'id' => 1,
                    'widgets' => [['module' => 'VisitsSummary', 'action' => 'get']],
                ],
                ['name' => 'Dashboard of alice', 'id' => 2, 'widgets' => []],
                [
                    'name' => 'Dashboard of alice (2)',
                    'id' => 3,
                    'widgets' => [['module' => 'Live', 'action' => 'widget']],
                ],
            ]);
    }

    public function test_user_cannot_read_or_change_another_users_dashboard(): void
    {
        $this->authenticate('alice');
        $message = "The user has to be either a Super User or the user 'bob' itself.";

        $this->get($this->url('getDashboards', ['login' => 'bob']))
            ->assertUnauthorized()
            ->assertJsonPath('message', $message);
        $this->post($this->url('removeDashboard'), ['idDashboard' => 2, 'login' => 'bob'])
            ->assertUnauthorized()
            ->assertJsonPath('message', $message);
    }

    public function test_user_can_create_remove_and_reset_own_dashboards(): void
    {
        $this->authenticate('alice');
        $repository = new MemoryDashboardRepository;
        $layouts = new RecordingDashboardLayoutProvider;
        $this->app->instance(DashboardRepository::class, $repository);
        $this->app->instance(DashboardLayoutProvider::class, $layouts);

        $this->post($this->url('createNewDashboardForUser'), [
            'login' => 'alice',
            'dashboardName' => "<Main> & \"Stats\"\nDetails",
        ])->assertOk()->assertExactJson(['value' => 1]);
        $this->assertSame(
            "&lt;Main&gt; &amp; &quot;Stats&quot;\nDetails",
            $repository->rows['alice'][0]['name'],
        );
        $this->assertSame($layouts->defaultLayoutValue, $repository->rows['alice'][0]['layout']);

        $this->post($this->url('createNewDashboardForUser'), [
            'login' => 'alice',
            'addDefaultWidgets' => '0',
        ])->assertOk()->assertExactJson(['value' => 2]);
        $this->assertSame('{}', $repository->rows['alice'][1]['layout']);

        $this->post($this->url('resetDashboardLayout'), ['idDashboard' => 2])
            ->assertOk()->assertJsonPath('result', 'success');
        $this->assertSame($layouts->defaultLayoutValue, $repository->rows['alice'][1]['layout']);

        $this->post($this->url('removeDashboard'), ['idDashboard' => 1])
            ->assertOk()->assertJsonPath('result', 'success');
        $this->assertSame([2], array_column($repository->rows['alice'], 'iddashboard'));
    }

    public function test_admin_can_copy_an_owned_dashboard_to_a_visible_user(): void
    {
        $this->authenticate('alice', someAdmin: true);
        $repository = new MemoryDashboardRepository([
            'alice' => [['iddashboard' => 7, 'name' => 'Source', 'layout' => 'stored-layout']],
        ]);
        $this->app->instance(DashboardRepository::class, $repository);
        $this->app->instance(DashboardRecipientPolicy::class, new FixedDashboardRecipientPolicy(true));

        $this->post($this->url('copyDashboardToUser'), [
            'idDashboard' => 7,
            'copyToUser' => 'bob',
            'dashboardName' => 'Copy',
        ])->assertOk()->assertExactJson(['value' => 1]);

        $this->assertSame('Copy', $repository->rows['bob'][0]['name']);
        $this->assertSame('stored-layout', $repository->rows['bob'][0]['layout']);
    }

    public function test_copy_requires_admin_access(): void
    {
        $repository = new MemoryDashboardRepository;
        $this->app->instance(DashboardRepository::class, $repository);
        $this->authenticate('alice');

        $this->post($this->url('copyDashboardToUser'), [
            'idDashboard' => 1,
            'copyToUser' => 'bob',
        ])->assertUnauthorized()->assertJsonPath(
            'message',
            "You can't access this resource as it requires 'admin' access.",
        );
    }

    public function test_copy_rejects_an_invisible_recipient(): void
    {
        $this->authenticate('alice', someAdmin: true);
        $this->app->instance(DashboardRecipientPolicy::class, new FixedDashboardRecipientPolicy(false));

        $this->post($this->url('copyDashboardToUser'), [
            'idDashboard' => 1,
            'copyToUser' => 'bob',
        ])->assertBadRequest()->assertJsonPath(
            'message',
            'Cannot copy dashboard to user bob, user not found.',
        );
    }

    public function test_copy_rejects_a_missing_source_dashboard(): void
    {
        $this->authenticate('alice', someAdmin: true);
        $this->app->instance(DashboardRepository::class, new MemoryDashboardRepository);
        $this->app->instance(DashboardRecipientPolicy::class, new FixedDashboardRecipientPolicy(true));

        $this->post($this->url('copyDashboardToUser'), [
            'idDashboard' => 1,
            'copyToUser' => 'bob',
        ])->assertBadRequest()->assertJsonPath('message', 'Dashboard not found');
    }

    public function test_writes_reject_anonymous_users(): void
    {
        $this->authenticate(null);

        $this->post($this->url('createNewDashboardForUser'), ['login' => 'alice'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'You must be logged in to access this functionality.');
    }

    public function test_writes_reject_anonymous_targets_and_missing_parameters(): void
    {
        $this->authenticate('alice');
        $this->post($this->url('createNewDashboardForUser'), ['login' => 'anonymous'])
            ->assertBadRequest()
            ->assertJsonPath('message', "This method can't be performed for anonymous user");
        $this->post($this->url('createNewDashboardForUser'))
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'login'.");
        $this->post($this->url('removeDashboard'))
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'idDashboard'.");
        $this->post($this->url('copyDashboardToUser'), ['idDashboard' => 1])
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'copyToUser'.");
    }

    private function authenticate(?string $login, bool $someAdmin = false, bool $superuser = false): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn($login);
        $authorizer->method('hasSomeAdminAccess')->willReturn($someAdmin || $superuser);
        $authorizer->method('hasSuperUserAccess')->willReturn($superuser);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->forgetInstance(ApiMethodDispatcher::class);
    }

    /** @param array<string, scalar> $parameters */
    private function url(string $method, array $parameters = []): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => "Dashboard.{$method}",
            'format' => 'json',
            'token_auth' => 'token',
            ...$parameters,
        ]);
    }
}

final class MemoryDashboardRepository implements DashboardRepository
{
    /** @param array<string, list<array{iddashboard: int, name: string|null, layout: string}>> $rows */
    public function __construct(public array $rows = []) {}

    public function all(string $login): array
    {
        return $this->rows[$login] ?? [];
    }

    public function layout(string $login, int $dashboardId): ?string
    {
        foreach ($this->all($login) as $row) {
            if ($row['iddashboard'] === $dashboardId) {
                return $row['layout'];
            }
        }

        return null;
    }

    public function create(string $login, string $name, string $layout): int
    {
        $dashboardId = max([0, ...array_column($this->all($login), 'iddashboard')]) + 1;
        $this->rows[$login][] = [
            'iddashboard' => $dashboardId,
            'name' => $name,
            'layout' => $layout,
        ];

        return $dashboardId;
    }

    public function delete(string $login, int $dashboardId): void
    {
        $this->rows[$login] = array_values(array_filter(
            $this->all($login),
            static fn (array $row): bool => $row['iddashboard'] !== $dashboardId,
        ));
    }

    public function updateLayout(string $login, int $dashboardId, string $layout): void
    {
        foreach ($this->rows[$login] ?? [] as $index => $row) {
            if ($row['iddashboard'] === $dashboardId) {
                $this->rows[$login][$index]['layout'] = $layout;

                return;
            }
        }

        $this->rows[$login][] = ['iddashboard' => $dashboardId, 'name' => null, 'layout' => $layout];
    }
}

final readonly class FixedDashboardRecipientPolicy implements DashboardRecipientPolicy
{
    public function __construct(private bool $allowed) {}

    public function canCopyTo(ApiAuthentication $authentication, string $login): bool
    {
        return $this->allowed;
    }
}

final class RecordingDashboardLayoutProvider implements DashboardLayoutProvider
{
    public string $defaultLayoutValue = 'default-layout';

    /** @param array<string, list<array{module: string, action: string}>> $widgets */
    public function __construct(private readonly array $widgets = []) {}

    public function defaultLayout(ApiAuthentication $authentication): string
    {
        return $this->defaultLayoutValue;
    }

    public function visibleWidgets(string $layout): array
    {
        return $this->widgets[$layout]
            ?? [['module' => 'CoreHome', 'action' => 'defaultWidget']];
    }
}
