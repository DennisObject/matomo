<?php

declare(strict_types=1);

namespace App\Matomo\CustomDimensions;

use App\Matomo\Localization\MatomoTranslator;

final readonly class CustomDimensionCatalog
{
    public function __construct(
        private CustomDimensionRepository $dimensions,
        private MatomoTranslator $translator,
    ) {}

    /** @return list<array<string, bool|int|string|list<array<string, mixed>>>> */
    public function configured(int $siteId, ?string $scope = null): array
    {
        $dimensions = $this->dimensions->configuredForSite($siteId);

        if ($scope === null) {
            return $dimensions;
        }

        return array_values(array_filter(
            $dimensions,
            static fn (array $dimension): bool => ($dimension['scope'] ?? null) === $scope,
        ));
    }

    /**
     * @return list<array{value: string, name: string, numSlotsAvailable: int, numSlotsUsed: int, numSlotsLeft: int, supportsExtractions: bool}>
     */
    public function scopes(int $siteId, string $language): array
    {
        $configured = $this->dimensions->configuredForSite($siteId);
        $result = [];

        foreach (['visit', 'action'] as $scope) {
            $available = count($this->dimensions->installedIndexes($scope));
            $used = count(array_filter(
                $configured,
                static fn (array $dimension): bool => ($dimension['scope'] ?? null) === $scope,
            ));
            $result[] = [
                'value' => $scope,
                'name' => $this->translator->translate('General_TrackingScope'.ucfirst($scope), $language),
                'numSlotsAvailable' => $available,
                'numSlotsUsed' => $used,
                'numSlotsLeft' => $available - $used,
                'supportsExtractions' => $scope === 'action',
            ];
        }

        return $result;
    }

    /** @return list<array{value: string, name: string}> */
    public function extractionDimensions(string $language): array
    {
        return [
            [
                'value' => 'url',
                'name' => $this->translator->translate('Actions_ColumnPageURL', $language),
            ],
            [
                'value' => 'urlparam',
                'name' => $this->translator->translate('CustomDimensions_PageUrlParam', $language),
            ],
            [
                'value' => 'action_name',
                'name' => $this->translator->translate('Goals_PageTitle', $language),
            ],
        ];
    }
}
