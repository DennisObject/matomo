<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Localization\MatomoTranslator;
use RuntimeException;

final readonly class ReportMetadataCatalog
{
    /** @var array<string, array{name: string, documentation: string}> */
    private const array DEFAULT_GLOSSARY_METRICS = [
        'nb_visits' => ['name' => 'General_ColumnNbVisits', 'documentation' => 'General_ColumnNbVisitsDocumentation'],
        'nb_uniq_visitors' => ['name' => 'General_ColumnNbUniqVisitors', 'documentation' => 'General_ColumnNbUniqVisitorsDocumentation'],
        'nb_actions' => ['name' => 'General_ColumnNbActions', 'documentation' => 'General_ColumnNbActionsDocumentation'],
        'nb_users' => ['name' => 'General_ColumnNbUsers', 'documentation' => 'General_ColumnNbUsersDocumentation'],
        'nb_actions_per_visit' => ['name' => 'General_ColumnActionsPerVisit', 'documentation' => 'General_ColumnActionsPerVisitDocumentation'],
        'avg_time_on_site' => ['name' => 'General_ColumnAvgTimeOnSite', 'documentation' => 'General_ColumnAvgTimeOnSiteDocumentation'],
        'bounce_rate' => ['name' => 'General_ColumnBounceRate', 'documentation' => 'General_ColumnBounceRateDocumentation'],
        'conversion_rate' => ['name' => 'General_ColumnConversionRate', 'documentation' => 'General_ColumnConversionRateDocumentation'],
        'avg_time_on_page' => ['name' => 'General_ColumnAverageTimeOnPage', 'documentation' => 'General_ColumnAverageTimeOnPageDocumentation'],
        'hits' => ['name' => 'General_ColumnHits', 'documentation' => 'General_ColumnHitsDocumentation'],
        'exit_rate' => ['name' => 'General_ColumnExitRate', 'documentation' => 'General_ColumnExitRateDocumentation'],
        'nb_visits_converted' => ['name' => 'General_ColumnVisitsWithConversions', 'documentation' => 'General_VisitConvertedGoalDocumentation'],
    ];

    public function __construct(
        private MatomoTranslator $translator,
        private string $catalogPath,
    ) {}

    /** @return list<array<string, mixed>> */
    public function all(string $language, bool $hideMetricsDocumentation = false): array
    {
        $catalog = require $this->catalogPath;
        if (! is_array($catalog)) {
            throw new RuntimeException('The report metadata catalog is invalid.');
        }

        $reports = [];
        foreach ($catalog as $report) {
            if (! is_array($report)) {
                continue;
            }

            $report = $this->translate($report, $language);
            if ($hideMetricsDocumentation) {
                unset($report['metricsDocumentation']);
            }

            $reports[] = $report;
        }

        return $reports;
    }

    /** @return array<string, mixed>|null */
    public function find(string $module, string $action, string $language, bool $hideMetricsDocumentation): ?array
    {
        foreach ($this->all($language, $hideMetricsDocumentation) as $report) {
            if (($report['module'] ?? null) === $module && ($report['action'] ?? null) === $action) {
                return $report;
            }
        }

        return null;
    }

    /** @return list<array{name: string, documentation: string, onlineGuideUrl?: string}> */
    public function reportsGlossary(string $language): array
    {
        $glossary = [];
        foreach ($this->all($language) as $report) {
            $name = $report['name'] ?? null;
            $category = $report['category'] ?? null;
            $documentation = $report['documentation'] ?? null;
            if (! is_string($name) || ! is_string($category) || ! is_string($documentation)) {
                continue;
            }

            $entry = ['name' => "{$name} ({$category})", 'documentation' => $documentation];
            if (isset($report['onlineGuideUrl']) && is_string($report['onlineGuideUrl'])) {
                $entry['onlineGuideUrl'] = $report['onlineGuideUrl'];
            }

            $glossary[] = $entry;
        }

        usort($glossary, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $glossary;
    }

    /** @return list<array{name: string, id: string, documentation: string}> */
    public function metricsGlossary(string $language): array
    {
        $metrics = [];
        foreach (self::DEFAULT_GLOSSARY_METRICS as $id => $definition) {
            $metrics[$id] = [
                'name' => $this->translator->translate($definition['name'], $language),
                'id' => $id,
                'documentation' => $this->translator->translate($definition['documentation'], $language),
            ];
        }

        /** @var array<string, array{name: string, documentation: list<string>}> $candidates */
        $candidates = [];
        foreach ($this->all($language) as $report) {
            $documentation = $report['metricsDocumentation'] ?? null;
            if (! is_array($documentation)) {
                continue;
            }

            foreach ($documentation as $id => $text) {
                if ($id === 'nb_hits' || ! is_string($id) || ! is_string($text) || isset($metrics[$id])) {
                    continue;
                }

                $name = $report['metrics'][$id] ?? $report['processedMetrics'][$id] ?? null;
                if (is_string($name)) {
                    $candidates[$id]['name'] ??= $name;
                    $candidates[$id]['documentation'][] = $text;
                }
            }
        }

        foreach ($candidates as $id => $candidate) {
            $counts = array_count_values($candidate['documentation']);
            arsort($counts);
            $documentation = array_key_first($counts);
            $count = $documentation === null ? 0 : $counts[$documentation];
            if ($documentation !== null && $count > count($candidate['documentation']) - $count) {
                $metrics[$id] = [
                    'name' => $candidate['name'],
                    'id' => $id,
                    'documentation' => $documentation,
                ];
            }
        }

        $result = array_values($metrics);
        usort($result, static fn (array $left, array $right): int => [$left['name'], $left['id']] <=> [$right['name'], $right['id']]);

        return $result;
    }

    private function translate(mixed $value, string $language): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_keys($value) === ['translationKey'] && is_string($value['translationKey'])) {
            return $this->translator->translate($value['translationKey'], $language);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->translate($item, $language);
        }

        return $value;
    }
}
