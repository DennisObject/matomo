<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Dashboard\ConfiguredDashboardLayoutProvider;
use App\Matomo\Dashboard\DashboardRepository;
use App\Matomo\Dashboard\Events\DashboardDefaultLayoutChanging;
use App\Matomo\Plugins\PluginState;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

class ConfiguredDashboardLayoutProviderTest extends TestCase
{
    public function test_builds_and_normalizes_the_plugin_aware_default_layout(): void
    {
        $repository = $this->createStub(DashboardRepository::class);
        $repository->method('layout')->with('', 1)->willReturn(null);
        $plugins = new class implements PluginState
        {
            public function isActivated(string $pluginName): bool
            {
                return in_array($pluginName, ['Tour', 'ProfessionalServices', 'Insights'], true);
            }
        };
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $provider = new ConfiguredDashboardLayoutProvider(
            $repository,
            $plugins,
            $authorizer,
            new Dispatcher,
            true,
        );

        $layout = json_decode($provider->defaultLayout($this->authentication()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('33-33-33', $layout['config']['layout']);
        $this->assertSame('Tour', $layout['columns'][2][0]['parameters']['module']);
        $this->assertContains(
            'ProfessionalServices',
            array_column(array_column($layout['columns'][1], 'parameters'), 'module'),
        );
        $this->assertContains(
            'Insights',
            array_column(array_column($layout['columns'][1], 'parameters'), 'module'),
        );
    }

    public function test_uses_the_stored_layout_event_and_filters_hidden_widgets(): void
    {
        $repository = $this->createStub(DashboardRepository::class);
        $repository->method('layout')->willReturn('[[]]');
        $plugins = $this->createStub(PluginState::class);
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $events = new Dispatcher;
        $events->listen(DashboardDefaultLayoutChanging::class, static function (
            DashboardDefaultLayoutChanging $event,
        ): void {
            $event->layout = json_encode([
                [[
                    'parameters' => ['module' => 'Live', 'action' => 'widget'],
                ], [
                    'isHidden' => true,
                    'parameters' => ['module' => 'VisitsSummary', 'action' => 'get'],
                ], [
                    'parameters' => ['action' => 'missingModule'],
                ]],
            ], JSON_THROW_ON_ERROR);
        });
        $provider = new ConfiguredDashboardLayoutProvider(
            $repository,
            $plugins,
            $authorizer,
            $events,
            false,
        );

        $layout = $provider->defaultLayout($this->authentication());

        $this->assertSame(
            [['module' => 'Live', 'action' => 'widget']],
            $provider->visibleWidgets($layout),
        );
        $this->assertSame([], $provider->visibleWidgets('not-json'));
    }

    public function test_empty_stored_default_layout_uses_the_built_in_layout(): void
    {
        $repository = $this->createStub(DashboardRepository::class);
        $repository->method('layout')->willReturn('');
        $provider = new ConfiguredDashboardLayoutProvider(
            $repository,
            $this->createStub(PluginState::class),
            $this->createStub(ApiAccessAuthorizer::class),
            new Dispatcher,
            false,
        );

        $widgets = $provider->visibleWidgets($provider->defaultLayout($this->authentication()));

        $this->assertContains(['module' => 'Live', 'action' => 'widget'], $widgets);
    }

    private function authentication(): ApiAuthentication
    {
        return new ApiAuthentication('token', true, false, null);
    }
}
