<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class ApiResponseFactoryStructuredTest extends TestCase
{
    public function test_keeps_site_ids_as_keys_in_json_and_xml(): void
    {
        $rows = [
            1 => ['idsite' => 1, 'name' => 'One'],
            3 => ['idsite' => 3, 'name' => 'Three'],
        ];
        $responses = new ApiResponseFactory;

        $this->assertSame(
            '{"1":{"idsite":1,"name":"One"},"3":{"idsite":3,"name":"Three"}}',
            $responses->keyedRows($this->request('json'), $rows)->getContent(),
        );
        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>\n".
            "\t<row key=\"1\">\n\t\t<idsite>1</idsite>\n\t\t<name>One</name>\n\t</row>\n".
            "\t<row key=\"3\">\n\t\t<idsite>3</idsite>\n\t\t<name>Three</name>\n\t</row>\n".
            '</result>',
            $responses->keyedRows($this->request('xml'), $rows)->getContent(),
        );
    }

    public function test_keeps_null_and_boolean_site_fields_in_legacy_formats(): void
    {
        $values = [
            'idsite' => 7,
            'creator_login' => null,
            'ecommerce' => false,
        ];
        $responses = new ApiResponseFactory;

        $this->assertSame(
            '{"idsite":7,"creator_login":null,"ecommerce":false}',
            $responses->row($this->request('json'), $values)->getContent(),
        );
        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>\n\t<row>\n".
            "\t\t<idsite>7</idsite>\n\t\t<creator_login />\n\t\t<ecommerce>0</ecommerce>\n".
            "\t</row>\n</result>",
            $responses->row($this->request('xml'), $values)->getContent(),
        );
        $this->assertSame(
            "- 1 ['idsite' => 7, 'creator_login' => , 'ecommerce' => ] [] [idsubtable = ]<br />\n",
            $responses->row($this->request('console'), $values)->getContent(),
        );
    }

    public function test_renders_nested_timezone_maps_as_legacy_json_and_xml(): void
    {
        $values = [
            'North America' => ['America/New_York' => 'United States & New York'],
            'UTC' => ['UTC' => 'UTC'],
        ];
        $responses = new ApiResponseFactory;

        $json = $responses->structured($this->request('json'), $values);
        $this->assertSame('application/json; charset=utf-8', $json->headers->get('Content-Type'));
        $this->assertSame(
            '{"North America":{"America\\/New_York":"United States & New York"},"UTC":{"UTC":"UTC"}}',
            $json->getContent(),
        );

        $xml = $responses->structured($this->request('xml'), $values);
        $this->assertSame('text/xml; charset=utf-8', $xml->headers->get('Content-Type'));
        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>\n".
            "\t<North America>\n".
            "\t\t<row key=\"America/New_York\">United States &amp; New York</row>\n".
            "\t</North America>\n".
            "\t<UTC>\n".
            "\t\t<UTC>UTC</UTC>\n".
            "\t</UTC>\n".
            '</result>',
            $xml->getContent(),
        );
    }

    public function test_renders_nested_row_values_as_legacy_json_and_xml(): void
    {
        $rows = [[
            'idsite' => 7,
            'name' => 'Docs',
            'alias_urls' => ['https://docs.test', 'https://www.docs.test'],
        ]];
        $responses = new ApiResponseFactory;

        $this->assertSame(
            '[{"idsite":7,"name":"Docs","alias_urls":["https:\/\/docs.test","https:\/\/www.docs.test"]}]',
            $responses->rows($this->request('json'), $rows)->getContent(),
        );
        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>\n\t<row>\n".
            "\t\t<idsite>7</idsite>\n\t\t<name>Docs</name>\n\t\t<alias_urls>\n".
            "\t\t\t<row key=\"0\">https://docs.test</row>\n".
            "\t\t\t<row key=\"1\">https://www.docs.test</row>\n".
            "\t\t</alias_urls>\n\t</row>\n</result>",
            $responses->rows($this->request('xml'), $rows)->getContent(),
        );
        $this->assertSame(
            "idsite,name,alias_urls_0,alias_urls_1\n".
            '7,Docs,https://docs.test,https://www.docs.test',
            $responses->rows(
                ApiRequest::fromRequest(Request::create(
                    '/index.php?format=csv&convertToUnicode=0',
                )),
                $rows,
            )->getContent(),
        );
    }

    private function request(string $format): ApiRequest
    {
        return ApiRequest::fromRequest(Request::create(
            "/index.php?format={$format}",
        ));
    }
}
