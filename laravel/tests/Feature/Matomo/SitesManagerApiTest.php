<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Options\OptionRepository;
use App\Matomo\Sites\ConsentManagerDetector;
use App\Matomo\Sites\CurrencyProvider;
use App\Matomo\Sites\Events\SiteRemovalWarningsCollecting;
use App\Matomo\Sites\QueryParameterExclusionPolicy;
use App\Matomo\Sites\SiteDetailsPresenter;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Sites\SiteRuntimeSettings;
use App\Matomo\Sites\TimezoneProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SitesManagerApiTest extends TestCase
{
    public function test_consent_manager_detection_uses_the_stored_site_url(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), 7)
            ->willReturn(true);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())->method('mainUrl')->with(7)->willReturn('https://site.example/');
        $detector = $this->createMock(ConsentManagerDetector::class);
        $detector->expects($this->once())
            ->method('detect')
            ->with('https://site.example/', 60)
            ->willReturn([
                'name' => 'Cookiebot',
                'url' => 'https://matomo.org/faq/how-to/using-cookiebot-consent-manager-with-matomo',
                'isConnected' => true,
            ]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(ConsentManagerDetector::class, $detector);

        $this->get(
            '/index.php?module=API&method=SitesManager.detectConsentManager'.
            '&idSite=7&format=json&token_auth=view-token',
        )->assertOk()
            ->assertExactJson([
                'name' => 'Cookiebot',
                'url' => 'https://matomo.org/faq/how-to/using-cookiebot-consent-manager-with-matomo',
                'isConnected' => true,
            ]);
    }

    #[DataProvider('consentManagerTimeouts')]
    public function test_consent_manager_detection_clamps_the_timeout(string $value, int $expected): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasViewAccessToSite')->willReturn(true);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())->method('mainUrl')->with(7)->willReturn('https://site.example/');
        $detector = $this->createMock(ConsentManagerDetector::class);
        $detector->expects($this->once())
            ->method('detect')
            ->with('https://site.example/', $expected)
            ->willReturn(null);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(ConsentManagerDetector::class, $detector);

        $this->get(
            '/index.php?module=API&method=SitesManager.detectConsentManager'.
            "&idSite=7&timeOut={$value}&format=json&token_auth=view-token",
        )->assertOk()
            ->assertExactJson(['result' => 'success', 'message' => 'ok']);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function consentManagerTimeouts(): iterable
    {
        yield 'upper bound' => ['99999', 60];
        yield 'mid range' => ['30', 30];
        yield 'zero' => ['0', 1];
        yield 'negative' => ['-100', 1];
    }

    public function test_consent_manager_detection_returns_success_without_a_site_url(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasViewAccessToSite')->willReturn(true);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())->method('mainUrl')->with(7)->willReturn(null);
        $detector = $this->createMock(ConsentManagerDetector::class);
        $detector->expects($this->never())->method('detect');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(ConsentManagerDetector::class, $detector);

        $this->get(
            '/index.php?module=API&method=SitesManager.detectConsentManager'.
            '&idSite=7&format=json&token_auth=view-token',
        )->assertOk()
            ->assertExactJson(['result' => 'success', 'message' => 'ok']);
    }

    public function test_consent_manager_detection_checks_view_access_before_reading_the_site(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasViewAccessToSite')->willReturn(false);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('mainUrl');
        $detector = $this->createMock(ConsentManagerDetector::class);
        $detector->expects($this->never())->method('detect');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(ConsentManagerDetector::class, $detector);

        $this->get(
            '/index.php?module=API&method=SitesManager.detectConsentManager'.
            '&idSite=7&format=json&token_auth=invalid-token',
        )->assertUnauthorized()
            ->assertExactJson([
                'result' => 'error',
                'message' => "You can't access this resource as it requires 'view' access for the website id = 7.",
            ]);
    }

    public function test_consent_manager_detection_requires_a_site_id_before_authorization(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasViewAccessToSite');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get('/index.php?module=API&method=SitesManager.detectConsentManager&format=json')
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => "Please specify a value for 'idSite'.",
            ]);
    }

    public function test_consent_manager_detection_rejects_an_invalid_timeout_before_authorization(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasViewAccessToSite');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get(
            '/index.php?module=API&method=SitesManager.detectConsentManager'.
            '&idSite=7&timeOut=slow&format=json',
        )->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => 'The API parameter [timeOut] must be a scalar value.',
            ]);
    }

    public function test_pattern_match_sites_apply_view_access_exclusions_and_limit(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithAtLeastViewAccess')
            ->with($this->isInstanceOf(ApiAuthentication::class))
            ->willReturn([1, 2, 3]);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(false);
        $languages = $this->createMock(LanguageResolver::class);
        $languages->expects($this->once())->method('resolve')->willReturn('fr');
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())
            ->method('detailsForIds')
            ->with([1, 3], 'docs', 2)
            ->willReturn([
                ['idsite' => 1, 'name' => 'Docs'],
                ['idsite' => 3, 'name' => 'API Docs'],
            ]);
        $presenter = $this->createMock(SiteDetailsPresenter::class);
        $presenter->expects($this->exactly(2))
            ->method('present')
            ->willReturnCallback(function (array $site, string $language, bool $includeCreator): array {
                $this->assertSame('fr', $language);
                $this->assertFalse($includeCreator);

                return [...$site, 'currency_name' => 'euro'];
            });
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LanguageResolver::class, $languages);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(SiteDetailsPresenter::class, $presenter);

        $this->get(
            '/index.php?module=API&method=SitesManager.getPatternMatchSites'.
            '&pattern=docs&limit=2&sitesToExclude=2&format=json&token_auth=view-token',
        )->assertOk()
            ->assertExactJson([
                ['idsite' => 1, 'name' => 'Docs', 'currency_name' => 'euro'],
                ['idsite' => 3, 'name' => 'API Docs', 'currency_name' => 'euro'],
            ]);
    }

    public function test_pattern_match_sites_return_empty_without_view_access(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithAtLeastViewAccess')
            ->willReturn([]);
        $authorizer->expects($this->never())->method('hasSuperUserAccess');
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('detailsForIds');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get(
            '/index.php?module=API&method=SitesManager.getPatternMatchSites'.
            '&pattern=docs&format=json&token_auth=invalid-token',
        )->assertOk()
            ->assertExactJson([]);
    }

    public function test_pattern_match_sites_require_pattern_before_authorization(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('siteIdsWithAtLeastViewAccess');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get('/index.php?module=API&method=SitesManager.getPatternMatchSites&format=json')
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => "Please specify a value for 'pattern'.",
            ]);
    }

    public function test_site_removal_warnings_collect_plugin_messages_for_superusers(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $events = $this->app->make(Dispatcher::class);
        $events->listen(
            SiteRemovalWarningsCollecting::class,
            function (SiteRemovalWarningsCollecting $event): void {
                $this->assertSame(7, $event->idSite);
                $event->messages[] = '<strong>Remove related data first.</strong>';
                $event->messages[] = 'This cannot be undone.';
            },
        );

        $this->get(
            '/index.php?module=API&method=SitesManager.getMessagesToWarnOnSiteRemoval'.
            '&idSite=7&format=json&token_auth=root-token',
        )->assertOk()
            ->assertExactJson([
                '<strong>Remove related data first.</strong>',
                'This cannot be undone.',
            ]);
    }

    public function test_site_removal_warnings_are_empty_without_plugin_listeners(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get(
            '/index.php?module=API&method=SitesManager.getMessagesToWarnOnSiteRemoval'.
            '&idSite=7&format=json&token_auth=root-token',
        )->assertOk()
            ->assertExactJson([]);
    }

    public function test_site_removal_warnings_require_superuser_before_dispatching_event(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(false);
        $events = $this->createMock(Dispatcher::class);
        $events->expects($this->never())->method('dispatch');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(Dispatcher::class, $events);

        $this->get(
            '/index.php?module=API&method=SitesManager.getMessagesToWarnOnSiteRemoval'.
            '&idSite=7&format=json&token_auth=admin-token',
        )->assertUnauthorized()
            ->assertExactJson([
                'result' => 'error',
                'message' => "You can't access this resource as it requires a 'superuser' access.",
            ]);
    }

    public function test_site_removal_warnings_require_a_site_id_before_authorization(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSuperUserAccess');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get(
            '/index.php?module=API&method=SitesManager.getMessagesToWarnOnSiteRemoval&format=json',
        )->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => "Please specify a value for 'idSite'.",
            ]);
    }

    public function test_at_least_view_sites_apply_restricted_login_and_limit(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithAtLeastViewAccess')
            ->with($this->isInstanceOf(ApiAuthentication::class), 'alice')
            ->willReturn([1, 2, 3]);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(true);
        $languages = $this->createMock(LanguageResolver::class);
        $languages->expects($this->once())->method('resolve')->willReturn('fr');
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())
            ->method('detailsForIds')
            ->with([1, 2, 3], null, 2)
            ->willReturn([
                ['idsite' => 1, 'name' => 'First site'],
                ['idsite' => 2, 'name' => 'Second site'],
            ]);
        $presenter = $this->createMock(SiteDetailsPresenter::class);
        $presenter->expects($this->exactly(2))
            ->method('present')
            ->willReturnCallback(function (array $site, string $language, bool $includeCreator): array {
                $this->assertSame('fr', $language);
                $this->assertTrue($includeCreator);

                return [...$site, 'creator_login' => 'root'];
            });
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LanguageResolver::class, $languages);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(SiteDetailsPresenter::class, $presenter);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSitesWithAtLeastViewAccess'.
            '&_restrictSitesToLogin=alice&limit=2&format=json&token_auth=root-token',
        )->assertOk()
            ->assertExactJson([
                ['idsite' => 1, 'name' => 'First site', 'creator_login' => 'root'],
                ['idsite' => 2, 'name' => 'Second site', 'creator_login' => 'root'],
            ]);
    }

    public function test_at_least_view_sites_return_empty_without_access(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithAtLeastViewAccess')
            ->with($this->isInstanceOf(ApiAuthentication::class), null)
            ->willReturn([]);
        $authorizer->expects($this->never())->method('hasSuperUserAccess');
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('detailsForIds');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSitesWithAtLeastViewAccess'.
            '&format=json&token_auth=invalid-token',
        )->assertOk()
            ->assertExactJson([]);
    }

    public function test_view_sites_return_only_exact_view_access_with_legacy_presentation(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithRole')
            ->with($this->isInstanceOf(ApiAuthentication::class), SiteAccessRole::View)
            ->willReturn([1, 3]);
        $languages = $this->createMock(LanguageResolver::class);
        $languages->expects($this->once())->method('resolve')->willReturn('fr');
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())
            ->method('detailsForIds')
            ->with([1, 3])
            ->willReturn([
                ['idsite' => 1, 'name' => 'First site'],
                ['idsite' => 3, 'name' => 'Third site'],
            ]);
        $presenter = $this->createMock(SiteDetailsPresenter::class);
        $presenter->expects($this->exactly(2))
            ->method('present')
            ->willReturnCallback(function (array $site, string $language, bool $includeCreator): array {
                $this->assertSame('fr', $language);
                $this->assertFalse($includeCreator);

                return [...$site, 'currency_name' => 'euro'];
            });
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LanguageResolver::class, $languages);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(SiteDetailsPresenter::class, $presenter);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSitesWithViewAccess'.
            '&format=json&token_auth=view-token',
        )->assertOk()
            ->assertExactJson([
                ['idsite' => 1, 'name' => 'First site', 'currency_name' => 'euro'],
                ['idsite' => 3, 'name' => 'Third site', 'currency_name' => 'euro'],
            ]);
    }

    public function test_view_sites_return_empty_for_superuser_exact_role_access(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithRole')
            ->with($this->isInstanceOf(ApiAuthentication::class), SiteAccessRole::View)
            ->willReturn([]);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('detailsForIds');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSitesWithViewAccess'.
            '&format=json&token_auth=root-token',
        )->assertOk()
            ->assertExactJson([]);
    }

    public function test_minimum_access_sites_apply_role_and_site_filters(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithMinimumRole')
            ->with($this->isInstanceOf(ApiAuthentication::class), SiteAccessRole::Write)
            ->willReturn([2, 4, 5]);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(false);
        $languages = $this->createMock(LanguageResolver::class);
        $languages->expects($this->once())->method('resolve')->willReturn('fr');
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())
            ->method('detailsForIds')
            ->with([2, 5], 'site', 2, ['intranet'])
            ->willReturn([
                ['idsite' => 2, 'name' => 'Second site'],
                ['idsite' => 5, 'name' => 'Fifth site'],
            ]);
        $presenter = $this->createMock(SiteDetailsPresenter::class);
        $presenter->expects($this->exactly(2))
            ->method('present')
            ->willReturnCallback(function (array $site, string $language, bool $includeCreator): array {
                $this->assertSame('fr', $language);
                $this->assertFalse($includeCreator);

                return [...$site, 'currency_name' => 'euro'];
            });
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LanguageResolver::class, $languages);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(SiteDetailsPresenter::class, $presenter);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSitesWithMinimumAccess'.
            '&permission=WRITE&pattern=site&limit=2&sitesToExclude=4'.
            '&siteTypesToExclude%5B0%5D=intranet&format=json&token_auth=write-token',
        )->assertOk()
            ->assertExactJson([
                ['idsite' => 2, 'name' => 'Second site', 'currency_name' => 'euro'],
                ['idsite' => 5, 'name' => 'Fifth site', 'currency_name' => 'euro'],
            ]);
    }

    public function test_minimum_access_sites_return_empty_before_reading_sites(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithMinimumRole')
            ->with($this->isInstanceOf(ApiAuthentication::class), SiteAccessRole::View)
            ->willReturn([]);
        $authorizer->expects($this->never())->method('hasSuperUserAccess');
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('detailsForIds');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSitesWithMinimumAccess'.
            '&permission=view&format=json&token_auth=view-token',
        )->assertOk()
            ->assertExactJson([]);
    }

    public function test_minimum_access_sites_treat_zero_pattern_as_no_filter(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithMinimumRole')
            ->with($this->isInstanceOf(ApiAuthentication::class), SiteAccessRole::View)
            ->willReturn([1]);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(false);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())
            ->method('detailsForIds')
            ->with([1], null, null, [])
            ->willReturn([['idsite' => 1, 'name' => 'First site']]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSitesWithMinimumAccess'.
            '&permission=view&pattern=0&format=json&token_auth=view-token',
        )->assertOk()
            ->assertExactJson([['idsite' => 1, 'name' => 'First site']]);
    }

    #[DataProvider('invalidMinimumAccessParameters')]
    public function test_minimum_access_sites_reject_invalid_parameters(
        string $parameters,
        string $message,
    ): void {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('siteIdsWithMinimumRole');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSitesWithMinimumAccess'.
            "&{$parameters}&format=json",
        )->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => $message,
            ]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidMinimumAccessParameters(): iterable
    {
        yield 'missing permission' => ['', "Please specify a value for 'permission'."];
        yield 'invalid permission' => ['permission=owner', 'Invalid permission provided'];
        yield 'nested site type' => [
            'permission=view&siteTypesToExclude%5B0%5D%5Btype%5D=intranet',
            'The API parameter [siteTypesToExclude] must be a scalar value.',
        ];
    }

    public function test_admin_sites_apply_filters_and_fetch_alias_urls_in_bulk(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithRole')
            ->with($this->isInstanceOf(ApiAuthentication::class), SiteAccessRole::Admin)
            ->willReturn([1, 2, 3]);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(false);
        $languages = $this->createMock(LanguageResolver::class);
        $languages->expects($this->once())->method('resolve')->willReturn('fr');
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())
            ->method('detailsForIds')
            ->with([1, 3], 'docs', 2)
            ->willReturn([
                ['idsite' => 1, 'name' => 'Docs', 'main_url' => 'https://docs.test'],
                ['idsite' => 3, 'name' => 'API Docs', 'main_url' => 'https://api.test'],
            ]);
        $sites->expects($this->once())
            ->method('aliasUrlsForIds')
            ->with([1, 3])
            ->willReturn([
                1 => ['https://www.docs.test'],
                3 => ['https://www.api.test'],
            ]);
        $presenter = $this->createMock(SiteDetailsPresenter::class);
        $presenter->expects($this->exactly(2))
            ->method('present')
            ->willReturnCallback(function (array $site, string $language, bool $includeCreator): array {
                $this->assertSame('fr', $language);
                $this->assertFalse($includeCreator);

                return [...$site, 'currency_name' => 'euro'];
            });
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LanguageResolver::class, $languages);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(SiteDetailsPresenter::class, $presenter);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSitesWithAdminAccess'.
            '&fetchAliasUrls=1&pattern=docs&limit=2&sitesToExclude=2'.
            '&format=json&token_auth=admin-token',
        )->assertOk()
            ->assertExactJson([
                [
                    'idsite' => 1,
                    'name' => 'Docs',
                    'main_url' => 'https://docs.test',
                    'currency_name' => 'euro',
                    'alias_urls' => ['https://docs.test', 'https://www.docs.test'],
                ],
                [
                    'idsite' => 3,
                    'name' => 'API Docs',
                    'main_url' => 'https://api.test',
                    'currency_name' => 'euro',
                    'alias_urls' => ['https://api.test', 'https://www.api.test'],
                ],
            ]);
    }

    public function test_admin_sites_return_an_empty_list_without_admin_access(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithRole')
            ->with($this->isInstanceOf(ApiAuthentication::class), SiteAccessRole::Admin)
            ->willReturn([]);
        $authorizer->expects($this->never())->method('hasSuperUserAccess');
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('detailsForIds');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSitesWithAdminAccess'.
            '&format=json&token_auth=view-token',
        )->assertOk()
            ->assertExactJson([]);
    }

    public function test_admin_sites_reject_invalid_site_exclusions_before_authorization(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('siteIdsWithRole');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSitesWithAdminAccess'.
            '&sitesToExclude%5B0%5D=invalid&format=json',
        )->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => 'The API parameter [sitesToExclude] must be a scalar value.',
            ]);
    }

    public function test_sites_from_group_trim_the_group_and_require_superuser(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(true);
        $languages = $this->createMock(LanguageResolver::class);
        $languages->expects($this->once())->method('resolve')->willReturn('fr');
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())
            ->method('detailsInGroup')
            ->with('Main')
            ->willReturn([['idsite' => 7, 'name' => 'Docs']]);
        $presenter = $this->createMock(SiteDetailsPresenter::class);
        $presenter->expects($this->once())
            ->method('present')
            ->with(['idsite' => 7, 'name' => 'Docs'], 'fr', true)
            ->willReturn(['idsite' => 7, 'name' => 'Docs', 'currency_name' => 'euro']);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LanguageResolver::class, $languages);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(SiteDetailsPresenter::class, $presenter);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSitesFromGroup'.
            '&group=%20Main%20&format=json&token_auth=root-token',
        )->assertOk()
            ->assertExactJson([
                ['idsite' => 7, 'name' => 'Docs', 'currency_name' => 'euro'],
            ]);
    }

    public function test_all_sites_require_superuser_and_keep_site_ids_as_keys(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(true);
        $languages = $this->createMock(LanguageResolver::class);
        $languages->expects($this->once())->method('resolve')->willReturn('fr');
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())->method('allDetails')->willReturn([
            1 => ['idsite' => 1, 'name' => 'One'],
            3 => ['idsite' => 3, 'name' => 'Three'],
        ]);
        $presenter = $this->createMock(SiteDetailsPresenter::class);
        $presenter->expects($this->exactly(2))
            ->method('present')
            ->willReturnCallback(
                static fn (array $site, string $language, bool $includeCreator): array => [
                    ...$site,
                    'language' => $language,
                    'include_creator' => (int) $includeCreator,
                ],
            );
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LanguageResolver::class, $languages);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(SiteDetailsPresenter::class, $presenter);

        $this->get(
            '/index.php?module=API&method=SitesManager.getAllSites'.
            '&format=json&token_auth=root-token',
        )->assertOk()
            ->assertContent(
                '{"1":{"idsite":1,"name":"One","language":"fr","include_creator":1},'.
                '"3":{"idsite":3,"name":"Three","language":"fr","include_creator":1}}',
            );
    }

    public function test_site_details_require_view_access_and_hide_regular_user_creator(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), 7)
            ->willReturn(true);
        $authorizer->expects($this->once())
            ->method('hasSuperUserAccess')
            ->willReturn(false);
        $languages = $this->createMock(LanguageResolver::class);
        $languages->expects($this->once())->method('resolve')->willReturn('fr');
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())
            ->method('details')
            ->with(7)
            ->willReturn([
                'idsite' => 7,
                'name' => 'Docs',
                'timezone' => 'Europe/Paris',
                'currency' => 'EUR',
                'creator_login' => 'owner',
            ]);
        $presenter = $this->createMock(SiteDetailsPresenter::class);
        $presenter->expects($this->once())
            ->method('present')
            ->with($this->isType('array'), 'fr', false)
            ->willReturn([
                'idsite' => 7,
                'name' => 'Docs',
                'timezone' => 'Europe/Paris',
                'currency' => 'EUR',
                'timezone_name' => 'France',
                'currency_name' => 'euro',
            ]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LanguageResolver::class, $languages);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(SiteDetailsPresenter::class, $presenter);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSiteFromId'.
            '&idSite=7&format=json&token_auth=view-token',
        )->assertOk()
            ->assertExactJson([
                'idsite' => 7,
                'name' => 'Docs',
                'timezone' => 'Europe/Paris',
                'currency' => 'EUR',
                'timezone_name' => 'France',
                'currency_name' => 'euro',
            ]);
    }

    public function test_timezone_list_is_public_and_uses_runtime_support(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $languages = $this->createMock(LanguageResolver::class);
        $languages->expects($this->once())->method('resolve')->willReturn('fr');
        $runtime = $this->createMock(SiteRuntimeSettings::class);
        $runtime->expects($this->once())->method('timezoneSupportEnabled')->willReturn(false);
        $timezones = $this->createMock(TimezoneProvider::class);
        $timezones->expects($this->once())
            ->method('all')
            ->with('fr', false)
            ->willReturn(['UTC' => ['UTC' => 'UTC']]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LanguageResolver::class, $languages);
        $this->app->instance(SiteRuntimeSettings::class, $runtime);
        $this->app->instance(TimezoneProvider::class, $timezones);

        $this->get('/index.php?module=API&method=SitesManager.getTimezonesList&format=json')
            ->assertOk()
            ->assertExactJson(['UTC' => ['UTC' => 'UTC']]);
    }

    public function test_timezone_name_is_public_and_keeps_optional_context(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $languages = $this->createMock(LanguageResolver::class);
        $languages->expects($this->once())->method('resolve')->willReturn('fr');
        $timezones = $this->createMock(TimezoneProvider::class);
        $timezones->expects($this->once())
            ->method('name')
            ->with('America/New_York', 'fr', 'US', true)
            ->willReturn('États-Unis - New York');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LanguageResolver::class, $languages);
        $this->app->instance(TimezoneProvider::class, $timezones);

        $this->get(
            '/index.php?module=API&method=SitesManager.getTimezoneName'.
            '&timezone=America%2FNew_York&countryCode=US'.
            '&multipleTimezonesInCountry=1&format=json',
        )->assertOk()
            ->assertContent('{"value":"\\u00c9tats-Unis - New York"}');
    }

    public function test_timezone_name_requires_a_timezone(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $timezones = $this->createMock(TimezoneProvider::class);
        $timezones->expects($this->never())->method('name');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(TimezoneProvider::class, $timezones);

        $this->get('/index.php?module=API&method=SitesManager.getTimezoneName&format=json')
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => "Please specify a value for 'timezone'.",
            ]);
    }

    public function test_currency_list_is_public_and_uses_the_resolved_language(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $languages = $this->createMock(LanguageResolver::class);
        $languages->expects($this->once())
            ->method('resolve')
            ->with(
                $this->isInstanceOf(Request::class),
                $this->isInstanceOf(ApiAuthentication::class),
            )
            ->willReturn('fr');
        $currencies = $this->createMock(CurrencyProvider::class);
        $currencies->expects($this->once())
            ->method('names')
            ->with('fr')
            ->willReturn([
                'EUR' => 'Euro (€)',
                'USD' => 'Dollar américain ($)',
            ]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(LanguageResolver::class, $languages);
        $this->app->instance(CurrencyProvider::class, $currencies);

        $this->get('/index.php?module=API&method=SitesManager.getCurrencyList&format=json')
            ->assertOk()
            ->assertContent('{"EUR":"Euro (\\u20ac)","USD":"Dollar am\\u00e9ricain ($)"}');
    }

    public function test_excluded_query_parameters_merge_site_and_policy_values(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), 7)
            ->willReturn(true);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())
            ->method('excludedParameters')
            ->with(7)
            ->willReturn('session,email');
        $policy = $this->createMock(QueryParameterExclusionPolicy::class);
        $policy->expects($this->once())
            ->method('parameters')
            ->with(7)
            ->willReturn('email,password');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(QueryParameterExclusionPolicy::class, $policy);

        $this->get(
            '/index.php?module=API&method=SitesManager.getExcludedQueryParameters'.
            '&idSite=7&format=json&token_auth=view-token',
        )->assertOk()
            ->assertExactJson(['session', 'email', 'password']);
    }

    public function test_query_parameter_exclusion_type_keeps_optional_site_scope(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSomeViewAccess')->willReturn(true);
        $policy = $this->createMock(QueryParameterExclusionPolicy::class);
        $policy->expects($this->once())
            ->method('type')
            ->with(7)
            ->willReturn('matomo_recommended_pii');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(QueryParameterExclusionPolicy::class, $policy);

        $this->get(
            '/index.php?module=API&method=SitesManager.getExclusionTypeForQueryParams'.
            '&idSite=7&format=json&token_auth=view-token',
        )->assertOk()
            ->assertContent('{"value":"matomo_recommended_pii"}');
    }

    public function test_global_query_parameter_exclusions_require_view_access(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSomeViewAccess')->willReturn(false);
        $policy = $this->createMock(QueryParameterExclusionPolicy::class);
        $policy->expects($this->never())->method('parameters');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(QueryParameterExclusionPolicy::class, $policy);

        $this->get(
            '/index.php?module=API&method=SitesManager.getExcludedQueryParametersGlobal'.
            '&format=json&token_auth=other-token',
        )->assertUnauthorized();
    }

    public function test_currency_symbols_are_public_and_keep_custom_codes(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $currencies = $this->createMock(CurrencyProvider::class);
        $currencies->expects($this->once())
            ->method('symbols')
            ->willReturn(['USD' => '$', 'BTC' => 'BTC']);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(CurrencyProvider::class, $currencies);

        $this->get('/index.php?module=API&method=SitesManager.getCurrencySymbols&format=json')
            ->assertOk()
            ->assertExactJson(['USD' => '$', 'BTC' => 'BTC']);
    }

    public function test_excluded_referrers_merge_global_and_site_values(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), 7)
            ->willReturn(true);
        $options = $this->createMock(OptionRepository::class);
        $options->expects($this->once())
            ->method('value')
            ->with('SitesManager_ExcludedReferrersGlobal')
            ->willReturn('global.test,shared.test');
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())
            ->method('excludedReferrers')
            ->with(7)
            ->willReturn('site.test,shared.test');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(OptionRepository::class, $options);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get(
            '/index.php?module=API&method=SitesManager.getExcludedReferrers'.
            '&idSite=7&format=json&token_auth=view-token',
        )->assertOk()
            ->assertExactJson(['global.test', 'shared.test', 'site.test']);
    }

    public function test_excluded_referrers_do_not_read_settings_without_view_access(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasViewAccessToSite')->willReturn(false);
        $options = $this->createMock(OptionRepository::class);
        $options->expects($this->never())->method('value');
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('excludedReferrers');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(OptionRepository::class, $options);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get(
            '/index.php?module=API&method=SitesManager.getExcludedReferrers'.
            '&idSite=7&format=json&token_auth=other-token',
        )->assertUnauthorized();
    }

    public function test_site_id_from_url_normalizes_urls_and_limits_access(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithAtLeastViewAccess')
            ->willReturn([2, 3]);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())
            ->method('idsForUrls')
            ->with(
                [
                    'https://www.example.test',
                    'http://example.test',
                    'http://www.example.test',
                    'https://example.test',
                    'https://www.example.test',
                ],
                [2, 3],
            )
            ->willReturn([['idsite' => '3']]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSitesIdFromSiteUrl'.
            '&url=https%3A%2F%2Fwww.example.test%2F&format=json&token_auth=view-token',
        )->assertOk()
            ->assertExactJson([['idsite' => '3']]);
    }

    public function test_site_id_from_url_rejects_a_missing_url(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('siteIdsWithAtLeastViewAccess');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get('/index.php?module=API&method=SitesManager.getSitesIdFromSiteUrl&format=json')
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => "Please specify a value for 'url'.",
            ]);
    }

    public function test_ip_range_returns_bounds_without_authentication(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $authorizer->expects($this->never())->method('hasSuperUserAccess');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get(
            '/index.php?module=API&method=SitesManager.getIpsForRange'.
            '&ipRange=192.168.1.0%2F24&format=json',
        )->assertOk()
            ->assertExactJson(['192.168.1.0', '192.168.1.255']);
    }

    public function test_invalid_ip_range_keeps_the_false_scalar_response(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get(
            '/index.php?module=API&method=SitesManager.getIpsForRange&ipRange=invalid&format=json',
        )->assertOk()
            ->assertContent('{"value":false}');
    }

    public function test_ip_range_rejects_a_missing_parameter(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get('/index.php?module=API&method=SitesManager.getIpsForRange&format=json')
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => "Please specify a value for 'ipRange'.",
            ]);
    }

    public function test_site_ids_from_timezones_parse_lists_and_require_superuser(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(true);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())
            ->method('idsInTimezones')
            ->with(['UTC+10', 'Pacific/Auckland'])
            ->willReturn([2, 3, 4]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSitesIdFromTimezones'.
            '&timezones=UTC%2B10,Pacific%2FAuckland,UTC%2B10&format=json&token_auth=root-token',
        )->assertOk()
            ->assertExactJson([2, 3, 4]);
    }

    public function test_site_ids_from_timezones_reject_a_missing_list(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSuperUserAccess');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get('/index.php?module=API&method=SitesManager.getSitesIdFromTimezones&format=json')
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => "Please specify a value for 'timezones'.",
            ]);
    }

    public function test_unique_site_timezones_require_superuser_access(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(true);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())->method('timezones')->willReturn(['UTC', 'Europe/Paris']);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get(
            '/index.php?module=API&method=SitesManager.getUniqueSiteTimezones'.
            '&format=json&token_auth=root-token',
        )->assertOk()
            ->assertExactJson(['UTC', 'Europe/Paris']);
    }

    public function test_site_urls_require_site_view_access_and_keep_main_url_first(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), 7)
            ->willReturn(true);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())
            ->method('urls')
            ->with(7)
            ->willReturn(['https://example.test', 'https://www.example.test']);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSiteUrlsFromId'.
            '&idSite=7&format=json&token_auth=view-token',
        )->assertOk()
            ->assertExactJson(['https://example.test', 'https://www.example.test']);
    }

    public function test_site_urls_reject_missing_site_id_before_authentication(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasViewAccessToSite');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get('/index.php?module=API&method=SitesManager.getSiteUrlsFromId&format=json')
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => "Please specify a value for 'idSite'.",
            ]);
    }

    public function test_site_urls_reject_missing_view_access_before_the_site_store(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasViewAccessToSite')->willReturn(false);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('urls');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSiteUrlsFromId'.
            '&idSite=7&format=json&token_auth=other-token',
        )->assertUnauthorized();
    }

    public function test_runtime_site_settings_keep_view_access_and_scalar_types(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->exactly(2))->method('hasSomeViewAccess')->willReturn(true);
        $runtime = $this->createMock(SiteRuntimeSettings::class);
        $runtime->expects($this->once())->method('timezoneSupportEnabled')->willReturn(false);
        $runtime->expects($this->once())->method('websitesCountToDisplay')->willReturn(42);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRuntimeSettings::class, $runtime);

        $this->get(
            '/index.php?module=API&method=SitesManager.isTimezoneSupportEnabled'.
            '&format=json&token_auth=view-token',
        )->assertOk()
            ->assertContent('{"value":false}');

        $this->get(
            '/index.php?module=API&method=SitesManager.getNumWebsitesToDisplayPerPage'.
            '&format=json&token_auth=view-token',
        )->assertOk()
            ->assertContent('{"value":42}');
    }

    public function test_default_timezone_is_public_and_uses_the_legacy_fallback(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasSomeViewAccess');
        $authorizer->expects($this->never())->method('hasSomeAdminAccess');
        $options = $this->createMock(OptionRepository::class);
        $options->expects($this->once())
            ->method('value')
            ->with('SitesManager_DefaultTimezone')
            ->willReturn(null);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(OptionRepository::class, $options);

        $this->get('/index.php?module=API&method=SitesManager.getDefaultTimezone&format=json')
            ->assertOk()
            ->assertContent('{"value":"UTC"}');
    }

    public function test_default_currency_requires_some_admin_access(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSomeAdminAccess')->willReturn(true);
        $options = $this->createMock(OptionRepository::class);
        $options->expects($this->once())
            ->method('value')
            ->with('SitesManager_DefaultCurrency')
            ->willReturn('EUR');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(OptionRepository::class, $options);

        $this->get(
            '/index.php?module=API&method=SitesManager.getDefaultCurrency&format=json&token_auth=admin-token',
        )->assertOk()
            ->assertContent('{"value":"EUR"}');
    }

    public function test_default_currency_rejects_view_access_before_reading_the_option(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSomeAdminAccess')->willReturn(false);
        $options = $this->createMock(OptionRepository::class);
        $options->expects($this->never())->method('value');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(OptionRepository::class, $options);

        $this->get(
            '/index.php?module=API&method=SitesManager.getDefaultCurrency&format=json&token_auth=view-token',
        )->assertUnauthorized()
            ->assertExactJson([
                'result' => 'error',
                'message' => "You can't access this resource as it requires admin access for at least one website.",
            ]);
    }

    #[DataProvider('globalSiteOptions')]
    public function test_global_site_option_reads_keep_access_and_fallbacks(
        string $method,
        string $optionName,
        ?string $storedValue,
        string $content,
    ): void {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSomeAdminAccess')->willReturn(true);
        $options = $this->createMock(OptionRepository::class);
        $options->expects($this->once())->method('value')->with($optionName)->willReturn($storedValue);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(OptionRepository::class, $options);

        $this->get("/index.php?module=API&method={$method}&format=json&token_auth=admin-token")
            ->assertOk()
            ->assertContent($content);
    }

    /**
     * @return iterable<string, array{string, string, string|null, string}>
     */
    public static function globalSiteOptions(): iterable
    {
        yield 'search keywords use the built-in default' => [
            'SitesManager.getSearchKeywordParametersGlobal',
            'SitesManager_SearchKeywordParameters',
            null,
            '{"value":"q,query,s,search,searchword,k,keyword,keywords"}',
        ];
        yield 'search categories preserve a missing option' => [
            'SitesManager.getSearchCategoryParametersGlobal',
            'SitesManager_SearchCategoryParameters',
            null,
            '{"value":false}',
        ];
        yield 'excluded user agents preserve their value' => [
            'SitesManager.getExcludedUserAgentsGlobal',
            'SitesManager_ExcludedUserAgentsGlobal',
            'bot,crawler',
            '{"value":"bot,crawler"}',
        ];
        yield 'excluded referrers use an empty fallback' => [
            'SitesManager.getExcludedReferrersGlobal',
            'SitesManager_ExcludedReferrersGlobal',
            null,
            '{"value":""}',
        ];
        yield 'excluded IPs preserve a missing option' => [
            'SitesManager.getExcludedIpsGlobal',
            'SitesManager_ExcludedIpsGlobal',
            null,
            '{"value":false}',
        ];
    }

    public function test_keep_url_fragments_uses_view_access_and_boolean_output(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSomeViewAccess')->willReturn(true);
        $options = $this->createMock(OptionRepository::class);
        $options->expects($this->once())
            ->method('value')
            ->with('SitesManager_KeepURLFragmentsGlobal')
            ->willReturn('1');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(OptionRepository::class, $options);

        $this->get(
            '/index.php?module=API&method=SitesManager.getKeepURLFragmentsGlobal'.
            '&format=json&token_auth=view-token',
        )->assertOk()
            ->assertContent('{"value":true}');
    }

    public function test_global_option_csv_quotes_lists_and_blocks_formulas(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSomeAdminAccess')->willReturn(true);
        $options = $this->createMock(OptionRepository::class);
        $options->expects($this->once())
            ->method('value')
            ->with('SitesManager_SearchKeywordParameters')
            ->willReturn('=SUM(A1:A2),q');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(OptionRepository::class, $options);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSearchKeywordParametersGlobal'.
            '&format=csv&convertToUnicode=0&token_auth=admin-token',
        )->assertOk()
            ->assertContent("value\n\"'=SUM(A1:A2),q\"");
    }

    #[DataProvider('siteGroupFormats')]
    public function test_site_groups_require_superuser_and_keep_string_list_formats(
        string $format,
        string $contentType,
        string $content,
    ): void {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(true);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())->method('groups')->willReturn(['a,b', 'a"b']);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSitesGroups'.
            '&convertToUnicode=0&token_auth=root-token&format='.$format,
        )->assertOk()
            ->assertHeader('Content-Type', $contentType)
            ->assertContent($content);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function siteGroupFormats(): iterable
    {
        yield 'JSON' => ['json', 'application/json; charset=utf-8', '["a,b","a\\"b"]'];
        yield 'XML' => [
            'xml',
            'text/xml; charset=utf-8',
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>\n".
                "\t<row>a,b</row>\n\t<row>a&quot;b</row>\n</result>",
        ];
        yield 'CSV' => ['csv', 'application/vnd.ms-excel', "\"a,b\"\n\"a\"\"b\""];
        yield 'TSV' => ['tsv', 'application/vnd.ms-excel', "\"a,b\"\n\"a\"\"b\""];
        yield 'console' => [
            'console',
            'text/plain; charset=utf-8',
            "- 1 ['0' => 'a,b'] [] [idsubtable = ]<br />\n".
                "- 2 ['0' => 'a\"b'] [] [idsubtable = ]<br />\n",
        ];
    }

    public function test_site_groups_reject_a_non_superuser_before_the_site_store(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(false);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('groups');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get('/index.php?module=API&method=SitesManager.getSitesGroups&format=json&token_auth=view-token')
            ->assertUnauthorized();
    }

    public function test_at_least_view_site_ids_pass_the_safe_login_restriction(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithAtLeastViewAccess')
            ->with(
                $this->callback(
                    static fn (ApiAuthentication $authentication): bool => $authentication->token === 'token',
                ),
                'alice',
            )
            ->willReturn([1, 2]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSitesIdWithAtLeastViewAccess'.
            '&format=json&token_auth=token&_restrictSitesToLogin=alice',
        )->assertOk()
            ->assertExactJson([1, 2]);
    }

    #[DataProvider('siteRoleMethods')]
    public function test_site_role_id_methods_use_the_exact_role(
        string $method,
        SiteAccessRole $role,
    ): void {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithRole')
            ->with(
                $this->isInstanceOf(ApiAuthentication::class),
                $role,
            )
            ->willReturn([4]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get("/index.php?module=API&method={$method}&format=json&token_auth=token")
            ->assertOk()
            ->assertExactJson([4]);
    }

    /**
     * @return iterable<string, array{string, SiteAccessRole}>
     */
    public static function siteRoleMethods(): iterable
    {
        yield 'view' => ['SitesManager.getSitesIdWithViewAccess', SiteAccessRole::View];
        yield 'write' => ['SitesManager.getSitesIdWithWriteAccess', SiteAccessRole::Write];
    }

    public function test_all_site_ids_requires_superuser_and_reads_the_site_store(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasSuperUserAccess')
            ->with($this->callback(
                static fn (ApiAuthentication $authentication): bool => $authentication->token === 'root-token',
            ))
            ->willReturn(true);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())->method('allIds')->willReturn([3, 8]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get('/index.php?module=API&method=SitesManager.getAllSitesId&format=json&token_auth=root-token')
            ->assertOk()
            ->assertExactJson([3, 8]);
    }

    public function test_all_site_ids_rejects_a_non_superuser_before_the_site_store(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(false);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->never())->method('allIds');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);

        $this->get('/index.php?module=API&method=SitesManager.getAllSitesId&format=json&token_auth=view-token')
            ->assertUnauthorized()
            ->assertExactJson([
                'result' => 'error',
                'message' => "You can't access this resource as it requires a 'superuser' access.",
            ]);
    }

    #[DataProvider('siteIdFormats')]
    public function test_admin_site_ids_keep_exact_legacy_formats(
        string $parameters,
        string $contentType,
        string $content,
        ?string $contentDisposition,
    ): void {
        $this->bindAdminSites([1, 2]);

        $response = $this->get(
            '/index.php?module=API&method=SitesManager.getSitesIdWithAdminAccess'.
            '&token_auth=token&'.$parameters,
        )->assertOk()
            ->assertHeader('Content-Type', $contentType)
            ->assertContent($content);

        if ($contentDisposition !== null) {
            $response->assertHeader('Content-Disposition', $contentDisposition);
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string|null}>
     */
    public static function siteIdFormats(): iterable
    {
        $spreadsheetDisposition = "attachment; filename*=UTF-8''Export";
        $xml = "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result>\n".
            "\t<row>1</row>\n\t<row>2</row>\n</result>";
        $html = <<<HTML
        <table border="1">
        <thead>
        \t<tr>
        \t\t<th>value</th>
        \t</tr>
        </thead>
        <tbody>
        \t<tr>
        \t\t<td>1</td>
        \t</tr>
        \t<tr>
        \t\t<td>2</td>
        \t</tr>
        </tbody>
        </table>

        HTML;
        $console = "- 1 ['0' => 1] [] [idsubtable = ]<br />\n".
            "- 2 ['0' => 2] [] [idsubtable = ]<br />\n";

        yield 'JSON' => ['format=json', 'application/json; charset=utf-8', '[1,2]', null];
        yield 'XML' => ['format=xml', 'text/xml; charset=utf-8', $xml, null];
        yield 'CSV' => [
            'format=csv&convertToUnicode=0',
            'application/vnd.ms-excel',
            "1\n2",
            $spreadsheetDisposition,
        ];
        yield 'TSV' => [
            'format=tsv&convertToUnicode=0',
            'application/vnd.ms-excel',
            "1\n2",
            $spreadsheetDisposition,
        ];
        yield 'HTML' => ['format=html', 'text/html; charset=utf-8', $html, null];
        yield 'original' => [
            'format=original',
            'text/plain; charset=utf-8',
            var_export([1, 2], true),
            null,
        ];
        yield 'serialized original' => [
            'format=original&serialize=1',
            'text/plain; charset=utf-8',
            serialize([1, 2]),
            null,
        ];
        yield 'console' => ['format=console', 'text/plain; charset=utf-8', $console, null];
        yield 'RSS array error' => [
            'format=rss',
            'text/plain; charset=utf-8',
            "Error: RSS feeds can be generated for one specific website &idSite=X.\n".
                'Please specify only one idSite or consider using &format=XML instead.',
            null,
        ];
    }

    #[DataProvider('emptySiteIdFormats')]
    public function test_no_admin_sites_keep_exact_empty_outputs(
        string $format,
        string $contentType,
        string $content,
    ): void {
        $this->bindAdminSites([]);

        $this->get(
            '/index.php?module=API&method=SitesManager.getSitesIdWithAdminAccess'.
            '&token_auth=invalid&convertToUnicode=0&format='.$format,
        )->assertOk()
            ->assertHeader('Content-Type', $contentType)
            ->assertContent($content);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function emptySiteIdFormats(): iterable
    {
        yield 'JSON' => ['json', 'application/json; charset=utf-8', '[]'];
        yield 'XML' => [
            'xml',
            'text/xml; charset=utf-8',
            "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<result />",
        ];
        yield 'CSV' => ['csv', 'application/vnd.ms-excel', 'No data available'];
        yield 'HTML' => [
            'html',
            'text/html; charset=utf-8',
            "<table border=\"1\">\n<thead>\n\t<tr>\n\t</tr>\n</thead>\n".
                "<tbody>\n</tbody>\n</table>\n",
        ];
        yield 'original' => ['original', 'text/plain; charset=utf-8', var_export([], true)];
        yield 'console' => ['console', 'text/plain; charset=utf-8', "Empty table<br />\n"];
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function bindAdminSites(array $siteIds): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithRole')
            ->with(
                $this->callback(
                    static fn (ApiAuthentication $authentication): bool => $authentication->token !== null,
                ),
                SiteAccessRole::Admin,
            )
            ->willReturn($siteIds);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
