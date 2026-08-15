<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class ImageGraphRequest
{
    /** @param list<string> $columns */
    public function __construct(
        public int $idSite,
        public string $period,
        public string $date,
        public string $apiModule,
        public string $apiAction,
        public string $graphType,
        public int $outputType,
        public array $columns,
        public bool $showLegend,
        public int $width,
        public int $height,
        public int $fontSize,
        public string $textColor,
        public string $backgroundColor,
        public string $gridColor,
    ) {}
}
