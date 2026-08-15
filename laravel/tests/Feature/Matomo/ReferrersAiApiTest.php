<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Referrers\ReferrerDefinitionCatalog;
use App\Matomo\Reporting\HierarchicalBlobArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class ReferrersAiApiTest extends TestCase
{
    public function test_returns_ai_assistants_with_metadata_and_expanded_urls(): void
    {
        $this->bindDependencies();
        $this->bindArchives([
            'Referrers_entryUrlByAIAssistant' => [$this->row('ChatGPT', 2, 4, 3)],
            'Referrers_entryUrlByAIAssistant_3' => [$this->row('https://example.test/page', 2, 4)],
        ]);

        $this->get($this->url('getAIAssistants').'&expanded=1')
            ->assertOk()
            ->assertJsonPath('0.label', 'ChatGPT')
            ->assertJsonPath('0.url', 'chatgpt.com')
            ->assertJsonPath('0.logo', 'icons/chatgpt.png')
            ->assertJsonPath('0.segment', 'referrerType==ai;referrerName==ChatGPT')
            ->assertJsonPath('0.subtable.0.label', 'example.test/page');
    }

    public function test_uses_title_archive_for_secondary_dimension(): void
    {
        $this->bindDependencies();
        $archives = $this->createMock(HierarchicalBlobArchiveRepository::class);
        $archives->expects($this->once())->method('records')->with(
            [7],
            $this->anything(),
            '',
            'Referrers_entryTitleByAIAssistant',
            true,
        )->willReturn([7 => ['2026-08-14,2026-08-14' => [
            'Referrers_entryTitleByAIAssistant' => [$this->row('Claude', 1, 2, 4)],
            'Referrers_entryTitleByAIAssistant_4' => [$this->row('A useful page', 1, 2)],
        ]]]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url('getAIAssistants').'&secondaryDimension=entryPageTitle&expanded=1')
            ->assertOk()
            ->assertJsonPath('0.subtable.0.label', 'A useful page');
    }

    public function test_returns_all_or_selected_entry_urls_and_titles(): void
    {
        $this->bindDependencies();
        $this->bindArchives([
            'Referrers_entryUrlByAIAssistant' => [
                $this->row('ChatGPT', 2, 4, 3),
                $this->row('Claude', 1, 2, 4),
            ],
            'Referrers_entryUrlByAIAssistant_3' => [$this->row('https://example.test/a', 2, 4)],
            'Referrers_entryUrlByAIAssistant_4' => [$this->row('https://example.test/b', 1, 2)],
            'Referrers_entryTitleByAIAssistant' => [$this->row('ChatGPT', 2, 4, 5)],
            'Referrers_entryTitleByAIAssistant_5' => [$this->row('Page title', 2, 4)],
        ]);

        $this->get($this->url('getEntryPageUrlsForAIAssistant'))
            ->assertOk()->assertJsonCount(2)->assertJsonPath('0.label', 'example.test/a');
        $this->get($this->url('getEntryPageUrlsForAIAssistant').'&idSubtable=3')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.label', 'example.test/a');
        $this->get($this->url('getEntryPageTitlesForAIAssistant').'&idSubtable=5')
            ->assertOk()->assertJsonPath('0.label', 'Page title');
    }

    public function test_flattens_ai_assistant_and_entry_dimensions(): void
    {
        $this->bindDependencies();
        $this->bindArchives([
            'Referrers_entryUrlByAIAssistant' => [$this->row('ChatGPT', 2, 4, 3)],
            'Referrers_entryUrlByAIAssistant_3' => [$this->row('https://example.test/a', 2, 4)],
        ]);

        $this->get($this->url('getAIAssistants').'&flat=1&show_dimensions=1')
            ->assertOk()
            ->assertJsonPath('0.label', 'ChatGPT - https://example.test/a')
            ->assertJsonPath('0.Referrers_AIAssistant', 'ChatGPT')
            ->assertJsonPath('0.Actions_EntryPageUrl', 'https://example.test/a')
            ->assertJsonMissingPath('0.url');
    }

    public function test_falls_back_to_ai_website_rows_when_dedicated_archive_is_empty(): void
    {
        $this->bindDependencies();
        $archives = $this->createMock(HierarchicalBlobArchiveRepository::class);
        $archives->expects($this->exactly(2))->method('records')->willReturnCallback(
            fn (array $sites, array $periods, string $segment, string $record): array => [
                7 => ['2026-08-14,2026-08-14' => [$record => $record === 'Referrers_entryUrlByAIAssistant'
                    ? []
                    : [
                        $this->row('chatgpt.com', 2, 4),
                        $this->row('news.example', 1, 2),
                    ]]],
            ],
        );
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url('getAIAssistants'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.label', 'ChatGPT')
            ->assertJsonPath('0.nb_visits', 2);
    }

    public function test_denies_access_before_reading_archives(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $archives = $this->createMock(HierarchicalBlobArchiveRepository::class);
        $archives->expects($this->never())->method('records');
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url('getAIAssistants'))->assertStatus(401);
    }

    private function bindDependencies(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(SiteRepository::class, $sites);
        $definitions = $this->createStub(ReferrerDefinitionCatalog::class);
        $definitions->method('aiAssistantName')->willReturnCallback(
            static fn (string $url): ?string => str_contains($url, 'chatgpt.com') ? 'ChatGPT' : null,
        );
        $definitions->method('aiAssistantUrl')->willReturnCallback(
            static fn (string $name): string => strtolower($name).'.com',
        );
        $definitions->method('aiAssistantLogo')->willReturnCallback(
            static fn (string $name): string => 'icons/'.strtolower($name).'.png',
        );
        $this->app->instance(ReferrerDefinitionCatalog::class, $definitions);
        $this->app->instance(
            HierarchicalBlobArchiveRepository::class,
            $this->createStub(HierarchicalBlobArchiveRepository::class),
        );
    }

    /** @param array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>> $records */
    private function bindArchives(array $records): void
    {
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturn([7 => ['2026-08-14,2026-08-14' => $records]]);
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
