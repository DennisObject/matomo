<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Reporting\HierarchicalBlobArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EventsApiTest extends TestCase
{
    #[DataProvider('methodProvider')]
    public function test_routes_each_event_method_to_its_archive(
        string $method,
        string $record,
        bool $requiresSubtable,
    ): void {
        $this->bindViewAccess();
        $this->bindSite();
        $requestedRecords = [];
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturnCallback(
            function (
                array $siteIds,
                array $periods,
                string $segmentHash,
                string $recordName,
                bool $includeSubtables,
            ) use (&$requestedRecords): array {
                $requestedRecords[] = $recordName;

                return [7 => ['2026-08-14,2026-08-14' => [
                    $recordName => [$this->row('Played', 2)],
                ]]];
            },
        );
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $parameters = $requiresSubtable ? ['idSubtable' => '42'] : [];
        $this->get($this->url($method, $parameters))
            ->assertOk()
            ->assertJsonPath('0.label', 'Played')
            ->assertJsonPath('0.nb_visits', 2);

        $this->assertContains($requiresSubtable ? $record.'_42' : $record, $requestedRecords);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function methodProvider(): iterable
    {
        yield 'category' => ['getCategory', 'Events_category_action', false];
        yield 'action' => ['getAction', 'Events_action_name', false];
        yield 'name' => ['getName', 'Events_name_action', false];
        yield 'action from category' => ['getActionFromCategoryId', 'Events_category_action', true];
        yield 'name from category' => ['getNameFromCategoryId', 'Events_category_name', true];
        yield 'category from action' => ['getCategoryFromActionId', 'Events_action_category', true];
        yield 'name from action' => ['getNameFromActionId', 'Events_action_name', true];
        yield 'action from name' => ['getActionFromNameId', 'Events_name_action', true];
        yield 'category from name' => ['getCategoryFromNameId', 'Events_name_category', true];
    }

    public function test_uses_requested_secondary_dimension_and_rejects_invalid_values(): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $archives = $this->createMock(HierarchicalBlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('records')
            ->with([7], $this->isType('array'), '', 'Events_category_name', false)
            ->willReturn([]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url('getCategory', ['secondaryDimension' => 'eventName']))
            ->assertOk()
            ->assertExactJson([]);

        $this->get($this->url('getCategory', ['secondaryDimension' => 'eventCategory']))
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => "Secondary dimension 'eventCategory' is not valid for the API getCategory. ".
                    'Use one of: eventAction, eventName',
            ]);
    }

    public function test_requires_subtable_id_for_subtable_methods(): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));

        $this->get($this->url('getActionFromCategoryId'))
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => "Please specify a value for 'idSubtable'.",
            ]);
    }

    public function test_expands_subtables_and_computes_event_metrics(): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $archives = $this->createMock(HierarchicalBlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('records')
            ->with([7], $this->isType('array'), '', 'Events_category_action', true)
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [
                'Events_category_action' => [$this->row('Movie', 4, 20, 2, 42)],
                'Events_category_action_42' => [$this->row('Play', 3, 0, 0)],
            ]]]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url('getCategory', ['expanded' => '1']))
            ->assertOk()
            ->assertExactJson([[
                'label' => 'Movie',
                'nb_visits' => 4,
                'sum_event_value' => 20,
                'nb_events_with_value' => 2,
                'nb_visits_percent_of_total' => '100%',
                'avg_event_value' => 10,
                'segment' => 'eventCategory==Movie',
                'idsubdatatable' => 42,
                'subtable' => [[
                    'label' => 'Play',
                    'nb_visits' => 3,
                    'sum_event_value' => 0,
                    'nb_events_with_value' => 0,
                    'nb_visits_percent_of_total' => '75%',
                    'avg_event_value' => 0,
                ]],
            ]]);
    }

    public function test_flattens_subtables_with_dimension_columns_and_replaces_missing_event_name(): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturn([7 => ['2026-08-14,2026-08-14' => [
            'Events_category_name' => [$this->row('Movie', 4, 0, 0, 42)],
            'Events_category_name_42' => [$this->row('Piwik_EventNameNotSet', 3, 0, 0)],
        ]]]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url('getCategory', [
            'secondaryDimension' => 'eventName',
            'flat' => '1',
            'show_dimensions' => '1',
            'showMetadata' => '0',
        ]))->assertOk()->assertExactJson([[
            'label' => 'Movie - Event Name not defined',
            'nb_visits' => 3,
            'sum_event_value' => 0,
            'nb_events_with_value' => 0,
            'nb_visits_percent_of_total' => '75%',
            'avg_event_value' => 0,
            'Events_EventCategory' => 'Movie',
            'Events_EventName' => 'Event Name not defined',
        ]]);
    }

    public function test_renders_expanded_subtables_in_xml(): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturn([7 => ['2026-08-14,2026-08-14' => [
            'Events_category_action' => [$this->row('Movie', 4, 0, 0, 42)],
            'Events_category_action_42' => [$this->row('Play', 3, 0, 0)],
        ]]]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url('getCategory', ['expanded' => '1', 'format' => 'xml']))
            ->assertOk()
            ->assertSee('<subtable>', false)
            ->assertSee('<label>Play</label>', false)
            ->assertSee('</subtable>', false);
    }

    /**
     * @return array{
     *     columns: array{label: string, nb_visits: int, sum_event_value: int, nb_events_with_value: int},
     *     metadata: array{},
     *     subtableId: int|null
     * }
     */
    private function row(
        string $label,
        int $visits,
        int $sumEventValue = 0,
        int $eventsWithValue = 0,
        ?int $subtableId = null,
    ): array {
        return [
            'columns' => [
                'label' => $label,
                'nb_visits' => $visits,
                'sum_event_value' => $sumEventValue,
                'nb_events_with_value' => $eventsWithValue,
            ],
            'metadata' => [],
            'subtableId' => $subtableId,
        ];
    }

    /** @param array<string, string> $parameters */
    private function url(string $method, array $parameters = []): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'Events.'.$method,
            'idSite' => '7',
            'period' => 'day',
            'date' => '2026-08-14',
            'format' => 'json',
            'token_auth' => 'view-token',
            ...$parameters,
        ]);
    }

    private function bindSite(): void
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(SiteRepository::class, $sites);
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
