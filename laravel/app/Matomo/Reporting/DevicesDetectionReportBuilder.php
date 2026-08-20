<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;

final readonly class DevicesDetectionReportBuilder
{
    /** @var array<string, string> */
    private const array RECORDS = [
        'DevicesDetection.getType' => 'DevicesDetection_types',
        'DevicesDetection.getBrand' => 'DevicesDetection_brands',
        'DevicesDetection.getModel' => 'DevicesDetection_models',
        'DevicesDetection.getOsFamilies' => 'DevicesDetection_os',
        'DevicesDetection.getOsVersions' => 'DevicesDetection_osVersions',
        'DevicesDetection.getBrowsers' => 'DevicesDetection_browsers',
        'DevicesDetection.getBrowserVersions' => 'DevicesDetection_browserVersions',
        'DevicesDetection.getBrowserEngines' => 'DevicesDetection_browserEngines',
    ];

    public function __construct(
        private BlobArchiveRepository $archives,
        private DeviceDetectionMetadata $metadata,
    ) {}

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    public function build(
        string $method,
        array $siteIds,
        array $periods,
        string $segmentHash,
        string $language,
        bool $showMetadata,
        bool $forceSiteIndex,
        bool $forceDateIndex,
    ): ApiTableReport {
        $record = self::RECORDS[$method] ?? '';
        $primary = $this->archives->rows($siteIds, $periods, $segmentHash, $record);
        $fallback = match ($method) {
            'DevicesDetection.getOsFamilies' => $this->archives->rows(
                $siteIds,
                $periods,
                $segmentHash,
                'DevicesDetection_osVersions',
            ),
            'DevicesDetection.getBrowsers' => $this->archives->rows(
                $siteIds,
                $periods,
                $segmentHash,
                'DevicesDetection_browserVersions',
            ),
            default => [],
        };
        $dimensions = [
            ...($forceSiteIndex ? ['idSite'] : []),
            ...($forceDateIndex ? ['date'] : []),
        ];

        if (! $forceSiteIndex) {
            $idSite = $siteIds[0] ?? 0;

            if (! $forceDateIndex) {
                $period = $periods[0] ?? null;
                $key = $period?->rangeKey();

                return new ApiTableReport($key === null ? [] : $this->rows(
                    $method,
                    $primary[$idSite][$key] ?? [],
                    $fallback[$idSite][$key] ?? [],
                    $language,
                    $showMetadata,
                ), []);
            }

            return new ApiTableReport($this->dateRows(
                $method,
                $primary[$idSite] ?? [],
                $fallback[$idSite] ?? [],
                $periods,
                $language,
                $showMetadata,
            ), $dimensions);
        }

        $data = [];

        foreach ($siteIds as $idSite) {
            if ($forceDateIndex) {
                $data[$idSite] = $this->dateRows(
                    $method,
                    $primary[$idSite] ?? [],
                    $fallback[$idSite] ?? [],
                    $periods,
                    $language,
                    $showMetadata,
                );

                continue;
            }

            $key = ($periods[0] ?? null)?->rangeKey();
            $data[$idSite] = $key === null ? [] : $this->rows(
                $method,
                $primary[$idSite][$key] ?? [],
                $fallback[$idSite][$key] ?? [],
                $language,
                $showMetadata,
            );
        }

        return new ApiTableReport($data, $dimensions);
    }

    /**
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>>  $primary
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>>  $fallback
     * @param  list<ReportingPeriod>  $periods
     * @return array<string, list<array<string, float|int|string|null>>>
     */
    private function dateRows(
        string $method,
        array $primary,
        array $fallback,
        array $periods,
        string $language,
        bool $showMetadata,
    ): array {
        $result = [];

        foreach ($periods as $period) {
            $key = $period->rangeKey();
            $result[$period->resultKey] = $this->rows(
                $method,
                $primary[$key] ?? [],
                $fallback[$key] ?? [],
                $language,
                $showMetadata,
            );
        }

        return $result;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $rows
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $fallback
     * @return list<array<string, float|int|string|null>>
     */
    private function rows(
        string $method,
        array $rows,
        array $fallback,
        string $language,
        bool $showMetadata,
    ): array {
        $usedFallback = false;

        if ($rows === [] && $fallback !== []) {
            $rows = $this->group($fallback, fn (int|string $label): string => $this->fallbackLabel(
                $method,
                (string) $label,
            ));
            $usedFallback = true;
        }

        if ($method === 'DevicesDetection.getType' && $rows !== []) {
            $existing = array_map(
                static fn (array $row): string => (string) ($row['columns']['label'] ?? ''),
                $rows,
            );

            foreach (array_keys($this->metadata->deviceTypeNames()) as $deviceType) {
                if (! in_array((string) $deviceType, $existing, true)) {
                    $rows[] = [
                        'columns' => ['label' => $deviceType, 'nb_visits' => 0],
                        'metadata' => [],
                    ];
                }
            }
        }

        $rows = $this->transform($method, $rows, $language, $usedFallback);
        $totals = $this->totals($rows);
        $result = [];

        foreach ($rows as $archiveRow) {
            $row = $archiveRow['columns'];
            $isSummary = in_array($row['label'] ?? null, [-1, '-1'], true);

            if ($isSummary) {
                $row['label'] = $this->metadata->translation('General_Others', $language);
            } elseif ($showMetadata) {
                $row = [...$row, ...$archiveRow['metadata']];
            }

            foreach ($totals as $metric => $total) {
                $value = $row[$metric] ?? null;

                if (is_float($value) || is_int($value)) {
                    $row[$metric.'_percent_of_total'] = $this->percent($value, $total);
                }
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $rows
     * @return list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>
     */
    private function transform(string $method, array $rows, string $language, bool $usedFallback): array
    {
        return match ($method) {
            'DevicesDetection.getType' => $this->group(
                $this->mapMetadata($rows, function (array $row, string $rawLabel): array {
                    $type = $this->metadata->deviceTypeNames()[(int) $rawLabel] ?? null;

                    if ($type !== null) {
                        $row['metadata']['segment'] = 'deviceType=='.urlencode($type);
                    }

                    $row['metadata']['logo'] = $this->metadata->deviceTypeLogo($rawLabel);

                    return $row;
                }),
                fn (int|string $label): string => $this->metadata->deviceTypeLabel($label, $language),
            ),
            'DevicesDetection.getBrand' => $this->mapMetadata(
                $this->group(
                    $rows,
                    fn (int|string $label): string => $this->metadata->brandLabel((string) $label, $language),
                ),
                function (array $row, string $label): array {
                    $row['metadata']['logo'] = $this->metadata->brandLogo($label);
                    $row['metadata']['segment'] = 'deviceBrand=='.urlencode($label);

                    return $row;
                },
            ),
            'DevicesDetection.getModel' => $this->group(
                $this->mapMetadata($rows, function (array $row, string $label) use ($language): array {
                    [$brand, $model] = str_contains($label, ';')
                        ? explode(';', $label, 2)
                        : ['', $label];
                    $brand = $brand !== '' ? $this->metadata->brandLabel($brand, $language) : '';
                    $row['metadata']['segment'] = sprintf(
                        'deviceBrand==%s;deviceModel==%s',
                        urlencode($brand),
                        urlencode($model),
                    );

                    return $row;
                }),
                fn (int|string $label): string => $this->metadata->modelLabel((string) $label, $language),
            ),
            'DevicesDetection.getOsFamilies' => $this->mapMetadata(
                $this->group(
                    $rows,
                    fn (int|string $label): string => $this->metadata->osFamilyLabel((string) $label, $language),
                ),
                function (array $row, string $label) use ($language): array {
                    $row['metadata']['logo'] = $this->metadata->osFamilyLogo($label, $language);

                    return $row;
                },
            ),
            'DevicesDetection.getOsVersions' => $this->group(
                $this->mapMetadata($rows, function (array $row, string $label) use ($language): array {
                    $row = $this->segmentByParts($row, $label, ['operatingSystemCode', 'operatingSystemVersion']);
                    $row['metadata']['logo'] = $this->metadata->osLogo(substr($label, 0, 3), $language);

                    return $row;
                }),
                fn (int|string $label): string => $this->metadata->osLabel((string) $label, $language),
            ),
            'DevicesDetection.getBrowsers' => $this->mapMetadata(
                $this->group(
                    $usedFallback ? $rows : $this->mapMetadata($rows, function (array $row, string $label): array {
                        $segmentValue = $this->metadata->validBrowserSegmentValue($label);

                        if ($segmentValue !== false) {
                            $row['metadata']['segmentValue'] = $segmentValue;
                        }

                        return $row;
                    }),
                    fn (int|string $label): string => $this->metadata->browserLabel((string) $label, $language),
                ),
                function (array $row, string $label) use ($language): array {
                    $row['metadata']['logo'] = $this->metadata->browserFamilyLogo($label, $language);

                    return $row;
                },
            ),
            'DevicesDetection.getBrowserVersions' => $this->mapMetadata(
                $rows,
                function (array $row, string $label) use ($language): array {
                    $row = $this->segmentByParts($row, $label, ['browserCode', 'browserVersion']);
                    $row['metadata']['logo'] = $this->metadata->browserLogo(
                        explode(';', $label, 2)[0],
                        $language,
                    );
                    $row['columns']['label'] = $this->metadata->browserVersionLabel($label, $language);

                    return $row;
                },
            ),
            'DevicesDetection.getBrowserEngines' => $this->group(
                $this->mapMetadata($rows, function (array $row, string $label): array {
                    $row['metadata']['segmentValue'] = $label;

                    return $row;
                }),
                fn (int|string $label): string => $this->metadata->browserEngineLabel((string) $label, $language),
            ),
            default => $rows,
        };
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $rows
     * @param  callable(array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}, string): array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}  $callback
     * @return list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>
     */
    private function mapMetadata(array $rows, callable $callback): array
    {
        foreach ($rows as $index => $row) {
            $label = $row['columns']['label'] ?? '';

            if (! in_array($label, [-1, '-1'], true)) {
                $rows[$index] = $callback($row, (string) $label);
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $rows
     * @param  callable(int|string): string  $labelCallback
     * @return list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>
     */
    private function group(array $rows, callable $labelCallback): array
    {
        $grouped = [];
        $summary = [];

        foreach ($rows as $row) {
            $rawLabel = $row['columns']['label'] ?? '';

            if (in_array($rawLabel, [-1, '-1'], true)) {
                $summary[] = $row;

                continue;
            }

            $label = $labelCallback(is_int($rawLabel) || is_string($rawLabel) ? $rawLabel : '');

            if (! isset($grouped[$label])) {
                $grouped[$label] = [
                    'columns' => ['label' => $label],
                    'metadata' => $row['metadata'],
                ];
            }

            foreach ($row['columns'] as $metric => $value) {
                if ($metric === 'label') {
                    continue;
                }

                if (is_float($value) || is_int($value)) {
                    $grouped[$label]['columns'][$metric] = $metric === 'max_actions'
                        ? max((float) ($grouped[$label]['columns'][$metric] ?? 0), $value)
                        : (float) ($grouped[$label]['columns'][$metric] ?? 0) + $value;
                } elseif (! isset($grouped[$label]['columns'][$metric])) {
                    $grouped[$label]['columns'][$metric] = $value;
                }
            }
        }

        return [...array_values($grouped), ...$summary];
    }

    /**
     * @param  array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}  $row
     * @param  list<string>  $segments
     * @return array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}
     */
    private function segmentByParts(array $row, string $label, array $segments): array
    {
        $parts = explode(';', $label);

        if (count($parts) === count($segments)) {
            $filters = [];

            foreach ($segments as $index => $segment) {
                if ($segment !== '') {
                    $filters[] = $segment.'=='.urlencode($parts[$index]);
                }
            }

            $row['metadata']['segment'] = implode(';', $filters);
        }

        return $row;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $rows
     * @return array<string, float|int>
     */
    private function totals(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            foreach ([
                'nb_visits',
                'nb_actions',
                'nb_visits_converted',
                'nb_conversions',
                'bounce_count',
                'revenue',
            ] as $metric) {
                $value = $row['columns'][$metric] ?? null;

                if (is_float($value) || is_int($value)) {
                    $totals[$metric] = ($totals[$metric] ?? 0) + $value;
                }
            }
        }

        return $totals;
    }

    private function fallbackLabel(string $method, string $label): string
    {
        if (preg_match('/(.+) [0-9]+(?:\.[0-9]+)?$/', $label, $matches) === 1) {
            return $matches[1];
        }

        return str_contains($label, ';')
            ? substr($label, 0, $method === 'DevicesDetection.getOsFamilies' ? 3 : 2)
            : $label;
    }

    private function percent(float|int $value, float|int $total): string
    {
        $percent = (float) $total === 0.0 ? 0 : round((float) $value / (float) $total * 100, 1);

        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').'%';
    }
}
