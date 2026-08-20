<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Privacy\AnonymizableColumnProvider;
use Tests\TestCase;

final class PrivacyManagerColumnApiTest extends TestCase
{
    public function test_superuser_gets_sorted_visit_columns(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $columns = $this->createMock(AnonymizableColumnProvider::class);
        $columns->expects($this->once())->method('forTable')->with('log_visit')->willReturn([
            ['column_name' => 'user_id', 'default_value' => null],
        ]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(AnonymizableColumnProvider::class, $columns);

        $this->get('/index.php?module=API'.
            '&method=PrivacyManager.getAvailableVisitColumnsToAnonymize'.
            '&format=json&token_auth=super-token')
            ->assertOk()->assertExactJson([['column_name' => 'user_id', 'default_value' => null]]);
    }

    public function test_non_superuser_cannot_inspect_log_schema(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(false);
        $columns = $this->createMock(AnonymizableColumnProvider::class);
        $columns->expects($this->never())->method('forTable');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(AnonymizableColumnProvider::class, $columns);

        $this->get('/index.php?module=API'.
            '&method=PrivacyManager.getAvailableLinkVisitActionColumnsToAnonymize'.
            '&format=json&token_auth=user-token')->assertUnauthorized();
    }
}
