<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\ImageGraph\GdImageGraphRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GdImageGraphRendererTest extends TestCase
{
    #[DataProvider('graphTypes')]
    public function test_renders_supported_graph_types_as_png(string $graphType): void
    {
        $png = (new GdImageGraphRenderer)->render(
            [['label' => 'A', 'nb_visits' => 4], ['label' => 'B', 'nb_visits' => 2]],
            ['nb_visits'],
            $graphType,
            320,
            180,
            9,
            true,
            '222222',
            'FFFFFF',
            'CCCCCC',
        );

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $this->assertGreaterThan(100, strlen($png));
    }

    public function test_rejects_empty_and_zero_only_reports(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('There is no data for this graph.');

        (new GdImageGraphRenderer)->render(
            [['label' => 'A', 'nb_visits' => 0]],
            ['nb_visits'],
            'evolution',
            320,
            180,
            9,
            true,
            '222222',
            'FFFFFF',
            'CCCCCC',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function graphTypes(): iterable
    {
        yield 'line' => ['evolution'];
        yield 'vertical bars' => ['verticalBar'];
        yield 'horizontal bars' => ['horizontalBar'];
        yield 'pie' => ['pie'];
        yield '3D pie compatibility' => ['3dPie'];
    }
}
