<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Privacy\ComplianceStatusProvider;
use Tests\TestCase;

final class PrivacyManagerComplianceReadApiTest extends TestCase
{
    public function test_superuser_gets_structured_site_compliance_status(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $status = $this->createMock(ComplianceStatusProvider::class);
        $status->expects($this->once())->method('status')->with(7)->willReturn([
            'complianceModeEnforced' => true,
            'complianceConfigControlled' => false,
            'complianceRequirements' => [[
                'name' => 'Opt out',
                'value' => 'unknown',
                'notes' => 'Set it up manually.',
            ]],
        ]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(ComplianceStatusProvider::class, $status);

        $this->get('/index.php?module=API&method=PrivacyManager.getComplianceStatus'.
            '&idSite=7&complianceType=cnil_v1&format=json&token_auth=super-token')
            ->assertOk()
            ->assertExactJson([
                'complianceModeEnforced' => true,
                'complianceConfigControlled' => false,
                'complianceRequirements' => [[
                    'name' => 'Opt out',
                    'value' => 'unknown',
                    'notes' => 'Set it up manually.',
                ]],
            ]);
    }

    public function test_invalid_policy_is_rejected_before_evaluation(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $status = $this->createMock(ComplianceStatusProvider::class);
        $status->expects($this->never())->method('status');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(ComplianceStatusProvider::class, $status);

        $this->get('/index.php?module=API&method=PrivacyManager.getComplianceStatus'.
            '&idSite=all&complianceType=other&format=json&token_auth=super-token')
            ->assertBadRequest();
    }
}
