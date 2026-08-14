<?php

declare(strict_types=1);

namespace Tests;

use App\Matomo\Security\ReportingApiIpAllowlist;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ReportingApiIpAllowlist::class, new class implements ReportingApiIpAllowlist
        {
            public function deniedClientIp(Request $request): ?string
            {
                return null;
            }
        });
        $this->app->instance(SiteRepository::class, new class implements SiteRepository
        {
            public function allIds(): array
            {
                return [];
            }
        });
    }
}
