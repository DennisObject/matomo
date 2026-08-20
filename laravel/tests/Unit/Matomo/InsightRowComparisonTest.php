<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Insights\InsightRowComparison;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InsightRowComparisonTest extends TestCase
{
    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('orders')]
    public function test_matches_legacy_ordering(string $order, array $expected): void
    {
        $rows = $this->compare(order: $order);

        $this->assertSame($expected, array_column($rows, 'label'));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function orders(): iterable
    {
        yield 'importance' => [InsightRowComparison::ORDER_IMPORTANCE, [
            'val6', 'val11', 'val107', 'val7', 'val3', 'val10', 'val2', 'val9', 'val8', 'val102', 'val4', 'val1', 'val12',
        ]];
        yield 'relative' => [InsightRowComparison::ORDER_RELATIVE, [
            'val11', 'val12', 'val7', 'val3', 'val10', 'val2', 'val1', 'val6', 'val107', 'val102', 'val9', 'val8', 'val4',
        ]];
        yield 'absolute' => [InsightRowComparison::ORDER_ABSOLUTE, [
            'val11', 'val7', 'val3', 'val10', 'val2', 'val1', 'val12', 'val6', 'val107', 'val9', 'val8', 'val102', 'val4',
        ]];
    }

    public function test_calculates_legacy_columns_and_row_types(): void
    {
        $rows = $this->compare();
        $indexed = array_column($rows, null, 'label');

        $this->assertSame([
            'growth_percent' => '3300%',
            'growth_percent_numeric' => '3300',
            'grown' => true,
            'value_old' => 5,
            'value_new' => 170,
            'difference' => 165,
            'importance' => 165,
            'isDisappeared' => false,
            'isNew' => false,
            'isMover' => true,
        ], array_intersect_key($indexed['val11'], array_flip([
            'growth_percent', 'growth_percent_numeric', 'grown', 'value_old', 'value_new',
            'difference', 'importance', 'isDisappeared', 'isNew', 'isMover',
        ])));
        $this->assertTrue($indexed['val7']['isNew']);
        $this->assertTrue($indexed['val107']['isDisappeared']);
    }

    public function test_applies_growth_impact_and_direction_limits(): void
    {
        $rows = (new InsightRowComparison)->compare(
            $this->current(),
            $this->past(),
            'nb_visits',
            200,
            20,
            -1,
            -1,
            20,
            -20,
            InsightRowComparison::ORDER_ABSOLUTE,
            1,
            1,
        );

        $this->assertSame(['val11', 'val6'], array_column($rows, 'label'));
    }

    public function test_rejects_unknown_ordering(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported orderBy');

        $this->compare(order: 'unknown');
    }

    /** @return list<array<string, mixed>> */
    private function compare(string $order = InsightRowComparison::ORDER_ABSOLUTE): array
    {
        return (new InsightRowComparison)->compare(
            $this->current(),
            $this->past(),
            'nb_visits',
            200,
            2,
            2,
            2,
            17,
            -17,
            $order,
            -1,
            -1,
        );
    }

    /** @return list<array{label: string, nb_visits: int}> */
    private function current(): array
    {
        return $this->rows([120, 70, 90, 99, 0, 0, 134, 100, 7, 89, 170, 14]);
    }

    /** @return list<array{label: string, nb_visits: int}> */
    private function past(): array
    {
        return [
            ['label' => 'val1', 'nb_visits' => 102],
            ['label' => 'val102', 'nb_visits' => 29],
            ['label' => 'val4', 'nb_visits' => 120],
            ['label' => 'val6', 'nb_visits' => 180],
            ['label' => 'val109', 'nb_visits' => 0],
            ['label' => 'val8', 'nb_visits' => 140],
            ['label' => 'val9', 'nb_visits' => 72],
            ['label' => 'val107', 'nb_visits' => 150],
            ['label' => 'val10', 'nb_visits' => 0],
            ['label' => 'val11', 'nb_visits' => 5],
            ['label' => 'val12', 'nb_visits' => 5],
        ];
    }

    /**
     * @param  list<int>  $values
     * @return list<array{label: string, nb_visits: int}>
     */
    private function rows(array $values): array
    {
        return array_map(
            static fn (int $value, int $index): array => [
                'label' => 'val'.($index + 1),
                'nb_visits' => $value,
            ],
            $values,
            array_keys($values),
        );
    }
}
