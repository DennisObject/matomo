<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Sites\SiteRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SitesManagerApiTest extends TestCase
{
    #[DataProvider('siteRoleMethods')]
    public function test_site_role_id_methods_use_the_exact_role(
        string $method,
        SiteAccessRole $role,
    ): void {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithRole')
            ->with(
                $this->isInstanceOf(ApiAuthentication::class),
                $role,
            )
            ->willReturn([4]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get("/index.php?module=API&method={$method}&format=json&token_auth=token")
            ->assertOk()
            ->assertExactJson([4]);
    }

    /**
     * @return iterable<string, array{string, SiteAccessRole}>
     */
    public static function siteRoleMethods(): iterable
    {
        yield 'view' => ['SitesManager.getSitesIdWithViewAccess', SiteAccessRole::View];
        yield 'write' => ['SitesManager.getSitesIdWithWriteAccess', SiteAccessRole::Write];
    }

    public function test_all_site_ids_requires_superuser_and_reads_the_site_store(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasSuperUserAccess')
            ->with($this->callback(
                static fn (ApiAuthentication $authentication): bool => $authentication->token === 'root-token',
            ))
            ->willReturn(true);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())->method('allIds')->willReturn([3, 8]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get('/index.php?module=API&method=SitesManager.getAllSitesId&format=json&token_auth=root-token')
            ->assertOk()
            ->assertExactJson([3, 8]);
    }

    public function test_all_site_ids_rejects_a_non_superuser_before_the_site_store(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(false);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('allIds');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get('/index.php?module=API&method=SitesManager.getAllSitesId&format=json&token_auth=view-token')
            ->assertUnauthorized()
            ->assertExactJson([
                'result' => 'error',
                'message' => "You can't access this resource as it requires a 'superuser' access.",
            ]);
    }

    #[DataProvider('siteIdFormats')]
    public function test_admin_site_ids_keep_exact_legacy_formats(
        string $parameters,
        string $contentType,
        string $content,
        ?string $contentDisposition,
    ): void {
        $this->bindAdminSites([1, 2]);

        $response = $this->get(
            '/index.php?module=API&method=SitesManager.getSitesIdWithAdminAccess'.
            '&token_auth=token&'.$parameters,
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
    public static function siteIdFormats(): iterable
    {
        $spreadsheetDisposition = "attachment; filename*=UTF-8''Export";
        $xml = "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>\n".
            "\t<row>1</row>\n\t<row>2</row>\n</result>";
        $html = <<<HTML
        <table border="1">
        <thead>
        \t<tr>
        \t\t<th>value</th>
        \t</tr>
        </thead>
        <tbody>
        \t<tr>
        \t\t<td>1</td>
        \t</tr>
        \t<tr>
        \t\t<td>2</td>
        \t</tr>
        </tbody>
        </table>

        HTML;
        $console = "- 1 ['0' => 1] [] [idsubtable = ]<br />\n".
            "- 2 ['0' => 2] [] [idsubtable = ]<br />\n";

        yield 'JSON' => ['format=json', 'application/json; charset=utf-8', '[1,2]', null];
        yield 'XML' => ['format=xml', 'text/xml; charset=utf-8', $xml, null];
        yield 'CSV' => [
            'format=csv&convertToUnicode=0',
            'application/vnd.ms-excel',
            "1\n2",
            $spreadsheetDisposition,
        ];
        yield 'TSV' => [
            'format=tsv&convertToUnicode=0',
            'application/vnd.ms-excel',
            "1\n2",
            $spreadsheetDisposition,
        ];
        yield 'HTML' => ['format=html', 'text/html; charset=utf-8', $html, null];
        yield 'original' => [
            'format=original',
            'text/plain; charset=utf-8',
            var_export([1, 2], true),
            null,
        ];
        yield 'serialized original' => [
            'format=original&serialize=1',
            'text/plain; charset=utf-8',
            serialize([1, 2]),
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

    #[DataProvider('emptySiteIdFormats')]
    public function test_no_admin_sites_keep_exact_empty_outputs(
        string $format,
        string $contentType,
        string $content,
    ): void {
        $this->bindAdminSites([]);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSitesIdWithAdminAccess'.
            '&token_auth=invalid&convertToUnicode=0&format='.$format,
        )->assertOk()
            ->assertHeader('Content-Type', $contentType)
            ->assertContent($content);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function emptySiteIdFormats(): iterable
    {
        yield 'JSON' => ['json', 'application/json; charset=utf-8', '[]'];
        yield 'XML' => [
            'xml',
            'text/xml; charset=utf-8',
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result />",
        ];
        yield 'CSV' => ['csv', 'application/vnd.ms-excel', 'No data available'];
        yield 'HTML' => [
            'html',
            'text/html; charset=utf-8',
            "<table border=\"1\">\n<thead>\n\t<tr>\n\t</tr>\n</thead>\n".
                "<tbody>\n</tbody>\n</table>\n",
        ];
        yield 'original' => ['original', 'text/plain; charset=utf-8', var_export([], true)];
        yield 'console' => ['console', 'text/plain; charset=utf-8', "Empty table<br />\n"];
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function bindAdminSites(array $siteIds): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithRole')
            ->with(
                $this->callback(
                    static fn (ApiAuthentication $authentication): bool => $authentication->token !== null,
                ),
                SiteAccessRole::Admin,
            )
            ->willReturn($siteIds);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
