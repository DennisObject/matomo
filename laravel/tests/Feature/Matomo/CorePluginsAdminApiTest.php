<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Api\Methods\ApiMethodDispatcher;
use App\Matomo\Api\Methods\CorePluginsAdminApiMethodHandler;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Marketplace\PluginUpdateCounter;
use App\Matomo\Plugins\PluginSettingDefinition;
use App\Matomo\Plugins\PluginSettingsRegistry;
use App\Matomo\Plugins\PluginSettingsStore;
use Tests\TestCase;

final class CorePluginsAdminApiTest extends TestCase
{
    private MemoryPluginSettingsStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new MemoryPluginSettingsStore;
        $this->app->instance(PluginSettingsStore::class, $this->store);
        $this->app->instance(PluginSettingsRegistry::class, new TestPluginSettingsRegistry);

        $passwords = $this->createStub(PasswordConfirmationVerifier::class);
        $passwords->method('isCorrect')->willReturnCallback(
            static fn (string $login, string $password): bool => $login === 'alice' && $password === 'correct',
        );
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $updates = $this->createStub(PluginUpdateCounter::class);
        $updates->method('count')->willReturn(3);
        $this->app->instance(PluginUpdateCounter::class, $updates);
    }

    public function test_superuser_reads_and_updates_system_settings_with_password_confirmation(): void
    {
        $this->bindAccess('alice', true);
        $this->post($this->url('setSystemSettings'), [
            'passwordConfirmation' => 'correct',
            'settingValues' => ['Demo' => [['name' => 'endpoint', 'value' => 'https://example.test']]],
        ])->assertOk()->assertJsonPath('result', 'success');

        $this->get($this->url('getSystemSettings'))->assertOk()
            ->assertJsonPath('0.pluginName', 'Demo')
            ->assertJsonPath('0.settings.0.value', 'https://example.test');
        $this->get($this->url('getNumberOfPluginUpdates'))->assertOk()->assertJsonPath('value', 3);
    }

    public function test_system_settings_reject_non_superusers(): void
    {
        $this->bindAccess('alice', false);
        $this->get($this->url('getSystemSettings'))->assertUnauthorized();
    }

    public function test_system_settings_reject_wrong_passwords(): void
    {
        $this->bindAccess('alice', true);
        $this->post($this->url('setSystemSettings'), [
            'passwordConfirmation' => 'wrong', 'settingValues' => [],
        ])->assertBadRequest()->assertJsonPath('message', 'Your password is incorrect.');
    }

    public function test_authenticated_user_reads_and_updates_own_settings(): void
    {
        $this->bindAccess('alice', false);
        $this->post($this->url('setUserSettings'), [
            'settingValues' => ['Demo' => [['name' => 'rows', 'value' => 25]]],
        ])->assertOk();
        $this->get($this->url('getUserSettings'))->assertOk()
            ->assertJsonPath('0.settings.0.value', 25);
        $this->assertSame(['rows' => 25], $this->store->values('Demo', 'alice'));
    }

    public function test_update_count_fails_closed_for_non_superusers(): void
    {
        $this->bindAccess('alice', false);
        $this->get($this->url('getNumberOfPluginUpdates'))->assertOk()->assertJsonPath('value', 0);
    }

    private function bindAccess(string $login, bool $superUser): void
    {
        $this->app->forgetInstance(ApiMethodDispatcher::class);
        $this->app->forgetInstance(CorePluginsAdminApiMethodHandler::class);

        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn($login);
        $authorizer->method('hasSuperUserAccess')->willReturn($superUser);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    private function url(string $method): string
    {
        return "/index.php?module=API&method=CorePluginsAdmin.{$method}&format=json&token_auth=token";
    }
}

final class TestPluginSettingsRegistry implements PluginSettingsRegistry
{
    public function system(): array
    {
        return [new PluginSettingDefinition('Demo', 'endpoint', 'Endpoint')];
    }

    public function user(): array
    {
        return [new PluginSettingDefinition('Demo', 'rows', 'Rows', 10, 'int')];
    }
}

final class MemoryPluginSettingsStore implements PluginSettingsStore
{
    /** @var array<string, array<string, mixed>> */
    private array $data = [];

    public function values(string $pluginName, string $login): array
    {
        return $this->data[$pluginName.'#'.$login] ?? [];
    }

    public function replace(string $pluginName, string $login, array $values): void
    {
        $this->data[$pluginName.'#'.$login] = $values;
    }
}
