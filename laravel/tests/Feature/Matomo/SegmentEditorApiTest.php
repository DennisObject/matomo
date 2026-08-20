<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Reporting\VisitsSummaryArchiveRepository;
use App\Matomo\Segments\Events\SegmentDeactivating;
use App\Matomo\Segments\Events\SegmentUpdating;
use App\Matomo\Segments\MutableStoredSegmentRepository;
use App\Matomo\Segments\SegmentCacheInvalidator;
use App\Matomo\Segments\SegmentCreationAuthorizer;
use App\Matomo\Segments\SegmentEditorSettings;
use App\Matomo\Segments\SegmentRearchiveScheduler;
use App\Matomo\Segments\StoredSegmentRepository;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** @phpstan-import-type StoredSegment from StoredSegmentRepository */
class SegmentEditorApiTest extends TestCase
{
    private FakeStoredSegmentRepository $segments;

    private FakeSegmentEditorSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('alice');
        $authorizer->method('hasSomeViewAccess')->willReturn(true);
        $authorizer->method('hasViewAccessToSite')->willReturn(true);
        $authorizer->method('siteIdsWithAtLeastViewAccess')->willReturn([1]);
        $authorizer->method('siteIdsWithMinimumRole')->willReturn([1]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->segments = new FakeStoredSegmentRepository;
        $this->app->instance(StoredSegmentRepository::class, $this->segments);
        $this->app->instance(MutableStoredSegmentRepository::class, $this->segments);
        $this->app->instance(SegmentCacheInvalidator::class, new FakeSegmentCacheInvalidator);
        $this->app->instance(SegmentCreationAuthorizer::class, new FakeSegmentCreationAuthorizer);

        $this->settings = new FakeSegmentEditorSettings;
        $this->app->instance(SegmentEditorSettings::class, $this->settings);
        $this->app->instance(SegmentRearchiveScheduler::class, new FakeSegmentRearchiveScheduler);
    }

    public function test_returns_a_visible_stored_segment(): void
    {
        $this->segments->rows = [$this->segment(1, 'Mine', 'alice', 0, 1)];

        $this->get($this->url('get', ['idSegment' => 1]))
            ->assertOk()
            ->assertJsonPath('idsegment', 1)
            ->assertJsonPath('name', 'Mine')
            ->assertJsonPath('enable_only_idsite', 1);
    }

    public function test_returns_success_for_a_missing_segment_and_rejects_hidden_rows(): void
    {
        $this->get($this->url('get', ['idSegment' => 99]))
            ->assertOk()
            ->assertExactJson(['result' => 'success', 'message' => 'ok']);

        $this->segments->rows = [$this->segment(2, 'Private', 'bob', 0, 1)];
        $this->get($this->url('get', ['idSegment' => 2]))
            ->assertBadRequest()
            ->assertJsonPath('result', 'error');

        $this->segments->rows = [$this->segment(3, 'Deleted', 'alice', 0, 1, deleted: 1)];
        $this->get($this->url('get', ['idSegment' => 3]))
            ->assertBadRequest()
            ->assertJsonPath('message', 'This segment is marked as deleted. ');
    }

    public function test_lists_valid_accessible_segments_with_owned_rows_first(): void
    {
        $this->segments->rows = [
            $this->segment(1, 'Shared first alphabetically', 'root', 1, 0),
            $this->segment(2, 'Mine', 'alice', 0, 1),
            $this->segment(3, 'No site access', 'root', 1, 2),
            $this->segment(4, 'Invalid', 'alice', 0, 1, definition: 'broken'),
        ];

        $this->get($this->url('getAll'))
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.idsegment', 2)
            ->assertJsonPath('1.idsegment', 1);
    }

    public function test_enforces_list_and_segment_site_access(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->segments->rows = [$this->segment(1, 'Denied', 'alice', 0, 5)];

        $this->get($this->url('getAll'))->assertUnauthorized();
        $this->get($this->url('getAll', ['idSite' => 5]))->assertUnauthorized();
        $this->get($this->url('get', ['idSegment' => 1]))->assertUnauthorized();
    }

    public function test_superuser_can_create_segments_for_all_sites(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('root');
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get($this->url('isUserCanAddNewSegment'))
            ->assertOk()
            ->assertExactJson(['value' => true]);
    }

