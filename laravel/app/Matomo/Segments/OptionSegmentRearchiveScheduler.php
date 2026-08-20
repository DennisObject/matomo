<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Sites\SiteRepository;
use Carbon\CarbonImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class OptionSegmentRearchiveScheduler implements SegmentRearchiveScheduler
{
    private const string OPTION = 'ReArchiveList';

    public function __construct(
        private MutableOptionRepository $options,
        private SiteRepository $sites,
        private SegmentEditorSettings $settings,
        private LoggerInterface $logger,
    ) {}

    public function schedule(array $segment): void
    {
        $definition = $segment['definition'] ?? null;

        if (! is_string($definition) || $definition === '') {
            return;
        }

        try {
            $siteId = (int) ($segment['enable_only_idsite'] ?? 0);
            $siteIds = $siteId === 0 ? $this->sites->allIds() : [$siteId];
            $entry = json_encode([
                'idSites' => $siteIds,
                'pluginName' => null,
                'report' => null,
                'startDate' => $this->startDate($segment, $siteId)?->getTimestamp(),
                'segment' => $definition,
            ], JSON_THROW_ON_ERROR);
            $items = $this->items();
            $items[] = $entry;
            $this->options->set(self::OPTION, serialize($items));
        } catch (Throwable $throwable) {
            $this->logger->info('Failed to schedule segment report rearchiving.', [
                'exception' => $throwable,
            ]);
        }
    }

    /**
     * @param  array<string, bool|int|string|null>  $segment
     */
    private function startDate(array $segment, int $siteId): ?CarbonImmutable
    {
        $created = $this->date($segment['ts_created'] ?? null);
        $edited = $this->date($segment['ts_last_edit'] ?? null) ?? $created;
        $setting = $this->settings->processNewSegmentsFrom();

        if ($setting === 'segment_creation_time') {
            return $created;
        }

        if ($setting === 'segment_last_edit_time') {
            return $edited;
        }

        if (preg_match('/^editLast([0-9]+)$/D', $setting, $matches) === 1) {
            return $edited?->subDays((int) $matches[1]);
        }

        if (preg_match('/^last([0-9]+)$/D', $setting, $matches) === 1) {
            return $created?->subDays((int) $matches[1]);
        }

        $start = CarbonImmutable::today('UTC')->subYears(7);

        if ($siteId === 0) {
            return $start;
        }

        $siteCreated = $this->date($this->sites->details($siteId)['ts_created'] ?? null);

        return $siteCreated !== null && $siteCreated->isAfter($start) ? $siteCreated : $start;
    }

    private function date(bool|int|string|null $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC');
    }

    /** @return list<string> */
    private function items(): array
    {
        $stored = $this->options->value(self::OPTION);

        if ($stored === null || $stored === '') {
            return [];
        }

        $items = @unserialize($stored, ['allowed_classes' => false]);

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, is_string(...)));
    }
}
