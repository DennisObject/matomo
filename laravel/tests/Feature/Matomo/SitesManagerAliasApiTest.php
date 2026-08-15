<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Goals\SiteTrackerCacheInvalidator;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class SitesManagerAliasApiTest extends TestCase
{
    public function test_adds_normalized_unique_aliases_and_returns_inserted_count(): void
    {
        $sites = $this->createMock(SiteRepository::class);
        $sites->method('mainUrl')->with(7)->willReturn('https://main.test/');
        $sites->method('urls')->with(7)->willReturn([
            'https://main.test/',
            'http://old.test',
        ]);
        $sites->expects($this->once())->method('replaceAliasUrls')->with(7, [
            'http://old.test',
            'http://new.test',
            'https://secure.test/path',
        ])->willReturnArgument(1);
        $this->bindDependencies($sites);

        $this->get($this->url('addSiteAliasUrls').
            '&urls[]=new.test/&urls[]=https%3A%2F%2Fsecure.test%2Fpath%2F&urls[]=new.test')
            ->assertOk()
            ->assertExactJson(['value' => 2]);
    }

    public function test_replaces_aliases_without_changing_main_url(): void
    {
        $sites = $this->createMock(SiteRepository::class);
        $sites->method('mainUrl')->with(7)->willReturn('https://main.test');
        $sites->method('urls')->with(7)->willReturn([
            'https://main.test',
            'http://old.test',
        ]);
        $sites->expects($this->once())->method('replaceAliasUrls')
            ->with(7, ['http://new.test'])
            ->willReturnArgument(1);
        $this->bindDependencies($sites);

        $this->get($this->url('setSiteAliasUrls').'&urls[]=new.test')
            ->assertOk()
            ->assertExactJson(['value' => 0]);
    }

    public function test_rejects_invalid_alias_before_storage_or_cache_changes(): void
    {
        $sites = $this->createMock(SiteRepository::class);
        $sites->method('mainUrl')->willReturn('https://main.test');
        $sites->expects($this->never())->method('replaceAliasUrls');
        $this->bindDependencies($sites, false);

        $this->get($this->url('addSiteAliasUrls').'&urls[]=http%3A%2F%2F')
            ->assertBadRequest();
    }

    public function test_requires_site_admin_access_before_site_reads(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('siteIdsWithRole')->willReturn([]);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('mainUrl');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get($this->url('setSiteAliasUrls').'&urls[]=new.test')
            ->assertUnauthorized();
    }

    private function bindDependencies(SiteRepository $sites, bool $expectCacheClear = true): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('siteIdsWithRole')->with($this->anything(), SiteAccessRole::Admin)->willReturn([7]);
        $siteCache = $this->createMock(SiteTrackerCacheInvalidator::class);
        $siteCache->expects($expectCacheClear ? $this->once() : $this->never())->method('clear');
        $generalCache = $this->createMock(TrackerCacheInvalidator::class);
        $generalCache->expects($expectCacheClear ? $this->once() : $this->never())->method('clearGeneral');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(SiteTrackerCacheInvalidator::class, $siteCache);
        $this->app->instance(TrackerCacheInvalidator::class, $generalCache);
    }

    private function url(string $method): string
    {
        return "/index.php?module=API&method=SitesManager.{$method}&idSite=7".
            '&format=json&token_auth=admin-token';
    }
}
