<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

final readonly class ActionArchiveConfiguration
{
    /** @param array<int, bool> $goalArchivingDisabledBySite */
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
        public bool $goalArchivingDisabled = false,
        private array $goalArchivingDisabledBySite = [],
    ) {}

    public static function fromFiles(string $defaultsPath, string $installationPath): self
    {
        $defaultSections = self::sections($defaultsPath);
        $installationSections = self::sections($installationPath);
        $defaults = $defaultSections['General'] ?? [];
        $installation = $installationSections['General'] ?? [];
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
            goalArchivingDisabled: self::boolean(
                $values,
                'disable_archive_actions_goals',
            ),
            goalArchivingDisabledBySite: self::siteGoalArchivingOverrides(
                $defaultSections,
                $installationSections,
            ),
        );
    }

    public function goalsDisabled(int $siteId): bool
    {
        return $this->goalArchivingDisabledBySite[$siteId] ?? $this->goalArchivingDisabled;
    }

    /** @return array<string, array<string, mixed>> */
    private static function sections(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $configuration = parse_ini_file($path, true, INI_SCANNER_RAW);

        if (! is_array($configuration)) {
            return [];
        }

        $sections = [];

        foreach ($configuration as $name => $values) {
            if (is_string($name) && is_array($values)) {
                $sections[$name] = $values;
            }
        }

        return $sections;
    }

    /**
     * @param  array<string, array<string, mixed>>  $defaults
     * @param  array<string, array<string, mixed>>  $installation
     * @return array<int, bool>
     */
    private static function siteGoalArchivingOverrides(
        array $defaults,
        array $installation,
    ): array {
        $overrides = [];
        $sectionNames = array_unique([...array_keys($defaults), ...array_keys($installation)]);

        foreach ($sectionNames as $name) {
            if (preg_match('/^General_([1-9][0-9]*)$/D', $name, $matches) !== 1) {
                continue;
            }

            $values = [
                ...($defaults[$name] ?? []),
                ...($installation[$name] ?? []),
            ];

            if (! array_key_exists('disable_archive_actions_goals', $values)) {
                continue;
            }

            $overrides[(int) $matches[1]] = self::boolean(
                $values,
                'disable_archive_actions_goals',
            );
        }

        return $overrides;
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

    /** @param array<string, mixed> $values */
    private static function boolean(array $values, string $name, bool $default = false): bool
    {
        $value = strtolower(self::string($values, $name, $default ? '1' : '0'));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
