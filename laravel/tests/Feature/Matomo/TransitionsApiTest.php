<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Archiving\ArchiveActionQueryFactory;
use App\Matomo\Archiving\ArchiveVisitQueryFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Database\MatomoDatabase;
use App\Matomo\Overlay\OverlaySettings;
use App\Matomo\Sites\QueryParameterExclusionPolicy;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Transitions\TransitionsReportBuilder;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class TransitionsApiTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.transitions_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('transitions_test');

        $this->connection = $databases->connection('transitions_test');
        $this->createSchema();
        $this->insertReportData();

        $this->app->instance(MatomoDatabase::class, new MatomoDatabase($this->connection));
        $this->app->forgetInstance(ArchiveActionQueryFactory::class);
        $this->app->forgetInstance(ArchiveVisitQueryFactory::class);
        $this->app->forgetInstance(TransitionsReportBuilder::class);
        $this->app->instance(OverlaySettings::class, new class implements OverlaySettings
        {
            public function followingPagesLimit(): int
            {
                return 300;
            }

            public function urlQueryParametersToExclude(): array
            {
                return ['jsessionid', 'token_auth', 'token'];
            }

            public function campaignNameParameters(): array
            {
                return ['utm_campaign'];
            }

            public function campaignKeywordParameters(): array
            {
                return ['utm_term'];
            }

            public function pageMaximumLength(): int
            {
                return 1024;
            }
        });

        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturnMap([[1, 'UTC']]);
        $this->app->instance(SiteRepository::class, $sites);
    }

    public function test_returns_complete_transitions_for_a_page_url(): void
    {
        $response = $this->get($this->url('Transitions.getTransitionsForPageUrl', [
            'pageUrl' => 'https://Example.com/page',
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
        ]))->assertOk()->json();

        $this->assertSame('Sat, Aug 15', $response['date'] ?? null);
        $this->assertSame([
            ['label' => 'example.com/previous', 'referrals' => 1],
        ], $response['previousPages'] ?? null);
        $this->assertSame([], $response['previousSiteSearches'] ?? null);
        $this->assertSame([
            'loops' => 1,
            'pageviews' => 3,
            'entries' => 3,
            'exits' => 0,
        ], $response['pageMetrics'] ?? null);
        $this->assertSame([
            ['label' => 'example.com/next', 'referrals' => 1],
        ], $response['followingPages'] ?? null);
        $this->assertSame([
            ['label' => 'find me', 'referrals' => 1],
        ], $response['followingSiteSearches'] ?? null);
        $this->assertSame([
            ['label' => 'https://external.test/path', 'referrals' => 1],
        ], $response['outlinks'] ?? null);
        $this->assertSame([
            ['label' => 'https://files.test/file.zip', 'referrals' => 1],
        ], $response['downloads'] ?? null);
        $this->assertSame([
            [
                'label' => 'Direct entries',
                'shortName' => 'direct',
                'visits' => 1,
                'details' => [],
            ],
            [
                'label' => 'From search engines',
                'shortName' => 'search',
                'visits' => 1,
                'details' => [['label' => 'SearchCo', 'referrals' => 1]],
            ],
            [
                'label' => 'From websites',
                'shortName' => 'website',
                'visits' => 1,
                'details' => [['label' => 'https://referrer.test/', 'referrals' => 1]],
            ],
        ], $response['referrers'] ?? null);
    }

    public function test_page_url_lookup_matches_the_legacy_protocol_independent_action_id(): void
    {
        $this->get($this->url('Transitions.getTransitionsForPageUrl', [
            'pageUrl' => 'http://www.example.com/page',
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
            'parts' => 'internalReferrers',
        ]))->assertOk()->assertJsonPath('pageMetrics.pageviews', 3);
    }

    public function test_returns_requested_parts_for_a_page_title(): void
    {
        $response = $this->get($this->url('Transitions.getTransitionsForAction', [
            'actionName' => 'Target title',
            'actionType' => 'title',
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
            'parts' => 'internalReferrers,followingActions',
        ]))->assertOk()->json();

        $this->assertSame([
            ['label' => 'Previous title', 'referrals' => 1],
        ], $response['previousPages'] ?? null);
        $this->assertSame([
            ['label' => 'Next title', 'referrals' => 1],
        ], $response['followingPages'] ?? null);
        $this->assertSame(['loops' => 1, 'pageviews' => 3], $response['pageMetrics'] ?? null);
        $this->assertArrayNotHasKey('referrers', $response);
        $this->assertArrayNotHasKey('exits', $response['pageMetrics']);
    }

    public function test_generic_action_endpoint_and_following_only_include_loops(): void
    {
        $response = $this->get($this->url('Transitions.getTransitionsForAction', [
            'actionName' => 'https://example.com/page',
            'actionType' => 'url',
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
            'parts' => 'followingActions',
        ]))->assertOk()->json();

        $this->assertSame([
            ['label' => 'example.com/next', 'referrals' => 1],
            ['label' => 'example.com/page', 'referrals' => 1],
        ], $response['followingPages'] ?? null);
        $this->assertArrayNotHasKey('pageMetrics', $response);
    }

    public function test_applies_a_segment_to_action_and_entry_queries(): void
    {
        $response = $this->get($this->url('Transitions.getTransitionsForAction', [
            'actionName' => 'https://example.com/page',
            'actionType' => 'url',
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
            'segment' => 'referrerType==search',
        ]))->assertOk()->json();

        $this->assertSame([
            ['label' => 'example.com/previous', 'referrals' => 1],
        ], $response['previousPages'] ?? null);
        $this->assertSame([
            'loops' => 0,
            'pageviews' => 1,
            'entries' => 1,
            'exits' => 0,
        ], $response['pageMetrics'] ?? null);
        $this->assertSame([
            [
                'label' => 'From search engines',
                'shortName' => 'search',
                'visits' => 1,
                'details' => [['label' => 'SearchCo', 'referrals' => 1]],
            ],
        ], $response['referrers'] ?? null);
    }

    public function test_groups_rows_after_the_requested_limit(): void
    {
        $this->connection->table('log_action')->insert([
            ['idaction' => 10, 'name' => 'example.com/a', 'type' => 1, 'url_prefix' => 2],
            ['idaction' => 11, 'name' => 'example.com/b', 'type' => 1, 'url_prefix' => 2],
            ['idaction' => 12, 'name' => 'example.com/c', 'type' => 1, 'url_prefix' => 2],
        ]);
        $this->connection->table('log_link_visit_action')->insert([
            $this->link(10, 1, 1, 6, 10, null),
            $this->link(11, 1, 1, 6, 11, null),
            $this->link(12, 1, 1, 6, 12, null),
        ]);

        $response = $this->get($this->url('Transitions.getTransitionsForAction', [
            'actionName' => 'https://example.com/page',
            'actionType' => 'url',
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
            'parts' => 'internalReferrers',
            'limitBeforeGrouping' => 2,
        ]))->assertOk()->json();

        $this->assertSame([
            ['label' => 'example.com/a', 'referrals' => 1],
            ['label' => 'example.com/b', 'referrals' => 1],
            ['label' => 'Others', 'referrals' => 2],
        ], $response['previousPages'] ?? null);
        $this->assertSame(['loops' => 1, 'pageviews' => 6], $response['pageMetrics'] ?? null);
    }

    public function test_groups_the_same_title_across_urls_before_applying_the_limit(): void
    {
        $this->connection->table('log_action')->insert([
            ['idaction' => 10, 'name' => 'example.com/previous-two', 'type' => 1, 'url_prefix' => 2],
            ['idaction' => 11, 'name' => 'aaa.test/previous', 'type' => 1, 'url_prefix' => 2],
            ['idaction' => 12, 'name' => 'Other previous title', 'type' => 4, 'url_prefix' => null],
            ['idaction' => 13, 'name' => 'example.com/next-two', 'type' => 1, 'url_prefix' => 2],
            ['idaction' => 14, 'name' => 'aaa.test/next', 'type' => 1, 'url_prefix' => 2],
            ['idaction' => 15, 'name' => 'Other next title', 'type' => 4, 'url_prefix' => null],
        ]);
        $this->connection->table('log_link_visit_action')->insert([
            $this->link(20, 1, 1, 6, 10, 7),
            $this->link(21, 1, 1, 6, 11, 12),
            $this->link(22, 1, 13, 8, 1, 6),
            $this->link(23, 1, 14, 15, 1, 6),
        ]);

        $response = $this->get($this->url('Transitions.getTransitionsForAction', [
            'actionName' => 'Target title',
            'actionType' => 'title',
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
            'parts' => 'internalReferrers,followingActions',
            'limitBeforeGrouping' => 1,
        ]))->assertOk()->json();

        $this->assertSame([
            ['label' => 'Previous title', 'referrals' => 2],
            ['label' => 'Others', 'referrals' => 1],
        ], $response['previousPages'] ?? null);
        $this->assertSame([
            ['label' => 'Next title', 'referrals' => 2],
            ['label' => 'Others', 'referrals' => 1],
        ], $response['followingPages'] ?? null);
        $this->assertSame(['loops' => 1, 'pageviews' => 5], $response['pageMetrics'] ?? null);
    }

    public function test_checks_view_access_before_reading_transition_logs(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get($this->url('Transitions.getTransitionsForPageUrl', [
            'pageUrl' => 'https://example.com/page',
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
        ]))->assertStatus(401)->assertExactJson([
            'result' => 'error',
            'message' => "You can't access this resource as it requires 'view' access for the website id = 1.",
        ]);
    }

    public function test_overlay_returns_following_pages_outlinks_and_downloads(): void
    {
        $response = $this->get($this->url('Overlay.getFollowingPages', [
            'url' => 'https://Example.com/page?utm_campaign=ignored&token_auth=secret',
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
            'filter_limit' => -1,
        ]))->assertOk()->json();

        $this->assertSame([
            ['label' => 'example.com/next', 'referrals' => 1],
            ['label' => 'example.com/page', 'referrals' => 1],
            ['label' => 'https://external.test/path', 'referrals' => 1],
            ['label' => 'https://files.test/file.zip', 'referrals' => 1],
        ], $response);
    }

    public function test_overlay_applies_the_outer_api_row_filter_after_merging_reports(): void
    {
        $this->get($this->url('Overlay.getFollowingPages', [
            'url' => 'https://example.com/page',
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
            'filter_offset' => 1,
            'filter_limit' => 2,
        ]))->assertOk()->assertExactJson([
            ['label' => 'example.com/page', 'referrals' => 1],
            ['label' => 'https://external.test/path', 'referrals' => 1],
        ]);
    }

    public function test_overlay_normalizes_matrix_parameters_and_site_exclusions(): void
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturnMap([[1, 'UTC']]);
        $sites->method('details')->willReturn(['keep_url_fragment' => 0]);
        $sites->method('excludedParameters')->willReturn('private,/^secret/');
        $this->app->instance(SiteRepository::class, $sites);

        $globalExclusions = $this->createStub(QueryParameterExclusionPolicy::class);
        $globalExclusions->method('parameters')->willReturn('global');
        $this->app->instance(QueryParameterExclusionPolicy::class, $globalExclusions);

        $this->get($this->url('Overlay.getFollowingPages', [
            'url' => 'https://Example.com/page;jsessionid=x;private=y;secretValue=z;global=a#removed',
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
            'filter_limit' => 1,
        ]))->assertOk()->assertExactJson([
            ['label' => 'example.com/next', 'referrals' => 1],
        ]);
    }

    public function test_overlay_uses_the_last_repeated_scalar_query_parameter(): void
    {
        $this->connection->table('log_action')->insert([
            'idaction' => 10,
            'name' => 'example.com/page?item=last',
            'type' => 1,
            'url_prefix' => 2,
        ]);
        $this->connection->table('log_link_visit_action')->insert(
            $this->link(20, 1, 3, 8, 10, null),
        );

        $this->get($this->url('Overlay.getFollowingPages', [
            'url' => 'https://example.com/page?item=first&item=last',
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
        ]))->assertOk()->assertExactJson([
            ['label' => 'example.com/next', 'referrals' => 1],
        ]);
    }

    public function test_overlay_returns_an_empty_report_when_the_page_is_unknown(): void
    {
        $this->get($this->url('Overlay.getFollowingPages', [
            'url' => 'https://example.com/missing',
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
        ]))->assertOk()->assertExactJson([]);
    }

    public function test_overlay_checks_view_access_before_reading_transition_logs(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get($this->url('Overlay.getFollowingPages', [
            'url' => 'https://example.com/page',
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
        ]))->assertStatus(401)->assertExactJson([
            'result' => 'error',
            'message' => "You can't access this resource as it requires 'view' access for the website id = 1.",
        ]);
    }

    public function test_returns_legacy_no_data_error_for_an_unknown_action(): void
    {
        $this->get($this->url('Transitions.getTransitionsForPageUrl', [
            'pageUrl' => 'https://example.com/missing',
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
        ]))->assertStatus(400)->assertExactJson([
            'result' => 'error',
            'message' => 'NoDataForAction',
        ]);
    }

    public function test_rejects_an_unknown_generic_action_type(): void
    {
        $this->get($this->url('Transitions.getTransitionsForAction', [
            'actionName' => 'Target title',
            'actionType' => 'event',
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
        ]))->assertStatus(400)->assertJsonPath('message', 'Unknown action type');
    }

    /** @param array<string, int|string> $parameters */
    private function url(string $method, array $parameters): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => $method,
            'format' => 'json',
            ...$parameters,
        ]);
    }

    private function createSchema(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $schema->create('log_visit', static function (Blueprint $table): void {
            $table->unsignedInteger('idvisit')->primary();
            $table->unsignedInteger('idsite');
            $table->dateTime('visit_last_action_time');
            $table->unsignedInteger('visit_entry_idaction_url')->nullable();
            $table->unsignedInteger('visit_entry_idaction_name')->nullable();
            $table->unsignedTinyInteger('referer_type')->nullable();
            $table->string('referer_name')->nullable();
            $table->string('referer_keyword')->nullable();
            $table->text('referer_url')->nullable();
        });
        $schema->create('log_action', static function (Blueprint $table): void {
            $table->unsignedInteger('idaction')->primary();
            $table->text('name');
            $table->unsignedTinyInteger('type');
            $table->unsignedTinyInteger('url_prefix')->nullable();
        });
        $schema->create('log_link_visit_action', static function (Blueprint $table): void {
            $table->unsignedInteger('idlink_va')->primary();
            $table->unsignedInteger('idsite');
            $table->unsignedInteger('idvisit');
            $table->unsignedInteger('idaction_url')->nullable();
            $table->unsignedInteger('idaction_name')->nullable();
            $table->unsignedInteger('idaction_url_ref')->nullable();
            $table->unsignedInteger('idaction_name_ref')->nullable();
            $table->dateTime('server_time');
        });
    }

    private function insertReportData(): void
    {
        $this->connection->table('log_action')->insert([
            ['idaction' => 1, 'name' => 'example.com/page', 'type' => 1, 'url_prefix' => 2],
            ['idaction' => 2, 'name' => 'example.com/previous', 'type' => 1, 'url_prefix' => 2],
            ['idaction' => 3, 'name' => 'example.com/next', 'type' => 1, 'url_prefix' => 2],
            ['idaction' => 4, 'name' => 'external.test/path', 'type' => 2, 'url_prefix' => 2],
            ['idaction' => 5, 'name' => 'files.test/file.zip', 'type' => 3, 'url_prefix' => 2],
            ['idaction' => 6, 'name' => 'Target title', 'type' => 4, 'url_prefix' => null],
            ['idaction' => 7, 'name' => 'Previous title', 'type' => 4, 'url_prefix' => null],
            ['idaction' => 8, 'name' => 'Next title', 'type' => 4, 'url_prefix' => null],
            ['idaction' => 9, 'name' => 'find me', 'type' => 8, 'url_prefix' => null],
        ]);
        $this->connection->table('log_visit')->insert([
            [
                'idvisit' => 1,
                'idsite' => 1,
                'visit_last_action_time' => '2026-08-15 10:00:00',
                'visit_entry_idaction_url' => 1,
                'visit_entry_idaction_name' => 6,
                'referer_type' => 1,
                'referer_name' => null,
                'referer_keyword' => null,
                'referer_url' => null,
            ],
            [
                'idvisit' => 2,
                'idsite' => 1,
                'visit_last_action_time' => '2026-08-15 11:00:00',
                'visit_entry_idaction_url' => 1,
                'visit_entry_idaction_name' => 6,
                'referer_type' => 2,
                'referer_name' => 'SearchCo',
                'referer_keyword' => 'query',
                'referer_url' => null,
            ],
            [
                'idvisit' => 3,
                'idsite' => 1,
                'visit_last_action_time' => '2026-08-15 12:00:00',
                'visit_entry_idaction_url' => 1,
                'visit_entry_idaction_name' => 6,
                'referer_type' => 3,
                'referer_name' => null,
                'referer_keyword' => null,
                'referer_url' => 'https://referrer.test/',
            ],
        ]);
        $this->connection->table('log_link_visit_action')->insert([
            $this->link(1, 1, 1, 6, null, null),
            $this->link(2, 2, 1, 6, 2, 7),
            $this->link(3, 3, 1, 6, 1, 6),
            $this->link(4, 1, 3, 8, 1, 6),
            $this->link(5, 2, null, 9, 1, 6),
            $this->link(6, 3, 4, null, 1, 6),
            $this->link(7, 3, 5, null, 1, 6),
        ]);
    }

    /** @return array<string, int|string|null> */
    private function link(
        int $id,
        int $visit,
        ?int $url,
        ?int $name,
        ?int $urlRef,
        ?int $nameRef,
    ): array {
        return [
            'idlink_va' => $id,
            'idsite' => 1,
            'idvisit' => $visit,
            'idaction_url' => $url,
            'idaction_name' => $name,
            'idaction_url_ref' => $urlRef,
            'idaction_name_ref' => $nameRef,
            'server_time' => '2026-08-15 10:00:00',
        ];
    }
}
