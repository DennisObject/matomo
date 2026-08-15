<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Privacy\AnonymizableColumnProvider;
use App\Matomo\Privacy\RawAnonymisationScheduler;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

final class PrivacyManagerRawAnonymisationApiTest extends TestCase
{
    public function test_superuser_schedules_valid_site_and_columns_after_password_confirmation(): void
    {
        $this->authorize();
        $passwords = $this->createMock(PasswordConfirmationVerifier::class);
        $passwords->expects($this->once())->method('isCorrect')->with('admin', 'correct')->willReturn(true);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('allIds')->willReturn([7, 9]);
        $columns = $this->createStub(AnonymizableColumnProvider::class);
        $columns->method('forTable')->willReturnMap([
            ['log_visit', [['column_name' => 'config_device_type', 'default_value' => null]]],
            ['log_link_visit_action', [['column_name' => 'idaction_event_category', 'default_value' => null]]],
        ]);
        $scheduler = $this->createMock(RawAnonymisationScheduler::class);
        $scheduler->expects($this->once())->method('schedule')->with(
            'admin',
            [7, 9],
            '2026-08-01 00:00:00',
            '2026-08-03 23:59:59',
            true,
            false,
            true,
            ['config_device_type'],
            ['idaction_event_category'],
        )->willReturn(12);
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(AnonymizableColumnProvider::class, $columns);
        $this->app->instance(RawAnonymisationScheduler::class, $scheduler);

        $this->get('/index.php?module=API&method=PrivacyManager.anonymizeSomeRawData'.
            '&idSites=7,9&date=2026-08-01,2026-08-03&anonymizeIp=1&anonymizeUserId=1'.
            '&unsetVisitColumns=config_device_type'.
            '&unsetLinkVisitActionColumns=idaction_event_category'.
            '&passwordConfirmation=correct&format=json&token_auth=super-token')
            ->assertOk()
            ->assertExactJson(['result' => 'success', 'message' => 'ok']);
    }

    public function test_empty_work_is_rejected_before_database_insert(): void
    {
        $this->authorize();
        $passwords = $this->createStub(PasswordConfirmationVerifier::class);
        $passwords->method('isCorrect')->willReturn(true);
        $scheduler = $this->createMock(RawAnonymisationScheduler::class);
        $scheduler->expects($this->never())->method('schedule');
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(RawAnonymisationScheduler::class, $scheduler);

        $this->get('/index.php?module=API&method=PrivacyManager.anonymizeSomeRawData'.
            '&idSites=all&date=2026-08-01&passwordConfirmation=correct'.
            '&format=json&token_auth=super-token')->assertBadRequest();
    }

    private function authorize(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $authorizer->method('authenticatedLogin')->willReturn('admin');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
