<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Segments\StoredSegmentRepository;
use Tests\TestCase;

/** @phpstan-import-type StoredSegment from StoredSegmentRepository */
class SegmentEditorApiTest extends TestCase
{
    private FakeStoredSegmentRepository $segments;

    protected function setUp(): void
    {
        parent::setUp();

        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('alice');
        $authorizer->method('hasSomeViewAccess')->willReturn(true);
        $authorizer->method('hasViewAccessToSite')->willReturn(true);
        $authorizer->method('siteIdsWithAtLeastViewAccess')->willReturn([1]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->segments = new FakeStoredSegmentRepository;
        $this->app->instance(StoredSegmentRepository::class, $this->segments);
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

    /** @return StoredSegment */
    private function segment(
        int $id,
        string $name,
        string $login,
        int $shared,
        int $siteId,
        int $deleted = 0,
        string $definition = 'browserCode==FF',
    ): array {
        return [
            'idsegment' => $id,
            'name' => $name,
            'definition' => $definition,
            'hash' => 'hash-'.$id,
            'login' => $login,
            'enable_all_users' => $shared,
            'enable_only_idsite' => $siteId,
            'auto_archive' => 0,
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
final class FakeStoredSegmentRepository implements StoredSegmentRepository
{
    /** @var list<StoredSegment> */
    public array $rows = [];

    public function find(int $segmentId): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['idsegment'] === $segmentId) {
                return $row;
            }
        }

        return null;
    }

    public function visible(string $login, bool $superUser, ?int $siteId): array
    {
        return $this->rows;
    }
}
