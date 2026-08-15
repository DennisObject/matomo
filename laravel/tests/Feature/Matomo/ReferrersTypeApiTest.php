<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Reporting\HierarchicalBlobArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class ReferrersTypeApiTest extends TestCase
{
    public function test_returns_labeled_referrer_types_with_segments_and_subtable_ids(): void
    {
        $this->bindDependencies([
            'Referrers_type' => [
                $this->row('1', 3, 3),
                $this->row('2', 2, 4),
            ],
        ]);

        $this->get($this->url('getReferrerType'))
            ->assertOk()
            ->assertJsonPath('0.label', 'Direct Entry')
            ->assertJsonPath('0.segment', 'referrerType==direct')
            ->assertJsonMissingPath('0.idsubdatatable')
            ->assertJsonPath('1.label', 'Search Engines')
            ->assertJsonPath('1.referrer_type', '2')
            ->assertJsonPath('1.idsubdatatable', 2);
    }

    public function test_filters_types_and_can_keep_numeric_labels(): void
    {
        $this->bindDependencies([
            'Referrers_type' => [
                $this->row('2', 2, 4),
                $this->row('3', 1, 2),
            ],
        ]);

        $this->get($this->url('getReferrerType').'&typeReferrer=3&_setReferrerTypeLabel=0')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.label', 3);
    }

    public function test_returns_selected_type_report_without_recursive_subtable_ids(): void
    {
        $this->bindDependencies([
            'Referrers_searchEngineByKeyword' => [
                $this->row('analytics', 2, 4, 11),
            ],
        ]);

        $this->get($this->url('getReferrerType').'&idSubtable=2')
            ->assertOk()
            ->assertJsonPath('0.label', 'analytics')
            ->assertJsonMissingPath('0.idsubdatatable');
    }

    public function test_expands_and_merges_referrer_subreports(): void
    {
        $this->bindDependencies([
            'Referrers_type' => [$this->row('2', 2, 4)],
            'Referrers_searchEngineByKeyword' => [$this->row('analytics', 2, 4)],
        ]);

        $this->get($this->url('getReferrerType').'&expanded=1')
            ->assertOk()
            ->assertJsonPath('0.subtable.0.label', 'analytics')
            ->assertJsonMissingPath('0.subtable.0.idsubdatatable');
        $this->get($this->url('getAll'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.label', 'analytics')
            ->assertJsonPath('0.referer_type', 2);
    }

    public function test_rejects_multiple_sites_before_archive_reads(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $archives = $this->createMock(HierarchicalBlobArchiveRepository::class);
        $archives->expects($this->never())->method('records');
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url('getReferrerType').'&idSite=7,8')->assertStatus(400);
    }

    /** @param array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>> $records */
    private function bindDependencies(array $records): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(SiteRepository::class, $sites);
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturnCallback(
            static fn (array $sites, array $periods, string $segment, string $record): array => [
                7 => ['2026-08-14,2026-08-14' => [$record => $records[$record] ?? []]],
            ],
        );
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
