<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Api\Events\ComparisonPagesCollecting;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use Illuminate\Contracts\Events\Dispatcher;
use Tests\TestCase;

class ComparisonPagesApiTest extends TestCase
{
    public function test_collects_comparison_disabled_pages_without_requiring_authentication(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $events = $this->createMock(Dispatcher::class);
        $events->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (object $event): bool {
                if (! $event instanceof ComparisonPagesCollecting) {
                    return false;
                }

                $event->pages = ['General_Visitors.Example', 'Example.index'];

                return true;
            }));
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(Dispatcher::class, $events);

        $this->get(
            '/index.php?module=API&method=API.getPagesComparisonsDisabledFor&format=json',
        )->assertOk()->assertExactJson([
            'General_Visitors.Example',
            'Example.index',
        ]);
    }

    public function test_returns_an_empty_xml_result_when_no_plugin_adds_a_page(): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));

        $this->get(
            '/index.php?module=API&method=API.getPagesComparisonsDisabledFor&format=xml',
        )->assertOk()->assertContent("<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result />");
    }
}
