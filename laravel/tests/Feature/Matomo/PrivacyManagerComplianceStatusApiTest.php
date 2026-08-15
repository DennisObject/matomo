<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Goals\SiteTrackerCacheInvalidator;
use App\Matomo\Privacy\CompliancePolicyStateRepository;
use App\Matomo\Privacy\Events\CompliancePolicyStatusChanged;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class PrivacyManagerComplianceStatusApiTest extends TestCase
{
    public function test_token_authenticated_superuser_enables_site_policy_and_clears_caches(): void
    {
        $this->authorize();
        $policies = $this->createMock(CompliancePolicyStateRepository::class);
        $policies->expects($this->once())->method('setActive')->with(7, true);
        $siteCache = $this->createMock(SiteTrackerCacheInvalidator::class);
        $siteCache->expects($this->once())->method('clear')->with(7);
        $trackerCache = $this->createMock(TrackerCacheInvalidator::class);
        $trackerCache->expects($this->once())->method('clearGeneral');
        $passwords = $this->createMock(PasswordConfirmationVerifier::class);
        $passwords->expects($this->never())->method('isCorrect');
        $this->app->instance(CompliancePolicyStateRepository::class, $policies);
        $this->app->instance(SiteTrackerCacheInvalidator::class, $siteCache);
        $this->app->instance(TrackerCacheInvalidator::class, $trackerCache);
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        Event::fake([CompliancePolicyStatusChanged::class]);

        $this->get('/index.php?module=API&method=PrivacyManager.setComplianceStatus'.
            '&idSite=7&complianceType=cnil_v1&enforce=1&format=json&token_auth=super-token')
            ->assertOk()->assertExactJson(['value' => true]);

        Event::assertDispatched(
            CompliancePolicyStatusChanged::class,
            static fn (CompliancePolicyStatusChanged $event): bool => $event->active && $event->idSite === 7 && $event->policy === 'cnil_v1',
        );
    }

    public function test_session_authenticated_change_requires_valid_password(): void
    {
        $this->authorize();
        $policies = $this->createMock(CompliancePolicyStateRepository::class);
        $policies->expects($this->never())->method('setActive');
        $passwords = $this->createMock(PasswordConfirmationVerifier::class);
        $passwords->expects($this->once())->method('isCorrect')->with('admin', 'wrong')->willReturn(false);
        $this->app->instance(CompliancePolicyStateRepository::class, $policies);
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);

        $this->withCookie('MATOMO_SESSID', 'session-id')->get(
            '/index.php?module=API&method=PrivacyManager.setComplianceStatus'.
            '&idSite=all&complianceType=cnil_v1&enforce=0'.
            '&passwordConfirmation=wrong&format=json&token_auth=session-token',
        )->assertForbidden();
    }

    public function test_invalid_policy_and_site_are_rejected_without_storage(): void
    {
        $this->authorize();
        $policies = $this->createMock(CompliancePolicyStateRepository::class);
        $policies->expects($this->never())->method('setActive');
        $this->app->instance(CompliancePolicyStateRepository::class, $policies);

        $this->get('/index.php?module=API&method=PrivacyManager.setComplianceStatus'.
            '&idSite=0&complianceType=other&enforce=1&format=json&token_auth=super-token')
            ->assertBadRequest();
    }

    private function authorize(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $authorizer->method('authenticatedLogin')->willReturn('admin');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
