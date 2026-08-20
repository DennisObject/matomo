<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Archiving\BrowserLanguageArchiveLabeler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BrowserLanguageArchiveLabelerTest extends TestCase
{
    #[DataProvider('labels')]
    public function test_matches_legacy_browser_language_labels(string $browserLanguage, string $expected): void
    {
        $labeler = new BrowserLanguageArchiveLabeler(
            languageCodes: ['de', 'en', 'fr', 'zh'],
            countryCodes: ['cn', 'de', 'fr', 'us'],
            countriesByLanguage: ['de' => 'de', 'fr' => 'fr'],
        );

        $this->assertSame($expected, $labeler->label($browserLanguage));
    }

    /** @return iterable<string, array{string, string}> */
    public static function labels(): iterable
    {
        yield 'language country guess' => ['de,de;q=0.8', 'de'];
        yield 'explicit different country' => ['en-US,en;q=0.5', 'en-us'];
        yield 'script and country' => ['zh-Hans-CN', 'zh-cn'];
        yield 'invalid language' => ['invalid', 'xx'];
    }
}
