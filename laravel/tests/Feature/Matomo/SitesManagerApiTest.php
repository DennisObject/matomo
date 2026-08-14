<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SitesManagerApiTest extends TestCase
{
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
            ->method('siteIdsWithAdminAccess')
            ->with($this->callback(
                static fn (ApiAuthentication $authentication): bool => $authentication->token !== null,
            ))
            ->willReturn($siteIds);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
