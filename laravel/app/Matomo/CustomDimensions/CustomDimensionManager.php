<?php

declare(strict_types=1);

namespace App\Matomo\CustomDimensions;

use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Goals\SiteTrackerCacheInvalidator;
use App\Matomo\Localization\MatomoTranslator;
use InvalidArgumentException;

final readonly class CustomDimensionManager
{
    public function __construct(
        private CustomDimensionRepository $dimensions,
        private SiteTrackerCacheInvalidator $siteCache,
        private TrackerCacheInvalidator $generalCache,
        private MatomoTranslator $translator,
    ) {}

    /** @param list<array{dimension: string, pattern: string}> $extractions */
    public function create(
        int $siteId,
        string $name,
        string $scope,
        bool $active,
        array $extractions,
        bool $caseSensitive,
        string $description,
        string $language,
    ): int {
        $this->validate($name, $scope, $extractions, $description, $language);
        $dimensionId = $this->dimensions->create(
            $siteId,
            $name,
            $scope,
            $active,
            $extractions,
            $caseSensitive,
            $description,
        );
        $this->clearCaches($siteId);

        return $dimensionId;
    }

    /** @param list<array{dimension: string, pattern: string}> $extractions */
    public function update(
        int $siteId,
        int $dimensionId,
        string $name,
        bool $active,
        array $extractions,
        ?bool $caseSensitive,
        ?string $description,
        string $language,
    ): void {
        $current = $this->dimensions->find($siteId, $dimensionId);

        if ($current === null) {
            throw new InvalidArgumentException($this->translator->translate(
                'CustomDimensions_ExceptionDimensionDoesNotExist',
                $language,
                [$dimensionId, $siteId],
            ));
        }

        $scope = is_string($current['scope'] ?? null) ? $current['scope'] : '';
        $caseSensitive ??= (bool) ($current['case_sensitive'] ?? true);
        $description ??= is_string($current['description'] ?? null) ? $current['description'] : '';
        $this->validate($name, $scope, $extractions, $description, $language);
        $this->dimensions->update(
            $siteId,
            $dimensionId,
            $name,
            $active,
            $extractions,
            $caseSensitive,
            $description,
        );
        $this->clearCaches($siteId);
    }

    /** @param list<array{dimension: string, pattern: string}> $extractions */
    private function validate(
        string $name,
        string $scope,
        array $extractions,
        string $description,
        string $language,
    ): void {
        if ($name === '') {
            throw new InvalidArgumentException($this->translator->translate(
                'CustomDimensions_NameIsRequired',
                $language,
            ));
        }

        if (strlen($name) > 255) {
            throw new InvalidArgumentException($this->translator->translate(
                'CustomDimensions_NameIsTooLong',
                $language,
                [255],
            ));
        }

        if (strip_tags($name) !== $name || str_replace(['/', '\\', '&', '.', '<', '>'], '', $name) !== $name) {
            throw new InvalidArgumentException($this->translator->translate(
                'CustomDimensions_NameAllowedCharacters',
                $language,
            ));
        }

        if (! in_array($scope, ['visit', 'action', 'conversion'], true)) {
            throw new InvalidArgumentException(
                "Invalid value '{$scope}' for 'scope' specified. Available scopes are: visit, action, conversion",
            );
        }

        if ($scope !== 'action' && $extractions !== []) {
            throw new InvalidArgumentException("Extractions can be used only in scope 'action'");
        }

        if (mb_strlen($description) > 1000) {
            throw new InvalidArgumentException($this->translator->translate(
                'CustomDimensions_DescriptionIsTooLong',
                $language,
                [1000],
            ));
        }

        if (strip_tags($description) !== $description) {
            throw new InvalidArgumentException($this->translator->translate(
                'CustomDimensions_DescriptionAllowedCharacters',
                $language,
            ));
        }

        foreach ($extractions as $extraction) {
            $this->validateExtraction($extraction);
        }
    }

    /** @param array{dimension: string, pattern: string} $extraction */
    private function validateExtraction(array $extraction): void
    {
        $dimension = $extraction['dimension'];
        $pattern = $extraction['pattern'];

        if (! in_array($dimension, ['url', 'urlparam', 'action_name'], true)) {
            throw new InvalidArgumentException(
                "Invalid dimension '{$dimension}' used in an extraction. Available dimensions are: url, urlparam, action_name",
            );
        }

        if (strlen($pattern) > 8192) {
            throw new InvalidArgumentException('The extraction pattern is too long.');
        }

        $nonCapturingGroups = substr_count($pattern, '(?');

        if ($pattern !== '' && $dimension !== 'urlparam'
            && (substr_count($pattern, '(') - $nonCapturingGroups !== 1
                || substr_count($pattern, ')') - $nonCapturingGroups !== 1
                || substr_count($pattern, ')', (int) strpos($pattern, '(')) - $nonCapturingGroups !== 1)) {
            throw new InvalidArgumentException(
                "You need to group exactly one part of the regular expression inside round brackets, eg 'index_(.+).html'",
            );
        }

        $expression = $dimension === 'urlparam' ? '\\?.*'.$pattern.'=([^&]*)' : $pattern;

        if (@preg_match('/'.str_replace('/', '\\/', $expression).'/', '') === false) {
            throw new InvalidArgumentException('The extraction pattern is not a valid regular expression.');
        }
    }

    private function clearCaches(int $siteId): void
    {
        $this->siteCache->clear($siteId);
        $this->generalCache->clearGeneral();
    }
}
