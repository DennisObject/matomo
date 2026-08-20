<?php

declare(strict_types=1);

namespace App\Matomo\Insights;

use App\Matomo\Api\InsightsRequest;
use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Reporting\VisitsSummaryArchiveRepository;
use InvalidArgumentException;

final readonly class InsightReportBuilder
{
    public function __construct(
        private ReportingPeriodFactory $periods,
        private InsightComparisonPeriodResolver $comparisons,
        private SegmentHashResolver $segments,
        private VisitsSummaryArchiveRepository $visits,
        private InsightSourceReportProvider $sources,
        private InsightRowComparison $rows,
    ) {}

    public function build(
        InsightsRequest $request,
        string $timezone,
        string $language,
        bool $moversAndShakers,
    ): InsightReportResult {
        $siteId = $request->siteId
            ?? throw new InvalidArgumentException('The website ID is required.');
        $uniqueId = $request->reportUniqueId
            ?? throw new InvalidArgumentException('The report unique ID is required.');
        [$periods] = $this->periods->make($request->period, $request->date, $timezone);
        $currentPeriod = $periods[0]
            ?? throw new InvalidArgumentException('The current report period is empty.');
        $pastPeriod = $this->comparisons->previous(
            $currentPeriod,
            $request->comparedToXPeriods,
            $timezone,
        );
        $segmentHash = $this->segments->resolve($request->segment);
        $current = $this->sources->report(
            $uniqueId,
            $siteId,
            $currentPeriod,
            $segmentHash,
            $language,
        );

        if ($current === null) {
            throw new InvalidArgumentException("A report having the ID {$uniqueId} does not exist");
        }

        $past = $this->sources->report(
            $uniqueId,
            $siteId,
            $pastPeriod,
            $segmentHash,
            $language,
        );

        if ($past === null) {
            throw new InvalidArgumentException("A report having the ID {$uniqueId} does not exist");
        }

        $visitMetrics = $this->visits->metrics(
            [$siteId],
            [$currentPeriod, $pastPeriod],
            $segmentHash,
            ['nb_visits'],
        );
        $totalValue = (int) ($visitMetrics[$siteId][$currentPeriod->rangeKey()]['nb_visits'] ?? 0);
        $pastTotalValue = (int) ($visitMetrics[$siteId][$pastPeriod->rangeKey()]['nb_visits'] ?? 0);
        $relevantTotal = $this->relevantTotal($current->metricTotal, $totalValue);

        if ($moversAndShakers) {
            $thresholds = $this->moverThresholds($totalValue, $pastTotalValue);
            $rows = $this->rows->compare(
                $current->rows,
                $past->rows,
                'nb_visits',
                $totalValue,
                $thresholds['minMoversPercent'],
                $thresholds['minNewPercent'],
                $thresholds['minDisappearedPercent'],
                $thresholds['minGrowthPositive'],
                $thresholds['minGrowthNegative'],
                InsightRowComparison::ORDER_ABSOLUTE,
                $request->limitIncreaser,
                $request->limitDecreaser,
            );
        } else {
            [$minMovers, $minNew, $minDisappeared] = match ($request->filterBy) {
                'movers' => [$request->minImpactPercent, -1, -1],
                'new' => [-1, $request->minImpactPercent, -1],
                'disappeared' => [-1, -1, $request->minImpactPercent],
                default => array_fill(0, 3, $request->minImpactPercent),
            };
            $growth = abs($request->minGrowthPercent);
            $rows = $this->rows->compare(
                $current->rows,
                $past->rows,
                'nb_visits',
                $relevantTotal,
                $minMovers,
                $minNew,
                $minDisappeared,
                $growth,
                -$growth,
                $request->orderBy,
                $request->limitIncreaser,
                $request->limitDecreaser,
            );
            $moverRows = $this->moverRows(
                $request,
                $current,
                $past,
                $totalValue,
                $pastTotalValue,
            );
            $moverLabels = array_fill_keys(array_column($moverRows, 'label'), true);

            foreach ($rows as &$row) {
                $row['isMoverAndShaker'] = isset($moverLabels[$row['label']]);
            }

            unset($row);
        }

        return new InsightReportResult($rows, [
            'reportName' => $current->metadata['name'],
            'metricName' => $current->metadata['metrics']['nb_visits'] ?? 'nb_visits',
            'date' => $request->date,
            'lastDate' => $pastPeriod->resultKey,
            'period' => $request->period,
            'report' => $current->metadata,
            'totalValue' => $totalValue,
            'lastTotalValue' => $pastTotalValue,
            'evolutionDifference' => $totalValue - $pastTotalValue,
            'evolutionTotal' => $this->percentage($totalValue - $pastTotalValue, $pastTotalValue),
            'orderBy' => $request->orderBy,
            'metric' => 'nb_visits',
        ]);
    }

    private function relevantTotal(int $metricTotal, int $totalValue): int
    {
        return $metricTotal > $totalValue || ($metricTotal * 2) < $totalValue
            ? $metricTotal
            : $totalValue;
    }

    /**
     * @return array{
     *   minMoversPercent: int,
     *   minNewPercent: int,
     *   minDisappearedPercent: int,
     *   minGrowthPositive: float,
     *   minGrowthNegative: float
     * }
     */
    private function moverThresholds(int $totalValue, int $pastTotalValue): array
    {
        $evolution = $this->percentage($totalValue - $pastTotalValue, $pastTotalValue);
        $minMovers = 1;

        if ($evolution >= 100) {
            $factor = (int) ceil($evolution / 500);
            $positive = $evolution + ($factor * 40);
            $negative = -70.0;
            $disappeared = 8;
            $new = (int) min(($evolution / 100) * 3, 10);
        } elseif ($evolution >= 0) {
            $positive = $evolution + 20;
            $negative = -$positive;
            $disappeared = 7;
            $new = 5;
        } else {
            $negative = $evolution - 20;
            $positive = abs($negative);
            $disappeared = 7;
            $new = 5;
        }

        if ($totalValue > 0 && $totalValue < 200) {
            $minMovers = (int) ceil(2 / ($totalValue / 100));
            $new = max($new, $minMovers);
            $disappeared = max($disappeared, $minMovers);
        }

        return [
            'minMoversPercent' => $minMovers,
            'minNewPercent' => $new,
            'minDisappearedPercent' => $disappeared,
            'minGrowthPositive' => $positive,
            'minGrowthNegative' => $negative,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function moverRows(
        InsightsRequest $request,
        InsightSourceReport $current,
        InsightSourceReport $past,
        int $totalValue,
        int $pastTotalValue,
    ): array {
        $thresholds = $this->moverThresholds($totalValue, $pastTotalValue);

        return $this->rows->compare(
            $current->rows,
            $past->rows,
            'nb_visits',
            $totalValue,
            $thresholds['minMoversPercent'],
            $thresholds['minNewPercent'],
            $thresholds['minDisappearedPercent'],
            $thresholds['minGrowthPositive'],
            $thresholds['minGrowthNegative'],
            InsightRowComparison::ORDER_ABSOLUTE,
            max(count($current->rows) + count($past->rows), 3),
            max(count($current->rows) + count($past->rows), 3),
        );
    }

    private function percentage(int $difference, int $past): float
    {
        if ($past === 0) {
            return $difference === 0 ? 0.0 : 100.0;
        }

        return round(($difference / $past) * 100, 1);
    }
}
