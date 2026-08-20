<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Reporting\VisitsSummaryArchiveRepository;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class SegmentDataReportBuilder
{
    public function __construct(
        private StoredSegmentRepository $segments,
        private ReportingPeriodFactory $periods,
        private SegmentHashResolver $hashes,
        private VisitsSummaryArchiveRepository $archives,
    ) {}

    /**
     * @return array{
     *   nb_visits: int,
     *   nb_actions: int,
     *   evolution_visits_direction: string,
     *   evolution_visits_icon: string,
     *   evolution_visits: string
     * }
     */
    public function build(
        int $siteId,
        string $period,
        string $date,
        string $segment,
        string $timezone,
    ): array {
        $definition = html_entity_decode($segment, ENT_QUOTES | ENT_HTML401, 'UTF-8');
        $stored = $definition === '' ? null : $this->segments->findByDefinition($definition);

        if ($stored !== null && $stored['auto_archive'] === 0) {
            throw new InvalidArgumentException(
                'This segment is processed in real time, so no data will be displayed.',
            );
        }

        [$periods] = $this->periods->make($period, $date, $timezone);
        $current = $periods[0]
            ?? throw new InvalidArgumentException('The report period is empty.');
        $previous = $this->previous($current, $timezone);
        $segmentHash = $this->hashes->resolve($definition);
        $metrics = $this->archives->metrics(
            [$siteId],
            [$current, $previous],
            $segmentHash,
            ['nb_visits', 'nb_actions'],
        );
        $currentMetrics = $metrics[$siteId][$current->rangeKey()] ?? [];
        $previousMetrics = $metrics[$siteId][$previous->rangeKey()] ?? [];
        $visits = (int) ($currentMetrics['nb_visits'] ?? 0);
        $actions = (int) ($currentMetrics['nb_actions'] ?? 0);
        $pastVisits = (int) ($previousMetrics['nb_visits'] ?? 0);
        $direction = match (true) {
            $visits > $pastVisits => 'positive',
            $visits < $pastVisits => 'negative',
            default => 'stable',
        };

        return [
            'nb_visits' => $visits,
            'nb_actions' => $actions,
            'evolution_visits_direction' => $direction,
            'evolution_visits_icon' => match ($direction) {
                'positive' => 'plugins/MultiSites/images/arrow_up.svg',
                'negative' => 'plugins/MultiSites/images/arrow_down.svg',
                default => 'plugins/MultiSites/images/stop.svg',
            },
            'evolution_visits' => $this->evolution($visits, $pastVisits),
        ];
    }

    private function previous(ReportingPeriod $period, string $timezone): ReportingPeriod
    {
        $start = CarbonImmutable::parse($period->startDate, $timezone);

        if ($period->label === 'range') {
            $end = CarbonImmutable::parse($period->endDate, $timezone);
            $days = $start->diffInDays($end) + 1;
            $previousEnd = $start->subDay();
            $previousStart = $previousEnd->subDays($days - 1);
            [$periods] = $this->periods->make(
                'range',
                $previousStart->toDateString().','.$previousEnd->toDateString(),
                $timezone,
            );
        } else {
            [$periods] = $this->periods->make(
                $period->label,
                $start->subDay()->toDateString(),
                $timezone,
            );
        }

        return $periods[0]
            ?? throw new InvalidArgumentException('The previous report period is empty.');
    }

    private function evolution(int $current, int $past): string
    {
        $value = match (true) {
            $current === $past => 0.0,
            $past === 0 => 100.0,
            default => (($current - $past) / $past) * 100,
        };

        return number_format(round($value), 0, '.', '').'%';
    }
}