    public function test_deletes_an_owned_segment_and_clears_the_cache(): void
    {
        Event::fake([SegmentDeactivating::class]);
        $cache = new FakeSegmentCacheInvalidator;
        $this->app->instance(SegmentCacheInvalidator::class, $cache);
        $this->segments->rows = [$this->segment(1, 'Mine', 'alice', 0, 1)];

        $this->get($this->url('delete', ['idSegment' => 1]))
            ->assertOk()
            ->assertExactJson(['result' => 'success', 'message' => 'ok']);

        self::assertSame(1, $this->segments->deletedId);
        self::assertNotNull($this->segments->deletedAt);
        self::assertTrue($cache->cleared);
        Event::assertDispatched(
            SegmentDeactivating::class,
            static fn (SegmentDeactivating $event): bool => $event->segmentId === 1,
        );
    }

    public function test_stars_and_unstars_an_owned_segment(): void
    {
        $this->segments->rows = [$this->segment(1, 'Mine', 'alice', 0, 1)];

        $this->get($this->url('star', ['idSegment' => 1]))
            ->assertOk()
            ->assertExactJson([
                'result' => true,
                'starred' => 1,
                'starred_by' => 'alice',
            ]);
        self::assertSame(['starred' => 1, 'starred_by' => 'alice'], $this->segments->updated);

        $this->get($this->url('unstar', ['idSegment' => 1]))
            ->assertOk()
            ->assertExactJson(['starred' => 0, 'result' => true]);
        self::assertSame(['starred' => 0, 'starred_by' => null], $this->segments->updated);
    }

    public function test_rejects_state_changes_to_foreign_global_deleted_and_missing_segments(): void
    {
        $this->segments->rows = [$this->segment(1, 'Foreign', 'bob', 1, 1)];
        $this->get($this->url('delete', ['idSegment' => 1]))->assertBadRequest();

        $this->segments->rows = [$this->segment(2, 'Global', 'alice', 0, 0)];
        $this->get($this->url('star', ['idSegment' => 2]))->assertBadRequest();

        $this->segments->rows = [$this->segment(3, 'Deleted', 'alice', 0, 1, deleted: 1)];
        $this->get($this->url('unstar', ['idSegment' => 3]))->assertBadRequest();

        $this->segments->rows = [];
        $this->get($this->url('delete', ['idSegment' => 99]))
            ->assertBadRequest()
            ->assertJsonPath('message', 'Requested segment not found');
    }

    public function test_adds_a_site_segment_with_legacy_encoding_and_cache_clear(): void
    {
        $cache = new FakeSegmentCacheInvalidator;
        $this->app->instance(SegmentCacheInvalidator::class, $cache);

        $this->get($this->url('add', [
            'name' => '<Mine>',
            'definition' => "pageUrl==example.test/a#b&c'd",
            'idSite' => 1,
            'autoArchive' => 0,
            'enabledAllUsers' => 0,
        ]))
            ->assertOk()
            ->assertExactJson(['value' => 10]);

        self::assertSame('&lt;Mine&gt;', $this->segments->created['name'] ?? null);
        self::assertSame(
            'pageUrl==example.test/a%23b%26c%27d',
            $this->segments->created['definition'] ?? null,
        );
        self::assertSame('alice', $this->segments->created['login'] ?? null);
        self::assertTrue($cache->cleared);
    }

    public function test_updates_and_schedules_a_changed_preprocessed_segment(): void
    {
        Event::fake([SegmentUpdating::class]);
        $scheduler = new FakeSegmentRearchiveScheduler;
        $this->app->instance(SegmentRearchiveScheduler::class, $scheduler);
        $this->segments->rows = [$this->segment(1, 'Mine', 'alice', 0, 1)];

        $this->get($this->url('update', [
            'idSegment' => 1,
            'name' => 'Changed',
            'definition' => 'browserCode==CH',
            'idSite' => 1,
            'autoArchive' => 1,
            'enabledAllUsers' => 0,
        ]))
            ->assertOk()
            ->assertExactJson(['result' => 'success', 'message' => 'ok']);

        self::assertSame('Changed', $this->segments->updated['name'] ?? null);
        self::assertSame(1, $this->segments->updated['auto_archive'] ?? null);
        self::assertSame('browserCode==CH', $scheduler->segment['definition'] ?? null);
        Event::assertDispatched(SegmentUpdating::class);
    }

    public function test_enforces_shared_realtime_and_browser_archiving_rules(): void
    {
        $this->get($this->url('add', [
            'name' => 'Shared',
            'definition' => 'browserCode==FF',
            'idSite' => 1,
            'enabledAllUsers' => 1,
        ]))->assertBadRequest()->assertJsonPath(
            'message',
            'enabledAllUsers=1 requires Super User access',
        );

        $this->settings->realtime = false;
        $this->get($this->url('add', [
            'name' => 'Realtime',
            'definition' => 'browserCode==FF',
            'idSite' => 1,
        ]))->assertBadRequest();

        $this->settings->realtime = true;
        $this->settings->browserTrigger = true;
        $this->get($this->url('add', [
            'name' => 'Preprocessed',
            'definition' => 'browserCode==FF',
            'idSite' => 1,
            'autoArchive' => 1,
        ]))->assertBadRequest();
    }

