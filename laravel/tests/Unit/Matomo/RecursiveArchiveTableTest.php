<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Archiving\RecursiveArchiveTable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RecursiveArchiveTableTest extends TestCase
{
    public function test_serializes_deep_paths_parent_totals_metadata_and_subtable_limits(): void
    {
        $table = new RecursiveArchiveTable(
            aggregationOperations: ['minimum' => 'min', 'maximum' => 'max'],
            nonSummableParentColumns: ['nb_uniq_visitors'],
        );
        $table->mergePath(['blog', 'guides', '/one'], [
            'nb_visits' => 2,
            'nb_uniq_visitors' => 2,
            'nb_hits' => 5,
            'minimum' => 9,
            'maximum' => 9,
        ], ['url' => 'https://example.test/blog/guides/one']);
        $table->mergePath(['blog', 'guides', '/one'], [
            'nb_visits' => 1,
            'nb_uniq_visitors' => 1,
            'nb_hits' => 1,
            'minimum' => 5,
            'maximum' => 12,
        ]);
        $table->mergePath(['blog', 'guides', '/two'], [
            'nb_visits' => 1,
            'nb_uniq_visitors' => 1,
            'nb_hits' => 4,
            'minimum' => 7,
            'maximum' => 7,
        ]);
        $table->mergePath(['blog', 'guides', '/three'], [
            'nb_visits' => 1,
            'nb_uniq_visitors' => 1,
            'nb_hits' => 3,
            'minimum' => 6,
            'maximum' => 8,
        ]);

        $records = $table->serialized(10, 2, 'nb_hits');
        $root = $this->decode($records['']);
        $this->assertSame([
            'label' => 'blog',
            'nb_visits' => 5,
            'nb_hits' => 13,
            'minimum' => 5,
            'maximum' => 12,
        ], $root[0][0]);
        $this->assertSame(1, $root[0][3]);
        $guides = $this->decode($records['_1']);
        $this->assertSame('guides', $guides[0][0]['label']);
        $this->assertSame(2, $guides[0][3]);
        $leaves = $this->decode($records['_2']);
        $this->assertSame(['/one', -1], array_column(array_column($leaves, 0), 'label'));
        $this->assertSame(7, $leaves[1][0]['nb_hits']);
        $this->assertSame(2, $leaves[1][0]['nb_visits']);
        $this->assertSame('https://example.test/blog/guides/one', $leaves[0][1]['url']);
    }

    public function test_merges_stored_trees_and_renames_period_metrics(): void
    {
        $table = new RecursiveArchiveTable(
            nonSummableParentColumns: ['sum_daily_nb_uniq_visitors'],
        );
        $table->mergeArchiveRecords([
            'Actions_actions_url' => [[
                'columns' => ['label' => 'docs', 'nb_visits' => 2],
                'metadata' => [],
                'subtableId' => 7,
            ]],
            'Actions_actions_url_7' => [[
                'columns' => [
                    'label' => '/start',
                    'nb_visits' => 2,
                    'nb_uniq_visitors' => 2,
                ],
                'metadata' => ['url' => 'https://example.test/docs/start'],
                'subtableId' => null,
            ]],
        ], 'Actions_actions_url', [
            'nb_uniq_visitors' => 'sum_daily_nb_uniq_visitors',
        ]);

        $records = $table->serialized(10, 10, 'nb_visits');
        $root = $this->decode($records['']);
        $leaf = $this->decode($records['_1']);
        $this->assertArrayNotHasKey('sum_daily_nb_uniq_visitors', $root[0][0]);
        $this->assertSame(2, $leaf[0][0]['sum_daily_nb_uniq_visitors']);
        $this->assertSame('https://example.test/docs/start', $leaf[0][1]['url']);
    }

    public function test_nests_goal_metrics_and_keeps_the_largest_pages_before_value(): void
    {
        $table = new RecursiveArchiveTable;
        $table->mergePath(['docs', '/one'], [
            'nb_visits' => 1,
            'goal_1_nb_conversions' => 1,
            'goal_1_nb_conv_pages_before' => 5,
        ]);
        $table->mergePath(['docs', '/one'], [
            'nb_visits' => 1,
            'goal_1_nb_conversions' => 1,
            'goal_1_nb_conv_pages_before' => 3,
        ]);
        $table->mergePath(['docs', '/two'], [
            'nb_visits' => 1,
            'goal_1_nb_conversions' => 1,
            'goal_1_nb_conv_pages_before' => 4,
        ]);

        $records = $table->serialized(10, 10, 'nb_visits');
        $root = unserialize($records[''], ['allowed_classes' => false]);
        $leaves = unserialize($records['_1'], ['allowed_classes' => false]);

        $this->assertIsArray($root);
        $this->assertIsArray($leaves);
        $this->assertSame(3, $root[0][0][10][1][1]);
        $this->assertSame(5, $root[0][0][10][1][9]);
        $this->assertSame(2, $leaves[0][0][10][1][1]);
        $this->assertSame(5, $leaves[0][0][10][1][9]);
    }

    public function test_rejects_invalid_serialization_limits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RecursiveArchiveTable)->serialized(0, 10, 'nb_visits');
    }

    /**
     * @return list<array{0: array<string, mixed>, 1: array<string, mixed>, 3: int|null}>
     */
    private function decode(string $value): array
    {
        $decoded = unserialize($value, ['allowed_classes' => false]);

        if (! is_array($decoded)) {
            self::fail('The archive payload is not an array.');
        }

        $rows = [];

        foreach ($decoded as $row) {
            if (! is_array($row)
                || ! is_array($row[0] ?? null)
                || ! is_array($row[1] ?? null)
                || (! is_int($row[3] ?? null) && ($row[3] ?? null) !== null)) {
                self::fail('The archive row does not use the expected shape.');
            }

            $rows[] = [
                0 => $this->stringMap($row[0]),
                1 => $this->stringMap($row[1]),
                3 => $row[3] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private function stringMap(array $values): array
    {
        $result = [];

        foreach ($values as $name => $value) {
            if (! is_string($name)) {
                self::fail('The archive map contains a non-string key.');
            }

            $result[$name] = $value;
        }

        return $result;
    }
}
