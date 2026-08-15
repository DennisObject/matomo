<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Segments\OptionSegmentRearchiveScheduler;
use App\Matomo\Segments\SegmentEditorSettings;
use App\Matomo\Sites\SiteRepository;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OptionSegmentRearchiveSchedulerTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_appends_a_legacy_rearchive_entry_and_limits_it_to_site_creation(): void
    {
        CarbonImmutable::setTestNow('2026-08-15 12:00:00 UTC');
        $options = new MemorySegmentOptionRepository;
        $options->set('ReArchiveList', serialize(['existing-entry']));

        $sites = $this->createStub(SiteRepository::class);
        $sites->method('details')->with(3)->willReturn([
            'idsite' => 3,
            'ts_created' => '2024-04-05 00:00:00',
        ]);
        $scheduler = new OptionSegmentRearchiveScheduler(
            $options,
            $sites,
            new SchedulerSegmentSettings,
            $this->createStub(LoggerInterface::class),
        );

        $scheduler->schedule([
            'definition' => 'browserCode==FF',
            'enable_only_idsite' => 3,
            'ts_created' => '2026-08-14 00:00:00',
            'ts_last_edit' => null,
        ]);

        $stored = $options->value('ReArchiveList');
        $this->assertIsString($stored);
        $items = unserialize($stored, ['allowed_classes' => false]);
        $this->assertIsArray($items);
        $this->assertSame('existing-entry', $items[0]);
        $entry = json_decode((string) $items[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([3], $entry['idSites']);
        $this->assertNull($entry['pluginName']);
        $this->assertNull($entry['report']);
        $this->assertSame('browserCode==FF', $entry['segment']);
        $this->assertSame(
            CarbonImmutable::parse('2024-04-05 00:00:00', 'UTC')->getTimestamp(),
            $entry['startDate'],
        );
    }

    public function test_uses_all_sites_and_the_configured_last_edit_window(): void
    {
        $options = new MemorySegmentOptionRepository;
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('allIds')->willReturn([1, 2]);
        $settings = new SchedulerSegmentSettings;
        $settings->processFrom = 'editLast10';

        $scheduler = new OptionSegmentRearchiveScheduler(
            $options,
            $sites,
            $settings,
            $this->createStub(LoggerInterface::class),
        );

        $scheduler->schedule([
            'definition' => 'countryCode==fr',
            'enable_only_idsite' => 0,
            'ts_created' => '2026-08-01 00:00:00',
            'ts_last_edit' => '2026-08-15 00:00:00',
        ]);

        $stored = $options->value('ReArchiveList');
        $this->assertIsString($stored);
        $items = unserialize($stored, ['allowed_classes' => false]);
        $this->assertIsArray($items);
        $entry = json_decode((string) $items[0], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([1, 2], $entry['idSites']);
        $this->assertSame(
            CarbonImmutable::parse('2026-08-05 00:00:00', 'UTC')->getTimestamp(),
            $entry['startDate'],
        );
    }
}

final class MemorySegmentOptionRepository implements MutableOptionRepository
{
    /** @var array<string, string> */
    private array $values = [];

    public function value(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }

    public function set(string $name, string $value, bool $autoload = false): void
    {
        $this->values[$name] = $value;
    }

    public function delete(string $name): void
    {
        unset($this->values[$name]);
    }
}

final class SchedulerSegmentSettings implements SegmentEditorSettings
{
    public string $processFrom = 'beginning_of_time';

    public function allSitesAllowed(): bool
    {
        return true;
    }

    public function realtimeAllowed(): bool
    {
        return true;
    }

    public function browserTriggerEnabled(): bool
    {
        return false;
    }

    public function browserArchivingAvailable(): bool
    {
        return true;
    }

    public function processNewSegmentsFrom(): string
    {
        return $this->processFrom;
    }
}
