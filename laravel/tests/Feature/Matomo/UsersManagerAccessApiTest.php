<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Users\AccessMetadataProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class UsersManagerAccessApiTest extends TestCase
{
    public function test_admin_lists_localized_roles(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSomeAdminAccess')->willReturn(true);
        $languages = $this->createMock(LanguageResolver::class);
        $languages->expects($this->once())->method('resolve')->willReturn('de');
        $metadata = $this->createMock(AccessMetadataProvider::class);
        $metadata->expects($this->once())->method('roles')->with('de')->willReturn([[
            'id' => 'view',
            'name' => 'Ansicht',
            'description' => 'Berichte ansehen',
            'helpUrl' => '',
        ]]);
        $metadata->expects($this->never())->method('capabilities');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LanguageResolver::class, $languages);
        $this->app->instance(AccessMetadataProvider::class, $metadata);

        $this->get('/index.php?module=API&method=UsersManager.getAvailableRoles'.
            '&language=de&format=json&token_auth=admin-token')
            ->assertOk()
            ->assertExactJson([[
                'id' => 'view',
                'name' => 'Ansicht',
                'description' => 'Berichte ansehen',
                'helpUrl' => '',
            ]]);
    }

    public function test_admin_lists_plugin_capabilities(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSomeAdminAccess')->willReturn(true);
        $metadata = $this->createMock(AccessMetadataProvider::class);
        $metadata->expects($this->never())->method('roles');
        $metadata->expects($this->once())->method('capabilities')->willReturn([[
            'id' => 'manage_tags',
            'name' => 'Manage tags',
            'description' => 'Create and publish tags.',
            'helpUrl' => '',
            'includedInRoles' => ['admin'],
            'category' => 'Tag Manager',
        ]]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(AccessMetadataProvider::class, $metadata);

        $this->get('/index.php?module=API&method=UsersManager.getAvailableCapabilities'.
            '&format=json&token_auth=admin-token')
            ->assertOk()
            ->assertJsonPath('0.id', 'manage_tags')
            ->assertJsonPath('0.includedInRoles.0', 'admin');
    }

    #[DataProvider('methods')]
    public function test_access_metadata_requires_some_site_admin_access(string $method): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSomeAdminAccess')->willReturn(false);
        $metadata = $this->createMock(AccessMetadataProvider::class);
        $metadata->expects($this->never())->method('roles');
        $metadata->expects($this->never())->method('capabilities');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(AccessMetadataProvider::class, $metadata);

        $this->get("/index.php?module=API&method={$method}&format=json&token_auth=view-token")
            ->assertUnauthorized()
            ->assertJsonPath('message', 'You must have admin access to at least one website.');
    }

    /** @return iterable<string, array{string}> */
    public static function methods(): iterable
    {
        yield 'roles' => ['UsersManager.getAvailableRoles'];
        yield 'capabilities' => ['UsersManager.getAvailableCapabilities'];
    }
}
