<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use Tests\TestCase;

class InsightsCapabilityApiTest extends TestCase
{
    public function test_reports_supported_fixed_and_range_dates(): void
    {
        $this->bindSomeViewAccess(true);

        foreach ([
            ['day', '2012-12-12'],
            ['week', '2012-12-12'],
            ['month', '2012-12-12'],
            ['range', '2012-11-11,2012-12-12'],
        ] as [$period, $date]) {
            $this->get($this->url($period, $date))
                ->assertOk()
                ->assertExactJson(['value' => true]);
        }
    }

    public function test_rejects_multi_period_and_invalid_dates_as_unsupported(): void
    {
        $this->bindSomeViewAccess(true);

        foreach ([
            ['day', 'last10'],
            ['day', 'not-a-date'],
            ['range', '2012-12-12,2012-11-11'],
            ['quarter', '2012-12-12'],
        ] as [$period, $date]) {
            $this->get($this->url($period, $date))
                ->assertOk()
                ->assertExactJson(['value' => false]);
        }
    }

    public function test_requires_view_access_to_at_least_one_site(): void
    {
        $this->bindSomeViewAccess(false);

        $this->get($this->url('day', '2012-12-12'))
            ->assertUnauthorized()
            ->assertJsonPath('result', 'error');
    }

    private function bindSomeViewAccess(bool $allowed): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->atLeastOnce())
            ->method('hasSomeViewAccess')
            ->willReturn($allowed);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    private function url(string $period, string $date): string
    {
        return '/index.php?module=API&method=Insights.canGenerateInsights'.
            '&period='.urlencode($period).'&date='.urlencode($date).
            '&format=json&token_auth=view-token';
    }
}
