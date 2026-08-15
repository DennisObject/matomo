<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

final readonly class BotTrackingArchiveConfiguration
{
    public function __construct(
        public int $rootLimit = 250,
        public int $subtableLimit = 250,
        public int $rankingLimit = 50_000,
        public int $contentLimit = 50_000,
        public int $contentRankingLimit = 50_000,
    ) {}

    public static function fromFiles(string $defaultsPath, string $installationPath): self
    {
        $values = [
            ...self::generalSection($defaultsPath),
            ...self::generalSection($installationPath),
        ];
        $rootLimit = self::nonNegativeInteger(
            $values,
            'datatable_archiving_maximum_rows_bots',
            250,
        );
        $subtableLimit = self::nonNegativeInteger(
            $values,
            'datatable_archiving_maximum_rows_subtable_bots',
            250,
        );
        $configuredRankingLimit = self::nonNegativeInteger(
            $values,
            'archiving_ranking_query_row_limit',
            50_000,
        );
        $rankingLimit = max($configuredRankingLimit, 10 * $rootLimit);
        $contentLimit = self::nonNegativeInteger(
            $values,
            'datatable_archiving_maximum_rows_ai_chatbot_content',
            50_000,
        );

        return new self(
            rootLimit: $rootLimit,
            subtableLimit: $subtableLimit,
            rankingLimit: $rankingLimit === 0
                ? 0
                : max($rankingLimit, $rootLimit, $subtableLimit),
            contentLimit: $contentLimit,
            contentRankingLimit: max($configuredRankingLimit, $contentLimit),
        );
    }

    /** @return array<string, mixed> */
    private static function generalSection(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $configuration = parse_ini_file($path, true, INI_SCANNER_RAW);
        $general = is_array($configuration) ? ($configuration['General'] ?? null) : null;

        return is_array($general) ? $general : [];
    }

    /** @param array<string, mixed> $values */
    private static function nonNegativeInteger(
        array $values,
        string $name,
        int $default,
    ): int {
        $value = $values[$name] ?? $default;
        $value = is_scalar($value) ? (string) $value : (string) $default;

        return preg_match('/^[0-9]+$/D', $value) === 1 ? (int) $value : $default;
    }
}
