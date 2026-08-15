<?php

declare(strict_types=1);

namespace App\Matomo\ImageGraph;

use RuntimeException;

final class GdImageGraphRenderer implements ImageGraphRenderer
{
    private const array COLORS = ['4472c4', 'ed7d31', '70ad47', 'ffc000', '5b9bd5', 'a5a5a5'];

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
    ): string {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('Error: To create graphs, enable the GD PHP extension.');
        }

        $series = $this->series($rows, $columns);
        if ($series === []) {
            throw new RuntimeException('There is no data for this graph.');
        }

        $width = max(1, $width);
        $height = max(1, $height);
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new RuntimeException('The graph image could not be created.');
        }

        imagefill($image, 0, 0, $this->color($image, $backgroundColor));
        $grid = $this->color($image, $gridColor);
        $text = $this->color($image, $textColor);
        $left = 45;
        $top = 20;
        $right = $width - 20;
        $bottom = $height - ($showLegend ? 45 : 25);
        imagerectangle($image, $left, $top, $right, $bottom, $grid);

        for ($line = 1; $line < 5; $line++) {
            $y = $top + (int) (($bottom - $top) * $line / 5);
            imageline($image, $left, $y, $right, $y, $grid);
        }

        if (in_array($graphType, ['pie', '3dPie'], true)) {
            $this->pie($image, $series, $left, $top, $right, $bottom);
        } else {
            $this->plot($image, $series, $graphType, $left, $top, $right, $bottom);
        }

        if ($showLegend) {
            $x = $left;
            foreach (array_keys($series) as $index => $name) {
                $color = $this->color($image, self::COLORS[$index % count(self::COLORS)]);
                imagefilledrectangle($image, $x, $height - 20, $x + 10, $height - 10, $color);
                imagestring($image, min(5, max(1, intdiv($fontSize, 2))), $x + 14, $height - 22, $name, $text);
                $x += 20 + strlen($name) * 7;
            }
        }

        ob_start();
        imagepng($image);
        $png = ob_get_clean();

        return is_string($png) ? $png : throw new RuntimeException('The graph image could not be encoded.');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $columns
     * @return array<string, non-empty-list<float>>
     */
    private function series(array $rows, array $columns): array
    {
        if ($columns === []) {
            foreach ($rows as $row) {
                foreach ($row as $name => $value) {
                    if ($name !== 'label' && is_numeric($value)) {
                        $columns = [$name];
                        break 2;
                    }
                }
            }
        }

        $series = [];
        foreach ($columns as $column) {
            foreach ($rows as $row) {
                $value = $row[$column] ?? null;
                if (is_numeric($value)) {
                    $series[$column][] = (float) $value;
                }
            }
        }

        $nonZeroSeries = [];
        foreach ($series as $name => $values) {
            if (max($values) > 0) {
                $nonZeroSeries[$name] = $values;
            }
        }

        return $nonZeroSeries;
    }

    /** @param array<string, non-empty-list<float>> $series */
    private function plot(\GdImage $image, array $series, string $type, int $left, int $top, int $right, int $bottom): void
    {
        $maximum = 0.0;
        $points = 0;
        foreach ($series as $values) {
            $maximum = max($maximum, max($values));
            $points = max($points, count($values));
        }

        $seriesIndex = 0;
        foreach ($series as $values) {
            $color = $this->color($image, self::COLORS[$seriesIndex++ % count(self::COLORS)]);
            foreach ($values as $index => $value) {
                $x = $left + (int) (($right - $left) * ($index + 0.5) / max(1, $points));
                $y = $bottom - (int) (($bottom - $top) * $value / $maximum);
                if (in_array($type, ['verticalBar', 'horizontalBar'], true)) {
                    $barWidth = max(2, (int) (($right - $left) / max(1, $points * count($series))));
                    imagefilledrectangle($image, $x, $y, $x + $barWidth, $bottom - 1, $color);
                } elseif ($index > 0) {
                    $previousX = $left + (int) (($right - $left) * ($index - 0.5) / max(1, $points));
                    $previousY = $bottom - (int) (($bottom - $top) * $values[$index - 1] / $maximum);
                    imageline($image, $previousX, $previousY, $x, $y, $color);
                }
            }
        }
    }

    /** @param non-empty-array<string, non-empty-list<float>> $series */
    private function pie(\GdImage $image, array $series, int $left, int $top, int $right, int $bottom): void
    {
        $values = $series[array_key_first($series)];
        $total = array_sum($values);
        $start = 0.0;
        foreach ($values as $index => $value) {
            $end = $start + 360 * $value / $total;
            imagefilledarc($image, intdiv($left + $right, 2), intdiv($top + $bottom, 2),
                $right - $left - 30, $bottom - $top - 10, (int) $start, (int) $end,
                $this->color($image, self::COLORS[$index % count(self::COLORS)]), IMG_ARC_PIE);
            $start = $end;
        }
    }

    private function color(\GdImage $image, string $hex): int
    {
        if (preg_match('/^[0-9a-f]{6}$/iD', $hex) !== 1) {
            throw new RuntimeException('Graph colors must use six hexadecimal characters.');
        }

        $red = min(255, max(0, (int) hexdec(substr($hex, 0, 2))));
        $green = min(255, max(0, (int) hexdec(substr($hex, 2, 2))));
        $blue = min(255, max(0, (int) hexdec(substr($hex, 4, 2))));
        $color = imagecolorallocate(
            $image,
            $red,
            $green,
            $blue,
        );

        return $color !== false ? $color : throw new RuntimeException('The graph color could not be allocated.');
    }
}
