<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Referrers\SearchEngineDefinitionCatalog;
use App\Matomo\Reporting\HierarchicalBlobArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class ReferrersSearchApiTest extends TestCase
{
    public function test_returns_search_engines_with_metadata_and_expanded_keywords(): void
    {
        $this->bindDependencies();
        $this->bindRecords('Referrers_keywordBySearchEngine', [
            'Referrers_keywordBySearchEngine' => [$this->row('Google', 3, 6, 5)],
            'Referrers_keywordBySearchEngine_5' => [$this->row('web analytics', 2, 4)],
        ]);

        $this->get($this->url('getSearchEngines').'&expanded=1')
            ->assertOk()
            ->assertJsonPath('0.url', 'http://google.test')
            ->assertJsonPath('0.logo', 'icons/google.png')
            ->assertJsonPath('0.segment', 'referrerType==search;referrerName==Google')
            ->assertJsonPath('0.subtable.0.label', 'web analytics')
            ->assertJsonPath('0.subtable.0.url', 'http://google.test/search?q=web+analytics');
    }

    public function test_returns_keywords_with_expanded_search_engines(): void
    {
        $this->bindDependencies();
        $this->bindRecords('Referrers_searchEngineByKeyword', [
            'Referrers_searchEngineByKeyword' => [$this->row('web analytics', 3, 6, 4)],
            'Referrers_searchEngineByKeyword_4' => [$this->row('Google', 2, 4)],
        ]);

        $this->get($this->url('getKeywords').'&expanded=1')
            ->assertOk()
            ->assertJsonPath('0.segment', 'referrerType==search;referrerKeyword==web+analytics')
            ->assertJsonPath('0.subtable.0.label', 'Google')
            ->assertJsonPath('0.subtable.0.url', 'http://google.test/search?q=web+analytics');
    }

    public function test_returns_both_direct_subtable_orientations(): void
    {
        $this->bindDependencies();
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturnCallback(
            fn (array $sites, array $periods, string $segment, string $record): array => [
                7 => ['2026-08-14,2026-08-14' => $record === 'Referrers_searchEngineByKeyword'
                    ? [
                        $record => [$this->row('web analytics', 3, 6, 4)],
                        $record.'_4' => [$this->row('Google', 2, 4)],
                    ]
                    : [
                        $record => [$this->row('Google', 3, 6, 5)],
                        $record.'_5' => [$this->row('web analytics', 2, 4)],
                    ]],
            ],
        );
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url('getSearchEnginesFromKeywordId').'&idSubtable=4')
            ->assertOk()
            ->assertJsonPath('0.label', 'Google')
            ->assertJsonPath('0.segment', 'referrerKeyword==web+analytics;referrerType==search;referrerName==Google');
        $this->get($this->url('getKeywordsFromSearchEngineId').'&idSubtable=5')
            ->assertOk()
            ->assertJsonPath('0.label', 'web analytics')
            ->assertJsonPath('0.segment', 'referrerName==Google;referrerType==search;referrerKeyword==web+analytics');
    }

    public function test_flattens_keyword_and_engine_dimensions(): void
    {
        $this->bindDependencies();
        $this->bindRecords('Referrers_searchEngineByKeyword', [
            'Referrers_searchEngineByKeyword' => [$this->row('web analytics', 3, 6, 4)],
            'Referrers_searchEngineByKeyword_4' => [$this->row('Google', 2, 4)],
        ]);

        $this->get($this->url('getKeywords').'&flat=1&show_dimensions=1')
            ->assertOk()
            ->assertJsonPath('0.label', 'web analytics - Google')
            ->assertJsonPath('0.Referrers_Keyword', 'web analytics')
            ->assertJsonPath('0.Referrers_SearchEngine', 'Google');
    }

    private function bindDependencies(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(SiteRepository::class, $sites);
        $definitions = $this->createStub(SearchEngineDefinitionCatalog::class);
        $definitions->method('url')->willReturn('http://google.test');
        $definitions->method('logo')->willReturn('icons/google.png');
        $definitions->method('backlink')->willReturnCallback(
            static fn (string $url, string $keyword): string => $url.'/search?q='.urlencode($keyword),
        );
        $this->app->instance(SearchEngineDefinitionCatalog::class, $definitions);
        $this->app->instance(
            HierarchicalBlobArchiveRepository::class,
            $this->createStub(HierarchicalBlobArchiveRepository::class),
        );
    }

    /** @param array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>> $records */
    private function bindRecords(string $record, array $records): void
    {
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->with(
            [7],
            $this->isType('array'),
            '',
            $record,
            true,
        )->willReturn([7 => ['2026-08-14,2026-08-14' => $records]]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);
    }

    /** @return array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null} */
    private function row(string $label, int $visits, int $actions, ?int $subtableId = null): array
    {
        return [
            'columns' => ['label' => $label, 'nb_visits' => $visits, 'nb_actions' => $actions],
            'metadata' => [],
            'subtableId' => $subtableId,
        ];
    }

    private function url(string $method): string
    {
        return "/index.php?module=API&method=Referrers.{$method}&idSite=7".
            '&period=day&date=2026-08-14&format=json&token_auth=view-token';
    }
}
