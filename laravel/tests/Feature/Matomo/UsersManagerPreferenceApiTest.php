<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Users\UserPreferenceRepository;
use Tests\TestCase;

final class UsersManagerPreferenceApiTest extends TestCase
{
    public function test_user_sets_own_preference_with_canonical_login(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('Alice');
        $preferences = $this->createMock(UserPreferenceRepository::class);
        $preferences->expects($this->once())->method('canonicalLogin')->with('alice')->willReturn('Alice');
        $preferences->expects($this->once())->method('set')->with('Alice', 'themeMode', 'dark');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserPreferenceRepository::class, $preferences);

        $this->post('/index.php', [
            'module' => 'API',
            'method' => 'UsersManager.setUserPreference',
            'userLogin' => 'alice',
            'preferenceName' => 'themeMode',
            'preferenceValue' => 'dark',
            'format' => 'json',
            'token_auth' => 'alice-token',
        ])->assertOk()->assertExactJson(['result' => 'success', 'message' => 'ok']);
    }

    public function test_user_cannot_write_another_users_preference(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('alice');
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(false);
        $preferences = $this->createMock(UserPreferenceRepository::class);
        $preferences->expects($this->never())->method('canonicalLogin');
        $preferences->expects($this->never())->method('set');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserPreferenceRepository::class, $preferences);

        $this->post('/index.php', [
            'module' => 'API',
            'method' => 'UsersManager.setUserPreference',
            'userLogin' => 'bob',
            'preferenceName' => 'themeMode',
            'preferenceValue' => 'dark',
            'format' => 'json',
            'token_auth' => 'alice-token',
        ])->assertUnauthorized();
    }

    public function test_missing_preference_returns_legacy_default(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('alice');
        $preferences = $this->createMock(UserPreferenceRepository::class);
        $preferences->method('canonicalLogin')->willReturn('alice');
        $preferences->expects($this->once())->method('get')->with('alice', 'themeMode')
            ->willReturn(['found' => false, 'value' => null]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserPreferenceRepository::class, $preferences);

        $this->get('/index.php?module=API&method=UsersManager.getUserPreference'.
            '&preferenceName=themeMode&format=json&token_auth=alice-token')
            ->assertOk()
            ->assertExactJson(['value' => 'light']);
    }

    public function test_superuser_reads_selected_preferences_for_all_users(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(true);
        $preferences = $this->createMock(UserPreferenceRepository::class);
        $preferences->expects($this->once())->method('forAllUsers')->with(['themeMode'])
            ->willReturn(['alice' => ['themeMode' => 'dark']]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserPreferenceRepository::class, $preferences);

        $this->get('/index.php?module=API&method=UsersManager.getAllUsersPreferences'.
            '&preferenceNames%5B0%5D=themeMode&format=json&token_auth=root-token')
            ->assertOk()
            ->assertExactJson(['alice' => ['themeMode' => 'dark']]);
    }

    public function test_ldap_preference_keeps_legacy_option(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('alice');
        $preferences = $this->createMock(UserPreferenceRepository::class);
        $preferences->method('canonicalLogin')->willReturn('alice');
        $preferences->expects($this->once())->method('set')->with('alice', 'isLDAPUser', '1');
        $options = $this->createMock(MutableOptionRepository::class);
        $options->expects($this->once())->method('set')->with('alice_isLDAPUser', '1');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserPreferenceRepository::class, $preferences);
        $this->app->instance(MutableOptionRepository::class, $options);

        $this->get('/index.php?module=API&method=UsersManager.setUserPreference'.
            '&userLogin=alice&preferenceName=isLDAPUser&preferenceValue=1'.
            '&format=json&token_auth=alice-token')->assertOk();
    }

    public function test_unsupported_preference_is_rejected_before_storage(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('alice');
        $preferences = $this->createMock(UserPreferenceRepository::class);
        $preferences->method('canonicalLogin')->willReturn('alice');
        $preferences->expects($this->never())->method('set');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserPreferenceRepository::class, $preferences);

        $this->get('/index.php?module=API&method=UsersManager.setUserPreference'.
            '&userLogin=alice&preferenceName=unknown_name&preferenceValue=x'.
            '&format=json&token_auth=alice-token')
            ->assertBadRequest()
            ->assertJsonPath('message', 'Preference name cannot contain underscores.');
    }
}
