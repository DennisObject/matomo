<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Users\AccessMetadataProvider;
use App\Matomo\Users\AnonymousAccessNotifier;
use App\Matomo\Users\MutableUserSiteAccessRepository;
use App\Matomo\Users\UserDirectoryRepository;
use Tests\TestCase;

final class UsersManagerAccessMutationApiTest extends TestCase
{
    public function test_role_and_extra_capability_replace_access_and_clear_cache(): void
    {
        $this->bindAuthorizedSites([7, 8]);
        $access = $this->createMock(MutableUserSiteAccessRepository::class);
        $access->expects($this->once())->method('replace')
            ->with('alice', [7, 8], 'view', ['manage_tags'])
            ->willReturn('updated');
        $cache = $this->createMock(TrackerCacheInvalidator::class);
        $cache->expects($this->once())->method('clearGeneral');
        $this->app->instance(MutableUserSiteAccessRepository::class, $access);
        $this->app->instance(TrackerCacheInvalidator::class, $cache);

        $this->get('/index.php?module=API&method=UsersManager.setUserAccess'.
            '&userLogin=alice&idSites[]=7&idSites[]=8&access[]=view'.
            '&access[]=manage_tags&format=json&token_auth=admin-token')
            ->assertOk()
            ->assertExactJson(['result' => 'success', 'message' => 'ok']);
    }

    public function test_site_scope_is_checked_before_target_user_lookup(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('siteIdsWithRole')
            ->with($this->anything(), SiteAccessRole::Admin)->willReturn([7]);
        $users = $this->createMock(UserDirectoryRepository::class);
        $users->expects($this->never())->method('user');
        $access = $this->createMock(MutableUserSiteAccessRepository::class);
        $access->expects($this->never())->method('replace');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserDirectoryRepository::class, $users);
        $this->app->instance(MutableUserSiteAccessRepository::class, $access);

        $this->get('/index.php?module=API&method=UsersManager.setUserAccess'.
            '&userLogin=hidden&idSites=8&access=view&format=json&token_auth=admin-token')
            ->assertUnauthorized();
    }

    public function test_anonymous_view_access_sends_notification(): void
    {
        $this->bindAuthorizedSites([7]);
        $users = $this->createStub(UserDirectoryRepository::class);
        $users->method('user')->willReturn(['login' => 'anonymous']);
        $access = $this->createMock(MutableUserSiteAccessRepository::class);
        $access->expects($this->once())->method('replace')
            ->with('anonymous', [7], 'view', [])->willReturn('updated');
        $notifier = $this->createMock(AnonymousAccessNotifier::class);
        $notifier->expects($this->once())->method('notify')->with([7]);
        $this->app->instance(UserDirectoryRepository::class, $users);
        $this->app->instance(MutableUserSiteAccessRepository::class, $access);
        $this->app->instance(AnonymousAccessNotifier::class, $notifier);
        $this->app->instance(TrackerCacheInvalidator::class, $this->createStub(TrackerCacheInvalidator::class));

        $this->get('/index.php?module=API&method=UsersManager.setUserAccess'.
            '&userLogin=anonymous&idSites=7&access=view&format=json&token_auth=admin-token')
            ->assertOk();
    }

    public function test_session_admin_grant_requires_correct_password(): void
    {
        $this->bindAuthorizedSites([7]);
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('siteIdsWithRole')->willReturn([7]);
        $authorizer->method('authenticatedLogin')->willReturn('admin');
        $passwords = $this->createMock(PasswordConfirmationVerifier::class);
        $passwords->expects($this->once())->method('isCorrect')->with('admin', 'wrong')->willReturn(false);
        $access = $this->createMock(MutableUserSiteAccessRepository::class);
        $access->expects($this->never())->method('replace');
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(MutableUserSiteAccessRepository::class, $access);

        $this->withCookie('MATOMO_SESSID', 'session-1')->get(
            '/index.php?module=API&method=UsersManager.setUserAccess'.
            '&userLogin=alice&idSites=7&access=admin&passwordConfirmation=wrong'.
            '&format=json&token_auth=admin-token',
        )->assertForbidden()->assertJsonPath('message', 'The password confirmation is invalid.');
    }

    public function test_capability_grant_reports_site_without_role(): void
    {
        $this->bindAuthorizedSites([7]);
        $access = $this->createMock(MutableUserSiteAccessRepository::class);
        $access->expects($this->once())->method('addCapabilities')
            ->with('alice', [7], ['manage_tags' => ['admin']])->willReturn(7);
        $this->app->instance(MutableUserSiteAccessRepository::class, $access);

        $this->get('/index.php?module=API&method=UsersManager.addCapabilities'.
            '&userLogin=alice&idSites=7&capabilities=manage_tags'.
            '&format=json&token_auth=admin-token')
            ->assertBadRequest()
            ->assertJsonPath('message', 'User alice has no role for website id = 7.');
    }

    public function test_remove_capability_mutates_and_clears_cache(): void
    {
        $this->bindAuthorizedSites([7]);
        $access = $this->createMock(MutableUserSiteAccessRepository::class);
        $access->expects($this->once())->method('removeCapabilities')
            ->with('alice', [7], ['manage_tags']);
        $cache = $this->createMock(TrackerCacheInvalidator::class);
        $cache->expects($this->once())->method('clearGeneral');
        $this->app->instance(MutableUserSiteAccessRepository::class, $access);
        $this->app->instance(TrackerCacheInvalidator::class, $cache);

        $this->get('/index.php?module=API&method=UsersManager.removeCapabilities'.
            '&userLogin=alice&idSites=7&capabilities=manage_tags'.
            '&format=json&token_auth=admin-token')
            ->assertOk();
    }

    /** @param list<int> $siteIds */
    private function bindAuthorizedSites(array $siteIds): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('siteIdsWithRole')->willReturn($siteIds);
        $users = $this->createStub(UserDirectoryRepository::class);
        $users->method('user')->willReturn(['login' => 'alice']);
        $metadata = $this->createStub(AccessMetadataProvider::class);
        $metadata->method('capabilities')->willReturn([[
            'id' => 'manage_tags',
            'name' => '',
            'description' => '',
            'helpUrl' => '',
            'includedInRoles' => ['admin'],
            'category' => '',
        ]]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserDirectoryRepository::class, $users);
        $this->app->instance(AccessMetadataProvider::class, $metadata);
    }
}
