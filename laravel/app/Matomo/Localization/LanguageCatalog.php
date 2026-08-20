<?php

declare(strict_types=1);

namespace App\Matomo\Localization;

interface LanguageCatalog
{
    /** @return list<string> */
    public function available(bool $ignoreConfig = false): array;

    public function isAvailable(string $languageCode, bool $ignoreConfig = false): bool;

    /** @return list<array{code: string, name: string, english_name: string}> */
    public function names(bool $ignoreConfig = false): array;

    /**
     * @return list<array{
     *     code: string,
     *     name: string,
     *     english_name: string,
     *     translators: string,
     *     percentage_complete: string
     * }>
     */
    public function information(bool $excludeNonCorePlugins = true, bool $ignoreConfig = false): array;

    /** @return list<array{label: string, value: string}>|null */
    public function translations(string $languageCode): ?array;
}
