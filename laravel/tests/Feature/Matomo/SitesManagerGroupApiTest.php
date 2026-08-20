<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Goals\SiteTrackerCacheInvalidator;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class SitesManagerGroupApiTest extends TestCase
{
    public function test_superuser_renames_group_and_clears_each_affected_site_cache(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())->method('renameGroup')->with('Old', 'New')->willReturn([3, 7]);
        $siteCache = $this->createMock(SiteTrackerCacheInvalidator::class);
        $cleared = [];
        $siteCache->expects($this->exactly(2))->method('clear')->willReturnCallback(
            static function (int $idSite) use (&$cleared): void {
                $cleared[] = $idSite;
            },
        );
        $generalCache = $this->createMock(TrackerCacheInvalidator::class);
        $generalCache->expects($this->once())->method('clearGeneral');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(SiteTrackerCacheInvalidator::class, $siteCache);
        $this->app->instance(TrackerCacheInvalidator::class, $generalCache);

        $this->get($this->url('Old', 'New'))
            ->assertOk()
            ->assertExactJson(['value' => true]);
        $this->assertSame([3, 7], $cleared);
    }

    public function test_same_group_name_is_a_noop_after_authorization(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('renameGroup');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get($this->url('Same', 'Same'))
            ->assertOk()
            ->assertExactJson(['value' => true]);
    }

    public function test_numeric_equivalent_group_names_preserve_legacy_noop_behavior(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('renameGroup');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get($this->url('0', '0.0'))
            ->assertOk()
            ->assertExactJson(['value' => true]);
    }

    public function test_group_rename_requires_superuser_before_storage(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(false);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('renameGroup');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get($this->url('Old', 'New'))->assertUnauthorized();
    }

    private function url(string $oldGroup, string $newGroup): string
    {
        return '/index.php?module=API&method=SitesManager.renameGroup'.
            '&oldGroupName='.urlencode($oldGroup).'&newGroupName='.urlencode($newGroup).
            '&format=json&token_auth=super-token';
    }
}
