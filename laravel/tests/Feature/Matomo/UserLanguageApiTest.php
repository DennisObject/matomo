<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class UserLanguageApiTest extends TestCase
{
    public function test_groups_locale_rows_by_language_and_sums_metrics(): void
    {
        $this->bindViewAccess();
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $archives = $this->createMock(BlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('rows')
            ->with([7], $this->isType('array'), '', 'UserLanguage_language')
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [
                $this->row('fr-FR', 2, 4),
                $this->row('fr-CA', 1, 2),
            ]]]);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=UserLanguage.getLanguage&idSite=7'.
            '&period=day&date=2026-08-14&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([[
            'label' => 'French',
            'nb_visits' => 3,
            'nb_actions' => 6,
            'nb_visits_percent_of_total' => '100%',
            'nb_actions_percent_of_total' => '100%',
            'segment' => 'languageCode==fr,languageCode=@fr-',
        ]]);
    }

    public function test_keeps_full_language_code_and_adds_its_segment(): void
    {
        $this->bindViewAccess();
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $archives = $this->createStub(BlobArchiveRepository::class);
        $archives->method('rows')->willReturn([7 => ['2026-08-14,2026-08-14' => [
            $this->row('fr', 2, 4),
        ]]]);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=UserLanguage.getLanguageCode&idSite=7'.
            '&period=day&date=2026-08-14&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([[
            'label' => 'French (fr)',
            'nb_visits' => 2,
            'nb_actions' => 4,
            'nb_visits_percent_of_total' => '100%',
            'nb_actions_percent_of_total' => '100%',
            'segment' => 'languageCode==fr',
        ]]);
    }

    /** @return array{columns: array<string, int|string>, metadata: array<string, string>} */
    private function row(string $label, int $visits, int $actions): array
    {
        return [
            'columns' => ['label' => $label, 'nb_visits' => $visits, 'nb_actions' => $actions],
            'metadata' => [],
        ];
    }

    private function bindViewAccess(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), 7)
            ->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
