<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\VersionAccessAuthorizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Piwik\Version;
use Tests\TestCase;

class VersionApiTest extends TestCase
{
    public function test_json_version_request_keeps_the_query_api_contract(): void
    {
        $this->bindAuthorizer('secret-token', true, true);

        $this->withHeader('Authorization', 'Bearer secret-token')
            ->get('/index.php?module=API&method=API.getMatomoVersion&format=JSON')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json; charset=utf-8')
            ->assertHeaderMissing('Set-Cookie')
            ->assertContent('{"value":"'.Version::VERSION.'"}');
    }

    public function test_deprecated_version_alias_keeps_the_default_xml_contract(): void
    {
        $this->bindAuthorizer('post-token', true, true);

        $this->post('/index.php?module=API&method=API.getPiwikVersion', [
            'token_auth' => 'post-token',
        ])->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=utf-8')
            ->assertContent(
                "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>".Version::VERSION.'</result>',
            );
    }

    #[DataProvider('legacyScalarFormats')]
    public function test_legacy_scalar_formats_keep_exact_output(
        string $parameters,
        string $contentType,
        string $content,
        ?string $contentDisposition,
    ): void {
        $this->bindAuthorizer('token', false, true);

        $response = $this->get(
            '/index.php?module=API&method=API.getMatomoVersion&token_auth=token&'.$parameters,
        )->assertOk()
            ->assertHeader('Content-Type', $contentType)
            ->assertContent($content);

        if ($contentDisposition !== null) {
            $response->assertHeader('Content-Disposition', $contentDisposition);
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string|null}>
     */
    public static function legacyScalarFormats(): iterable
    {
        $version = Version::VERSION;
        $unicodeSpreadsheet = "\xFF\xFE".mb_convert_encoding(
            "value\n{$version}",
            'UTF-16LE',
            'UTF-8',
        );
        $spreadsheetDisposition = "attachment; filename*=UTF-8''Export";
        $html = <<<HTML
        <table id="API_getMatomoVersion" border="1">
        <thead>
        \t<tr>
        \t\t<th>value</th>
        \t</tr>
        </thead>
        <tbody>
        \t<tr>
        \t\t<td>{$version}</td>
        \t</tr>
        </tbody>
        </table>

        HTML;

        yield 'CSV' => [
            'format=csv',
            'application/vnd.ms-excel',
            $unicodeSpreadsheet,
            $spreadsheetDisposition,
        ];
        yield 'TSV' => [
            'format=tsv',
            'application/vnd.ms-excel',
            $unicodeSpreadsheet,
            $spreadsheetDisposition,
        ];
        yield 'CSV without UTF-16 conversion' => [
            'format=csv&convertToUnicode=0',
            'application/vnd.ms-excel',
            "value\n{$version}",
            $spreadsheetDisposition,
        ];
        yield 'HTML' => ['format=html', 'text/html; charset=utf-8', $html, null];
        yield 'original' => ['format=original', 'text/plain; charset=utf-8', $version, null];
        yield 'serialized original' => [
            'format=original&serialize=1',
            'text/plain; charset=utf-8',
            serialize($version),
            null,
        ];
        yield 'console' => [
            'format=console',
            'text/plain; charset=utf-8',
            "- 1 ['0' => '{$version}'] [] [idsubtable = ]<br />\n",
            null,
        ];
        yield 'console without metadata' => [
            'format=console&showMetadata=0',
            'text/plain; charset=utf-8',
            "- 1 ['0' => '{$version}']<br />\n",
            null,
        ];
        yield 'RSS scalar error' => [
            'format=rss',
            'text/plain; charset=utf-8',
            "Error: RSS feeds can be generated for one specific website &idSite=X.\n".
                'Please specify only one idSite or consider using &format=XML instead.',
            null,
        ];
    }

    public function test_query_parameters_keep_priority_over_post_parameters(): void
    {
        $this->bindAuthorizer('post-token', true, true);

        $this->post('/index.php?module=API&method=API.getMatomoVersion&format=json', [
            'method' => 'SitesManager.getAllSites',
            'format' => 'xml',
            'token_auth' => 'post-token',
        ])->assertOk()
            ->assertHeader('Content-Type', 'application/json; charset=utf-8')
            ->assertContent('{"value":"'.Version::VERSION.'"}');
    }

    public function test_null_bytes_are_removed_from_api_parameters(): void
    {
        $this->bindAuthorizer('token', false, true);

        $this->get('/index.php?module=API&method=API.getMatomo%00Version&format=json&token_auth=token')
            ->assertOk()
            ->assertContent('{"value":"'.Version::VERSION.'"}');
    }

    public function test_invalid_token_returns_the_matomo_error_shape(): void
    {
        $this->bindAuthorizer('invalid-token', false, false);

        $this->get('/index.php?module=API&method=API.getMatomoVersion&format=json&token_auth=invalid-token')
            ->assertUnauthorized()
            ->assertExactJson([
                'result' => 'error',
                'message' => 'You must have view access to at least one website.',
            ]);
    }

    public function test_jsonp_callback_is_strictly_validated(): void
    {
        $authorizer = $this->createMock(VersionAccessAuthorizer::class);
        $authorizer->expects($this->exactly(2))
            ->method('hasSomeViewAccess')
            ->with('token', false)
            ->willReturn(true);
        $this->app->instance(VersionAccessAuthorizer::class, $authorizer);

        $this->get('/index.php?module=API&method=API.getMatomoVersion&format=json&token_auth=token&callback=app.callback')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertContent('app.callback({"value":"'.Version::VERSION.'"})');

        $this->get('/index.php?module=API&method=API.getMatomoVersion&format=json&token_auth=token&callback=alert(1)')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json; charset=utf-8')
            ->assertContent('{"value":"'.Version::VERSION.'"}');
    }

    public function test_conflicting_tokens_are_rejected_before_authentication(): void
    {
        $authorizer = $this->createMock(VersionAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $this->app->instance(VersionAccessAuthorizer::class, $authorizer);

        $this->withHeader('Authorization', 'Bearer header-token')
            ->get('/index.php?module=API&method=API.getMatomoVersion&format=json&token_auth=query-token')
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => 'Authentication parameters must not have conflicting values.',
            ]);
    }

    public function test_array_token_is_rejected_before_anonymous_access(): void
    {
        $authorizer = $this->createMock(VersionAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $this->app->instance(VersionAccessAuthorizer::class, $authorizer);

        $this->get('/index.php?module=API&method=API.getMatomoVersion&format=json&token_auth%5B0%5D=secret')
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => 'The API parameter [token_auth] must be a scalar value.',
            ]);
    }

    public function test_unmigrated_api_method_is_not_dispatched(): void
    {
        $authorizer = $this->createMock(VersionAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $this->app->instance(VersionAccessAuthorizer::class, $authorizer);

        $this->get('/index.php?module=API&method=SitesManager.getAllSites&format=json')
            ->assertStatus(501)
            ->assertExactJson([
                'result' => 'error',
                'message' => 'This API method has not moved to Laravel yet.',
            ]);
    }

    private function bindAuthorizer(string $token, bool $tokenIsSecure, bool $result): void
    {
        $authorizer = $this->createMock(VersionAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasSomeViewAccess')
            ->with($token, $tokenIsSecure)
            ->willReturn($result);
        $this->app->instance(VersionAccessAuthorizer::class, $authorizer);
    }
}
