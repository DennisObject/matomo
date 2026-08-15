<?php

declare(strict_types=1);

namespace App\Matomo\Localization;

use App\Matomo\Localization\Events\AvailableLanguagesCollecting;
use Illuminate\Contracts\Events\Dispatcher;
use JsonException;
use RuntimeException;

final class FilesystemLanguageCatalog implements LanguageCatalog
{
    /** @var array<int, list<string>> */
    private array $available = [];

    /** @var array<int, list<array{code: string, name: string, english_name: string}>> */
    private array $names = [];

    /**
     * @param  list<string>  $configuredLanguages
     * @param  list<string>  $activatedPlugins
     * @param  list<string>  $bundledPlugins
     */
    public function __construct(
        private readonly string $rootDirectory,
        private readonly array $configuredLanguages,
        private readonly array $activatedPlugins,
        private readonly array $bundledPlugins,
        private readonly bool $developmentEnabled,
        private readonly Dispatcher $events,
    ) {}

    public function available(bool $ignoreConfig = false): array
    {
        $cacheKey = (int) $ignoreConfig;

        if (isset($this->available[$cacheKey])) {
            return $this->available[$cacheKey];
        }

        $paths = glob($this->rootDirectory.'/lang/*.json');

        if (! is_array($paths)) {
            throw new RuntimeException('The Matomo language directory is not readable.');
        }

        $installed = array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            $paths,
        );
        $languages = $ignoreConfig
            ? $installed
            : array_values(array_intersect($installed, $this->configuredLanguages));

        if (! $this->developmentEnabled) {
            $languages = array_values(array_diff($languages, ['dev']));
        } elseif (! in_array('dev', $languages, true)) {
            $languages[] = 'dev';
        }

        $event = new AvailableLanguagesCollecting($languages);
        $this->events->dispatch($event);

        return $this->available[$cacheKey] = array_values(array_unique(array_filter(
            $event->languages,
            $this->validLanguageCode(...),
        )));
    }

    public function isAvailable(string $languageCode, bool $ignoreConfig = false): bool
    {
        return $this->validLanguageCode($languageCode)
            && in_array($languageCode, $this->available($ignoreConfig), true);
    }

    public function names(bool $ignoreConfig = false): array
    {
        $cacheKey = (int) $ignoreConfig;

        if (isset($this->names[$cacheKey])) {
            return $this->names[$cacheKey];
        }

        $names = [];

        foreach ($this->available($ignoreConfig) as $languageCode) {
            $intl = $this->translationFile(
                $this->rootDirectory."/plugins/Intl/lang/{$languageCode}.json",
            );
            $values = $intl['Intl'] ?? null;

            if (! is_array($values)
                || ! is_string($values['OriginalLanguageName'] ?? null)
                || ! is_string($values['EnglishLanguageName'] ?? null)) {
                continue;
            }

            $names[] = [
                'code' => $languageCode,
                'name' => $values['OriginalLanguageName'],
                'english_name' => $values['EnglishLanguageName'],
            ];
        }

        return $this->names[$cacheKey] = $names;
    }

    public function information(bool $excludeNonCorePlugins = true, bool $ignoreConfig = false): array
    {
        $english = $this->allTranslations('en', $excludeNonCorePlugins);
        $englishCount = count($english, COUNT_RECURSIVE);
        $information = [];

        foreach ($this->available($ignoreConfig) as $languageCode) {
            $translations = $this->allTranslations($languageCode, $excludeNonCorePlugins);
            $intl = $translations['Intl'] ?? null;

            if (! is_array($intl)
                || ! is_string($intl['OriginalLanguageName'] ?? null)
                || ! is_string($intl['EnglishLanguageName'] ?? null)) {
                continue;
            }

            $completed = $this->completedTranslations($english, $translations);
            $percentage = $englishCount === 0
                ? 0.0
                : round(100 * count($completed, COUNT_RECURSIVE) / $englishCount);
            $general = $translations['General'] ?? [];
            $translator = is_array($general) && is_string($general['TranslatorName'] ?? null)
                ? $general['TranslatorName']
                : '-';
            $information[] = [
                'code' => $languageCode,
                'name' => $intl['OriginalLanguageName'],
                'english_name' => $intl['EnglishLanguageName'],
                'translators' => $translator,
                'percentage_complete' => $percentage.'%',
            ];
        }

        return $information;
    }

    public function translations(string $languageCode): ?array
    {
        if (! $this->isAvailable($languageCode)) {
            return null;
        }

        $translations = $this->translationFile($this->rootDirectory."/lang/{$languageCode}.json") ?? [];

        foreach ($this->activatedPlugins as $plugin) {
            if (! $this->validPluginName($plugin)) {
                continue;
            }

            $pluginTranslations = $this->translationFile(
                $this->rootDirectory."/plugins/{$plugin}/lang/{$languageCode}.json",
            );

            if ($pluginTranslations !== null) {
                $translations = array_merge_recursive($translations, $pluginTranslations);
            }
        }

        return $this->flatten($translations);
    }

    /** @return array<string, mixed> */
    private function allTranslations(string $languageCode, bool $excludeNonCorePlugins): array
    {
        $translations = $this->translationFile($this->rootDirectory."/lang/{$languageCode}.json") ?? [];
        $pluginDirectories = glob($this->rootDirectory.'/plugins/*', GLOB_ONLYDIR);

        if (! is_array($pluginDirectories)) {
            return $translations;
        }

        foreach ($pluginDirectories as $pluginDirectory) {
            $plugin = basename($pluginDirectory);

            if (($excludeNonCorePlugins && ! in_array($plugin, $this->bundledPlugins, true))
                || ! $this->validPluginName($plugin)) {
                continue;
            }

            $pluginTranslations = $this->translationFile(
                "{$pluginDirectory}/lang/{$languageCode}.json",
            );

            if ($pluginTranslations !== null) {
                $translations = array_merge_recursive($translations, $pluginTranslations);
            }
        }

        return $translations;
    }

    /**
     * @param  array<string, mixed>  $english
     * @param  array<string, mixed>  $translations
     * @return array<string, mixed>
     */
    private function completedTranslations(array $english, array $translations): array
    {
        $completed = [];

        foreach ($english as $module => $keys) {
            $translatedKeys = $translations[$module] ?? null;

            if (! is_array($keys) || ! is_array($translatedKeys)) {
                continue;
            }

            $nonEmpty = array_filter(
                $translatedKeys,
                static fn (mixed $value): bool => is_string($value) && strlen($value) > 0,
            );
            $completed[$module] = array_intersect_key($keys, $nonEmpty);
        }

        return $completed;
    }

    /**
     * @param  array<string, mixed>  $translations
     * @return list<array{label: string, value: string}>
     */
    private function flatten(array $translations): array
    {
        $rows = [];

        foreach ($translations as $module => $keys) {
            if (! is_array($keys)) {
                continue;
            }

            foreach ($keys as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $rows[] = ['label' => "{$module}_{$key}", 'value' => $value];
                }
            }
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function translationFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("The Matomo translation file [{$path}] is not readable.");
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException(
                "The Matomo translation file [{$path}] is invalid.",
                previous: $jsonException,
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function validLanguageCode(string $languageCode): bool
    {
        return $languageCode !== ''
            && ! in_array($languageCode, ['.', '..'], true)
            && preg_match('/^[a-z0-9][a-z0-9._-]*$/Di', $languageCode) === 1;
    }

    private function validPluginName(string $plugin): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $plugin) === 1;
    }
}
