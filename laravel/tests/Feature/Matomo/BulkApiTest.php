<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Api\BulkRequestLimit;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
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
            'urls' => [
                rawurlencode('method=API.getMatomoVersion'),
                rawurlencode('method=API.getPhpVersion'),
            ],
        ]))->assertOk()->json();

        $this->assertIsArray($response);
        $this->assertCount(2, $response);
        $this->assertIsString($response[0]['value'] ?? null);
        $this->assertIsString($response[1]['version'] ?? null);
    }

    public function test_applies_jsonp_only_to_the_outer_response(): void
    {
        $this->bindAuthorizer();

        $response = $this->get('/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'API.getBulkRequest',
            'format' => 'json',
            'callback' => 'callback',
            'urls' => ['method=API.getMatomoVersion&callback=nestedCallback'],
        ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8');

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringStartsWith('callback([{"value":', $content);
        $this->assertStringEndsWith('}])', $content);
        $this->assertStringNotContainsString('nestedCallback', $content);
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

    public function test_skips_a_nested_request_that_inherits_the_bulk_method(): void
    {
        $this->bindAuthorizer();
        $response = $this->get('/index.php?'.http_build_query([
            'module' => 'API', 'method' => 'API.getBulkRequest', 'format' => 'json',
            'urls' => [''],
        ]))->assertOk()->json();

        $this->assertSame([], $response);
    }

    public function test_inherits_outer_parameters_and_header_authentication(): void
    {
        $this->bindAuthorizer(
            static fn (ApiAuthentication $authentication, int $idSite): bool => $authentication->token === 'header-token'
                && $authentication->tokenIsSecure
                && $idSite === 7,
        );

        $response = $this->withHeader('Authorization', 'Bearer header-token')
            ->get('/index.php?'.http_build_query([
                'module' => 'API',
                'method' => 'API.getBulkRequest',
                'format' => 'json',
                'idSite' => 7,
                'urls' => [rawurlencode('method=SitesManager.getSiteUrlsFromId')],
            ]))
            ->assertOk()
            ->json();

        $this->assertSame([[]], $response);
    }

    public function test_allows_a_nested_token_outside_a_session(): void
    {
        $tokens = [];
        $this->bindAuthorizer(
            static function (ApiAuthentication $authentication, int $idSite) use (&$tokens): bool {
                $tokens[] = $authentication->token;

                return $idSite === 7;
            },
        );

        $response = $this->get('/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'API.getBulkRequest',
            'format' => 'json',
            'token_auth' => 'root-token',
            'idSite' => 7,
            'urls' => [
                'method=SitesManager.getSiteUrlsFromId',
                'method=SitesManager.getSiteUrlsFromId&token_auth=nested-token',
            ],
        ]))->assertOk()->json();

        $this->assertSame([[], []], $response);
        $this->assertSame(['root-token', 'nested-token'], $tokens);
    }

    public function test_rejects_nested_authentication_that_changes_a_session(): void
    {
        $this->bindAuthorizer();

        $this->withCookie('MATOMO_SESSID', 'session-id')
            ->post('/index.php?'.http_build_query([
                'module' => 'API',
                'method' => 'API.getBulkRequest',
                'format' => 'json',
            ]), [
                'token_auth' => 'session-token',
                'force_api_session' => '1',
                'urls' => ['method=API.getMatomoVersion&token_auth=different-token'],
            ])
            ->assertBadRequest()
            ->assertJsonPath('result', 'error')
            ->assertJsonPath(
                'message',
                'Authentication parameters must not have conflicting values.',
            );
    }

    public function test_returns_nested_parameter_errors_in_the_bulk_result(): void
    {
        $this->bindAuthorizer();

        $response = $this->get('/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'API.getBulkRequest',
            'format' => 'json',
            'urls' => ['method=SitesManager.getSiteUrlsFromId'],
        ]))->assertOk()->json();

        $this->assertSame('error', $response[0]['result'] ?? null);
        $this->assertStringContainsString('idSite', $response[0]['message'] ?? '');
    }

    public function test_enforces_the_current_request_limit(): void
    {
        $this->bindAuthorizer(requestLimit: 1);

        $this->get('/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'API.getBulkRequest',
            'format' => 'json',
            'urls' => ['method=API.getMatomoVersion', 'method=API.getPhpVersion'],
        ]))
            ->assertBadRequest()
            ->assertJsonPath('message', 'The maximum number of bulk request URLs is 1.');
    }

    /** @param (callable(ApiAuthentication, int): bool)|null $siteAccess */
    private function bindAuthorizer(?callable $siteAccess = null, int $requestLimit = 250): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSomeViewAccess')->willReturn(true);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $authorizer->method('hasViewAccessToSite')->willReturnCallback(
            $siteAccess ?? static fn (): bool => true,
        );
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $limit = $this->createStub(BulkRequestLimit::class);
        $limit->method('current')->willReturn($requestLimit);
        $this->app->instance(BulkRequestLimit::class, $limit);
    }
}