    public function test_returns_preprocessed_segment_totals_and_visit_evolution(): void
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->with(1)->willReturn('UTC');
        $this->app->instance(SiteRepository::class, $sites);
        $archives = new SegmentVisitsArchiveRepository;
        $archives->metrics = [
            '2026-08-15,2026-08-15' => ['nb_visits' => 10, 'nb_actions' => 30],
            '2026-08-14,2026-08-14' => ['nb_visits' => 5, 'nb_actions' => 12],
        ];
        $this->app->instance(VisitsSummaryArchiveRepository::class, $archives);
        $this->segments->rows = [
            $this->segment(1, 'Archived', 'alice', 0, 1, definition: 'browserCode==FF', autoArchive: 1),
        ];

        $this->get($this->url('getSegmentData', [
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
            'segment' => 'browserCode==FF',
        ]))
            ->assertOk()
            ->assertExactJson([
                'nb_visits' => 10,
                'nb_actions' => 30,
                'evolution_visits_direction' => 'positive',
                'evolution_visits_icon' => 'plugins/MultiSites/images/arrow_up.svg',
                'evolution_visits' => '100%',
            ]);
    }

    public function test_rejects_report_data_for_a_saved_realtime_segment(): void
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(SiteRepository::class, $sites);
        $this->segments->rows = [
            $this->segment(1, 'Realtime', 'alice', 0, 1, definition: 'browserCode==FF'),
        ];

        $this->get($this->url('getSegmentData', [
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-15',
            'segment' => 'browserCode==FF',
        ]))->assertBadRequest();
    }

    public function test_compares_a_range_with_the_equal_length_previous_range(): void
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(SiteRepository::class, $sites);
        $archives = new SegmentVisitsArchiveRepository;
        $archives->metrics = [
            '2026-01-01,2026-01-03' => ['nb_visits' => 4, 'nb_actions' => 9],
            '2025-12-29,2025-12-31' => ['nb_visits' => 8, 'nb_actions' => 15],
        ];
        $this->app->instance(VisitsSummaryArchiveRepository::class, $archives);

        $this->get($this->url('getSegmentData', [
            'idSite' => 1,
            'period' => 'range',
            'date' => '2026-01-01,2026-01-03',
            'segment' => '',
        ]))
            ->assertOk()
            ->assertJsonPath('evolution_visits_direction', 'negative')
            ->assertJsonPath('evolution_visits_icon', 'plugins/MultiSites/images/arrow_down.svg')
            ->assertJsonPath('evolution_visits', '-50%');
    }

    /** @return StoredSegment */
    private function segment(
        int $id,
        string $name,
        string $login,
        int $shared,
        int $siteId,
        int $deleted = 0,
        string $definition = 'browserCode==FF',
        int $autoArchive = 0,
    ): array {
        return [
            'idsegment' => $id,
            'name' => $name,
            'definition' => $definition,
            'hash' => 'hash-'.$id,
            'login' => $login,
            'enable_all_users' => $shared,
            'enable_only_idsite' => $siteId,
            'auto_archive' => $autoArchive,
            'ts_created' => '2026-08-15 12:00:00',
            'ts_last_edit' => null,
            'deleted' => $deleted,
            'starred' => 0,
            'starred_by' => null,
        ];
    }

    /** @param array<string, mixed> $parameters */
    private function url(string $method, array $parameters = []): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'SegmentEditor.'.$method,
            'format' => 'json',
            'token_auth' => 'test-token',
            ...$parameters,
        ]);
    }
}

/** @phpstan-import-type StoredSegment from StoredSegmentRepository */
final class FakeStoredSegmentRepository implements MutableStoredSegmentRepository
{
    /** @var list<StoredSegment> */
    public array $rows = [];

    public ?int $deletedId = null;

    public ?string $deletedAt = null;

    /** @var array<string, bool|int|string|null> */
    public array $updated = [];

    /** @var array<string, bool|int|string|null> */
    public array $created = [];

