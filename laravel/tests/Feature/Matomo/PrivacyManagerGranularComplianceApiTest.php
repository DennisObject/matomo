<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Privacy\GranularComplianceSettingsProvider;
use App\Matomo\Privacy\PrivacyFeatureFlags;
use Tests\TestCase;

final class PrivacyManagerGranularComplianceApiTest extends TestCase
{
    public function test_enabled_feature_returns_global_policy_settings(): void
    {
        $this->authorize();
        $features = $this->createStub(PrivacyFeatureFlags::class);
        $features->method('granularComplianceEnabled')->willReturn(true);
        $settings = $this->createMock(GranularComplianceSettingsProvider::class);
        $settings->expects($this->once())->method('settings')->with(null, 'en')->willReturn([
            'policy' => 'cnil_v1',
            'title' => 'CNIL Website Analytics Compliance',
            'description' => 'Policy guidance.',
            'configControlled' => false,
            'policyEnforced' => false,
            'settings' => [],
        ]);
        $this->app->instance(PrivacyFeatureFlags::class, $features);
        $this->app->instance(GranularComplianceSettingsProvider::class, $settings);

        $this->get('/index.php?module=API&method=PrivacyManager.getCompliancePolicySettings'.
            '&idSite=all&compliancePolicy=cnil_v1&format=json&token_auth=super-token')
            ->assertOk()
            ->assertJsonPath('policy', 'cnil_v1');
    }

    public function test_disabled_feature_rejects_request_before_loading_settings(): void
    {
        $this->authorize();
        $features = $this->createStub(PrivacyFeatureFlags::class);
        $features->method('granularComplianceEnabled')->willReturn(false);
        $settings = $this->createMock(GranularComplianceSettingsProvider::class);
        $settings->expects($this->never())->method('settings');
        $this->app->instance(PrivacyFeatureFlags::class, $features);
        $this->app->instance(GranularComplianceSettingsProvider::class, $settings);

        $this->get('/index.php?module=API&method=PrivacyManager.getCompliancePolicySettings'.
            '&idSite=all&compliancePolicy=cnil_v1&format=json&token_auth=super-token')
            ->assertBadRequest();
    }

    private function authorize(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
