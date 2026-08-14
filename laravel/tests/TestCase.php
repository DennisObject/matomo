<?php

declare(strict_types=1);

namespace Tests;

use App\Matomo\Options\OptionRepository;
use App\Matomo\Security\ClientIpResolver;
use App\Matomo\Security\ReportingApiIpAllowlist;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ClientIpResolver::class, new ClientIpResolver([], [], true));
        $this->app->instance(OptionRepository::class, new class implements OptionRepository
        {
            public function value(string $name): ?string
            {
                return null;
            }
        });
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

            public function groups(): array
            {
                return [];
            }
        });
    }
}
