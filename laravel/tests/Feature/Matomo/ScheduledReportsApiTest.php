<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Dashboard\DashboardRepository;
use App\Matomo\Reporting\VisitsSummaryArchiveRepository;
use App\Matomo\ScheduledReports\LaravelScheduledReportGenerator;
use App\Matomo\ScheduledReports\RenderedScheduledReport;
use App\Matomo\ScheduledReports\ScheduledReportGenerator;
use App\Matomo\ScheduledReports\ScheduledReportRepository;
use App\Matomo\ScheduledReports\ScheduledReportSender;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Users\UserDirectoryRepository;
use Illuminate\Http\Request;
use Tests\TestCase;

final class ScheduledReportsApiTest extends TestCase
{
    private MemoryScheduledReportRepository $reports;

    private RecordingScheduledReportSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reports = new MemoryScheduledReportRepository;
        $this->sender = new RecordingScheduledReportSender;
        $this->app->instance(ScheduledReportRepository::class, $this->reports);
        $this->app->instance(ScheduledReportGenerator::class, new StaticScheduledReportGenerator);
        $this->app->instance(ScheduledReportSender::class, $this->sender);
        $this->bindAccess('alice', false, true);
    }

    public function test_adds_reads_updates_and_soft_deletes_owned_reports(): void
    {
        $id = $this->post($this->url('addReport'), $this->attributes())
            ->assertOk()->json('value');
        $this->assertSame(1, $id);

        $this->get($this->url('getReports').'&idReport=1')
            ->assertOk()->assertJsonPath('0.description', 'Weekly overview');

        $this->post($this->url('updateReport'), [
            ...$this->attributes(), 'idReport' => 1, 'description' => 'Updated overview',
        ])->assertOk();
        $this->assertSame('Updated overview', $this->reports->values[1]['description']);

        $this->post($this->url('deleteReport'), ['idReport' => 1])->assertOk();
        $this->assertSame(1, $this->reports->values[1]['deleted']);
    }

    public function test_rejects_invalid_schedule_attributes_and_foreign_updates(): void
    {
        $this->post($this->url('addReport'), [...$this->attributes(), 'hour' => 24])
            ->assertBadRequest()->assertJsonPath('message', 'The report hour must be between 0 and 23.');
        $this->reports->values[2] = $this->storedReport(['idreport' => 2, 'login' => 'bob']);

        $this->post($this->url('deleteReport'), ['idReport' => 2])
            ->assertBadRequest()->assertJsonPath('message', "Requested report couldn't be found.");
    }

    public function test_generates_and_sends_a_report_to_valid_recipients(): void
    {
        $this->reports->values[1] = $this->storedReport();
        $users = $this->createStub(UserDirectoryRepository::class);
        $users->method('user')->willReturn(['email' => 'alice@example.test']);
        $this->app->instance(UserDirectoryRepository::class, $users);

        $this->get($this->url('generateReport').'&idReport=1&date=2026-08-14&outputType=4')
            ->assertOk()->assertSee('<h1>Generated</h1>', false);
        $pdf = $this->get($this->url('generateReport').
            '&idReport=1&date=2026-08-14&outputType=4&reportFormat=pdf')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $pdf->getContent());
        $this->post($this->url('sendReport'), ['idReport' => 1, 'date' => '2026-08-14'])
            ->assertOk();

        $this->assertSame(['alice@example.test', 'extra@example.test'], $this->sender->recipients);
        $this->assertSame('Weekly overview', $this->sender->subject);
        $this->assertNotNull($this->reports->values[1]['ts_last_sent']);
    }

    public function test_real_generator_dispatches_selected_laravel_reports(): void
    {
        $this->reports->values[1] = $this->storedReport();
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $archives = $this->createStub(VisitsSummaryArchiveRepository::class);
        $archives->method('metrics')->willReturn([
            7 => ['2026-08-14,2026-08-14' => [
                'nb_uniq_visitors' => 2, 'nb_users' => 1, 'nb_visits' => 4, 'nb_actions' => 10,
                'nb_visits_converted' => 1, 'bounce_count' => 1, 'sum_visit_length' => 20, 'max_actions' => 5,
            ]],
        ]);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $archives);
        $this->app->instance(ScheduledReportGenerator::class, new LaravelScheduledReportGenerator($this->app));

        $this->get($this->url('generateReport').'&idReport=1&date=2026-08-14&outputType=4')
            ->assertOk()->assertSee('&quot;VisitsSummary_get&quot;', false)
            ->assertSee('&quot;nb_visits&quot;', false);
    }

    public function test_returns_dashboard_export_shape_and_enforces_site_access(): void
    {
        $dashboards = $this->createStub(DashboardRepository::class);
        $dashboards->method('all')->willReturn([[
            'iddashboard' => 3, 'name' => 'Main',
            'layout' => '[{"module":"VisitsSummary","action":"get"}]',
        ]]);
        $this->app->instance(DashboardRepository::class, $dashboards);

        $this->get($this->url('getWidgetReportMap').'&dashId=3&idSite=7')
            ->assertOk()->assertJsonPath('dashboardName', 'Main')
            ->assertJsonPath('email.VisitsSummary_get', true)->assertJsonPath('idSegment', null);
    }

    public function test_widget_map_enforces_site_access(): void
    {
        $this->bindAccess('alice', false, false);
        $this->get($this->url('getWidgetReportMap').'&dashId=3&idSite=7')->assertUnauthorized();
    }

    /** @return array<string, mixed> */
    private function attributes(): array
    {
        return [
            'idSite' => 7, 'description' => 'Weekly overview', 'period' => 'week', 'hour' => 8,
            'reportType' => 'email', 'reportFormat' => 'html', 'reports' => ['VisitsSummary_get'],
            'parameters' => ['emailMe' => true, 'additionalEmails' => ['extra@example.test']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function storedReport(array $overrides = []): array
    {
        return [
            'idreport' => 1, 'idsite' => 7, 'login' => 'alice', 'description' => 'Weekly overview',
            'period' => 'week', 'period_param' => 'week', 'hour' => 8, 'type' => 'email', 'format' => 'html',
            'reports' => ['VisitsSummary_get'],
            'parameters' => ['emailMe' => true, 'additionalEmails' => ['extra@example.test']],
            'deleted' => 0, ...$overrides,
        ];
    }

    private function bindAccess(string $login, bool $superUser, bool $view): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn($login);
        $authorizer->method('hasSuperUserAccess')->willReturn($superUser);
        $authorizer->method('hasSomeViewAccess')->willReturn($view);
        $authorizer->method('hasViewAccessToSite')->willReturn($view);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    private function url(string $method): string
    {
        return "/index.php?module=API&method=ScheduledReports.{$method}&format=json&token_auth=test-token";
    }
}

final class MemoryScheduledReportRepository implements ScheduledReportRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $values = [];

    public function create(array $report): int
    {
        $id = count($this->values) + 1;
        $this->values[$id] = ['idreport' => $id, ...$report];

        return $id;
    }

    public function update(int $idReport, array $changes): void
    {
        $this->values[$idReport] = [...($this->values[$idReport] ?? []), ...$changes];
    }

    public function find(?int $idSite, ?string $period, ?int $idReport, ?string $login, ?int $idSegment): array
    {
        return array_values(array_filter($this->values, static fn (array $report): bool => ($report['deleted'] ?? 0) === 0
            && ($idSite === null || $report['idsite'] === $idSite)
            && ($period === null || $report['period'] === $period)
            && ($idReport === null || $report['idreport'] === $idReport)
            && ($login === null || $report['login'] === $login)
            && ($idSegment === null || ($report['idsegment'] ?? null) === $idSegment)));
    }
}

final class StaticScheduledReportGenerator implements ScheduledReportGenerator
{
    public function generate(array $report, string $date, string $period, Request $request): string
    {
        return '<html><h1>Generated</h1></html>';
    }
}

final class RecordingScheduledReportSender implements ScheduledReportSender
{
    /** @var list<string> */
    public array $recipients = [];

    public string $subject = '';

    public function send(
        array $recipients,
        string $subject,
        string $html,
        ?RenderedScheduledReport $attachment = null,
    ): void {
        $this->recipients = $recipients;
        $this->subject = $subject;
    }
}