    public function find(int $segmentId): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['idsegment'] === $segmentId) {
                return $row;
            }
        }

        return null;
    }

    public function findByDefinition(string $definition): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['definition'] === $definition && $row['deleted'] === 0) {
                return $row;
            }
        }

        return null;
    }

    public function visible(string $login, bool $superUser, ?int $siteId): array
    {
        return $this->rows;
    }

    public function delete(int $segmentId, string $editedAt): void
    {
        $this->deletedId = $segmentId;
        $this->deletedAt = $editedAt;
    }

    public function create(array $values): int
    {
        $this->created = $values;
        $this->rows[] = [
            'idsegment' => 10,
            'name' => (string) ($values['name'] ?? ''),
            'definition' => (string) ($values['definition'] ?? ''),
            'hash' => md5(urldecode((string) ($values['definition'] ?? ''))),
            'login' => (string) ($values['login'] ?? ''),
            'enable_all_users' => (int) ($values['enable_all_users'] ?? 0),
            'enable_only_idsite' => (int) ($values['enable_only_idsite'] ?? 0),
            'auto_archive' => (int) ($values['auto_archive'] ?? 0),
            'ts_created' => is_string($values['ts_created'] ?? null) ? $values['ts_created'] : null,
            'ts_last_edit' => null,
            'deleted' => 0,
            'starred' => 0,
            'starred_by' => null,
        ];

        return 10;
    }

    public function update(int $segmentId, array $values): bool
    {
        $this->updated = $values;

        foreach ($this->rows as $index => $row) {
            if ($row['idsegment'] === $segmentId) {
                $row['name'] = is_string($values['name'] ?? null) ? $values['name'] : $row['name'];
                $row['definition'] = is_string($values['definition'] ?? null)
                    ? $values['definition']
                    : $row['definition'];
                $row['hash'] = isset($values['definition']) && is_string($values['definition'])
                    ? md5(urldecode($values['definition']))
                    : $row['hash'];
                $row['enable_all_users'] = isset($values['enable_all_users'])
                    ? (int) $values['enable_all_users']
                    : $row['enable_all_users'];
                $row['enable_only_idsite'] = isset($values['enable_only_idsite'])
                    ? (int) $values['enable_only_idsite']
                    : $row['enable_only_idsite'];
                $row['auto_archive'] = isset($values['auto_archive'])
                    ? (int) $values['auto_archive']
                    : $row['auto_archive'];
                $row['ts_last_edit'] = is_string($values['ts_last_edit'] ?? null)
                    ? $values['ts_last_edit']
                    : $row['ts_last_edit'];
                $row['deleted'] = isset($values['deleted']) ? (int) $values['deleted'] : $row['deleted'];
                $row['starred'] = isset($values['starred']) ? (int) $values['starred'] : $row['starred'];

                if (array_key_exists('starred_by', $values)) {
                    $row['starred_by'] = is_string($values['starred_by'])
                        ? $values['starred_by']
                        : null;
                }

                $this->rows[$index] = $row;
            }
        }

        return true;
    }
}

final class FakeSegmentEditorSettings implements SegmentEditorSettings
{
    public bool $allSites = true;

    public bool $realtime = true;

    public bool $browserTrigger = false;

    public bool $browserAvailable = true;

    public string $processFrom = 'beginning_of_time';

    public function allSitesAllowed(): bool
    {
        return $this->allSites;
    }

    public function realtimeAllowed(): bool
    {
        return $this->realtime;
    }

    public function browserTriggerEnabled(): bool
    {
        return $this->browserTrigger;
    }

    public function browserArchivingAvailable(): bool
    {
        return $this->browserAvailable;
    }

    public function processNewSegmentsFrom(): string
    {
        return $this->processFrom;
    }
}

final class FakeSegmentCreationAuthorizer implements SegmentCreationAuthorizer
{
    public bool $allowed = true;

    public function allowed(ApiAuthentication $authentication, ?int $siteId): bool
    {
        return $this->allowed;
    }
}

final class FakeSegmentRearchiveScheduler implements SegmentRearchiveScheduler
{
    /** @var array<string, bool|int|string|null> */
    public array $segment = [];

    public function schedule(array $segment): void
    {
        $this->segment = $segment;
    }
}

final class FakeSegmentCacheInvalidator implements SegmentCacheInvalidator
{
    public bool $cleared = false;

    public function clear(): void
    {
        $this->cleared = true;
    }
}

final class SegmentVisitsArchiveRepository implements VisitsSummaryArchiveRepository
{
    /** @var array<string, array<string, int|float>> */
    public array $metrics = [];

    public function metrics(
        array $siteIds,
        array $periods,
        string $segmentHash,
        array $metrics,
    ): array {
        $rows = [];

        foreach ($periods as $period) {
            $rows[$period->rangeKey()] = $this->metrics[$period->rangeKey()] ?? [];
        }

        return [$siteIds[0] => $rows];
    }
}
