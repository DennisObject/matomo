<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

final readonly class ActionArchiveConfiguration
{
    public function __construct(
        public string $urlDelimiter = '/',
        public string $titleDelimiter = '',
        public string $defaultActionName = 'index',
        public int $categoryLevelLimit = 10,
        public int $rootLimit = 500,
        public int $subtableLimit = 100,
        public int $siteSearchLimit = 500,
        public int $flatLimit = 0,
        public int $rankingLimit = 50_000,
    ) {}

    public static function fromFiles(string $defaultsPath, string $installationPath): self
    {
        $defaults = self::generalSection($defaultsPath);
        $installation = self::generalSection($installationPath);
        $values = [...$defaults, ...$installation];
        $legacyDelimiter = self::string($values, 'action_category_delimiter');
        $urlDelimiter = $legacyDelimiter !== ''
            ? $legacyDelimiter
            : self::string($values, 'action_url_category_delimiter', '/');
        $titleDelimiter = $legacyDelimiter !== ''
            ? $legacyDelimiter
            : self::string($values, 'action_title_category_delimiter');
        $rootLimit = self::positiveInteger(
            $values,
            'datatable_archiving_maximum_rows_actions',
            500,
        );
        $subtableLimit = self::positiveInteger(
            $values,
            'datatable_archiving_maximum_rows_subtable_actions',
            100,
        );
        $configuredRankingLimit = self::nonNegativeInteger(
            $values,
            'archiving_ranking_query_row_limit',
            50_000,
        );

        return new self(
            urlDelimiter: $urlDelimiter,
            titleDelimiter: $titleDelimiter,
            defaultActionName: self::string($values, 'action_default_name', 'index'),
            categoryLevelLimit: self::positiveInteger(
                $values,
                'action_category_level_limit',
                10,
            ),
            rootLimit: $rootLimit,
            subtableLimit: $subtableLimit,
            siteSearchLimit: self::positiveInteger(
                $values,
                'datatable_archiving_maximum_rows_site_search',
                500,
            ),
            flatLimit: self::nonNegativeInteger(
                $values,
                'datatable_archiving_maximum_rows_actions_flat',
                0,
            ),
            rankingLimit: $configuredRankingLimit === 0
                ? 0
                : max($configuredRankingLimit, $rootLimit, $subtableLimit),
        );
    }

    /** @return array<string, mixed> */
    private static function generalSection(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $configuration = parse_ini_file($path, true, INI_SCANNER_RAW);
        $general = is_array($configuration) ? ($configuration['General'] ?? []) : [];

        return is_array($general) ? $general : [];
    }

    /** @param array<string, mixed> $values */
    private static function string(array $values, string $name, string $default = ''): string
    {
        $value = $values[$name] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @param array<string, mixed> $values */
    private static function positiveInteger(array $values, string $name, int $default): int
    {
        $value = self::string($values, $name, (string) $default);

        return preg_match('/^[1-9][0-9]*$/D', $value) === 1 ? (int) $value : $default;
    }

    /** @param array<string, mixed> $values */
    private static function nonNegativeInteger(array $values, string $name, int $default): int
    {
        $value = self::string($values, $name, (string) $default);

        return preg_match('/^[0-9]+$/D', $value) === 1 ? (int) $value : $default;
    }
}
