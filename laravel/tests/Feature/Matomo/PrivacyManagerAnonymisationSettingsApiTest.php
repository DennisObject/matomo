<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Goals\SiteTrackerCacheInvalidator;
use App\Matomo\Privacy\AnonymisationSettingsRepository;
use App\Matomo\Privacy\Events\CustomTrackerUpdateRequested;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class PrivacyManagerAnonymisationSettingsApiTest extends TestCase
{
    public function test_site_admin_reads_inherited_settings_and_policy_metadata(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('siteIdsWithRole')->willReturnCallback(
            static fn ($authentication, SiteAccessRole $role): array => $role === SiteAccessRole::Admin ? [7] : [],
        );
        $authorizer->method('hasSuperUserAccess')->willReturn(false);
        $settings = $this->createStub(AnonymisationSettingsRepository::class);
        $settings->method('values')->with(7)->willReturn([
            'ipAnonymizerEnabled' => true,
            'ipAddressMaskLength' => 2,
        ]);
        $settings->method('usesSiteSettings')->with(7)->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(AnonymisationSettingsRepository::class, $settings);

        $this->get('/index.php?module=API&method=PrivacyManager.getAnonymisationSettings'.
            '&idSiteSpecific=7&format=json&token_auth=admin-token')
            ->assertOk()
            ->assertJsonPath('ipAnonymizerEnabled', true)
            ->assertJsonPath('useSiteSpecificSettings', false)
            ->assertJsonPath('trackerFileName', '');
    }

    public function test_superuser_updates_global_settings_with_password_and_tracker_event(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $authorizer->method('authenticatedLogin')->willReturn('admin');
        $passwords = $this->createMock(PasswordConfirmationVerifier::class);
        $passwords->expects($this->once())->method('isCorrect')->with('admin', 'correct')->willReturn(true);
        $settings = $this->createMock(AnonymisationSettingsRepository::class);
        $settings->expects($this->once())->method('replace')->with(null, [
            'ipAnonymizerEnabled' => true,
            'ipAddressMaskLength' => 3,
            'useAnonymizedIpForVisitEnrichment' => false,
            'anonymizeUserId' => true,
            'anonymizeOrderId' => true,
            'anonymizeReferrer' => 'exclude_path',
            'randomizeConfigId' => true,
            'forceCookielessTracking' => true,
        ]);
        $trackerCache = $this->createMock(TrackerCacheInvalidator::class);
        $trackerCache->expects($this->once())->method('clearGeneral');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(AnonymisationSettingsRepository::class, $settings);
        $this->app->instance(TrackerCacheInvalidator::class, $trackerCache);
        Event::fake([CustomTrackerUpdateRequested::class]);

        $this->get('/index.php?module=API&method=PrivacyManager.setAnonymizeIpSettings'.
            '&anonymizeIPEnable=1&ipAddressMaskLength=3'.
            '&useAnonymizedIpForVisitEnrichment=0&anonymizeUserId=1&anonymizeOrderId=1'.
            '&anonymizeReferrer=exclude_path&forceCookielessTracking=1&randomizeConfigId=1'.
            '&passwordConfirmation=correct&format=json&token_auth=super-token')
            ->assertOk()->assertExactJson(['value' => true]);

        Event::assertDispatched(CustomTrackerUpdateRequested::class);
    }

    public function test_site_admin_removes_site_override_without_password_confirmation(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('siteIdsWithRole')->willReturn([7]);
        $settings = $this->createMock(AnonymisationSettingsRepository::class);
        $settings->expects($this->once())->method('removeSiteSettings')->with(7);
        $passwords = $this->createMock(PasswordConfirmationVerifier::class);
        $passwords->expects($this->never())->method('isCorrect');
        $siteCache = $this->createMock(SiteTrackerCacheInvalidator::class);
        $siteCache->expects($this->once())->method('clear')->with(7);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(AnonymisationSettingsRepository::class, $settings);
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(SiteTrackerCacheInvalidator::class, $siteCache);

        $this->get('/index.php?module=API&method=PrivacyManager.setAnonymizeIpSettings'.
            '&idSiteSpecific=7&anonymizeIPEnable=1&ipAddressMaskLength=2'.
            '&useAnonymizedIpForVisitEnrichment=1&useSiteSpecificSettings=0'.
            '&randomizeConfigId=1&format=json&token_auth=admin-token')
            ->assertOk();
    }
}
