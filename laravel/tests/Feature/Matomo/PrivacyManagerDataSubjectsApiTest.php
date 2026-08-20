<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Privacy\DataSubjectRepository;
use Tests\TestCase;

final class PrivacyManagerDataSubjectsApiTest extends TestCase
{
    public function test_admin_can_export_visits_for_every_authorized_site(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSomeAdminAccess')->willReturn(true);
        $authorizer->method('siteIdsWithRole')->with($this->anything(), SiteAccessRole::Admin)
            ->willReturn([2, 7]);
        $repository = $this->createMock(DataSubjectRepository::class);
        $repository->expects($this->once())->method('export')->with([
            ['idsite' => 2, 'idvisit' => 11],
            ['idsite' => 7, 'idvisit' => 19],
        ])->willReturn(['log_visit' => [['idvisit' => 11]]]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(DataSubjectRepository::class, $repository);

        $visits = rawurlencode(json_encode([
            ['idsite' => 2, 'idvisit' => 11],
            ['idsite' => 7, 'idvisit' => 19],
        ], JSON_THROW_ON_ERROR));

        $this->get('/index.php?module=API&method=PrivacyManager.exportDataSubjects'.
            '&visits='.$visits.'&format=json&token_auth=admin-token')
            ->assertOk()->assertExactJson(['log_visit' => [['idvisit' => 11]]]);
    }

    public function test_request_is_rejected_when_one_site_is_not_administered(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSomeAdminAccess')->willReturn(true);
        $authorizer->method('siteIdsWithRole')->willReturn([2]);
        $repository = $this->createMock(DataSubjectRepository::class);
        $repository->expects($this->never())->method('delete');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(DataSubjectRepository::class, $repository);

        $visits = rawurlencode('[{"idsite":7,"idvisit":19}]');
        $this->get('/index.php?module=API&method=PrivacyManager.deleteDataSubjects'.
            '&visits='.$visits.'&format=json&token_auth=admin-token')->assertForbidden();
    }
}
