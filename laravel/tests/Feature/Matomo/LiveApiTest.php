<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Live\LiveAccessPolicy;
use App\Matomo\Live\LiveCounterRepository;
use Tests\TestCase;

final class LiveApiTest extends TestCase
{
    public function test_counters_apply_site_access_and_column_filters(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('siteIdsWithAtLeastViewAccess')->willReturn([2, 7]);
        $counters = $this->createMock(LiveCounterRepository::class);
        $counters->expects($this->once())->method('counters')->with([2, 7], 30, 'countryCode==nz')
            ->willReturn(['visits' => 4, 'actions' => 9, 'visitors' => 3, 'visitsConverted' => 1]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LiveCounterRepository::class, $counters);

        $this->get('/index.php?module=API&method=Live.getCounters&idSite=all&lastMinutes=30'.
            '&segment=countryCode%3D%3Dnz&showColumns=visits,actions&hideColumns=actions'.
            '&format=json&token_auth=view-token')
            ->assertOk()->assertExactJson([['visits' => 4]]);
    }

    public function test_profile_is_disabled_if_any_requested_site_disables_it(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('siteIdsWithAtLeastViewAccess')->willReturn([2, 7]);
        $access = $this->createStub(LiveAccessPolicy::class);
        $access->method('visitorProfileEnabled')->willReturnMap([[2, true], [7, false]]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LiveAccessPolicy::class, $access);

        $this->get('/index.php?module=API&method=Live.isVisitorProfileEnabled'.
            '&idSite=2,7&format=json&token_auth=view-token')
            ->assertOk()->assertExactJson(['value' => false]);
    }
}
