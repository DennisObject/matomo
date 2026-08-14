<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class ApiResponseFactoryStructuredTest extends TestCase
{
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

    private function request(string $format): ApiRequest
    {
        return ApiRequest::fromRequest(Request::create("/index.php?format={$format}"));
    }
}
