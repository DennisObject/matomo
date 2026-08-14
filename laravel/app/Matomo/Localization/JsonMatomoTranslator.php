<?php

declare(strict_types=1);

namespace App\Matomo\Localization;

use JsonException;
use RuntimeException;

final class JsonMatomoTranslator implements MatomoTranslator
{
    /**
     * @var array<string, array<string, array<string, string>>>
     */
    private array $translations = [];

    /**
     * @param  list<string>  $directories
     */
    public function __construct(private readonly array $directories) {}

    public function translate(string $key, string $language, array $arguments = []): string
    {
        $translation = $this->translation($key, $language);

        if ($arguments === []) {
            return str_replace('%%', '%', $translation);
        }

        return vsprintf($translation, $arguments);
    }

    private function translation(string $key, string $language): string
    {
        $separator = strpos($key, '_');

        if ($separator === false) {
            return $key;
        }

        $plugin = substr($key, 0, $separator);
        $name = substr($key, $separator + 1);
        $translations = $this->translations($language);

        if (isset($translations[$plugin][$name])) {
            return $translations[$plugin][$name];
        }

        if ($plugin !== 'Intl' && isset($translations['Intl'][$name])) {
            return $translations['Intl'][$name];
        }

        return $language === 'en' ? $key : $this->translation($key, 'en');
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function translations(string $language): array
    {
        if (isset($this->translations[$language])) {
            return $this->translations[$language];
        }

        $translations = [];

        foreach ($this->directories as $directory) {
            $path = $directory.'/'.$language.'.json';

            if (! is_file($path)) {
                continue;
            }

            $content = file_get_contents($path);

            if (! is_string($content)) {
                throw new RuntimeException("The Matomo translation file [{$path}] is not readable.");
            }

            try {
                $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    "The Matomo translation file [{$path}] is invalid.",
                    previous: $exception,
                );
            }

            if (is_array($decoded)) {
                $translations = array_replace_recursive($translations, $decoded);
            }
        }

        return $this->translations[$language] = $this->stringTranslations($translations);
    }

    /**
     * @param  array<array-key, mixed>  $translations
     * @return array<string, array<string, string>>
     */
    private function stringTranslations(array $translations): array
    {
        $result = [];

        foreach ($translations as $plugin => $values) {
            if (! is_string($plugin) || ! is_array($values)) {
                continue;
            }

            foreach ($values as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $result[$plugin][$name] = $value;
                }
            }
        }

        return $result;
    }
}
