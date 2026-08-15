<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class ExamplePluginApiTest extends TestCase
{
    public function test_returns_both_answers_to_life(): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));

        $this->get($this->url('getAnswerToLife'))
            ->assertOk()
            ->assertExactJson(['value' => 42]);
        $this->get($this->url('getAnswerToLife', ['truth' => '0']))
            ->assertOk()
            ->assertExactJson(['value' => 24]);
        $this->get($this->url('getAnswerToLife', ['truth' => 'invalid']))
            ->assertOk()
            ->assertExactJson(['value' => 42]);
    }

    public function test_returns_static_example_report(): void
    {
        $this->bindViewAccess();
        $this->bindSite();

        $this->get($this->reportUrl('getExampleReport'))
            ->assertOk()
            ->assertExactJson([
                ['label' => 'My Label 1', 'nb_visits' => '1'],
                ['label' => 'My Label 2', 'nb_visits' => '5'],
            ]);
    }

    public function test_returns_both_archived_metrics(): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $archives = $this->createMock(NumericArchiveRepository::class);
        $archives->expects($this->once())
            ->method('pluginMetrics')
            ->with(
                [7],
                $this->isType('array'),
                md5('eventAction==play'),
                ['ExamplePlugin_example_metric', 'ExamplePlugin_example_metric2'],
                'ExamplePlugin',
            )
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [
                'ExamplePlugin_example_metric' => 10,
                'ExamplePlugin_example_metric2' => 1,
            ]]]);
        $this->app->instance(NumericArchiveRepository::class, $archives);

        $this->get($this->reportUrl('getExampleArchivedMetric', ['segment' => 'eventAction==play']))
            ->assertOk()
            ->assertExactJson([
                'ExamplePlugin_example_metric' => 10,
                'ExamplePlugin_example_metric2' => 1,
            ]);
    }

    public function test_returns_segment_hash_after_view_access_check(): void
    {
        $this->bindViewAccess();
        $segments = $this->createMock(SegmentHashResolver::class);
        $segments->expects($this->once())
            ->method('resolve')
            ->with('eventAction==play')
            ->willReturn('0123456789abcdef0123456789abcdef');
        $this->app->instance(SegmentHashResolver::class, $segments);

        $this->get($this->url('getSegmentHash', [
            'idSite' => '7',
            'segment' => 'eventAction==play',
        ]))->assertOk()->assertExactJson([
            'value' => '0123456789abcdef0123456789abcdef',
        ]);
    }

    public function test_requires_segment_for_hash_method(): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));

        $this->get($this->url('getSegmentHash', ['idSite' => '7']))
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'segment'.");
    }

    /** @param array<string, string> $parameters */
    private function url(string $method, array $parameters = []): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'ExamplePlugin.'.$method,
            'format' => 'json',
            ...$parameters,
        ]);
    }

    /** @param array<string, string> $parameters */
    private function reportUrl(string $method, array $parameters = []): string
    {
        return $this->url($method, [
            'idSite' => '7',
            'period' => 'day',
            'date' => '2026-08-14',
            'token_auth' => 'view-token',
            ...$parameters,
        ]);
    }

    private function bindSite(): void
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(SiteRepository::class, $sites);
    }

    private function bindViewAccess(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), 7)
            ->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
