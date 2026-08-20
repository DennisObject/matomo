<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Referrers\ReferrerDefinitionCatalog;
use App\Matomo\Reporting\HierarchicalBlobArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class ReferrersSocialApiTest extends TestCase
{
    public function test_returns_socials_with_metadata_expansion_and_instagram_grouping(): void
    {
        $this->bindDependencies();
        $this->bindArchives([
            'Referrers_urlBySocialNetwork' => [
                $this->row('instagram', 2, 4, 3),
                $this->row('Instagram', 1, 2, 4),
                $this->row('Facebook', 1, 2, 5),
            ],
            'Referrers_urlBySocialNetwork_3' => [$this->row('https://instagram.com/a', 2, 4)],
            'Referrers_urlBySocialNetwork_4' => [$this->row('https://instagram.com/b', 1, 2)],
            'Referrers_urlBySocialNetwork_5' => [$this->row('https://facebook.com/c', 1, 2)],
        ]);

        $this->get($this->url('getSocials').'&expanded=1')
            ->assertOk()
            ->assertJsonPath('0.label', 'Instagram')
            ->assertJsonPath('0.nb_visits', 3)
            ->assertJsonPath('0.url', 'instagram.com')
            ->assertJsonPath('0.logo', 'icons/instagram.png')
            ->assertJsonPath('0.segment', 'referrerType==social;referrerName==Instagram')
            ->assertJsonCount(2, '0.subtable');
    }

    public function test_returns_all_or_selected_social_urls(): void
    {
        $this->bindDependencies();
        $this->bindArchives([
            'Referrers_urlBySocialNetwork' => [
                $this->row('Facebook', 2, 4, 3),
                $this->row('Instagram', 1, 2, 4),
            ],
            'Referrers_urlBySocialNetwork_3' => [$this->row('https://facebook.com/a', 2, 4)],
            'Referrers_urlBySocialNetwork_4' => [$this->row('https://instagram.com/b', 1, 2)],
        ]);

        $this->get($this->url('getUrlsForSocial'))
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.label', 'facebook.com/a')
            ->assertJsonPath('0.segment', 'referrerUrl==https%3A%2F%2Ffacebook.com%2Fa');
        $this->get($this->url('getUrlsForSocial').'&idSubtable=1')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.label', 'facebook.com/a');
    }

    public function test_returns_unformatted_percentage_quotients(): void
    {
        $this->bindDependencies();
        $this->bindArchives([
            'Referrers_urlBySocialNetwork' => [
                $this->row('Facebook', 1, 2),
                $this->row('Instagram', 2, 4),
            ],
        ]);

        $this->get($this->url('getSocials').'&format_metrics=0')
            ->assertOk()
            ->assertJsonPath('0.nb_visits_percent_of_total', 0.3333)
            ->assertJsonPath('1.nb_visits_percent_of_total', 0.6667);
    }

    public function test_falls_back_to_grouped_website_archives_only_when_social_archive_is_empty(): void
    {
        $this->bindDependencies();
        $archives = $this->createMock(HierarchicalBlobArchiveRepository::class);
        $archives->expects($this->exactly(2))->method('records')->willReturnCallback(
            fn (array $sites, array $periods, string $segment, string $record): array => [
                7 => ['2026-08-14,2026-08-14' => $record === 'Referrers_urlBySocialNetwork'
                    ? [$record => []]
                    : [
                        $record => [
                            $this->row('facebook.com', 2, 4, 8),
                            $this->row('news.example', 1, 2),
                        ],
                        $record.'_8' => [$this->row('https://facebook.com/a', 2, 4)],
                    ]],
            ],
        );
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url('getSocials').'&expanded=1')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.label', 'Facebook')
            ->assertJsonPath('0.nb_visits', 2)
            ->assertJsonPath('0.subtable.0.label', 'facebook.com/a');
    }

    public function test_flattens_social_and_url_dimensions(): void
    {
        $this->bindDependencies();
        $this->bindArchives([
            'Referrers_urlBySocialNetwork' => [$this->row('Facebook', 2, 4, 3)],
            'Referrers_urlBySocialNetwork_3' => [$this->row('https://facebook.com/a', 2, 4)],
        ]);

        $this->get($this->url('getSocials').'&flat=1&show_dimensions=1')
            ->assertOk()
            ->assertJsonPath('0.label', 'Facebook - https://facebook.com/a')
            ->assertJsonPath('0.Referrers_SocialNetwork', 'Facebook')
            ->assertJsonPath('0.Referrers_WebsitePage', 'https://facebook.com/a');
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
        $definitions->method('socialName')->willReturnCallback(
            static fn (string $url): ?string => str_contains($url, 'facebook.com') ? 'Facebook' : null,
        );
        $definitions->method('socialUrl')->willReturnCallback(
            static fn (string $name): string => strtolower($name).'.com',
        );
        $definitions->method('socialLogo')->willReturnCallback(
            static fn (string $name): string => 'icons/'.strtolower($name).'.png',
        );
        $definitions->method('socialNameAtPosition')->willReturn('Facebook');
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
