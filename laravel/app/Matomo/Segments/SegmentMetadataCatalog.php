<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

use App\Matomo\CustomDimensions\CustomDimensionRepository;
use App\Matomo\Localization\MatomoTranslator;
use RuntimeException;

final readonly class SegmentMetadataCatalog
{
    public function __construct(
        private MatomoTranslator $translator,
        private CustomDimensionRepository $dimensions,
        private string $catalogPath,
    ) {}

    /**
     * @param  list<int>  $siteIds
     * @return list<array<string, mixed>>
     */
    public function metadata(array $siteIds, string $language, bool $includeRestricted): array
    {
        $metadata = $this->builtIn($language, $includeRestricted);
        foreach ($siteIds as $siteId) {
            foreach ($this->dimensions->configuredForSite($siteId) as $dimension) {
                if (! ($dimension['active'] ?? false)) {
                    continue;
                }

                $id = (int) ($dimension['idcustomdimension'] ?? 0);
                $name = $dimension['name'] ?? '';
                if ($id < 1 || ! is_string($name) || $name === '') {
                    continue;
                }

                $metadata[] = [
                    'type' => 'dimension',
                    'category' => $this->translator->translate('CustomDimensions_CustomDimensions', $language),
                    'name' => $name,
                    'segment' => 'dimension'.$id,
                ];
            }
        }

        $unique = [];
        foreach ($metadata as $entry) {
            $segment = $entry['segment'] ?? null;
            if (is_string($segment)) {
                $unique[$segment] = $entry;
            }
        }

        return array_values($unique);
    }

    /** @return list<array<string, mixed>> */
    private function builtIn(string $language, bool $includeRestricted): array
    {
        $catalog = require $this->catalogPath;
        if (! is_array($catalog)) {
            throw new RuntimeException('The segment metadata catalog is invalid.');
        }

        $result = [];
        foreach ($catalog as $entry) {
            if (! is_array($entry) || (! $includeRestricted && ($entry['permission'] ?? null) === '1')) {
                continue;
            }

            foreach (['category', 'name', 'acceptedValues'] as $field) {
                $key = $entry[$field.'Key'] ?? null;
                if (is_string($key)) {
                    $entry[$field] = $this->translator->translate($key, $language);
                    unset($entry[$field.'Key']);
                }
            }

            unset($entry['permission']);
            $result[] = $entry;
        }

        return $result;
    }
}
