<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Login\BruteForceUnblocker;
use Tests\TestCase;

class LoginApiTest extends TestCase
{
    public function test_superuser_unblocks_currently_blocked_ips(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $unblocker = $this->createMock(BruteForceUnblocker::class);
        $unblocker->expects($this->once())->method('unblockCurrentlyBlocked')->willReturn(12);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(BruteForceUnblocker::class, $unblocker);

        $this->post(
            '/index.php?module=API&method=Login.unblockBruteForceIPs'.
            '&format=json&token_auth=super-token',
        )->assertOk()->assertExactJson(['result' => 'success', 'message' => 'ok']);
    }

    public function test_non_superuser_cannot_unblock_ips(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(false);
        $unblocker = $this->createMock(BruteForceUnblocker::class);
        $unblocker->expects($this->never())->method('unblockCurrentlyBlocked');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(BruteForceUnblocker::class, $unblocker);

        $this->post(
            '/index.php?module=API&method=Login.unblockBruteForceIPs'.
            '&format=json&token_auth=admin-token',
        )->assertStatus(401)->assertExactJson([
            'result' => 'error',
            'message' => "You can't access this resource as it requires a 'superuser' access.",
        ]);
    }
}
