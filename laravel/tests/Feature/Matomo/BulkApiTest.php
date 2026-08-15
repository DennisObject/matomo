<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use Tests\TestCase;

final class BulkApiTest extends TestCase
{
    public function test_dispatches_multiple_native_api_requests(): void
    {
        $this->bindAuthorizer();
        $response = $this->get('/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'API.getBulkRequest',
            'format' => 'json',
            'token_auth' => 'token',
            'urls' => ['method=API.getMatomoVersion', 'method=API.getPhpVersion'],
        ]))->assertOk()->json();

        $this->assertIsArray($response);
        $this->assertCount(2, $response);
        $this->assertIsString($response[0]['value'] ?? null);
        $this->assertIsString($response[1]['version'] ?? null);
    }

    public function test_skips_recursive_bulk_requests(): void
    {
        $this->bindAuthorizer();
        $response = $this->get('/index.php?'.http_build_query([
            'module' => 'API', 'method' => 'API.getBulkRequest', 'format' => 'json', 'token_auth' => 'token',
            'urls' => ['method=API.getBulkRequest'],
        ]))->assertOk()->json();

        $this->assertSame([], $response);
    }

    private function bindAuthorizer(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSomeViewAccess')->willReturn(true);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
