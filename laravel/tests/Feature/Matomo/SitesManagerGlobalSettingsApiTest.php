<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Options\MutableOptionRepository;
use Tests\TestCase;

class SitesManagerGlobalSettingsApiTest extends TestCase
{
    public function test_superuser_updates_global_tracker_settings_and_clears_cache(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $writes = [];
        $options = $this->createMock(MutableOptionRepository::class);
        $options->expects($this->exactly(6))->method('set')->willReturnCallback(
            static function (string $name, string $value) use (&$writes): void {
                $writes[$name] = $value;
            },
        );
        $cache = $this->createMock(TrackerCacheInvalidator::class);
        $cache->expects($this->exactly(5))->method('clearGeneral');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(MutableOptionRepository::class, $options);
        $this->app->instance(TrackerCacheInvalidator::class, $cache);

        $this->get($this->url('setGlobalExcludedIps', 'excludedIps=1.2.3.4%2C+1.2.3.*'))->assertOk();
        $this->get($this->url(
            'setGlobalSearchParameters',
            'searchKeywordParameters=q%2Cquery&searchCategoryParameters=category',
        ))->assertOk();
        $this->get($this->url(
            'setGlobalExcludedUserAgents',
            'excludedUserAgents=bot%2C+spider%2Cbot',
        ))->assertOk();
        $this->get($this->url(
            'setGlobalExcludedReferrers',
            'excludedReferrers=https%3A%2F%2Fexample.test%2C+.internal.test',
        ))->assertOk();
        $this->get($this->url('setKeepURLFragmentsGlobal', 'enabled=1'))->assertOk();

        $this->assertSame([
            'SitesManager_ExcludedIpsGlobal' => '1.2.3.4,1.2.3.*',
            'SitesManager_SearchKeywordParameters' => 'q,query',
            'SitesManager_SearchCategoryParameters' => 'category',
            'SitesManager_ExcludedUserAgentsGlobal' => 'bot,spider',
            'SitesManager_ExcludedReferrersGlobal' => 'https://example.test,.internal.test',
            'SitesManager_KeepURLFragmentsGlobal' => '1',
        ], $writes);
    }

    public function test_invalid_global_exclusions_do_not_write_or_clear_cache(): void
    {
        $this->bindNoMutationDependencies(true);

        $this->get($this->url('setGlobalExcludedIps', 'excludedIps=not-an-ip'))
            ->assertBadRequest();
        $this->get($this->url('setGlobalExcludedReferrers', 'excludedReferrers=http%3A%2F%2F'))
            ->assertBadRequest();
    }

    public function test_global_query_parameter_exclusion_replaces_or_deletes_custom_values(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $writes = [];
        $options = $this->createMock(MutableOptionRepository::class);
        $options->expects($this->exactly(3))->method('set')->willReturnCallback(
            static function (string $name, string $value) use (&$writes): void {
                $writes[] = [$name, $value];
            },
        );
        $options->expects($this->once())->method('delete')
            ->with('SitesManager_ExcludedQueryParameters');
        $cache = $this->createMock(TrackerCacheInvalidator::class);
        $cache->expects($this->exactly(2))->method('clearGeneral');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(MutableOptionRepository::class, $options);
        $this->app->instance(TrackerCacheInvalidator::class, $cache);

        $this->get($this->url(
            'setGlobalQueryParamExclusion',
            'exclusionType=custom&queryParamsToExclude=email%2C+phone%2Cemail',
        ))->assertOk();
        $this->get($this->url(
            'setGlobalQueryParamExclusion',
            'exclusionType=common_session_parameters',
        ))->assertOk();

        $this->assertSame([
            ['SitesManager_ExcludeTypeQueryParamsGlobal', 'custom'],
            ['SitesManager_ExcludedQueryParameters', 'email,phone'],
            ['SitesManager_ExcludeTypeQueryParamsGlobal', 'common_session_parameters'],
        ], $writes);
    }

    public function test_global_query_parameter_exclusion_rejects_inconsistent_values(): void
    {
        $this->bindNoMutationDependencies(true);

        $this->get($this->url('setGlobalQueryParamExclusion', 'exclusionType=custom'))
            ->assertBadRequest();
        $this->get($this->url(
            'setGlobalQueryParamExclusion',
            'exclusionType=matomo_recommended_pii&queryParamsToExclude=email',
        ))->assertBadRequest();
    }

    public function test_global_tracker_settings_require_superuser_before_mutation(): void
    {
        $this->bindNoMutationDependencies(false);

        $this->get($this->url('setKeepURLFragmentsGlobal', 'enabled=1'))
            ->assertUnauthorized();
    }

    private function bindNoMutationDependencies(bool $superuser): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn($superuser);
        $options = $this->createMock(MutableOptionRepository::class);
        $options->expects($this->never())->method('set');
        $options->expects($this->never())->method('delete');
        $cache = $this->createMock(TrackerCacheInvalidator::class);
        $cache->expects($this->never())->method('clearGeneral');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(MutableOptionRepository::class, $options);
        $this->app->instance(TrackerCacheInvalidator::class, $cache);
    }

    private function url(string $method, string $parameters): string
    {
        return "/index.php?module=API&method=SitesManager.{$method}&{$parameters}".
            '&format=json&token_auth=super-token';
    }
}
