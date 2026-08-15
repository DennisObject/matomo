<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Archiving\SegmentExpressionParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SegmentExpressionParserTest extends TestCase
{
    public function test_parses_encoded_groups_and_escaped_delimiters(): void
    {
        $groups = (new SegmentExpressionParser)->parse(
            'countryCode%3D%3Dnz%2CcountryCode%3D%3Dau%3BreferrerName%3D%40news%255C%252Cletter',
        );

        $this->assertCount(2, $groups);
        $this->assertSame('countryCode', $groups[0][0]->name);
        $this->assertSame('==', $groups[0][0]->operator);
        $this->assertSame('nz', $groups[0][0]->value);
        $this->assertSame('countryCode', $groups[0][1]->name);
        $this->assertSame('au', $groups[0][1]->value);
        $this->assertSame('referrerName', $groups[1][0]->name);
        $this->assertSame('=@', $groups[1][0]->operator);
        $this->assertSame('news,letter', $groups[1][0]->value);
    }

    #[DataProvider('invalidSegments')]
    public function test_rejects_invalid_syntax(string $segment): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SegmentExpressionParser)->parse($segment);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSegments(): iterable
    {
        yield 'no operator' => ['countryCode'];
        yield 'missing value' => ['countryCode>='];
        yield 'missing name' => ['==nz'];
        yield 'unknown operator' => ['countryCode~~nz'];
    }
}
