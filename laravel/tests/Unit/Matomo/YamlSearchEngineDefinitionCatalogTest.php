<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Referrers\YamlSearchEngineDefinitionCatalog;
use PHPUnit\Framework\TestCase;

class YamlSearchEngineDefinitionCatalogTest extends TestCase
{
    public function test_reads_canonical_urls_backlinks_and_icons_from_the_bundle(): void
    {
        $icons = dirname(__DIR__, 3).'/../plugins/Morpheus/icons/dist/searchEngines';
        $catalog = new YamlSearchEngineDefinitionCatalog(
            dirname(__DIR__, 3).'/vendor/matomo/searchengine-and-social-list/SearchEngines.yml',
            $icons,
        );

        self::assertSame('http://google.com', $catalog->url('Google'));
        self::assertSame('http://google.com/search?q=web+analytics', $catalog->backlink(
            'http://google.com',
            'web analytics',
        ));
        self::assertSame(
            'plugins/Morpheus/icons/dist/searchEngines/xx.png',
            $catalog->logo('http://google.com'),
        );
        self::assertSame('URL unknown!', $catalog->url('Missing'));
        self::assertSame(
            ['name' => 'Google', 'keywords' => 'blue shoes'],
            $catalog->search('https://www.google.com/search?q=blue+shoes'),
        );
        self::assertNull($catalog->search('https://news.example/story'));
    }
}
