<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use Tests\TestCase;

final class PrivacyManagerComplianceApiTest extends TestCase
{
    public function test_compliance_policy_catalog_is_available_without_authentication(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSuperUserAccess');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $languages = $this->createStub(LanguageResolver::class);
        $languages->method('resolve')->willReturn('fr');
        $this->app->instance(LanguageResolver::class, $languages);

        $response = $this->get('/index.php?module=API'.
            '&method=PrivacyManager.getCompliancePolicies&language=fr&format=json')
            ->assertOk()
            ->assertJsonPath('0.id', 'cnil_v1')
            ->assertJsonPath('0.title', 'Conformité des statistiques du site web à la CNIL');

        $description = $response->json('0.description');
        $this->assertIsString($description);
        $this->assertStringContainsString('mtm_medium=App.PrivacyManager.compliance', $description);
        $this->assertStringContainsString('plugins tiers', $description);
    }
}
