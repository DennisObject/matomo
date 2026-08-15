<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Reporting\CarbonReportingPeriodFactory;
use App\Matomo\Transitions\ConfiguredTransitionsPeriodPolicy;
use App\Matomo\Transitions\TransitionsSettings;
use PHPUnit\Framework\TestCase;

class ConfiguredTransitionsPeriodPolicyTest extends TestCase
{
    public function test_limits_period_labels_and_inclusive_date_ranges(): void
    {
        $settings = $this->createStub(TransitionsSettings::class);
        $settings->method('maxPeriodAllowed')->willReturn('week');
        $policy = new ConfiguredTransitionsPeriodPolicy(
            $settings,
            new CarbonReportingPeriodFactory,
        );

        $this->assertTrue($policy->isAllowed(7, 'day', '2026-08-15'));
        $this->assertTrue($policy->isAllowed(7, 'week', '2026-08-15'));
        $this->assertFalse($policy->isAllowed(7, 'month', '2026-08-15'));
        $this->assertTrue($policy->isAllowed(7, 'range', '2026-08-01,2026-08-07'));
        $this->assertFalse($policy->isAllowed(7, 'range', '2026-08-01,2026-08-08'));
    }

    public function test_all_matches_the_legacy_unrestricted_behavior(): void
    {
        $settings = $this->createStub(TransitionsSettings::class);
        $settings->method('maxPeriodAllowed')->willReturn('all');
        $policy = new ConfiguredTransitionsPeriodPolicy(
            $settings,
            new CarbonReportingPeriodFactory,
        );

        $this->assertTrue($policy->isAllowed(7, 'unsupported', 'invalid'));
    }
}
