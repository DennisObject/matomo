<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Localization\MatomoTranslator;
use RuntimeException;

final readonly class ReportMetadataCatalog
{
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
                    $metrics[$id] = ['name' => $name, 'id' => $id, 'documentation' => $text];
                }
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
