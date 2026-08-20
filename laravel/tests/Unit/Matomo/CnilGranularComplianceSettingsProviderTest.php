<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Privacy\CnilGranularComplianceSettingsProvider;
use App\Matomo\Privacy\CompliancePolicyCatalog;
use App\Matomo\Privacy\CompliancePolicyStateRepository;
use App\Matomo\Privacy\ComplianceStatusProvider;
use PHPUnit\Framework\TestCase;

final class CnilGranularComplianceSettingsProviderTest extends TestCase
{
    public function test_builds_legacy_granular_setting_shape_and_statuses(): void
    {
        $names = [
            'Device model detection disabled',
            'Only Major versions',
            'Ecommerce Restricted',
            'Ecommerce - Order ID anonymisation',
            'PII data filtered',
            'Turn off Visits log and Visitor profiles',
            'Campaign parameter values masked',
            'Segmented data rounding enabled',
            'IP Anonymisation Enabled',
            'IP Address Mask Length',
            'Referrer Anonymisation',
            'Data retention period',
            'Limit available segments',
            'Screen resolution detection disabled',
            'User ID disabled',
            'Third-party cookies',
            'Opt out',
        ];
        $requirements = array_map(
            static fn (string $name): array => [
                'name' => $name,
                'value' => $name === 'Opt out' ? 'unknown' : 'compliant',
                'notes' => $name === 'Opt out' ? 'Manual setup is required.' : '',
            ],
            $names,
        );
        $status = $this->createStub(ComplianceStatusProvider::class);
        $status->method('status')->willReturn([
            'complianceModeEnforced' => true,
            'complianceConfigControlled' => false,
            'complianceRequirements' => $requirements,
        ]);
        $policies = $this->createStub(CompliancePolicyStateRepository::class);
        $policies->method('settingEnforced')->willReturn(true);
        $policies->method('configControlled')->willReturn(false);
        $provider = new CnilGranularComplianceSettingsProvider(
            $status,
            $policies,
            new CompliancePolicyCatalog($this->createStub(MatomoTranslator::class)),
        );

        $result = $provider->settings(7, 'en');

        $this->assertSame('cnil_v1', $result['policy']);
        $this->assertTrue($result['policyEnforced']);
        $this->assertCount(17, $result['settings']);
        $this->assertSame('enforced', $result['settings'][0]['status']);
        $this->assertSame('DevicesDetection.DeviceModelDetectionDisabled', $result['settings'][0]['id']);
        $this->assertSame('on_by_default', $result['settings'][15]['status']);
        $this->assertSame('external', $result['settings'][15]['section']);
        $this->assertSame('manual', $result['settings'][16]['status']);
        $this->assertSame('Manual setup is required.', $result['settings'][16]['whatItDoes']);
    }
}
