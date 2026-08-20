<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Archiving\VisitSegmentApplicator;
use App\Matomo\Geolocation\CountryMetadataProvider;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Privacy\DatabaseDataSubjectFinder;
use App\Matomo\Reporting\DeviceDetectionMetadata;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabaseDataSubjectFinderTest extends TestCase
{
    public function test_search_applies_segment_formats_identity_and_excludes_disabled_site(): void
    {
        $connection = $this->app->make('db')->connection();
        $this->tables($connection);
        $connection->table('log_visit')->insert([
            $this->visit(11, 2, 'person', '2026-08-03 12:00:00'),
            $this->visit(12, 2, 'other', '2026-08-04 12:00:00'),
            $this->visit(13, 7, 'person', '2026-08-05 12:00:00'),
        ]);
        $connection->table('site_setting')->insert([
            'idsite' => 7,
            'plugin_name' => 'Live',
            'setting_name' => 'disable_visitor_profile',
            'setting_value' => '1',
        ]);

        $segments = $this->createMock(VisitSegmentApplicator::class);
        $segments->expects($this->once())->method('apply')
            ->with($this->isInstanceOf(Builder::class), 'userId==person')
            ->willReturnCallback(static function (Builder $query): bool {
                $query->where('user_id', 'person');

                return true;
            });
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('details')->willReturn(['name' => 'Example']);
        $countries = $this->createStub(CountryMetadataProvider::class);
        $countries->method('countryName')->willReturn('New Zealand');
        $countries->method('regionName')->willReturn('Wellington');
        $countries->method('flag')->willReturn('flag.png');
        $translator = $this->createStub(MatomoTranslator::class);
        $translator->method('translate')->willReturn('Unknown');
        $finder = new DatabaseDataSubjectFinder(
            $connection,
            $segments,
            $sites,
            new DeviceDetectionMetadata($translator, dirname(__DIR__, 4)),
            $countries,
        );

        $rows = $finder->find([2, 7], 'userId==person', 'en');

        $this->assertCount(1, $rows);
        $this->assertSame(11, $rows[0]['idVisit']);
        $this->assertSame('0102', $rows[0]['visitorId']);
        $this->assertSame('127.0.0.1', $rows[0]['visitIp']);
        $this->assertSame('Example', $rows[0]['siteName']);
        $this->assertSame('New Zealand', $rows[0]['country']);
    }

    public function test_global_live_privacy_setting_excludes_every_site(): void
    {
        $connection = $this->app->make('db')->connection();
        $this->tables($connection);
        $connection->table('log_visit')->insert($this->visit(11, 2, 'person', '2026-08-03 12:00:00'));
        $connection->table('plugin_setting')->insert([
            'plugin_name' => 'Live',
            'user_login' => '',
            'setting_name' => 'disable_visitor_profile',
            'setting_value' => '1',
        ]);
        $segments = $this->createMock(VisitSegmentApplicator::class);
        $segments->expects($this->never())->method('apply');
        $translator = $this->createStub(MatomoTranslator::class);
        $finder = new DatabaseDataSubjectFinder(
            $connection,
            $segments,
            $this->createStub(SiteRepository::class),
            new DeviceDetectionMetadata($translator, dirname(__DIR__, 4)),
            $this->createStub(CountryMetadataProvider::class),
        );

        $this->assertSame([], $finder->find([2], 'userId==person', 'en'));
    }

    private function tables(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();
        $schema->dropIfExists('log_visit');
        $schema->dropIfExists('site_setting');
        $schema->dropIfExists('plugin_setting');
        $schema->create('log_visit', static function (Blueprint $table): void {
            $table->integer('idvisit')->primary();
            $table->integer('idsite');
            $table->dateTime('visit_last_action_time');
            $table->binary('idvisitor');
            $table->binary('location_ip');
            $table->string('user_id')->nullable();
            $table->integer('config_device_type')->nullable();
            $table->string('config_device_model')->nullable();
            $table->string('config_os')->nullable();
            $table->string('config_os_version')->nullable();
            $table->string('config_browser_name')->nullable();
            $table->string('config_browser_version')->nullable();
            $table->string('location_country')->nullable();
            $table->string('location_region')->nullable();
        });
        $schema->create('site_setting', static function (Blueprint $table): void {
            $table->integer('idsite');
            $table->string('plugin_name');
            $table->string('setting_name');
            $table->string('setting_value')->nullable();
        });
        $schema->create('plugin_setting', static function (Blueprint $table): void {
            $table->string('plugin_name');
            $table->string('user_login')->default('');
            $table->string('setting_name');
            $table->string('setting_value')->nullable();
        });
    }

    /** @return array<string, int|string|null> */
    private function visit(int $idVisit, int $idSite, string $userId, string $date): array
    {
        $ip = inet_pton('127.0.0.1');
        if ($ip === false) {
            self::fail('The test IP address must be valid.');
        }

        return [
            'idvisit' => $idVisit,
            'idsite' => $idSite,
            'visit_last_action_time' => $date,
            'idvisitor' => "\x01\x02",
            'location_ip' => $ip,
            'user_id' => $userId,
            'config_device_type' => 0,
            'config_device_model' => '',
            'config_os' => '',
            'config_os_version' => '',
            'config_browser_name' => '',
            'config_browser_version' => '',
            'location_country' => 'nz',
            'location_region' => 'WGN',
        ];
    }
}
