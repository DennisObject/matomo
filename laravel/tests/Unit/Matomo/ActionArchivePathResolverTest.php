<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Archiving\ActionArchiveConfiguration;
use App\Matomo\Archiving\ActionArchivePathResolver;
use PHPUnit\Framework\TestCase;

class ActionArchivePathResolverTest extends TestCase
{
    public function test_builds_legacy_url_title_link_and_search_paths(): void
    {
        $paths = new ActionArchivePathResolver(new ActionArchiveConfiguration);

        $this->assertSame(
            ['docs', 'guide', '/index'],
            $paths->path('example.test/docs/guide/', ActionArchivePathResolver::PAGE_URL, 2),
        );
        $this->assertSame(
            [' Docs / Guide'],
            $paths->path('Docs / Guide', ActionArchivePathResolver::PAGE_TITLE, null),
        );
        $this->assertSame(
            ['cdn.test', '/files/report.zip#latest'],
            $paths->path(
                'https://cdn.test/files/report.zip#latest',
                ActionArchivePathResolver::DOWNLOAD,
                null,
            ),
        );
        $this->assertSame(
            ['keyword'],
            $paths->path('keyword', ActionArchivePathResolver::SITE_SEARCH, null),
        );
        $this->assertSame('/docs/guide/index', $paths->flatLabel(
            ['docs', 'guide', '/index'],
            ActionArchivePathResolver::PAGE_URL,
        ));
        $this->assertSame(
            'https://www.example.test/path',
            $paths->reconstructedUrl('example.test/path', 3),
        );
    }

    public function test_uses_configured_title_delimiter_and_depth_limit(): void
    {
        $configuration = new ActionArchiveConfiguration(
            titleDelimiter: '::',
            categoryLevelLimit: 2,
        );
        $paths = new ActionArchivePathResolver($configuration);

        $this->assertSame(
            ['Area', ' Sub::Page'],
            $paths->path('Area::Sub::Page', ActionArchivePathResolver::PAGE_TITLE, null),
        );
        $this->assertSame(
            'Area::Sub::Page',
            $paths->flatLabel(
                ['Area', ' Sub::Page'],
                ActionArchivePathResolver::PAGE_TITLE,
            ),
        );
    }
}
