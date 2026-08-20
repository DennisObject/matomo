<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Privacy\DataSubjectFinder;
use Tests\TestCase;

final class PrivacyManagerDataSubjectSearchApiTest extends TestCase
{
    public function test_admin_searches_only_requested_viewable_sites(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSomeAdminAccess')->willReturn(true);
        $authorizer->method('siteIdsWithAtLeastViewAccess')->willReturn([2, 7]);
        $languages = $this->createStub(LanguageResolver::class);
        $languages->method('resolve')->willReturn('en');
        $finder = $this->createMock(DataSubjectFinder::class);
        $finder->expects($this->once())->method('find')->with([7], 'userId==person', 'en')
            ->willReturn([['idVisit' => 19, 'idSite' => 7]]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LanguageResolver::class, $languages);
        $this->app->instance(DataSubjectFinder::class, $finder);

        $this->get('/index.php?module=API&method=PrivacyManager.findDataSubjects'.
            '&idSite=7,9&segment=userId%3D%3Dperson&format=json&token_auth=admin-token')
            ->assertOk()->assertExactJson([['idVisit' => 19, 'idSite' => 7]]);
    }

    public function test_non_admin_cannot_search_data_subjects(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSomeAdminAccess')->willReturn(false);
        $finder = $this->createMock(DataSubjectFinder::class);
        $finder->expects($this->never())->method('find');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(DataSubjectFinder::class, $finder);

        $this->get('/index.php?module=API&method=PrivacyManager.findDataSubjects'.
            '&idSite=7&segment=userId%3D%3Dperson&format=json&token_auth=view-token')
            ->assertUnauthorized();
    }
}
