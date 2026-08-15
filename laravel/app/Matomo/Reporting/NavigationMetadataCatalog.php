<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Localization\MatomoTranslator;
use RuntimeException;

final readonly class NavigationMetadataCatalog
{
    public function __construct(private MatomoTranslator $translator, private string $resourceDirectory) {}

    /** @return list<array<string, mixed>> */
    public function reportPages(string $language): array
    {
        return $this->load('report-pages-metadata.php', $language);
    }

    /** @return list<array<string, mixed>> */
    public function widgets(string $language): array
    {
        return $this->load('widget-metadata.php', $language);
    }

    /** @return list<array<string, mixed>> */
    private function load(string $file, string $language): array
    {
        $catalog = require $this->resourceDirectory.'/'.$file;
        if (! is_array($catalog)) {
            throw new RuntimeException('The navigation metadata catalog is invalid.');
        }

        return array_values(array_filter(array_map(
            fn (mixed $entry): mixed => $this->translate($entry, $language),
            $catalog,
        ), is_array(...)));
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
