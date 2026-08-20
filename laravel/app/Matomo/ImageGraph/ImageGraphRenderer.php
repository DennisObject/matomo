<?php

declare(strict_types=1);

namespace App\Matomo\ImageGraph;

interface ImageGraphRenderer
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $columns
     */
    public function render(
        array $rows,
        array $columns,
        string $graphType,
        int $width,
        int $height,
        int $fontSize,
        bool $showLegend,
        string $textColor,
        string $backgroundColor,
        string $gridColor,
    ): string;
}
