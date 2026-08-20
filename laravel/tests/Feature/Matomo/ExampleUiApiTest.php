<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use Tests\TestCase;

class ExampleUiApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));
    }

    public function test_returns_hourly_temperatures(): void
    {
        $rows = $this->get($this->url('getTemperatures'))->assertOk()->json();

        $this->assertIsArray($rows);
        $this->assertCount(24, $rows);
        $this->assertSame('0h', $rows[0]['label']);
        $this->assertSame('23h', $rows[23]['label']);
        $this->assertGreaterThanOrEqual(50, $rows[0]['value']);
        $this->assertLessThanOrEqual(90, $rows[0]['value']);
        $this->assertCount(24, array_unique(array_column($rows, 'value')));
    }

    public function test_returns_planet_ratios_with_optional_metadata(): void
    {
        $this->get($this->url('getPlanetRatios'))
            ->assertOk()
            ->assertJsonPath('0.label', 'Mercury')
            ->assertJsonPath('0.value', 0.382)
            ->assertJsonPath('2.label', 'Earth')
            ->assertJsonPath('2.value', 1)
            ->assertJsonPath('7.label', 'Neptune')
            ->assertJsonMissingPath('0.logo');
        $this->get($this->url('getPlanetRatiosWithLogos'))
            ->assertOk()
            ->assertJsonPath('0.logo', 'plugins/ExampleUI/images/icons-planet/mercury.png')
            ->assertJsonPath('0.url', 'http://en.wikipedia.org/wiki/Mercury');
        $this->get($this->url('getPlanetRatiosWithLogos', ['showMetadata' => '0']))
            ->assertOk()
            ->assertJsonMissingPath('0.logo')
            ->assertJsonMissingPath('0.url');
    }

    public function test_returns_fixed_thirty_period_temperature_evolution(): void
    {
        $rows = $this->get($this->url('getTemperaturesEvolution', [
            'date' => 'ignored',
            'period' => 'day',
        ]))->assertOk()->json();

        $this->assertIsArray($rows);
        $this->assertCount(30, $rows);
        $this->assertSame('Wed, Sep 11', $rows[0]['label']);
        $this->assertSame('Thu, Oct 10', $rows[29]['label']);
        $this->assertGreaterThanOrEqual(50, $rows[0]['server1']);
        $this->assertLessThanOrEqual(90, $rows[0]['server1']);
        $this->assertGreaterThanOrEqual(40, $rows[0]['server2']);
        $this->assertLessThanOrEqual(110, $rows[0]['server2']);

        $yearRows = $this->get($this->url('getTemperaturesEvolution', [
            'date' => 'ignored',
            'period' => 'year',
        ]))->assertOk()->json();
        $this->assertIsArray($yearRows);
        $this->assertCount(10, $yearRows);
        $this->assertSame('2004', $yearRows[0]['label']);
        $this->assertSame('2013', $yearRows[9]['label']);
    }

    public function test_requires_evolution_parameters_and_rejects_invalid_period(): void
    {
        $this->get($this->url('getTemperaturesEvolution'))
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'date'.");
        $this->get($this->url('getTemperaturesEvolution', ['date' => 'ignored']))
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'period'.");
        $this->get($this->url('getTemperaturesEvolution', [
            'date' => 'ignored',
            'period' => 'invalid',
        ]))->assertBadRequest()->assertJsonPath('message', "The period 'invalid' is not supported.");
    }

    /** @param array<string, string> $parameters */
    private function url(string $method, array $parameters = []): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'ExampleUI.'.$method,
            'format' => 'json',
            ...$parameters,
        ]);
    }
}
