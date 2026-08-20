<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiReport;

final readonly class AiAgentsReportBuilder
{
    private const string AI_AGENT_SEGMENT = 'aiAgentName!=';

    private const string HUMAN_SEGMENT = 'aiAgentName==';

    public function __construct(
        private VisitsSummaryReportBuilder $visitsSummary,
        private SegmentHashResolver $segments,
    ) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     * @param  list<string>|null  $requestedColumns
     */
    public function build(
        array $siteIds,
        array $periods,
        string $requestedPeriod,
        ?string $segment,
        ?array $requestedColumns,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiReport {
        $data = [];
        $dimensions = [];

        foreach ([
            '_ai_agent' => self::AI_AGENT_SEGMENT,
            '_human' => self::HUMAN_SEGMENT,
        ] as $suffix => $clientSegment) {
            $columns = $this->columns($requestedColumns, $suffix);

            if ($requestedColumns !== null && $columns === []) {
                continue;
            }

            $combinedSegment = $segment === null || $segment === ''
                ? $clientSegment
                : $segment.';'.$clientSegment;
            $report = $this->visitsSummary->build(
                siteIds: $siteIds,
                periods: $periods,
                requestedPeriod: $requestedPeriod,
                segmentHash: $this->segments->resolve($combinedSegment),
                requestedColumns: $columns,
                showColumns: [],
                hideColumns: [],
                forceSiteIndex: $forceSiteIndex,
                forceDateIndex: $forceDateIndex,
            );
            $dimensions = $report->dimensions;
            $data = $this->merge($data, $report->data, $report->dimensions, $suffix, 0);
        }

        return new ApiReport($data, $dimensions);
    }

    /**
     * @param  list<string>|null  $requestedColumns
     * @return list<string>|null
     */
    private function columns(?array $requestedColumns, string $suffix): ?array
    {
        if ($requestedColumns === null) {
            return null;
        }

        $columns = [];

        foreach ($requestedColumns as $column) {
            if (str_ends_with($column, $suffix)) {
                $columns[] = substr($column, 0, -strlen($suffix));
            }
        }

        return $columns;
    }

    /**
     * @param  array<array-key, mixed>  $target
     * @param  array<array-key, mixed>  $source
     * @param  list<'idSite'|'date'>  $dimensions
     * @return array<array-key, mixed>
     */
    private function merge(array $target, array $source, array $dimensions, string $suffix, int $depth): array
    {
        if (! isset($dimensions[$depth])) {
            foreach ($source as $name => $value) {
                if (is_string($name)
                    && (is_float($value) || is_int($value) || is_string($value) || $value === null)) {
                    $target[$name.$suffix] = $value;
                }
            }

            return $target;
        }

        foreach ($source as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $existing = $target[$key] ?? [];
            $target[$key] = $this->merge(
                is_array($existing) ? $existing : [],
                $value,
                $dimensions,
                $suffix,
                $depth + 1,
            );
        }

        return $target;
    }
}
