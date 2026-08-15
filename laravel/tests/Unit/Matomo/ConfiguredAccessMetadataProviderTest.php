<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Users\ConfiguredAccessMetadataProvider;
use App\Matomo\Users\Events\AccessCapabilitiesCollecting;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

final class ConfiguredAccessMetadataProviderTest extends TestCase
{
    public function test_roles_keep_legacy_order_and_localize_admin_description(): void
    {
        $translator = $this->createMock(MatomoTranslator::class);
        $translator->method('translate')->willReturnCallback(
            static fn (string $key, string $language, array $arguments = []): string => $key.':'.$language.($arguments === [] ? '' : ':'.implode(',', $arguments)),
        );
        $provider = new ConfiguredAccessMetadataProvider($translator, new Dispatcher);

        $roles = $provider->roles('fr');

        $this->assertSame(['view', 'write', 'admin'], array_column($roles, 'id'));
        $this->assertSame('UsersManager_PrivView:fr', $roles[0]['name']);
        $this->assertSame(
            'UsersManager_PrivAdminDescription:fr:UsersManager_PrivWrite:fr',
            $roles[2]['description'],
        );
    }

    public function test_capabilities_are_collected_from_extensions(): void
    {
        $translator = $this->createStub(MatomoTranslator::class);
        $events = new Dispatcher;
        $events->listen(AccessCapabilitiesCollecting::class, static function (AccessCapabilitiesCollecting $event): void {
            $event->capabilities[] = [
                'id' => 'custom',
                'name' => 'Custom',
                'description' => '',
                'helpUrl' => '',
                'includedInRoles' => ['write', 'admin'],
                'category' => 'Plugin',
            ];
        });
        $provider = new ConfiguredAccessMetadataProvider($translator, $events);

        $this->assertSame('custom', $provider->capabilities()[0]['id']);
    }
}
