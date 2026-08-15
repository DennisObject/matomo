<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use Piwik\Version;
use Tests\TestCase;

class ExampleApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));
    }

    public function test_returns_answer_sum_null_and_information_values(): void
    {
        $this->get($this->url('getAnswerToLife'))
            ->assertOk()
            ->assertExactJson(['value' => 42]);
        $this->get($this->url('getSum', ['a' => '12.5', 'b' => '-0.25']))
            ->assertOk()
            ->assertExactJson(['value' => 12.25]);
        $this->get($this->url('getSum', ['a' => 'invalid', 'b' => '2']))
            ->assertOk()
            ->assertExactJson(['value' => 2]);
        $this->get($this->url('getSum', ['a' => '1_000.5', 'b' => '2.5']))
            ->assertOk()
            ->assertExactJson(['value' => 1003]);
        $this->get($this->url('getNull'))
            ->assertOk()
            ->assertExactJson(['result' => 'success', 'message' => 'ok']);
        $this->get($this->url('getMoreInformationAnswerToLife'))
            ->assertOk()
            ->assertJsonPath(
                'value',
                'Check http://en.wikipedia.org/wiki/The_Answer_to_Life,_the_Universe,_and_Everything',
            );
    }

    public function test_returns_description_and_competition_tables(): void
    {
        $this->get($this->url('getDescriptionArray'))
            ->assertOk()
            ->assertExactJson([
                'piwik',
                'free/libre',
                'web analytics',
                'free',
                'Strong message: Свободный Тибет',
            ]);
        $this->get($this->url('getCompetitionDatatable'))
            ->assertOk()
            ->assertExactJson([
                ['name' => 'piwik', 'license' => 'GPL', 'logo' => 'logo.png'],
                ['name' => 'google analytics', 'license' => 'commercial'],
            ]);
        $this->get($this->url('getCompetitionDatatable', ['showMetadata' => '0']))
            ->assertOk()
            ->assertJsonMissingPath('0.logo');
    }

    public function test_returns_multi_array_only_in_structured_formats(): void
    {
        $expected = [
            'Limitation' => [
                'Multi dimensional arrays is only supported by format=JSON',
                'Known limitation',
            ],
            'Second Dimension' => [true, false, 1, 0, 152, 'test', ['42' => 'end']],
        ];

        $this->get($this->url('getMultiArray'))
            ->assertOk()
            ->assertExactJson($expected);
        $this->get($this->url('getMultiArray', ['format' => 'xml']))
            ->assertStatus(500)
            ->assertSee('Data structure returned is not convertible', false);
    }

    public function test_rejects_objects_in_web_formats_and_serializes_original_objects(): void
    {
        $this->get($this->url('getObject'))
            ->assertOk()
            ->assertExactJson([
                'result' => 'error',
                'message' => 'The API cannot handle this data structure.',
            ]);
        $this->get($this->url('getObject', ['format' => 'original', 'serialize' => '1']))
            ->assertOk()
            ->assertContent('O:8:"stdClass":1:{s:5:"great";s:10:"formidable";}');
    }

    public function test_version_requires_view_access(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->exactly(2))
            ->method('hasSomeViewAccess')
            ->with($this->isInstanceOf(ApiAuthentication::class))
            ->willReturnOnConsecutiveCalls(false, true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get($this->url('getMatomoVersion'))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'You must have view access to at least one website.');
        $this->get($this->url('getMatomoVersion'))
            ->assertOk()
            ->assertExactJson(['value' => Version::VERSION]);
    }

    /** @param array<string, string> $parameters */
    private function url(string $method, array $parameters = []): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'ExampleAPI.'.$method,
            'format' => 'json',
            ...$parameters,
        ]);
    }
}
