<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Annotations\AnnotationRepository;
use App\Matomo\Annotations\DatabaseAnnotationRepository;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Database\MatomoDatabase;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class AnnotationsApiTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.annotations_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('annotations_test');

        $this->connection = $databases->connection('annotations_test');
        $this->connection->getSchemaBuilder()->create('annotations', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('idsite');
            $table->dateTime('date');
            $table->text('note');
            $table->boolean('starred')->default(false);
            $table->string('user', 100);
            $table->index(['idsite', 'date']);
        });
        $this->app->instance(MatomoDatabase::class, new MatomoDatabase($this->connection));
        $this->app->instance(
            AnnotationRepository::class,
            new DatabaseAnnotationRepository($this->connection),
        );
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturnCallback(
            static fn (int $siteId): ?string => $siteId === 7 ? 'UTC' : null,
        );
        $this->app->instance(SiteRepository::class, $sites);
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(true);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $authorizer->method('authenticatedLogin')->willReturn('alice');
        $authorizer->method('siteIdsWithAtLeastViewAccess')->willReturn([7]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    public function test_runs_the_complete_annotation_lifecycle(): void
    {
        $this->get($this->url('add', [
            'date' => '2026-08-14',
            'note' => '<b>release</b>',
        ]))->assertOk()
            ->assertJsonPath('id', 1)
            ->assertJsonPath('idNote', 1)
            ->assertJsonPath('idsite', 7)
            ->assertJsonPath('date', '2026-08-14')
            ->assertJsonPath('note', '&lt;b&gt;release&lt;/b&gt;')
            ->assertJsonPath('starred', 0)
            ->assertJsonPath('user', 'alice')
            ->assertJsonPath('canEditOrDelete', true);

        $this->get($this->url('get', ['idNote' => '1']))
            ->assertOk()
            ->assertJsonPath('note', '&lt;b&gt;release&lt;/b&gt;');

        $this->get($this->url('save', [
            'idNote' => '1',
            'date' => '2026-08-15',
            'note' => 'deployed',
            'starred' => '1',
        ]))->assertOk()
            ->assertJsonPath('date', '2026-08-15')
            ->assertJsonPath('note', 'deployed')
            ->assertJsonPath('starred', 1);

        $this->get($this->url('getAll', [
            'date' => '2026-08-15',
            'period' => 'day',
        ]))->assertOk()
            ->assertJsonCount(1, '7')
            ->assertJsonPath('7.0.id', 1)
            ->assertJsonPath('7.0.canEditOrDelete', true);

        $this->get($this->url('getAnnotationCountForDates', [
            'date' => '2026-08-15',
            'period' => 'day',
            'getAnnotationText' => '1',
        ]))->assertOk()
            ->assertJsonPath('7.0.0', '2026-08-15')
            ->assertJsonPath('7.0.1.count', 1)
            ->assertJsonPath('7.0.1.starred', 1)
            ->assertJsonPath('7.0.1.note', 'deployed');

        $this->get($this->url('delete', ['idNote' => '1']))
            ->assertOk()
            ->assertExactJson(['result' => 'success', 'message' => 'ok']);

        $this->get($this->url('add', ['date' => '2026-08-15', 'note' => 'one']))->assertOk();
        $this->get($this->url('add', ['date' => '2026-08-15', 'note' => 'two']))->assertOk();
        $this->get($this->url('deleteAll'))->assertOk();
        $this->assertSame(0, $this->connection->table('annotations')->count());
    }

    public function test_lists_unbounded_annotations_and_supports_last_n_periods(): void
    {
        $this->connection->table('annotations')->insert([
            [
                'idsite' => 7,
                'date' => '2026-06-15 00:00:00',
                'note' => 'June',
                'starred' => 0,
                'user' => 'alice',
            ],
            [
                'idsite' => 7,
                'date' => '2026-08-15 00:00:00',
                'note' => 'August',
                'starred' => 1,
                'user' => 'alice',
            ],
        ]);

        $this->get($this->url('getAll'))
            ->assertOk()
            ->assertJsonCount(2, '7');

        $this->get($this->url('getAll', [
            'date' => '2026-08-15',
            'period' => 'month',
            'lastN' => '2',
        ]))->assertOk()
            ->assertJsonCount(1, '7')
            ->assertJsonPath('7.0.note', 'August');
    }

    public function test_rejects_missing_and_invalid_requests(): void
    {
        $this->get($this->url('add', ['note' => 'missing date']))
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'date'.");

        $this->get($this->url('get', ['idNote' => '0']))
            ->assertBadRequest()
            ->assertJsonPath('message', 'The API parameter [idNote] must be a scalar value.');

    }

    public function test_rejects_a_site_without_view_access(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get($this->url('get', ['idNote' => '1']))
            ->assertUnauthorized()
            ->assertJsonPath(
                'message',
                "You can't access this resource as it requires 'view' access for the website id = 7.",
            );
    }

    public function test_read_only_users_can_view_but_cannot_change_notes(): void
    {
        $this->connection->table('annotations')->insert([
            'idsite' => 7,
            'date' => '2026-08-15 00:00:00',
            'note' => 'Read only',
            'starred' => 0,
            'user' => 'alice',
        ]);
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(true);
        $authorizer->method('hasSuperUserAccess')->willReturn(false);
        $authorizer->method('siteIdsWithMinimumRole')->willReturn([]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get($this->url('get', ['idNote' => '1']))
            ->assertOk()
            ->assertJsonPath('canEditOrDelete', false);

        $this->get($this->url('save', ['idNote' => '1', 'note' => 'Changed']))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'The current user is not allowed to modify notes for site #7.');

        $this->get($this->url('save', ['idNote' => '999', 'note' => 'Changed']))
            ->assertBadRequest()
            ->assertJsonPath('message', "There is no note with id '999' for site with id '7'.");
    }

    /** @param array<string, string> $parameters */
    private function url(string $method, array $parameters = []): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'Annotations.'.$method,
            'idSite' => '7',
            'format' => 'json',
            'token_auth' => 'annotation-token',
            ...$parameters,
        ]);
    }
}
