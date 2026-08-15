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
