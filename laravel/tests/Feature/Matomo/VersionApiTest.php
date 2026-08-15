<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Plugins\PluginState;
use App\Matomo\Security\ClientIpResolver;
use App\Matomo\Security\ConfiguredReportingApiIpAllowlist;
use App\Matomo\Security\ReportingApiIpAllowlist;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Piwik\Version;
use Tests\TestCase;

class VersionApiTest extends TestCase
{
    public function test_plugin_activation_uses_configured_plugins_and_view_access(): void
    {
        $this->bindAuthorizer('token', false, true);
        $plugins = $this->createMock(PluginState::class);
        $plugins->expects($this->once())->method('isActivated')->with('SitesManager')->willReturn(true);
        $this->app->instance(PluginState::class, $plugins);

        $this->get(
            '/index.php?module=API&method=API.isPluginActivated'.
            '&pluginName=SitesManager&format=json&token_auth=token',
        )->assertOk()
            ->assertContent('{"value":true}');
    }

    public function test_plugin_activation_rejects_a_missing_plugin_name(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get('/index.php?module=API&method=API.isPluginActivated&format=json')
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => "Please specify a value for 'pluginName'.",
            ]);
    }

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

    #[DataProvider('legacyBooleanScalarFormats')]
    public function test_legacy_boolean_scalar_formats_keep_exact_output(
        string $parameters,
        string $contentType,
        string $content,
    ): void {
        $request = ApiRequest::fromRequest(
            Request::create('/index.php?'.$parameters),
        );

        $response = $this->app->make(ApiResponseFactory::class)
            ->scalar($request, false);

        $this->assertSame($contentType, $response->headers->get('Content-Type'));
        $this->assertSame($content, $response->getContent());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function legacyBooleanScalarFormats(): iterable
    {
        yield 'JSON' => ['format=json', 'application/json; charset=utf-8', '{"value":false}'];
        yield 'XML' => [
            'format=xml',
            'text/xml; charset=utf-8',
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>0</result>",
        ];
        yield 'CSV' => ['format=csv&convertToUnicode=0', 'application/vnd.ms-excel', "value\n0"];
        yield 'HTML' => [
            'format=html',
            'text/html; charset=utf-8',
            "<table id=\"\" border=\"1\">\n<thead>\n\t<tr>\n\t\t<th>value</th>\n\t</tr>\n".
                "</thead>\n<tbody>\n\t<tr>\n\t\t<td>0</td>\n\t</tr>\n</tbody>\n</table>\n",
        ];
        yield 'original' => ['format=original', 'text/plain; charset=utf-8', ''];
        yield 'serialized original' => [
            'format=original&serialize=1',
            'text/plain; charset=utf-8',
            'b:0;',
        ];
        yield 'console' => [
            'format=console',
            'text/plain; charset=utf-8',
            "- 1 ['0' => ] [] [idsubtable = ]<br />\n",
        ];
    }

    #[DataProvider('legacySuccessFormats')]
    public function test_legacy_success_formats_keep_exact_output(
        string $parameters,
        string $contentType,
        string $content,
        ?string $contentDisposition,
    ): void {
        $request = ApiRequest::fromRequest(Request::create('/index.php?'.$parameters));
        $response = $this->app->make(ApiResponseFactory::class)->success($request);

        $this->assertSame($contentType, $response->headers->get('Content-Type'));
        $this->assertSame($content, $response->getContent());

        if ($contentDisposition !== null) {
            $this->assertSame(
                $contentDisposition,
                $response->headers->get('Content-Disposition'),
            );
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string|null}>
     */
    public static function legacySuccessFormats(): iterable
    {
        $spreadsheetDisposition = 'attachment; filename=piwik-report-export.csv';

        yield 'JSON' => [
            'format=json',
            'application/json; charset=utf-8',
            '{"result":"success","message":"ok"}',
            null,
        ];
        yield 'XML' => [
            'format=xml',
            'text/xml; charset=utf-8',
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>\n\t<success message=\"ok\" />\n</result>",
            null,
        ];
        yield 'CSV' => [
            'format=csv',
            'application/vnd.ms-excel',
            "message\nok",
            $spreadsheetDisposition,
        ];
        yield 'TSV' => [
            'format=tsv',
            'application/vnd.ms-excel',
            "message\tok",
            $spreadsheetDisposition,
        ];
        yield 'HTML' => [
            'format=html',
            'text/html; charset=utf-8',
            '<!-- Success: ok -->',
            null,
        ];
        yield 'original' => ['format=original', 'text/plain; charset=utf-8', '1', null];
        yield 'console' => ['format=console', 'text/plain; charset=utf-8', 'Success:ok', null];
        yield 'RSS' => ['format=rss', 'text/xml; charset=utf-8', 'Success:ok', null];
    }

    #[DataProvider('legacyRowListFormats')]
    public function test_legacy_row_list_formats_keep_exact_output(
        string $parameters,
        string $contentType,
        string $content,
    ): void {
        $request = ApiRequest::fromRequest(Request::create('/index.php?'.$parameters));
        $rows = [['idsite' => '1'], ['idsite' => '3']];

        $response = $this->app->make(ApiResponseFactory::class)->rows($request, $rows);

        $this->assertSame($contentType, $response->headers->get('Content-Type'));
        $this->assertSame($content, $response->getContent());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function legacyRowListFormats(): iterable
    {
        yield 'JSON' => [
            'format=json',
            'application/json; charset=utf-8',
            '[{"idsite":"1"},{"idsite":"3"}]',
        ];
        yield 'XML' => [
            'format=xml',
            'text/xml; charset=utf-8',
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>\n".
                "\t<row>\n\t\t<idsite>1</idsite>\n\t</row>\n".
                "\t<row>\n\t\t<idsite>3</idsite>\n\t</row>\n</result>",
        ];
        yield 'CSV' => [
            'format=csv&convertToUnicode=0',
            'application/vnd.ms-excel',
            "idsite\n1\n3",
        ];
        yield 'console' => [
            'format=console',
            'text/plain; charset=utf-8',
            "- 1 ['idsite' => '1'] [] [idsubtable = ]<br />\n".
                "- 2 ['idsite' => '3'] [] [idsubtable = ]<br />\n",
        ];
    }

    #[DataProvider('legacyPhpVersionFormats')]
    public function test_php_version_requires_superuser_and_keeps_exact_output(
        string $parameters,
        string $contentType,
        string $content,
        ?string $contentDisposition,
    ): void {
        $this->bindSuperuserAuthorizer(true);

        $response = $this->withHeader('Authorization', 'Bearer root-token')
            ->get('/index.php?module=API&method=API.getPhpVersion&'.$parameters)
            ->assertOk()
            ->assertHeader('Content-Type', $contentType)
            ->assertContent($content);

        if ($contentDisposition !== null) {
            $response->assertHeader('Content-Disposition', $contentDisposition);
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string|null}>
     */
    public static function legacyPhpVersionFormats(): iterable
    {
        $values = self::phpVersionValues();
        $spreadsheetDisposition = "attachment; filename*=UTF-8''Export";
        $xmlExtra = $values['extra'] === '' ? '<extra />' : '<extra>'.$values['extra'].'</extra>';
        $xml = <<<XML
        <?xml version="1.0" encoding="utf-8" ?>
        <result>
        \t<row>
        \t\t<version>{$values['version']}</version>
        \t\t<major>{$values['major']}</major>
        \t\t<minor>{$values['minor']}</minor>
        \t\t<release>{$values['release']}</release>
        \t\t<versionId>{$values['versionId']}</versionId>
        \t\t{$xmlExtra}
        \t</row>
        </result>
        XML;
        $html = <<<HTML
        <table border="1">
        <thead>
        \t<tr>
        \t\t<th>version</th>
        \t\t<th>major</th>
        \t\t<th>minor</th>
        \t\t<th>release</th>
        \t\t<th>versionId</th>
        \t\t<th>extra</th>
        \t</tr>
        </thead>
        <tbody>
        \t<tr>
        \t\t<td>{$values['version']}</td>
        \t\t<td>{$values['major']}</td>
        \t\t<td>{$values['minor']}</td>
        \t\t<td>{$values['release']}</td>
        \t\t<td>{$values['versionId']}</td>
        \t\t<td>{$values['extra']}</td>
        \t</tr>
        </tbody>
        </table>

        HTML;
        $console = "- 1 ['version' => '{$values['version']}', 'major' => {$values['major']}, ".
            "'minor' => {$values['minor']}, 'release' => {$values['release']}, ".
            "'versionId' => {$values['versionId']}, 'extra' => '{$values['extra']}'] ".
            "[] [idsubtable = ]<br />\n";

        yield 'JSON' => [
            'format=json',
            'application/json; charset=utf-8',
            json_encode($values, JSON_THROW_ON_ERROR),
            null,
        ];
        yield 'XML' => ['format=xml', 'text/xml; charset=utf-8', $xml, null];
        yield 'CSV' => [
            'format=csv&convertToUnicode=0',
            'application/vnd.ms-excel',
            "version,major,minor,release,versionId,extra\n".
                "{$values['version']},{$values['major']},{$values['minor']},".
                "{$values['release']},{$values['versionId']},{$values['extra']}",
            $spreadsheetDisposition,
        ];
        yield 'TSV' => [
            'format=tsv&convertToUnicode=0',
            'application/vnd.ms-excel',
            "version\tmajor\tminor\trelease\tversionId\textra\n".
                "{$values['version']}\t{$values['major']}\t{$values['minor']}\t".
                "{$values['release']}\t{$values['versionId']}\t{$values['extra']}",
            $spreadsheetDisposition,
        ];
        yield 'HTML' => ['format=html', 'text/html; charset=utf-8', $html, null];
        yield 'original' => [
            'format=original',
            'text/plain; charset=utf-8',
            var_export($values, true),
            null,
        ];
        yield 'serialized original' => [
            'format=original&serialize=1',
            'text/plain; charset=utf-8',
            serialize($values),
            null,
        ];
        yield 'console' => ['format=console', 'text/plain; charset=utf-8', $console, null];
        yield 'RSS array error' => [
            'format=rss',
            'text/plain; charset=utf-8',
            "Error: RSS feeds can be generated for one specific website &idSite=X.\n".
                'Please specify only one idSite or consider using &format=XML instead.',
            null,
        ];
    }

    public function test_php_version_rejects_a_non_superuser(): void
    {
        $this->bindSuperuserAuthorizer(false);

        $this->withHeader('Authorization', 'Bearer view-token')
            ->get('/index.php?module=API&method=API.getPhpVersion&format=json')
            ->assertUnauthorized()
            ->assertExactJson([
                'result' => 'error',
                'message' => "You can't access this resource as it requires a 'superuser' access.",
            ]);
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

    public function test_client_ip_uses_the_matomo_proxy_configuration(): void
    {
        $this->bindAuthorizer('token', false, true);
        $this->app->instance(
            ClientIpResolver::class,
            new ClientIpResolver(['HTTP_X_FORWARDED_FOR'], ['10.0.0.0/8'], false),
        );

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->withHeader('X-Forwarded-For', '198.51.100.20, 10.0.0.1')
            ->get('/index.php?module=API&method=API.getIpFromHeader&format=json&token_auth=token')
            ->assertOk()
            ->assertContent('{"value":"198.51.100.20"}');
    }

    public function test_api_ip_allowlist_runs_before_authentication_and_allows_matching_ips(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSomeViewAccess')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(ReportingApiIpAllowlist::class, new ConfiguredReportingApiIpAllowlist(
            clientIps: new ClientIpResolver([], [], true),
            cache: $this->app->make(Repository::class),
            allowlistedIps: ['203.0.113.0/24'],
            appliesToReportingApi: true,
        ));

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->get('/index.php?module=API&method=API.getMatomoVersion&format=json&token_auth=secret')
            ->assertUnauthorized()
            ->assertExactJson([
                'result' => 'error',
                'message' => 'You cannot use this Matomo as your IP 198.51.100.20 is not allowed.',
            ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->get('/index.php?module=API&method=API.getMatomoVersion&format=json&token_auth=secret')
            ->assertOk()
            ->assertContent('{"value":"'.Version::VERSION.'"}');
    }

    public function test_jsonp_callback_is_strictly_validated(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->exactly(2))
            ->method('hasSomeViewAccess')
            ->with($this->callback(
                static fn (ApiAuthentication $authentication): bool => $authentication->token === 'token'
                    && ! $authentication->tokenIsSecure,
            ))
            ->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

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
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->withHeader('Authorization', 'Bearer header-token')
            ->get('/index.php?module=API&method=API.getMatomoVersion&format=json&token_auth=query-token')
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => 'Authentication parameters must not have conflicting values.',
            ]);
    }

    public function test_conflicting_session_flags_are_rejected_before_authentication(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->post(
            '/index.php?module=API&method=API.getMatomoVersion&format=json&force_api_session=1',
            [
                'token_auth' => 'token',
                'force_api_session' => '0',
            ],
        )->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => 'Authentication parameters must not have conflicting values.',
            ]);
    }

    public function test_post_session_credentials_reach_the_authorizer(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasSomeViewAccess')
            ->with($this->callback(
                static fn (ApiAuthentication $authentication): bool => $authentication->token === 'session-token'
                    && $authentication->tokenIsSecure
                    && $authentication->forceSession
                    && $authentication->sessionId === 'session-id',
            ))
            ->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->withUnencryptedCookie('MATOMO_SESSID', 'session-id')
            ->post('/index.php?module=API&method=API.getMatomoVersion&format=json', [
                'token_auth' => 'session-token',
                'force_api_session' => '1',
            ])->assertOk()
            ->assertContent('{"value":"'.Version::VERSION.'"}');
    }

    public function test_array_token_is_rejected_before_anonymous_access(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get('/index.php?module=API&method=API.getMatomoVersion&format=json&token_auth%5B0%5D=secret')
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => 'The API parameter [token_auth] must be a scalar value.',
            ]);
    }

    public function test_unmigrated_api_method_is_not_dispatched(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get('/index.php?module=API&method=PrivacyManager.getAvailablePseudonymizers&format=json')
            ->assertStatus(501)
            ->assertExactJson([
                'result' => 'error',
                'message' => 'This API method has not moved to Laravel yet.',
            ]);
    }

    private function bindAuthorizer(string $token, bool $tokenIsSecure, bool $result): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasSomeViewAccess')
            ->with($this->callback(
                static fn (ApiAuthentication $authentication): bool => $authentication->token === $token
                    && $authentication->tokenIsSecure === $tokenIsSecure,
            ))
            ->willReturn($result);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    private function bindSuperuserAuthorizer(bool $result): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasSuperUserAccess')
            ->with($this->callback(
                static fn (ApiAuthentication $authentication): bool => $authentication->token === 'root-token'
                    || $authentication->token === 'view-token',
            ))
            ->willReturn($result);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    /**
     * @return array{version: string, major: int, minor: int, release: int, versionId: int, extra: string}
     */
    private static function phpVersionValues(): array
    {
        return [
            'version' => PHP_VERSION,
            'major' => PHP_MAJOR_VERSION,
            'minor' => PHP_MINOR_VERSION,
            'release' => PHP_RELEASE_VERSION,
            'versionId' => PHP_VERSION_ID,
            'extra' => PHP_EXTRA_VERSION,
        ];
    }
}
