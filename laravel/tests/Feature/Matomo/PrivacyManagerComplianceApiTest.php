<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use Tests\TestCase;

final class PrivacyManagerComplianceApiTest extends TestCase
{
    public function test_compliance_policy_catalog_is_available_without_authentication(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSuperUserAccess');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get('/index.php?module=API&method=PrivacyManager.getCompliancePolicies&format=json')
            ->assertOk()
            ->assertExactJson([[
                'id' => 'cnil_v1',
                'title' => 'CNIL Website Analytics Compliance',
                'description' => 'Shows how the analytics configuration aligns with CNIL guidance for consent-exempt audience measurement. This information is not legal advice and does not guarantee compliance.',
            ]]);
    }
}
