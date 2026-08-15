<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;
use App\Matomo\Localization\MatomoTranslator;

final readonly class CustomDimensionsReportBuilder
{
    public function __construct(
        private HierarchicalBlobArchiveRepository $archives,
        private VisitsSummaryArchiveRepository $visitMetrics,
        private MatomoTranslator $translator,
    ) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function build(
        int $dimensionId,
        ?int $idSubtable,
        array $siteIds,
        array $periods,
        string $segmentHash,
        bool $expanded,
        bool $flat,
        string $language,
        bool $showMetadata,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiTableReport {
        $recordName = 'CustomDimensions_Dimension'.$dimensionId;
        $idSubtable = $idSubtable === 0 ? null : $idSubtable;
        $requestedRecord = $idSubtable === null ? $recordName : $recordName.'_'.$idSubtable;
        $includeSubtables = ($expanded || $flat) && $idSubtable === null;
        $records = $this->archives->records(
            $siteIds,
            $periods,
            $segmentHash,
            $includeSubtables ? $recordName : $requestedRecord,
            $includeSubtables,
        );
        $roots = $idSubtable === null
            ? $records
            : $this->archives->records($siteIds, $periods, $segmentHash, $recordName, false);
        $siteUsers = $this->visitMetrics->metrics($siteIds, $periods, '', ['nb_users']);
        $dimensions = [
            ...($forceSiteIndex ? ['idSite'] : []),
            ...($forceDateIndex ? ['date'] : []),
        ];

        if (! $forceSiteIndex) {
            $siteId = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $period = $periods[0] ?? null;
                $key = $period?->rangeKey();

                return new ApiTableReport($key === null ? [] : $this->rows(
                    $records[$siteId][$key] ?? [],
                    $roots[$siteId][$key] ?? [],
                    $recordName,
                    $requestedRecord,
                    $dimensionId,
                    $idSubtable,
                    $expanded,
                    $flat,
                    $language,
                    $showMetadata,
                    (float) ($siteUsers[$siteId][$key]['nb_users'] ?? 0),
                ), []);
            }

            return new ApiTableReport($this->dateRows(
                $records[$siteId] ?? [],
                $roots[$siteId] ?? [],
                $periods,
                $recordName,
                $requestedRecord,
                $dimensionId,
                $idSubtable,
                $expanded,
                $flat,
                $language,
                $showMetadata,
                $siteUsers[$siteId] ?? [],
            ), $dimensions);
        }

        $data = [];

        foreach ($siteIds as $siteId) {
            if ($forceDateIndex) {
                $data[$siteId] = $this->dateRows(
                    $records[$siteId] ?? [],
                    $roots[$siteId] ?? [],
                    $periods,
                    $recordName,
                    $requestedRecord,
                    $dimensionId,
                    $idSubtable,
                    $expanded,
                    $flat,
                    $language,
                    $showMetadata,
                    $siteUsers[$siteId] ?? [],
                );
            } else {
                $key = ($periods[0] ?? null)?->rangeKey();
                $data[$siteId] = $key === null ? [] : $this->rows(
                    $records[$siteId][$key] ?? [],
                    $roots[$siteId][$key] ?? [],
                    $recordName,
                    $requestedRecord,
                    $dimensionId,
                    $idSubtable,
                    $expanded,
                    $flat,
                    $language,
                    $showMetadata,
                    (float) ($siteUsers[$siteId][$key]['nb_users'] ?? 0),
                );
            }
        }

        return new ApiTableReport($data, $dimensions);
    }

    /**
     * @param  array<string, array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>>>  $records
     * @param  array<string, array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>>>  $roots
     * @param  list<ReportingPeriod>  $periods
     * @param  array<string, array<string, int|float>>  $siteUsers
     * @return array<string, list<array<string, mixed>>>
     */
    private function dateRows(
        array $records,
        array $roots,
        array $periods,
        string $recordName,
        string $requestedRecord,
        int $dimensionId,
        ?int $idSubtable,
        bool $expanded,
        bool $flat,
        string $language,
        bool $showMetadata,
        array $siteUsers,
    ): array {
        $result = [];

        foreach ($periods as $period) {
            $key = $period->rangeKey();
            $result[$period->resultKey] = $this->rows(
                $records[$key] ?? [],
                $roots[$key] ?? [],
                $recordName,
                $requestedRecord,
                $dimensionId,
                $idSubtable,
                $expanded,
                $flat,
                $language,
                $showMetadata,
                (float) ($siteUsers[$key]['nb_users'] ?? 0),
            );
        }

        return $result;
    }

    /**
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>>  $records
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>>  $roots
     * @return list<array<string, mixed>>
     */
    private function rows(
        array $records,
        array $roots,
        string $recordName,
        string $requestedRecord,
        int $dimensionId,
        ?int $idSubtable,
        bool $expanded,
        bool $flat,
        string $language,
        bool $showMetadata,
        float $siteUsers,
    ): array {
        $rootRows = $roots[$recordName] ?? [];
        $totals = $this->totals($rootRows);
        $keepUsers = (float) ($totals['nb_users'] ?? 0) !== 0.0 || $siteUsers !== 0.0;

        if ($idSubtable !== null) {
            $parent = $this->parentLabel($rootRows, $idSubtable);

            return array_map(
                fn (array $row): array => $this->childRow(
                    $row,
                    $parent,
                    $dimensionId,
                    $totals,
                    $language,
                    $showMetadata,
                    $keepUsers,
                ),
                $records[$requestedRecord] ?? [],
            );
        }

        if ($flat) {
            return $this->flatRows(
                $rootRows,
                $records,
                $recordName,
                $dimensionId,
                $totals,
                $language,
                $showMetadata,
                $keepUsers,
            );
        }

        $result = [];

        foreach ($rootRows as $archiveRow) {
            $rawLabel = $archiveRow['columns']['label'] ?? '';
            $row = $this->processed($archiveRow['columns'], $totals, $language, $keepUsers);
            $label = $this->segmentLabel($rawLabel);

            if ($showMetadata) {
                $row = [...$row, ...$archiveRow['metadata'], 'segment' => 'dimension'.$dimensionId.'=='.urlencode($label)];
            }

            $subtableId = $archiveRow['subtableId'];

            if ($subtableId !== null) {
                $row['idsubdatatable'] = $subtableId;
            }

            if ($expanded && $subtableId !== null) {
                $row['subtable'] = array_map(
                    fn (array $child): array => $this->childRow(
                        $child,
                        $label,
                        $dimensionId,
                        $totals,
                        $language,
                        $showMetadata,
                        $keepUsers,
                    ),
                    $records[$recordName.'_'.$subtableId] ?? [],
                );
            }

            $result[] = $row;
        }

        return $result;
    }

    /** @param list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}> $rootRows */
    private function parentLabel(array $rootRows, int $subtableId): string
    {
        foreach ($rootRows as $row) {
            if ($row['subtableId'] === $subtableId) {
                return $this->segmentLabel($row['columns']['label'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param  array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}  $archiveRow
     * @param  array<string, float|int>  $totals
     * @return array<string, mixed>
     */
    private function childRow(
        array $archiveRow,
        string $parent,
        int $dimensionId,
        array $totals,
        string $language,
        bool $showMetadata,
        bool $keepUsers,
    ): array {
        $row = $this->processed($archiveRow['columns'], $totals, $language, $keepUsers);
        $label = $archiveRow['columns']['label'] ?? '';

        if ($showMetadata && is_string($label) && $parent !== '') {
            $encoded = urlencode($label);
            $row = [
                ...$row,
                ...$archiveRow['metadata'],
                'segment' => 'dimension'.$dimensionId.'=='.urlencode($parent).';actionUrl=$'.$encoded,
                'url' => $encoded,
            ];
        }

        return $row;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>  $rootRows
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>>  $records
     * @param  array<string, float|int>  $totals
     * @return list<array<string, mixed>>
     */
    private function flatRows(
        array $rootRows,
        array $records,
        string $recordName,
        int $dimensionId,
        array $totals,
        string $language,
        bool $showMetadata,
        bool $keepUsers,
    ): array {
        $result = [];

        foreach ($rootRows as $parentRow) {
            $parent = $this->segmentLabel($parentRow['columns']['label'] ?? '');
            $subtableId = $parentRow['subtableId'];

            if ($subtableId === null) {
                continue;
            }

            foreach ($records[$recordName.'_'.$subtableId] ?? [] as $archiveRow) {
                $row = $this->childRow(
                    $archiveRow,
                    $parent,
                    $dimensionId,
                    $totals,
                    $language,
                    $showMetadata,
                    $keepUsers,
                );
                $child = $row['label'] ?? '';
                $row['label'] = $parent.' - '.(string) $child;
                $row['CustomDimension_CustomDimension'.$dimensionId] = $row['label'];
                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>  $rows
     * @return array<string, float|int>
     */
    private function totals(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            foreach ($row['columns'] as $name => $value) {
                if ((is_float($value) || is_int($value)) && $name !== 'max_actions') {
                    $totals[$name] = ($totals[$name] ?? 0) + $value;
                }
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, float|int|string|null>  $columns
     * @param  array<string, float|int>  $totals
     * @return array<string, mixed>
     */
    private function processed(array $columns, array $totals, string $language, bool $keepUsers): array
    {
        $row = $columns;

        if (! $keepUsers) {
            unset($row['nb_users']);
        }

        $label = $row['label'] ?? '';

        if ($label === -1 || $label === '-1') {
            $row['label'] = $this->translator->translate('General_Others', $language);
        }

        foreach ([
            'nb_visits', 'nb_actions', 'nb_visits_converted', 'nb_conversions',
            'bounce_count', 'revenue', 'nb_hits', 'exit_nb_visits',
        ] as $metric) {
            $value = $row[$metric] ?? null;

            if (is_float($value) || is_int($value)) {
                $row[$metric.'_percent_of_total'] = $this->percent($value, $totals[$metric] ?? 0);
            }
        }

        $visits = $this->number($row['nb_visits'] ?? null);
        $actions = $this->number($row['nb_actions'] ?? null);
        $visitLength = $this->number($row['sum_visit_length'] ?? null);
        $bounces = $this->number($row['bounce_count'] ?? null);
        $hits = $this->number($row['nb_hits'] ?? null);
        $time = $this->number($row['sum_time_spent'] ?? null);
        $exits = $this->number($row['exit_nb_visits'] ?? null);

        if ($visits !== null) {
            if ($visitLength !== null) {
                $row['avg_time_on_site'] = $this->numeric($visits === 0.0 ? 0 : round($visitLength / $visits));
            }

            if ($bounces !== null) {
                $row['bounce_rate'] = $this->rate($bounces, $visits);
            }

            if ($actions !== null) {
                $row['nb_actions_per_visit'] = $this->numeric($visits === 0.0 ? 0 : round($actions / $visits, 1));
            }
        }

        if ($hits !== null) {
            if ($time !== null) {
                $row['avg_time_on_dimension'] = $this->numeric($hits === 0.0 ? 0 : round($time / $hits));
            }

            if ($exits !== null) {
                $row['exit_rate'] = $this->rate($exits, $visits ?? 0.0);
            }
        }

        return $this->goals($row);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function goals(array $row): array
    {
        $goals = [];

        foreach (array_keys($row) as $column) {
            if (preg_match('/^goal_(-?[0-9]+)_(.+)$/D', $column, $matches) !== 1) {
                continue;
            }

            $goals[(int) $matches[1]][$matches[2]] = $row[$column];
            unset($row[$column]);
        }

        if ($goals !== []) {
            ksort($goals);
            $row['goals'] = $goals;
        }

        return $row;
    }

    private function segmentLabel(float|int|string|null $label): string
    {
        return $label === 'Value not defined' ? '' : (string) $label;
    }

    private function number(mixed $value): ?float
    {
        return is_float($value) || is_int($value) ? (float) $value : null;
    }

    private function percent(float|int $value, float|int $total): string
    {
        return $this->rate($value, $total);
    }

    private function rate(float|int $value, float|int $total): string
    {
        $rate = (float) $total === 0.0 ? 0 : round((float) $value / (float) $total * 100, 1);

        return rtrim(rtrim(number_format($rate, 1, '.', ''), '0'), '.').'%';
    }

    private function numeric(float|int $value): float|int
    {
        return (float) $value === (float) (int) $value ? (int) $value : $value;
    }
}
