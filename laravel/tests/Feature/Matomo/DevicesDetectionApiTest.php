<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Reporting\DeviceModelPolicy;
use App\Matomo\Sites\SiteRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DevicesDetectionApiTest extends TestCase
{
    /**
     * @param  array<string, string>  $metadata
     */
    #[DataProvider('reportProvider')]
    public function test_returns_each_device_detection_report(
        string $method,
        string $record,
        int|string $rawLabel,
        string $expectedLabel,
        array $metadata,
    ): void {
        $this->bindViewAccess([7]);
        $this->bindSites();
        $archives = $this->createStub(BlobArchiveRepository::class);
        $archives->method('rows')->willReturnCallback(
            fn (array $siteIds, array $periods, string $segmentHash, string $requestedRecord): array => [
                7 => ['2026-08-14,2026-08-14' => $requestedRecord === $record
                    ? [$this->row($rawLabel, 3)]
                    : []],
            ],
        );
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $response = $this->get($this->url($method, ['showMetadata' => '1']))->assertOk();

        $response->assertJsonPath('0.label', $expectedLabel)
            ->assertJsonPath('0.nb_visits', 3)
            ->assertJsonPath('0.nb_visits_percent_of_total', '100%');

        foreach ($metadata as $key => $value) {
            $response->assertJsonPath('0.'.$key, $value);
        }
    }

    /**
     * @return iterable<string, array{string, string, int|string, string, array<string, string>}>
     */
    public static function reportProvider(): iterable
    {
        yield 'device type' => [
            'getType',
            'DevicesDetection_types',
            1,
            'Smartphone',
            [
                'segment' => 'deviceType==smartphone',
                'logo' => 'plugins/Morpheus/icons/dist/devices/smartphone.png',
            ],
        ];
        yield 'brand' => [
            'getBrand',
            'DevicesDetection_brands',
            'AP',
            'Apple',
            [
                'segment' => 'deviceBrand==Apple',
                'logo' => 'plugins/Morpheus/icons/dist/brand/unk.png',
            ],
        ];
        yield 'model' => [
            'getModel',
            'DevicesDetection_models',
            'AP;iPhone',
            'Apple - iPhone',
            ['segment' => 'deviceBrand==Apple;deviceModel==iPhone'],
        ];
        yield 'operating system family' => [
            'getOsFamilies',
            'DevicesDetection_os',
            'WIN',
            'Windows',
            ['logo' => 'plugins/Morpheus/icons/dist/os/UNK.png'],
        ];
        yield 'operating system version' => [
            'getOsVersions',
            'DevicesDetection_osVersions',
            'WIN;10',
            'Windows 10',
            [
                'segment' => 'operatingSystemCode==WIN;operatingSystemVersion==10',
                'logo' => 'plugins/Morpheus/icons/dist/os/UNK.png',
            ],
        ];
        yield 'browser family' => [
            'getBrowsers',
            'DevicesDetection_browsers',
            'CH',
            'Chrome',
            [
                'segmentValue' => 'CH',
                'logo' => 'plugins/Morpheus/icons/dist/browsers/UNK.png',
            ],
        ];
        yield 'browser version' => [
            'getBrowserVersions',
            'DevicesDetection_browserVersions',
            'CH;120',
            'Chrome 120',
            [
                'segment' => 'browserCode==CH;browserVersion==120',
                'logo' => 'plugins/Morpheus/icons/dist/browsers/UNK.png',
            ],
        ];
        yield 'browser engine' => [
            'getBrowserEngines',
            'DevicesDetection_browserEngines',
            'webkit',
            'WebKit (Safari)',
            ['segmentValue' => 'webkit'],
        ];
    }

    public function test_type_report_adds_every_known_device_type_when_data_exists(): void
    {
        $this->bindViewAccess([7]);
        $this->bindSites();
        $archives = $this->createStub(BlobArchiveRepository::class);
        $archives->method('rows')->willReturn([
            7 => ['2026-08-14,2026-08-14' => [$this->row(0, 2)]],
        ]);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $rows = $this->get($this->url('getType'))->assertOk()->json();

        $this->assertIsArray($rows);
        $this->assertCount(14, $rows);
        $this->assertSame('Desktop', $rows[0]['label']);
        $this->assertSame(2, $rows[0]['nb_visits']);
        $this->assertSame(0, $rows[13]['nb_visits']);
    }

    public function test_family_reports_fall_back_to_version_archives(): void
    {
        $this->bindViewAccess([7, 7]);
        $this->bindSites();
        $archives = $this->createStub(BlobArchiveRepository::class);
        $archives->method('rows')->willReturnCallback(
            fn (array $siteIds, array $periods, string $segmentHash, string $record): array => [
                7 => ['2026-08-14,2026-08-14' => match ($record) {
                    'DevicesDetection_osVersions' => [$this->row('WIN;10', 2)],
                    'DevicesDetection_browserVersions' => [$this->row('CH;120', 4)],
                    default => [],
                }],
            ],
        );
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $this->get($this->url('getOsFamilies'))
            ->assertOk()
            ->assertJsonPath('0.label', 'Windows')
            ->assertJsonPath('0.nb_visits', 2);
        $this->get($this->url('getBrowsers'))
            ->assertOk()
            ->assertJsonPath('0.label', 'Chrome')
            ->assertJsonPath('0.nb_visits', 4)
            ->assertJsonMissingPath('0.segmentValue');
    }

    public function test_model_report_filters_disabled_sites_and_rejects_when_none_remain(): void
    {
        $this->bindViewAccess([7, 8]);
        $this->bindSites();
        $policy = $this->createStub(DeviceModelPolicy::class);
        $policy->method('detectionDisabled')->willReturnCallback(fn (int $idSite): bool => $idSite === 7);
        $archives = $this->createMock(BlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('rows')
            ->with([8], $this->isType('array'), '', 'DevicesDetection_models')
            ->willReturn([]);
        $this->app->instance(DeviceModelPolicy::class, $policy);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $this->get($this->url('getModel', ['idSite' => '7,8']))
            ->assertOk()
            ->assertExactJson(['8' => []]);
    }

    public function test_model_report_rejects_a_fully_disabled_request(): void
    {
        $this->bindViewAccess([7]);
        $policy = $this->createStub(DeviceModelPolicy::class);
        $policy->method('detectionDisabled')->willReturn(true);
        $this->app->instance(DeviceModelPolicy::class, $policy);

        $this->get($this->url('getModel'))
            ->assertBadRequest()
            ->assertJsonPath('message', 'Device model report is disabled by compliance policy.');
    }

    /** @return array{columns: array{label: int|string, nb_visits: int}, metadata: array{}} */
    private function row(int|string $label, int $visits): array
    {
        return [
            'columns' => ['label' => $label, 'nb_visits' => $visits],
            'metadata' => [],
        ];
    }

    /** @param array<string, string> $parameters */
    private function url(string $method, array $parameters = []): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'DevicesDetection.'.$method,
            'idSite' => '7',
            'period' => 'day',
            'date' => '2026-08-14',
            'format' => 'json',
            'token_auth' => 'view-token',
            ...$parameters,
        ]);
    }

    private function bindSites(): void
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(SiteRepository::class, $sites);
    }

    /** @param list<int> $siteIds */
    private function bindViewAccess(array $siteIds): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->exactly(count($siteIds)))
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), $this->isType('int'))
            ->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
