<?php

declare(strict_types=1);

namespace App\Matomo\Feedback;

use App\Matomo\Localization\MatomoTranslator;
use JsonException;

final readonly class JsonFeedbackFeatureNameResolver implements FeedbackFeatureNameResolver
{
    /** @param list<string> $translationDirectories */
    public function __construct(
        private array $translationDirectories,
        private MatomoTranslator $translator,
    ) {}

    public function englishName(string $name, string $language): string
    {
        if ($language === 'en') {
            return $name;
        }

        foreach ($this->translationDirectories as $directory) {
            $translationKey = $this->findTranslationKey($directory.'/'.$language.'.json', $name);

            if ($translationKey !== null) {
                return $this->translator->translate($translationKey, 'en');
            }
        }

        return $name;
    }

    private function findTranslationKey(string $path, string $name): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            return null;
        }

        try {
            $translations = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($translations)) {
            return null;
        }

        foreach ($translations as $plugin => $values) {
            if (! is_string($plugin) || ! is_array($values)) {
                continue;
            }

            foreach ($values as $key => $value) {
                if (is_string($key) && $value === $name) {
                    return $plugin.'_'.$key;
                }
            }
        }

        return null;
    }
}
